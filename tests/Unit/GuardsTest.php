<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Guard\ArrayGuardStore;
use BAGArt\TelegramBotTts\Guard\ChatSemaphore;
use BAGArt\TelegramBotTts\Guard\GlobalConcurrencyLimiter;
use BAGArt\TelegramBotTts\Guard\GuardStoreContract;
use BAGArt\TelegramBotTts\Guard\ProviderBreaker;
use BAGArt\TelegramBotTts\Guard\QuotaCounter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Guard degraded-Redis matrix (§9): quota fail-closed; chat semaphore and
 * global limiter fail-open; breaker fail-open (treated closed).
 */
final class GuardsTest extends TestCase
{
    public function test_quota_allows_up_to_the_daily_cap(): void
    {
        $quota = new QuotaCounter(new ArrayGuardStore);

        for ($i = 0; $i < 3; $i++) {
            self::assertTrue($quota->allow('bot1', 100, 3));
        }

        self::assertFalse($quota->allow('bot1', 100, 3));
    }

    public function test_quota_zero_means_unlimited(): void
    {
        $quota = new QuotaCounter(new ArrayGuardStore);

        for ($i = 0; $i < 50; $i++) {
            self::assertTrue($quota->allow('bot1', 100, 0));
        }
    }

    public function test_quota_is_fail_closed_when_store_is_down(): void
    {
        $quota = new QuotaCounter(new ThrowingGuardStore);

        self::assertFalse($quota->allow('bot1', 100, 10));
    }

    public function test_quota_keys_are_per_bot_chat_and_day(): void
    {
        $store = new ArrayGuardStore;
        $quota = new QuotaCounter($store);

        self::assertTrue($quota->allow('botA', 1, 1));
        self::assertTrue($quota->allow('botB', 1, 1));
        self::assertTrue($quota->allow('botA', 2, 1));
        self::assertFalse($quota->allow('botA', 1, 1));
    }

    public function test_chat_semaphore_is_exclusive_per_chat(): void
    {
        $semaphore = new ChatSemaphore(new ArrayGuardStore);

        self::assertTrue($semaphore->acquire('bot1', 7));
        self::assertFalse($semaphore->acquire('bot1', 7));
        self::assertTrue($semaphore->acquire('bot1', 8));

        $semaphore->release('bot1', 7);
        self::assertTrue($semaphore->acquire('bot1', 7));
    }

    public function test_chat_semaphore_is_fail_open_when_store_is_down(): void
    {
        $semaphore = new ChatSemaphore(new ThrowingGuardStore);

        self::assertTrue($semaphore->acquire('bot1', 7));
    }

    public function test_global_limiter_enforces_cap_and_self_heals(): void
    {
        $limiter = new GlobalConcurrencyLimiter(new ArrayGuardStore, 2);

        self::assertTrue($limiter->acquire());
        self::assertTrue($limiter->acquire());
        self::assertFalse($limiter->acquire());

        $limiter->release();
        self::assertTrue($limiter->acquire());
    }

    public function test_global_limiter_is_fail_open_when_store_is_down(): void
    {
        $limiter = new GlobalConcurrencyLimiter(new ThrowingGuardStore, 1);

        self::assertTrue($limiter->acquire());
    }

    public function test_breaker_stays_closed_below_threshold(): void
    {
        $breaker = new ProviderBreaker(new ArrayGuardStore);

        for ($i = 0; $i < 4; $i++) {
            self::assertTrue($breaker->canPass('edge-tts'));
            $breaker->recordFailure('edge-tts');
        }

        self::assertSame(ProviderBreaker::PHASE_CLOSED, $breaker->phase('edge-tts'));
    }

    public function test_breaker_opens_after_threshold_and_resets_on_success(): void
    {
        $store = new ArrayGuardStore;
        $breaker = new ProviderBreaker($store);

        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure('edge-tts');
        }

        self::assertSame(ProviderBreaker::PHASE_OPEN, $breaker->phase('edge-tts'));
        self::assertFalse($breaker->canPass('edge-tts'));

        // Simulate open-TTL expiry: the open key vanishes while the
        // recent-open marker keeps half-open semantics (key layout is
        // documented on ProviderBreaker).
        $store->delete('tts:brk:edge-tts:open');

        self::assertSame(ProviderBreaker::PHASE_HALF_OPEN, $breaker->phase('edge-tts'));

        // Exactly one probe passes.
        self::assertTrue($breaker->canPass('edge-tts'));
        self::assertFalse($breaker->canPass('edge-tts'));

        // A failed probe reopens immediately.
        $breaker->recordFailure('edge-tts');
        self::assertSame(ProviderBreaker::PHASE_OPEN, $breaker->phase('edge-tts'));
    }

    public function test_successful_probe_closes_the_breaker(): void
    {
        $store = new ArrayGuardStore;
        $breaker = new ProviderBreaker($store);

        for ($i = 0; $i < 5; $i++) {
            $breaker->recordFailure('kokoro');
        }

        $store->delete('tts:brk:kokoro:open');
        self::assertTrue($breaker->canPass('kokoro'));
        $breaker->recordSuccess('kokoro');

        self::assertSame(ProviderBreaker::PHASE_CLOSED, $breaker->phase('kokoro'));
        self::assertTrue($breaker->canPass('kokoro'));
        self::assertTrue($breaker->canPass('kokoro'), 'closed phase must not serialize calls');
    }

    public function test_breaker_is_fail_open_when_store_is_down(): void
    {
        $breaker = new ProviderBreaker(new ThrowingGuardStore);

        self::assertTrue($breaker->canPass('edge-tts'));
    }
}

/**
 * Test double standing in for a dead Redis: every operation throws the same
 * checked failure RedisGuardStore raises when the daemon is unreachable.
 */
final class ThrowingGuardStore implements GuardStoreContract
{
    public function incrementWithTtl(string $key, int $ttlSeconds): int
    {
        throw new RuntimeException('redis down');
    }

    public function decrementFloorZero(string $key): void
    {
        throw new RuntimeException('redis down');
    }

    public function addIfAbsent(string $key, string $value, int $ttlMilliseconds): bool
    {
        throw new RuntimeException('redis down');
    }

    public function get(string $key): ?string
    {
        throw new RuntimeException('redis down');
    }

    public function setWithTtl(string $key, string $value, int $ttlSeconds): void
    {
        throw new RuntimeException('redis down');
    }

    public function delete(string $key): void
    {
        throw new RuntimeException('redis down');
    }
}
