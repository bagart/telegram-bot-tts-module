<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Chooses the wire-dialect driver for a resolved provider config. A contract
 * so tests can substitute canned providers without touching the pipeline.
 */
interface AdapterSelectorContract
{
    public function for(VoiceProviderConfig $config): TtsProviderContract;
}
