<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use RuntimeException;

/**
 * Module-local metric counters (Redis, TTL-bounded). They back the
 * `tts_*` series surfaced by the doctor command; promotion into the host
 * /health/metrics exposition is a host-app concern (RFC §12).
 */
class TtsMetrics
{
    private const TTL_SECONDS = 172800;

    public function __construct(
        private readonly GuardStoreContract $store,
    ) {
    }

    public function recordSynthesis(string $botId, string $providerKey, string $status): void
    {
        $this->increment(sprintf('tts:stat:%s:%s:%s', $botId, $providerKey, $status));
    }

    public function recordLatency(string $providerKey, int $latencyMs): void
    {
        $bucket = $latencyMs <= 250 ? 'le250'
            : ($latencyMs <= 1000 ? 'le1000'
            : ($latencyMs <= 5000 ? 'le5000'
            : ($latencyMs <= 15000 ? 'le15000' : 'le30000')));

        $this->increment(sprintf('tts:lat:%s:%s', $providerKey, $bucket));
    }

    public function recordQuotaBlocked(string $botId): void
    {
        $this->increment(sprintf('tts:qblocked:%s', $botId));
    }

    public function recordUpload(string $botId, string $status): void
    {
        $this->increment(sprintf('tts:up:%s:%s', $botId, $status));
    }

    /** Aggregated per-provider failure counter feeding the doctor command. */
    public function recordProviderFailure(string $providerKey): void
    {
        $this->increment(sprintf('tts:stat:failures:%s', $providerKey));
    }

    /**
     * Last-24h failure counts per provider for the doctor command.
     *
     * @return array<string, int> providerKey → failure count
     */
    public function failuresByProvider(array $providerKeys): array
    {
        $counts = [];

        foreach ($providerKeys as $providerKey) {
            try {
                $raw = $this->store->get(sprintf('tts:stat:failures:%s', $providerKey));
            } catch (RuntimeException) {
                $raw = null;
            }

            $counts[$providerKey] = (int) ($raw ?? 0);
        }

        return $counts;
    }

    private function increment(string $key): void
    {
        try {
            $this->store->incrementWithTtl($key, self::TTL_SECONDS);
        } catch (RuntimeException) {
            // Metrics must never break the request path.
        }
    }
}
