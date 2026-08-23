<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Fully resolved provider configuration: preset/custom settings merged with
 * vault token and platform defaults. The token is decrypted only inside the
 * ConfigResolver and must never appear in logs, exceptions or metrics.
 */
final readonly class VoiceProviderConfig
{
    public function __construct(
        public string $key,
        public TtsApiStyle $apiStyle,
        public string $baseUrl,
        public ?string $token = null,
        public ?string $model = null,
        public ?string $voice = null,
        public int $connectTimeoutSec = 10,
        public int $timeoutSec = 25,
        public int $maxResponseBytes = 8388608,
    ) {}
}
