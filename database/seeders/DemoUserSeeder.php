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

    public int $userCount = 300;

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

        $contentPlan = $this->buildContentPlan($imagesPool, $videosPool, $textPool);

        $userIds = $this->insertUsers($this->userCount);
        $this->insertPostsAndMedia($userIds, $contentPlan, $typeIds);
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
     * Each content item (image, video, text) is used exactly once and shuffled,
     * so posts are distributed across users without any repetition.
     *
     * @param  array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>  $imagesPool
     * @param  array<int, array{id: int, path: string, tags: string, user: string, caption?: string}>  $videosPool
     * @param  array<int, string>  $textPool
     * @return array<int, array{type: 'text'|'image'|'video', body: ?string, file_path: ?string}>
     */
    private function buildContentPlan(array $imagesPool, array $videosPool, array $textPool): array
    {
        $plan = [];

        foreach ($imagesPool as $item) {
            $plan[] = [
                'type' => 'image',
                'body' => isset($item['caption']) && fake()->boolean(85) ? $item['caption'] : null,
                'file_path' => $item['path'],
            ];
        }

        foreach ($videosPool as $item) {
            $plan[] = [
                'type' => 'video',
                'body' => isset($item['caption']) && fake()->boolean(85) ? $item['caption'] : null,
                'file_path' => $item['path'],
            ];
        }

        foreach ($textPool as $text) {
            $plan[] = [
                'type' => 'text',
                'body' => $text,
                'file_path' => null,
            ];
        }

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
     * @param  array<int, array{type: 'text'|'image'|'video', body: ?string, file_path: ?string}>  $contentPlan
     * @param  array<string, int>  $typeIds
     */
    private function insertPostsAndMedia(
        array $userIds,
        array $contentPlan,
        array $typeIds,
    ): void {
        $totalPosts = count($contentPlan);
        $this->say('info', "Creating {$totalPosts} posts and media...");

        $now = Carbon::now();
        $startPostId = (int) (DB::table('posts')->max('id') ?? 0);
        $userCount = count($userIds);

        $postRows = [];
        $mediaPlan = [];

        foreach ($contentPlan as $index => $content) {
            $userId = $userIds[$index % $userCount];
            $createdAt = $now->copy()->subMinutes(random_int(0, 60 * 24 * 30));

            $postRows[] = [
                'user_id' => $userId,
                'post_type_id' => $typeIds[$content['type']],
                'body' => $content['body'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            if ($content['file_path'] !== null) {
                $mediaPlan[$index] = [
                    'file_path' => $content['file_path'],
                    'created_at' => $createdAt,
                ];
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
