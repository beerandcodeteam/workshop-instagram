<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class PixabayService
{
    private const IMAGES_URL = 'https://pixabay.com/api/';

    private const VIDEOS_URL = 'https://pixabay.com/api/videos/';

    public function __construct(private readonly string $apiKey) {}

    /**
     * @return array<int, array{id: int, url: string, tags: string, user: string, extension: string}>
     */
    public function searchImages(string $query, int $page = 1, int $perPage = 200): array
    {
        $hits = Http::retry(3, 2000, throw: false)
            ->get(self::IMAGES_URL, [
                'key' => $this->apiKey,
                'q' => $query,
                'image_type' => 'photo',
                'safesearch' => 'true',
                'per_page' => $perPage,
                'page' => $page,
            ])
            ->throw()
            ->json('hits', []);

        return array_map(function (array $hit): array {
            $extension = pathinfo(parse_url((string) $hit['largeImageURL'], PHP_URL_PATH), PATHINFO_EXTENSION);

            return [
                'id' => $hit['id'],
                'url' => $hit['largeImageURL'],
                'tags' => $hit['tags'],
                'user' => $hit['user'],
                'extension' => $extension !== '' ? $extension : 'jpg',
            ];
        }, $hits);
    }

    /**
     * @return array<int, array{id: int, url: string, tags: string, user: string, extension: string}>
     */
    public function searchVideos(string $query, int $page = 1, int $perPage = 200): array
    {
        $hits = Http::retry(3, 2000, throw: false)
            ->get(self::VIDEOS_URL, [
                'key' => $this->apiKey,
                'q' => $query,
                'safesearch' => 'true',
                'per_page' => $perPage,
                'page' => $page,
            ])
            ->throw()
            ->json('hits', []);

        return array_map(function (array $hit): array {
            $url = $hit['videos']['medium']['url']
                ?? $hit['videos']['small']['url']
                ?? $hit['videos']['tiny']['url'];

            return [
                'id' => $hit['id'],
                'url' => $url,
                'tags' => $hit['tags'],
                'user' => $hit['user'],
                'extension' => 'mp4',
            ];
        }, $hits);
    }

    public function download(string $url): string
    {
        return Http::timeout(60)->retry(2, 1500, throw: false)->get($url)->throw()->body();
    }
}
