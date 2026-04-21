<?php

namespace App\Services\Recommendation;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

class SeenFilter
{
    public function key(User|int $user): string
    {
        $id = $user instanceof User ? $user->id : $user;
        $prefix = (string) config('recommendation.seen.redis_prefix', 'rec:user');

        return "{$prefix}:{$id}:seen";
    }

    /**
     * @return array<int, true> Set of post_ids flipped for isset() lookup.
     */
    public function seenFor(User $user): array
    {
        $members = Redis::smembers($this->key($user));

        if ($members === []) {
            return [];
        }

        $result = [];
        foreach ($members as $member) {
            $result[(int) $member] = true;
        }

        return $result;
    }

    /**
     * Mark the given post ids as seen by the user. Refreshes TTL on every call.
     *
     * @param  iterable<int>  $postIds
     */
    public function markSeen(User $user, iterable $postIds): void
    {
        $ids = [];
        foreach ($postIds as $postId) {
            $ids[] = (int) $postId;
        }

        if ($ids === []) {
            return;
        }

        $key = $this->key($user);
        $ttl = (int) config('recommendation.seen.ttl_seconds', 172800);

        Redis::sadd($key, ...$ids);
        Redis::expire($key, $ttl);
    }

    public function clear(User $user): void
    {
        Redis::del($this->key($user));
    }

    public function ttlSeconds(): int
    {
        return (int) config('recommendation.seen.ttl_seconds', 172800);
    }
}
