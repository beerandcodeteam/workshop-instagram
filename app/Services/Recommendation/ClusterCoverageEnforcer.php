<?php

namespace App\Services\Recommendation;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClusterCoverageEnforcer
{
    /**
     * Ensure that the top-K window of the feed covers at least
     * `coverage_min_ratio` of the user's interest clusters.
     *
     * Only applied for users with ≥ `coverage_min_clusters`. Candidates whose
     * cluster is missing from the top-K are promoted by swapping in the
     * lowest-scoring candidate whose cluster is already over-represented.
     *
     * @param  list<RankedCandidate>  $ranked
     * @return list<RankedCandidate>
     */
    public function enforce(User $user, array $ranked): array
    {
        if ($ranked === []) {
            return $ranked;
        }

        $topK = (int) config('recommendation.clusters.coverage_top_k', 20);
        $minRatio = (float) config('recommendation.clusters.coverage_min_ratio', 0.7);
        $minClusters = (int) config('recommendation.clusters.coverage_min_clusters', 3);

        $clusters = DB::table('user_interest_clusters')
            ->where('user_id', $user->id)
            ->orderBy('cluster_index')
            ->get(['cluster_index', DB::raw('embedding::text as embedding_text')]);

        if ($clusters->count() < $minClusters) {
            return $ranked;
        }

        $clusterEmbeddings = [];
        foreach ($clusters as $row) {
            $vector = $this->parseVector((string) $row->embedding_text);
            if ($vector === []) {
                continue;
            }

            $clusterEmbeddings[(int) $row->cluster_index] = $vector;
        }

        if ($clusterEmbeddings === []) {
            return $ranked;
        }

        $totalClusters = count($clusterEmbeddings);
        $required = (int) ceil($minRatio * $totalClusters);

        $closest = [];
        foreach ($ranked as $position => $candidate) {
            $closest[$position] = $this->closestCluster($candidate->embedding, $clusterEmbeddings);
        }

        $window = min($topK, count($ranked));

        $clusterCounts = [];
        foreach (array_keys($clusterEmbeddings) as $clusterIndex) {
            $clusterCounts[$clusterIndex] = 0;
        }
        foreach ($this->countClustersInWindow($closest, $window) as $clusterIndex => $count) {
            $clusterCounts[$clusterIndex] = $count;
        }

        $present = count(array_filter($clusterCounts, static fn (int $c) => $c > 0));

        if ($present >= $required) {
            return $ranked;
        }

        $missing = array_keys(array_filter($clusterCounts, static fn (int $c) => $c === 0));

        foreach ($missing as $clusterIndex) {
            if ($present >= $required) {
                break;
            }

            $promoteIndex = null;
            for ($i = $window; $i < count($ranked); $i++) {
                if ($closest[$i] === $clusterIndex) {
                    $promoteIndex = $i;
                    break;
                }
            }

            if ($promoteIndex === null) {
                continue;
            }

            $demoteIndex = $this->findDemoteCandidate($closest, $window, $clusterCounts);

            if ($demoteIndex === null) {
                continue;
            }

            $demotedCluster = $closest[$demoteIndex];
            $promotedCluster = $closest[$promoteIndex];

            [$ranked[$demoteIndex], $ranked[$promoteIndex]] = [$ranked[$promoteIndex], $ranked[$demoteIndex]];
            [$closest[$demoteIndex], $closest[$promoteIndex]] = [$closest[$promoteIndex], $closest[$demoteIndex]];

            $clusterCounts[$demotedCluster]--;
            $clusterCounts[$promotedCluster] = ($clusterCounts[$promotedCluster] ?? 0) + 1;

            $present = count(array_filter($clusterCounts, static fn (int $c) => $c > 0));
        }

        return $ranked;
    }

    /**
     * @param  array<int, int>  $closest
     * @return array<int, int>
     */
    private function countClustersInWindow(array $closest, int $window): array
    {
        $counts = [];
        for ($i = 0; $i < $window; $i++) {
            $cluster = $closest[$i];
            if ($cluster === null) {
                continue;
            }

            $counts[$cluster] = ($counts[$cluster] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Find the latest position inside the window whose cluster is duplicated,
     * so we can swap it out without losing coverage.
     *
     * @param  array<int, ?int>  $closest
     * @param  array<int, int>  $clusterCounts
     */
    private function findDemoteCandidate(array $closest, int $window, array $clusterCounts): ?int
    {
        for ($i = $window - 1; $i >= 0; $i--) {
            $cluster = $closest[$i];
            if ($cluster === null) {
                continue;
            }

            if (($clusterCounts[$cluster] ?? 0) > 1) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<float>  $vector
     * @param  array<int, list<float>>  $clusterEmbeddings
     */
    private function closestCluster(array $vector, array $clusterEmbeddings): ?int
    {
        if ($vector === []) {
            return null;
        }

        $bestCluster = null;
        $bestScore = -INF;

        foreach ($clusterEmbeddings as $clusterIndex => $centroid) {
            $score = $this->cosine($vector, $centroid);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCluster = $clusterIndex;
            }
        }

        return $bestCluster;
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
}
