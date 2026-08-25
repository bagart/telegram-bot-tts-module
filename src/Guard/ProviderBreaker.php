<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use RuntimeException;
use Throwable;

/**
 * Per-provider circuit breaker (§9):
 *   5 consecutive failures ⇒ open 60 s ⇒ single half-open probe.
 *
 * Phases are separate TTL-bounded keys so expiry drives transitions:
 *   tts:brk:{p}:fails    consecutive-failure counter (closed phase)
 *   tts:brk:{p}:open     presence = OPEN (all traffic denied)
 *   tts:brk:{p}:recent   recent-open marker; presence after open expiry =
 *                        HALF-OPEN territory where exactly one probe passes
 *   tts:brk:{p}:probe    in-flight probe slot
 *
 * FAIL-OPEN on Redis loss (§9): treated as closed + logged.
 */
class ProviderBreaker
{
    private const FAILURE_THRESHOLD = 5;

    private const FAILURES_TTL_SECONDS = 120;

    private const OPEN_TTL_SECONDS = 60;

    private const RECENT_OPEN_TTL_SECONDS = 180;

    private const PROBE_LOCK_TTL_MILLISECONDS = 30000;

    public const PHASE_CLOSED = 0;

    public const PHASE_OPEN = 1;

    public const PHASE_HALF_OPEN = 2;

    public function __construct(
        private readonly GuardStoreContract $store,
    ) {
    }

    /** @return bool true = call may proceed */
    public function canPass(string $providerKey): bool
    {
        try {
            if ($this->store->get($this->key($providerKey, 'open')) !== null) {
                return false;
            }

            if ($this->store->get($this->key($providerKey, 'probe')) !== null) {
                return false;
            }

            $recentlyOpen = $this->store->get($this->key($providerKey, 'recent')) !== null;

            if (! $recentlyOpen) {
                return true;
            }

            // Half-open: exactly one probe may pass; the slot frees via its
            // own TTL or when the probe outcome is recorded.
            return $this->store->addIfAbsent(
                $this->key($providerKey, 'probe'),
                (string) time(),
                self::PROBE_LOCK_TTL_MILLISECONDS,
            );
        } catch (RuntimeException $e) {
            // Best-effort telemetry: outside a booted app (pure unit runs)
            // the ExceptionHandler binding does not exist — never let
            // reporting break the fail-open guarantee.
            if (\function_exists('report')) {
                try {
                    report($e);
                } catch (Throwable) {
                }
            }

            return true;
        }
    }

    public function recordSuccess(string $providerKey): void
    {
        foreach (['fails', 'open', 'recent', 'probe'] as $suffix) {
            $this->store->delete($this->key($providerKey, $suffix));
        }
    }

    public function recordFailure(string $providerKey): void
    {
        try {
            // A failed half-open probe reopens immediately without waiting
            // for the threshold again.
            if ($this->store->get($this->key($providerKey, 'probe')) !== null) {
                $this->tripOpen($providerKey);

                return;
            }

            $failures = $this->store->incrementWithTtl(
                $this->key($providerKey, 'fails'),
                self::FAILURES_TTL_SECONDS,
            );

            if ($failures >= self::FAILURE_THRESHOLD) {
                $this->tripOpen($providerKey);
            }
        } catch (RuntimeException) {
        }
    }

    /** @return int 0 closed | 1 open | 2 half-open (probe pending/active) */
    public function phase(string $providerKey): int
    {
        try {
            if ($this->store->get($this->key($providerKey, 'open')) !== null) {
                return self::PHASE_OPEN;
            }

            $recentlyOpen = $this->store->get($this->key($providerKey, 'recent')) !== null;

            if ($recentlyOpen && $this->store->get($this->key($providerKey, 'probe')) === null) {
                return self::PHASE_HALF_OPEN;
            }

            return self::PHASE_CLOSED;
        } catch (RuntimeException) {
            return self::PHASE_CLOSED;
        }
    }

    private function tripOpen(string $providerKey): void
    {
        $this->store->setWithTtl($this->key($providerKey, 'open'), (string) time(), self::OPEN_TTL_SECONDS);
        $this->store->setWithTtl($this->key($providerKey, 'recent'), (string) time(), self::RECENT_OPEN_TTL_SECONDS);
        $this->store->delete($this->key($providerKey, 'probe'));
        $this->store->delete($this->key($providerKey, 'fails'));
    }

    private function key(string $providerKey, string $suffix): string
    {
        return sprintf('tts:brk:%s:%s', $providerKey, $suffix);
    }
}
