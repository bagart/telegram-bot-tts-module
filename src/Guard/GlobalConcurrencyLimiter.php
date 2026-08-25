<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use RuntimeException;

/**
 * Global concurrency cap shared by synthesis AND Track A uploads (both call
 * sites share one counter so the uploader cannot double-book the budget).
 * FAIL-OPEN on Redis loss (§9): FPM pool itself is the backstop.
 */
class GlobalConcurrencyLimiter
{
    private const COUNTER_TTL_SECONDS = 120;

    public function __construct(
        private readonly GuardStoreContract $store,
        private readonly int $cap,
    ) {
    }

    /** @return bool false = cap reached (or never when degraded). */
    public function acquire(): bool
    {
        try {
            $current = $this->store->incrementWithTtl('tts:conc', self::COUNTER_TTL_SECONDS);
        } catch (RuntimeException) {
            return true;
        }

        if ($current > $this->cap) {
            $this->store->decrementFloorZero('tts:conc');

            return false;
        }

        return true;
    }

    public function release(): void
    {
        try {
            $this->store->decrementFloorZero('tts:conc');
        } catch (RuntimeException) {
        }
    }
}
