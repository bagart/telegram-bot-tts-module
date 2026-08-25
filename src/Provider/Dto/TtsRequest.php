<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider\Dto;

use BAGArt\TelegramBotTts\Provider\VoiceProviderConfig;

/**
 * One synthesis job. Text is already normalized (trimmed, char-capped) by
 * the pipeline before it reaches an adapter.
 */
final readonly class TtsRequest
{
    public function __construct(
        public string $text,
        public VoiceProviderConfig $config,
        public ?string $voice = null,
        public ?string $languageHint = null,
    ) {
    }
}
