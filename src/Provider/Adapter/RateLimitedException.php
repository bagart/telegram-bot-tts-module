<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider\Adapter;

/**
 * Internal signal for HTTP 429 responses carrying a Retry-After hint;
 * converted into ErrorCode::RateLimited / QuotaProvider by the drivers'
 * retry loops.
 */
final class RateLimitedException extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('TTS provider rate limited');
    }
}
