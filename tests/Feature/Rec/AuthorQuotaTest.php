<?php

use App\Services\Recommendation\AuthorQuota;
use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\RankedCandidate;

function rankedForQuota(int $postId, int $authorId, float $score): RankedCandidate
{
    return new RankedCandidate(
        candidate: Candidate::make($postId, 'ann_long_term', $score),
        authorId: $authorId,
        score: $score,
        scoresBreakdown: ['final' => $score],
        embedding: [],
    );
}

test('top_k_has_at_most_n_posts_per_author', function () {
    $ranked = [
        rankedForQuota(1, 10, 0.9),
        rankedForQuota(2, 10, 0.88),
        rankedForQuota(3, 10, 0.85), // deve ir para overflow
        rankedForQuota(4, 10, 0.80), // deve ir para overflow
        rankedForQuota(5, 20, 0.70),
        rankedForQuota(6, 30, 0.65),
        rankedForQuota(7, 40, 0.60),
    ];

    $quota = app(AuthorQuota::class);
    $out = $quota->applyAuthorQuota($ranked, topK: 4, perAuthor: 2);

    $topAuthors = array_slice(array_map(fn ($c) => $c->authorId, $out), 0, 4);

    $counts = array_count_values($topAuthors);
    expect($counts[10] ?? 0)->toBeLessThanOrEqual(2);
});

test('underfilled_quota_promotes_next_candidate_respecting_rule', function () {
    // Se não há candidatos suficientes para preencher topK sem ferir a regra,
    // os overflow são promovidos apenas para não deixar slots vazios.
    $ranked = [
        rankedForQuota(1, 10, 0.9),
        rankedForQuota(2, 10, 0.85),
        rankedForQuota(3, 10, 0.82), // overflow inicialmente
        rankedForQuota(4, 10, 0.80), // overflow inicialmente
    ];

    $quota = app(AuthorQuota::class);
    $out = $quota->applyAuthorQuota($ranked, topK: 4, perAuthor: 2);

    // topK=4, mas todos os posts são do mesmo autor; temos que promover
    // overflow para preencher.
    expect($out)->toHaveCount(4);
    $ids = array_map(fn ($c) => $c->candidate->postId, $out);
    expect($ids)->toBe([1, 2, 3, 4]);
});
