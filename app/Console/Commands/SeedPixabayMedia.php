<?php

namespace App\Console\Commands;

use App\Services\PixabayService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Signature('app:seed-pixabay-media {--images=600} {--videos=400} {--manifest=pixabay-manifest.json}')]
#[Description('Download a pool of Pixabay images and videos to the default filesystem disk')]
class SeedPixabayMedia extends Command
{
    /**
     * @var array<int, string>
     */
    private const IMAGE_QUERIES = [
        // pets
        'dog', 'puppy', 'cat', 'kitten',
        // food & drinks
        'coffee', 'brunch', 'pizza', 'sushi', 'dessert', 'cocktail', 'smoothie',
        // cars & rides
        'sports car', 'motorcycle', 'classic car',
        // lifestyle
        'fashion outfit', 'portrait selfie', 'street style',
        // fitness & wellness
        'gym workout', 'yoga pose', 'running shoes',
        // travel hotspots
        'beach sunset', 'paris eiffel', 'tropical vacation',
        // home & hobbies
        'house plant', 'cozy interior', 'bookshelf',
        // beauty
        'makeup', 'nail art',
    ];

    /**
     * @var array<int, string>
     */
    private const VIDEO_QUERIES = [
        // pets
        'dog playing', 'cat playing',
        // food
        'coffee pour', 'cooking food',
        // cars
        'sports car', 'motorcycle ride',
        // lifestyle
        'fashion runway', 'dance',
        // fitness
        'gym workout', 'yoga',
        // travel
        'beach waves', 'city street',
        // hobbies
        'skateboarding', 'surfing',
    ];

    public function handle(): int
    {
        $apiKey = config('services.pixabay.key');

        if (empty($apiKey)) {
            $this->error('PIXABAY_API_KEY is not set in .env.');

            return self::FAILURE;
        }

        $disk = config('filesystems.default');
        $imagesTarget = (int) $this->option('images');
        $videosTarget = (int) $this->option('videos');

        $this->info("Disk: [{$disk}]. Target: {$imagesTarget} images, {$videosTarget} videos.");

        $pixabay = new PixabayService($apiKey);

        $images = $this->fillPool($pixabay, 'images', self::IMAGE_QUERIES, $imagesTarget, $disk);
        $videos = $this->fillPool($pixabay, 'videos', self::VIDEO_QUERIES, $videosTarget, $disk);

        $manifest = $this->option('manifest');
        Storage::disk('local')->put(
            $manifest,
            (string) json_encode(['images' => $images, 'videos' => $videos], JSON_PRETTY_PRINT),
        );

        $this->newLine();
        $this->info("Manifest saved to storage/app/private/{$manifest}");
        $this->info('Pool: '.count($images).' images, '.count($videos).' videos.');

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $queries
     * @return array<int, array{id: int, path: string, tags: string, user: string, query: string}>
     */
    private function fillPool(
        PixabayService $pixabay,
        string $kind,
        array $queries,
        int $target,
        string $disk,
    ): array {
        $this->info("Fetching {$kind} across ".count($queries).' categories...');

        $perQuery = (int) ceil($target / count($queries));
        $stored = [];
        $seen = [];

        $progress = $this->output->createProgressBar($target);
        $progress->start();

        foreach ($queries as $query) {
            if (count($stored) >= $target) {
                break;
            }

            $remaining = $target - count($stored);
            $quota = min($perQuery, $remaining);

            $fetchSize = min(200, max(20, $quota + 10));

            $hits = $kind === 'images'
                ? $pixabay->searchImages($query, 1, $fetchSize)
                : $pixabay->searchVideos($query, 1, $fetchSize);

            $takenFromQuery = 0;

            foreach ($hits as $hit) {
                if ($takenFromQuery >= $quota || count($stored) >= $target) {
                    break;
                }

                if (isset($seen[$hit['id']])) {
                    continue;
                }

                $seen[$hit['id']] = true;

                try {
                    $binary = $pixabay->download($hit['url']);
                } catch (Throwable $e) {
                    $this->newLine();
                    $this->warn("Skip {$kind} #{$hit['id']} ({$query}): {$e->getMessage()}");

                    continue;
                }

                $path = "seed/{$kind}/{$hit['id']}.{$hit['extension']}";
                Storage::disk($disk)->put($path, $binary);

                $stored[] = [
                    'id' => $hit['id'],
                    'path' => $path,
                    'tags' => $hit['tags'],
                    'user' => $hit['user'],
                    'query' => $query,
                ];

                $takenFromQuery++;
                $progress->advance();
            }
        }

        $progress->finish();
        $this->newLine();

        return $stored;
    }
}
