<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use RuntimeException;

/**
 * Per-chat daily synthesis quota. FAIL-CLOSED on Redis loss (T7): TTS
 * generates outbound media, so unbounded traffic is a paid-egress risk —
 * when the counter cannot be consulted the request is refused.
 */
class QuotaCounter
{
    private const KEY_TTL_SECONDS = 172800;

    public function __construct(
        private readonly GuardStoreContract $store,
    ) {}

    /**
     * @return bool true = allowed; false = quota exhausted OR guard store
     *              unavailable (fail-closed)
     */
    public function allow(string $botId, int $chatId, int $dailyQuota): bool
    {
        if ($dailyQuota <= 0) {
            return true;
        }

        try {
            $used = $this->store->incrementWithTtl(
                sprintf('tts:%s:%d:%s', $botId, $chatId, date('Ymd')),
                self::KEY_TTL_SECONDS,
            );
        } catch (RuntimeException) {
            return false;
        }

        return $used <= $dailyQuota;
    }
}
