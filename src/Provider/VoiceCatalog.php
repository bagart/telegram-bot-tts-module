<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

use BAGArt\TelegramBotTts\Provider\Adapter\EdgeTts;
use BAGArt\TelegramBotTts\Settings\TtsSettings;
use Throwable;

/**
 * Voice-picker catalog source (Q2). OpenAI-dialect providers use a static
 * voice list; edge-tts dialects fetch GET /v1/voices from their base URL
 * and are narrowed to the chat locale prefix (e.g. "ru-RU…"). Any failure
 * or an oversized result yields [] — the menu then falls back to manual
 * text input. Listing is capped at a short timeout: it runs inside the
 * webhook path, so it must never approach the synthesis budget.
 */
class VoiceCatalog
{
    private const LIST_TIMEOUT_SECONDS = 5;

    private const MAX_LISTED_VOICES = 8;

    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly EdgeTts $edgeAdapter = new EdgeTts(),
    ) {
    }

    /**
     * @return list<string> display-ready voice ids ([] when unavailable)
     */
    public function for(TtsSettings $settings): array
    {
        try {
            $voices = $this->catalog($settings);
        } catch (Throwable) {
            return [];
        }

        if (count($voices) > self::MAX_LISTED_VOICES) {
            return [];
        }

        return array_values($voices);
    }

    /**
     * @return list<string>
     */
    private function catalog(TtsSettings $settings): array
    {
        if ($settings->providerKey === ProviderRegistry::CUSTOM_KEY) {
            $custom = $settings->customProvider;

            if ($custom === null) {
                return [];
            }

            return TtsApiStyle::from((string) $custom['api_style']) === TtsApiStyle::EdgeTts
                ? $this->edgeVoices((string) $custom['base_url'], $settings)
                : ProviderRegistry::openaiVoiceCatalog();
        }

        $preset = $this->registry->get($settings->providerKey);

        if ($preset === null) {
            return [];
        }

        return $preset->apiStyle === TtsApiStyle::EdgeTts
            ? $this->edgeVoices(
                rtrim((string) config('tts.presets.'.$preset->key.'.base_url', $preset->baseUrl), '/'),
                $settings,
            )
            : ProviderRegistry::openaiVoiceCatalog();
    }

    /**
     * @return list<string> locale-narrowed ids; empty when nothing matches
     */
    private function edgeVoices(string $baseUrl, TtsSettings $settings): array
    {
        $entries = $this->edgeAdapter->voices($baseUrl, self::LIST_TIMEOUT_SECONDS);
        $prefix = strtolower($settings->locale).'-';
        $ids = [];

        foreach ($entries as $entry) {
            $lang = strtolower((string) ($entry['lang'] ?? ''));

            if ($lang !== '' && str_starts_with($lang, $prefix)) {
                $ids[] = $entry['id'];
            }
        }

        return $ids;
    }
}
