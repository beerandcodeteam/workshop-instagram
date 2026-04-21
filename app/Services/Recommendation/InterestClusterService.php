<?php

namespace App\Services\Recommendation;

use App\Models\EmbeddingModel;
use App\Models\User;
use App\Models\UserInterestCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InterestClusterService
{
    /**
     * Recompute interest clusters for the given user using k-means + silhouette.
     *
     * Returns the number of clusters persisted, or 0 if the user is below the
     * minimum sample threshold.
     */
    public function computeFor(User $user): int
    {
        $minSamples = (int) config('recommendation.clusters.min_samples', 30);
        $windowDays = (int) config('recommendation.clusters.window_days', 90);
        $kMin = (int) config('recommendation.clusters.k_min', 3);
        $kMax = (int) config('recommendation.clusters.k_max', 7);
        $maxIter = (int) config('recommendation.clusters.max_iterations', 25);
        $tolerance = (float) config('recommendation.clusters.tolerance', 1e-4);

        $points = $this->loadPositiveEmbeddings($user->id, $windowDays);

        if (count($points) < $minSamples) {
            return 0;
        }

        $best = $this->selectBestK($points, $kMin, $kMax, $maxIter, $tolerance);

        if ($best === null) {
            return 0;
        }

        $this->replaceClusters($user, $best['centroids'], $best['assignments'], count($points));

        return count($best['centroids']);
    }

    /**
     * Whether the user accumulated enough new positive interactions since the
     * last cluster computation to justify a refresh.
     */
    public function shouldRefresh(User $user): bool
    {
        $minSamples = (int) config('recommendation.clusters.min_samples', 30);
        $delta = (int) config('recommendation.clusters.count_delta_threshold', 20);
        $windowDays = (int) config('recommendation.clusters.window_days', 90);

        $totalPositive = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.user_id', $user->id)
            ->where('it.is_positive', true)
            ->whereNotNull('p.embedding')
            ->where('pi.created_at', '>=', now()->subDays($windowDays))
            ->count();

        if ($totalPositive < $minSamples) {
            return false;
        }

        $lastComputedAt = DB::table('user_interest_clusters')
            ->where('user_id', $user->id)
            ->max('computed_at');

        if ($lastComputedAt === null) {
            return true;
        }

        $newSince = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.user_id', $user->id)
            ->where('it.is_positive', true)
            ->whereNotNull('p.embedding')
            ->where('pi.created_at', '>', $lastComputedAt)
            ->count();

        return $newSince > $delta;
    }

    /**
     * Load positive interaction post embeddings for the user, deduplicated by
     * post (we only keep one vector per post even if it was liked + commented).
     *
     * @return list<list<float>>
     */
    private function loadPositiveEmbeddings(int $userId, int $windowDays): array
    {
        $rows = DB::table('post_interactions as pi')
            ->join('interaction_types as it', 'it.id', '=', 'pi.interaction_type_id')
            ->join('posts as p', 'p.id', '=', 'pi.post_id')
            ->where('pi.user_id', $userId)
            ->where('it.is_positive', true)
            ->where('pi.created_at', '>=', now()->subDays($windowDays))
            ->whereNotNull('p.embedding')
            ->select('p.id as post_id', DB::raw('p.embedding::text as embedding_text'))
            ->distinct()
            ->get();

        $byPost = [];
        foreach ($rows as $row) {
            $postId = (int) $row->post_id;
            if (isset($byPost[$postId])) {
                continue;
            }

            $vector = $this->parseVector((string) $row->embedding_text);

            if ($vector === []) {
                continue;
            }

            $byPost[$postId] = $this->l2Normalize($vector);
        }

        return array_values($byPost);
    }

    /**
     * Run k-means for each k in [kMin..kMax] and pick the result with the
     * highest silhouette score. Returns null if no valid clustering was found.
     *
     * @param  list<list<float>>  $points
     * @return array{centroids: list<list<float>>, assignments: list<int>, k: int, silhouette: float}|null
     */
    private function selectBestK(array $points, int $kMin, int $kMax, int $maxIter, float $tolerance): ?array
    {
        $kMin = max(2, $kMin);
        $kMax = min(count($points) - 1, $kMax);

        if ($kMax < $kMin) {
            return null;
        }

        $best = null;

        for ($k = $kMin; $k <= $kMax; $k++) {
            $result = $this->runKmeans($points, $k, $maxIter, $tolerance);

            if ($result === null) {
                continue;
            }

            $score = $this->silhouette($points, $result['assignments'], $k);

            if ($best === null || $score > $best['silhouette']) {
                $best = [
                    'centroids' => $result['centroids'],
                    'assignments' => $result['assignments'],
                    'k' => $k,
                    'silhouette' => $score,
                ];
            }
        }

        return $best;
    }

    /**
     * @param  list<list<float>>  $points
     * @return array{centroids: list<list<float>>, assignments: list<int>}|null
     */
    private function runKmeans(array $points, int $k, int $maxIter, float $tolerance): ?array
    {
        if ($k <= 0 || count($points) < $k) {
            return null;
        }

        $centroids = $this->initializeCentroids($points, $k);
        $assignments = array_fill(0, count($points), 0);

        for ($iter = 0; $iter < $maxIter; $iter++) {
            $changed = false;

            foreach ($points as $i => $point) {
                $closest = $this->closestCentroid($point, $centroids);

                if ($closest !== $assignments[$i]) {
                    $assignments[$i] = $closest;
                    $changed = true;
                }
            }

            $newCentroids = $this->recomputeCentroids($points, $assignments, $k, $centroids);

            $shift = 0.0;
            foreach ($centroids as $idx => $old) {
                $shift = max($shift, 1.0 - $this->cosine($old, $newCentroids[$idx]));
            }

            $centroids = $newCentroids;

            if (! $changed && $shift < $tolerance) {
                break;
            }
        }

        $clusterSizes = array_fill(0, $k, 0);
        foreach ($assignments as $cluster) {
            $clusterSizes[$cluster]++;
        }

        foreach ($clusterSizes as $size) {
            if ($size === 0) {
                return null;
            }
        }

        return [
            'centroids' => $centroids,
            'assignments' => $assignments,
        ];
    }

    /**
     * k-means++ initialization.
     *
     * @param  list<list<float>>  $points
     * @return list<list<float>>
     */
    private function initializeCentroids(array $points, int $k): array
    {
        $n = count($points);
        $firstIndex = random_int(0, $n - 1);

        $centroids = [$points[$firstIndex]];

        while (count($centroids) < $k) {
            $distances = [];
            $total = 0.0;

            foreach ($points as $point) {
                $minDist = INF;

                foreach ($centroids as $centroid) {
                    $dist = max(0.0, 1.0 - $this->cosine($point, $centroid));

                    if ($dist < $minDist) {
                        $minDist = $dist;
                    }
                }

                $squared = $minDist * $minDist;
                $distances[] = $squared;
                $total += $squared;
            }

            if ($total <= 0.0) {
                $candidateIndex = random_int(0, $n - 1);
                $centroids[] = $points[$candidateIndex];

                continue;
            }

            $threshold = (mt_rand() / mt_getrandmax()) * $total;
            $running = 0.0;
            $picked = $n - 1;

            foreach ($distances as $i => $squared) {
                $running += $squared;
                if ($running >= $threshold) {
                    $picked = $i;
                    break;
                }
            }

            $centroids[] = $points[$picked];
        }

        return $centroids;
    }

    /**
     * @param  list<float>  $point
     * @param  list<list<float>>  $centroids
     */
    private function closestCentroid(array $point, array $centroids): int
    {
        $bestIndex = 0;
        $bestSimilarity = -INF;

        foreach ($centroids as $idx => $centroid) {
            $similarity = $this->cosine($point, $centroid);

            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestIndex = $idx;
            }
        }

        return $bestIndex;
    }

    /**
     * @param  list<list<float>>  $points
     * @param  list<int>  $assignments
     * @param  list<list<float>>  $previous
     * @return list<list<float>>
     */
    private function recomputeCentroids(array $points, array $assignments, int $k, array $previous): array
    {
        $dim = count($points[0]);
        $sums = array_fill(0, $k, array_fill(0, $dim, 0.0));
        $counts = array_fill(0, $k, 0);

        foreach ($points as $i => $point) {
            $cluster = $assignments[$i];
            $counts[$cluster]++;

            foreach ($point as $j => $value) {
                $sums[$cluster][$j] += $value;
            }
        }

        $centroids = [];

        for ($c = 0; $c < $k; $c++) {
            if ($counts[$c] === 0) {
                $centroids[] = $previous[$c];

                continue;
            }

            $mean = array_map(fn (float $v) => $v / $counts[$c], $sums[$c]);
            $centroids[] = $this->l2Normalize($mean);
        }

        return $centroids;
    }

    /**
     * Average silhouette score using cosine distance (1 - cosine similarity).
     *
     * @param  list<list<float>>  $points
     * @param  list<int>  $assignments
     */
    private function silhouette(array $points, array $assignments, int $k): float
    {
        $n = count($points);
        if ($n < 2) {
            return 0.0;
        }

        $byCluster = array_fill(0, $k, []);
        foreach ($assignments as $i => $cluster) {
            $byCluster[$cluster][] = $i;
        }

        $totals = 0.0;

        foreach ($points as $i => $point) {
            $own = $assignments[$i];
            $sameCluster = $byCluster[$own];

            if (count($sameCluster) <= 1) {
                continue;
            }

            $a = 0.0;
            foreach ($sameCluster as $j) {
                if ($j === $i) {
                    continue;
                }

                $a += 1.0 - $this->cosine($point, $points[$j]);
            }
            $a /= max(1, count($sameCluster) - 1);

            $b = INF;
            for ($c = 0; $c < $k; $c++) {
                if ($c === $own || $byCluster[$c] === []) {
                    continue;
                }

                $sum = 0.0;
                foreach ($byCluster[$c] as $j) {
                    $sum += 1.0 - $this->cosine($point, $points[$j]);
                }
                $mean = $sum / count($byCluster[$c]);

                if ($mean < $b) {
                    $b = $mean;
                }
            }

            if ($b === INF) {
                continue;
            }

            $denom = max($a, $b);
            if ($denom <= 0.0) {
                continue;
            }

            $totals += ($b - $a) / $denom;
        }

        return $totals / $n;
    }

    /**
     * Delete + insert: never update in place. The trade-off is sequence id
     * burn, but it keeps the rebuild atomic and easy to reason about.
     *
     * @param  list<list<float>>  $centroids
     * @param  list<int>  $assignments
     */
    private function replaceClusters(User $user, array $centroids, array $assignments, int $totalSamples): void
    {
        $counts = array_fill(0, count($centroids), 0);
        foreach ($assignments as $cluster) {
            $counts[$cluster]++;
        }

        DB::transaction(function () use ($user, $centroids, $counts, $totalSamples): void {
            DB::table('user_interest_clusters')
                ->where('user_id', $user->id)
                ->delete();

            $modelId = $this->currentModelId();
            $now = now();

            foreach ($centroids as $idx => $centroid) {
                UserInterestCluster::create([
                    'user_id' => $user->id,
                    'cluster_index' => $idx,
                    'embedding' => $centroid,
                    'weight' => round($counts[$idx] / $totalSamples, 4),
                    'sample_count' => $counts[$idx],
                    'embedding_model_id' => $modelId,
                    'computed_at' => $now,
                ]);
            }
        });
    }

    private function currentModelId(): ?int
    {
        return EmbeddingModel::where('slug', config('services.gemini.embedding.model'))->value('id');
    }

    /**
     * @return list<float>
     */
    private function parseVector(string $text): array
    {
        $trimmed = trim($text, "[] \t\n\r");

        if ($trimmed === '') {
            return [];
        }

        return array_map('floatval', explode(',', $trimmed));
    }

    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function l2Normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(static fn (float $v) => $v * $v, $vector)));

        if ($magnitude <= 0.0) {
            return $vector;
        }

        return array_map(static fn (float $v) => $v / $magnitude, $vector);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $count = min(count($a), count($b));
        if ($count === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);
        if ($denominator <= 0.0) {
            return 0.0;
        }

        return $dot / $denominator;
    }

    /**
     * @return Collection<int, UserInterestCluster>
     */
    public function clustersFor(User $user): Collection
    {
        return UserInterestCluster::where('user_id', $user->id)
            ->orderBy('cluster_index')
            ->get();
    }
}
