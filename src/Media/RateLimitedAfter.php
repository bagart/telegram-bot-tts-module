<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

/**
 * Internal signal for HTTP 429 upload responses carrying Retry-After.
 */
final class RateLimitedAfter extends \RuntimeException
{
    public function __construct(
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct('Upload rate limited');
    }
}
