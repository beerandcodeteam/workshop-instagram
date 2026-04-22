<?php

arch('recommendation services nao podem ser usados a partir de Blade')
    ->expect('App\Services\Recommendation')
    ->toOnlyBeUsedIn([
        'App\Services',
        'App\Jobs',
        'App\Http',
        'App\Livewire',
        'App\Console',
        'App\Observers',
        'App\Providers',
    ]);

arch('jobs da camada de recomendacao nao podem usar auth() helper nem facade Auth')
    ->expect('App\Jobs')
    ->not->toUse([
        'Illuminate\Support\Facades\Auth',
    ]);

arch('jobs nao podem chamar auth() global helper')
    ->expect('App\Jobs')
    ->not->toUse(['auth']);

arch('models de recomendacao nao podem conter logica de ranking')
    ->expect([
        'App\Models\Post',
        'App\Models\PostInteraction',
        'App\Models\RecommendationLog',
        'App\Models\UserInterestCluster',
        'App\Models\InteractionType',
        'App\Models\RecommendationSource',
        'App\Models\EmbeddingModel',
        'App\Models\RecommendationExperiment',
        'App\Models\RecommendationSetting',
        'App\Models\Report',
    ])
    ->not->toUse([
        'App\Services\Recommendation\Ranker',
        'App\Services\Recommendation\MmrReranker',
        'App\Services\Recommendation\CandidateGenerator',
        'App\Services\Recommendation\RecommendationService',
    ]);
