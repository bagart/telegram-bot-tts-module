<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

/**
 * Primitive atomic operations the module guards are built from. Backed by
 * Redis in production; an array implementation exists for tests. Every
 * implementation failure surfaces as a checked exception so each guard can
 * apply its own declared degraded-Redis mode (§9) instead of improvising.
 */
interface GuardStoreContract
{
    /** INCR with EXPIRE applied only on the 1→ transition. */
    public function incrementWithTtl(string $key, int $ttlSeconds): int;

    /** DECR clamped at zero (release path for counting semaphores). */
    public function decrementFloorZero(string $key): void;

    /** SET NX PX — returns false when the key already existed. */
    public function addIfAbsent(string $key, string $value, int $ttlMilliseconds): bool;

    public function get(string $key): ?string;

    public function setWithTtl(string $key, string $value, int $ttlSeconds): void;

    public function delete(string $key): void;
}
