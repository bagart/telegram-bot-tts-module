<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Built-in provider preset shown in the settings panel for one-click
 * selection.
 */
final readonly class VoiceProviderPreset
{
    public function __construct(
        public string $key,
        public string $name,
        public string $baseUrl,
        public TtsApiStyle $apiStyle,
        public bool $needsToken = false,
        public ?string $model = null,
        public ?string $voice = null,
        public ?string $note = null,
    ) {
    }
}
