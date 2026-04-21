<?php

use App\Services\Recommendation\Candidate;
use App\Services\Recommendation\MmrReranker;
use App\Services\Recommendation\RankedCandidate;

function fakeRanked(int $postId, float $score, array $embedding, int $authorId = 1): RankedCandidate
{
    return new RankedCandidate(
        candidate: Candidate::make($postId, 'ann_long_term', $score),
        authorId: $authorId,
        score: $score,
        scoresBreakdown: ['final' => $score],
        embedding: $embedding,
    );
}

test('mmr_prevents_adjacent_similar_posts', function () {
    $dim = 8;

    $ranked = [];
    // 10 posts quase idênticos (variam pouco no eixo 0).
    for ($i = 0; $i < 10; $i++) {
        $vec = array_fill(0, $dim, 0.0);
        $vec[0] = 1.0;
        $vec[1] = 0.01 * $i;
        $ranked[] = fakeRanked($i + 1, 0.95 - 0.001 * $i, $vec);
    }

    // 10 posts bem variados em outras dimensões.
    for ($j = 0; $j < 10; $j++) {
        $vec = array_fill(0, $dim, 0.0);
        $vec[$j % $dim] = 1.0;
        $ranked[] = fakeRanked($j + 100, 0.90 - 0.001 * $j, $vec);
    }

    $reranker = app(MmrReranker::class);
    $out = $reranker->applyMmr($ranked, lambda: 0.5, poolSize: 20);

    $top10 = array_slice($out, 0, 10);

    // Conta a diversidade: quantos posts do grupo variado entram no top10.
    $diverseCount = 0;
    foreach ($top10 as $candidate) {
        if ($candidate->candidate->postId >= 100) {
            $diverseCount++;
        }
    }

    expect($diverseCount)->toBeGreaterThan(0);
});

test('mmr_is_identity_when_lambda_is_1', function () {
    $dim = 4;

    $ranked = [
        fakeRanked(1, 0.9, [1.0, 0.0, 0.0, 0.0]),
        fakeRanked(2, 0.8, [1.0, 0.0, 0.0, 0.0]),
        fakeRanked(3, 0.7, [0.0, 1.0, 0.0, 0.0]),
        fakeRanked(4, 0.6, [0.0, 0.0, 1.0, 0.0]),
    ];

    $reranker = app(MmrReranker::class);
    $out = $reranker->applyMmr($ranked, lambda: 1.0, poolSize: 10);

    $ids = array_map(fn ($c) => $c->candidate->postId, $out);
    expect($ids)->toBe([1, 2, 3, 4]);
});

test('mmr_degrades_to_popularity_when_lambda_is_0', function () {
    $dim = 4;

    $ranked = [
        fakeRanked(1, 0.9, [1.0, 0.0, 0.0, 0.0]),
        fakeRanked(2, 0.8, [0.0, 1.0, 0.0, 0.0]),
        fakeRanked(3, 0.7, [1.0, 0.0, 0.0, 0.0]),
        fakeRanked(4, 0.6, [0.0, 0.0, 1.0, 0.0]),
    ];

    $reranker = app(MmrReranker::class);
    $out = $reranker->applyMmr($ranked, lambda: 0.0, poolSize: 10);

    $ids = array_map(fn ($c) => $c->candidate->postId, $out);

    // Primeiro pick continua sendo o mais popular (top rank).
    expect($ids[0])->toBe(1);
    // Todos os posts são preservados (degradação só afeta ordem, não set).
    expect(count($out))->toBe(count($ranked));
});
