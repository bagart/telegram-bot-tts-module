<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

use BAGArt\TelegramBotTts\Provider\Adapter\EdgeTts;
use BAGArt\TelegramBotTts\Provider\Adapter\OpenAiCompatibleTts;

/**
 * Default driver selection by wire dialect (one adapter per apiStyle, T2).
 */
final class DefaultAdapterSelector implements AdapterSelectorContract
{
    public function for(VoiceProviderConfig $config): TtsProviderContract
    {
        return match ($config->apiStyle) {
            TtsApiStyle::EdgeTts => new EdgeTts(),
            TtsApiStyle::OpenaiTts => new OpenAiCompatibleTts(),
        };
    }
}
