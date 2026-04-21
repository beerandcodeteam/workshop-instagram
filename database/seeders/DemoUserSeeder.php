<?php

namespace Database\Seeders;

use App\Models\PostType;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoUserSeeder extends Seeder
{
    use WithoutModelEvents;

    public int $userCount = 5000;

    public int $postsPerUser = 2;

    public int $userChunk = 500;

    public int $postChunk = 1000;

    public string $manifestPath = __DIR__.'/data/pixabay-manifest.json';

    public string $textPoolPath = __DIR__.'/data/text_pool.json';

    public function run(): void
    {
        $manifest = $this->loadManifest();

        if ($manifest === null) {
            $this->say('error', 'Manifest not found at '.$this->manifestPath);

            return;
        }

        $imagesPool = $manifest['images'];
        $videosPool = $manifest['videos'];

        if ($imagesPool === [] || $videosPool === []) {
            $this->say('error', 'Manifest is empty — re-run the download command.');

            return;
        }

        $textPool = $this->loadTextPool();

        if ($textPool === []) {
            $this->say('error', 'Text pool not found at '.$this->textPoolPath);

            return;
        }

        $this->call(PostTypeSeeder::class);

        $typeIds = [
            'text' => PostType::where('slug', 'text')->value('id'),
            'image' => PostType::where('slug', 'image')->value('id'),
            'video' => PostType::where('slug', 'video')->value('id'),
        ];

        $totalPosts = $this->userCount * $this->postsPerUser;
        $typePlan = $this->buildTypePlan($totalPosts);

        $userIds = $this->insertUsers($this->userCount);
        $this->insertPostsAndMedia($userIds, $typePlan, $typeIds, $imagesPool, $videosPool, $textPool);
    }

    /**
     * @return array<int, string>
     */
    private function loadTextPool(): array
    {
        if (! is_file($this->textPoolPath)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->textPoolPath), true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function say(string $level, string $message): void
    {
        if ($this->command !== null) {
            $this->command->{$level}($message);
        }
    }

    /**
     * @return array{images: array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>, videos: array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>}|null
     */
    private function loadManifest(): ?array
    {
        if (! is_file($this->manifestPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->manifestPath), true);

        if (! is_array($decoded) || ! isset($decoded['images'], $decoded['videos'])) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<int, 'text'|'image'|'video'>
     */
    private function buildTypePlan(int $totalPosts): array
    {
        $imageCount = (int) round($totalPosts * 0.4);
        $videoCount = (int) round($totalPosts * 0.2);
        $textCount = $totalPosts - $imageCount - $videoCount;

        $plan = array_merge(
            array_fill(0, $imageCount, 'image'),
            array_fill(0, $videoCount, 'video'),
            array_fill(0, $textCount, 'text'),
        );

        shuffle($plan);

        return $plan;
    }

    /**
     * @return array<int, int>
     */
    private function insertUsers(int $count): array
    {
        $this->say('info', "Creating {$count} users...");

        $password = Hash::make('password');
        $now = Carbon::now();
        $marker = 'demo-'.uniqid().'-';
        $startingId = (int) (DB::table('users')->max('id') ?? 0);

        $progress = $this->command?->getOutput()->createProgressBar($count);
        $progress?->start();

        $buffer = [];

        for ($i = 0; $i < $count; $i++) {
            $buffer[] = [
                'name' => fake()->name(),
                'email' => $marker.($i + 1).'-'.fake()->unique()->safeEmail(),
                'email_verified_at' => $now,
                'password' => $password,
                'remember_token' => Str::random(10),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) === $this->userChunk) {
                DB::table('users')->insert($buffer);
                $progress?->advance(count($buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('users')->insert($buffer);
            $progress?->advance(count($buffer));
        }

        $progress?->finish();

        if ($this->command !== null) {
            $this->command->newLine();
        }

        return DB::table('users')
            ->where('id', '>', $startingId)
            ->where('email', 'like', $marker.'%')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @param  array<int, 'text'|'image'|'video'>  $typePlan
     * @param  array<string, int>  $typeIds
     * @param  array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>  $imagesPool
     * @param  array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>  $videosPool
     * @param  array<int, string>  $textPool
     */
    private function insertPostsAndMedia(
        array $userIds,
        array $typePlan,
        array $typeIds,
        array $imagesPool,
        array $videosPool,
        array $textPool,
    ): void {
        $totalPosts = count($typePlan);
        $this->say('info', "Creating {$totalPosts} posts and media...");

        $now = Carbon::now();
        $startPostId = (int) (DB::table('posts')->max('id') ?? 0);

        $postRows = [];
        $mediaPlan = [];
        $typeIndex = 0;

        foreach ($userIds as $userId) {
            for ($p = 0; $p < $this->postsPerUser; $p++) {
                $type = $typePlan[$typeIndex++];
                $createdAt = $now->copy()->subMinutes(random_int(0, 60 * 24 * 30));

                $body = null;

                if ($type === 'text') {
                    $body = $textPool[array_rand($textPool)];
                } else {
                    $pool = $type === 'image' ? $imagesPool : $videosPool;
                    $hit = $pool[array_rand($pool)];
                    $body = isset($hit['caption']) && fake()->boolean(85) ? $hit['caption'] : null;
                }

                $postRows[] = [
                    'user_id' => $userId,
                    'post_type_id' => $typeIds[$type],
                    'body' => $body,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                if ($type === 'image' || $type === 'video') {
                    $mediaPlan[count($postRows) - 1] = [
                        'file_path' => $hit['path'],
                        'created_at' => $createdAt,
                    ];
                }
            }
        }

        $progress = $this->command?->getOutput()->createProgressBar($totalPosts);
        $progress?->start();

        foreach (array_chunk($postRows, $this->postChunk) as $chunk) {
            DB::table('posts')->insert($chunk);
            $progress?->advance(count($chunk));
        }

        $progress?->finish();

        if ($this->command !== null) {
            $this->command->newLine();
        }

        $insertedIds = DB::table('posts')
            ->where('id', '>', $startPostId)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $mediaRows = [];
        foreach ($mediaPlan as $index => $plan) {
            $mediaRows[] = [
                'post_id' => $insertedIds[$index],
                'file_path' => $plan['file_path'],
                'sort_order' => 0,
                'created_at' => $plan['created_at'],
                'updated_at' => $plan['created_at'],
            ];
        }

        foreach (array_chunk($mediaRows, $this->postChunk) as $chunk) {
            DB::table('post_media')->insert($chunk);
        }
    }
}
