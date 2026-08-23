<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * Atomic Redis implementation of the guard primitives. All keys live unde
 * the module prefix `tts:` and always carry a TTL (runtime leases/locks/
 * metrics class — loss acceptable, rebuilt by traffic).
 */
class RedisGuardStore implements GuardStoreContract
{
    private const string LUA_INCREMENT = <<<'LUA'
        local n = redis.call('INCR', KEYS[1])
        if n == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return n
        LUA;

    private const string LUA_DECREMENT_FLOOR_ZERO = <<<'LUA'
        local n = redis.call('DECR', KEYS[1])
        if n < 0 then
            redis.call('SET', KEYS[1], 0)
        end
        LUA;

    public function incrementWithTtl(string $key, int $ttlSeconds): int
    {
        try {
            return (int) Redis::connection()->eval(
                self::LUA_INCREMENT,
                1,
                $key,
                $ttlSeconds,
            );
        } catch (Throwable $e) {
            throw new RuntimeException('guard store unavailable', 0, $e);
        }
    }

    public function decrementFloorZero(string $key): void
    {
        try {
            Redis::connection()->eval(self::LUA_DECREMENT_FLOOR_ZERO, 1, $key);
        } catch (Throwable) {
            // Release path: a stale counter self-heals through its TTL.
        }
    }

    public function addIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        try {
            return (bool) Redis::connection()->set($key, $value, 'PX', $ttlMilliseconds, 'NX');
        } catch (Throwable $e) {
            throw new RuntimeException('guard store unavailable', 0, $e);
        }
    }

    public function get(string $key): ?string
    {
        try {
            $value = Redis::connection()->get($key);
        } catch (Throwable $e) {
            throw new RuntimeException('guard store unavailable', 0, $e);
        }

        return $value === false || $value === null ? null : (string) $value;
    }

    public function setWithTtl(string $key, string $value, int $ttlSeconds): void
    {
        try {
            Redis::connection()->setex($key, $ttlSeconds, $value);
        } catch (Throwable $e) {
            throw new RuntimeException('guard store unavailable', 0, $e);
        }
    }

    public function delete(string $key): void
    {
        try {
            Redis::connection()->del($key);
        } catch (Throwable) {
            // Cleanup path: nothing depends on delete succeeding.
        }
    }
}
