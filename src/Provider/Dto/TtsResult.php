<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider\Dto;

/**
 * Synthesis outcome: raw audio bytes (size-capped by max_response_bytes),
 * the response mime type as reported by the provider, and timing metadata
 * for cache rows and metrics.
 */
final readonly class TtsResult
{
    public function __construct(
        public string $binary,
        public string $mimeType,
        public string $providerKey,
        public int $latencyMs,
    ) {}
}
