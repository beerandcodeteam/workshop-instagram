<?php

namespace App\Console\Commands;

use App\Contracts\EmbeddingServiceContract;
use Illuminate\Console\Command;
use Throwable;

class SmokeTestEmbeddingCommand extends Command
{
    protected $signature = 'rec:smoke-test-embedding';

    protected $description = 'Smoke test the configured embedding provider with a single hello-world payload';

    public function handle(EmbeddingServiceContract $embeddingService): int
    {
        $this->info('Calling embedding provider with payload [["text" => "hello world"]]...');

        try {
            $vector = $embeddingService->embed([['text' => 'hello world']], 'RETRIEVAL_DOCUMENT');
        } catch (Throwable $e) {
            $this->error('Falha ao gerar embedding: '.$e->getMessage());

            return self::FAILURE;
        }

        $expectedDimensions = (int) config('services.gemini.embedding.dimensions', 1536);
        $actualDimensions = is_array($vector) ? count($vector) : 0;

        if ($actualDimensions !== $expectedDimensions) {
            $this->error("Embedding retornado tem {$actualDimensions} dimensões, esperado {$expectedDimensions}.");

            return self::FAILURE;
        }

        $this->info("Sucesso: embedding com {$actualDimensions} dimensões retornado pelo provider.");

        return self::SUCCESS;
    }
}
