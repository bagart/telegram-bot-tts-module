<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

use BAGArt\TelegramBotTts\Models\TtsToken;
use BAGArt\TelegramBotTts\Settings\TtsSettings;

/**
 * Merges chat settings, preset/custom provider definition and the vault
 * token into one resolved VoiceProviderConfig. This is the only place where
 * the vault token is decrypted.
 */
class ConfigResolver
{
    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {}

    public function resolve(string $botId, TtsSettings $settings): VoiceProviderConfig
    {
        $providerKey = $settings->providerKey;

        if (! $this->registry->has($providerKey)) {
            throw new ProviderException(
                ErrorCode::BadRequest,
                sprintf('Unknown TTS provider "%s"', $providerKey),
            );
        }

        if ($providerKey === ProviderRegistry::CUSTOM_KEY) {
            return $this->resolveCustom($botId, $settings);
        }

        $preset = $this->registry->get($providerKey);
        \assert($preset !== null);

        $token = null;

        if ($preset->needsToken) {
            $token = $this->vaultToken($botId, $providerKey);

            if ($token === null || $token === '') {
                throw new ProviderException(
                    ErrorCode::Auth,
                    sprintf('No vault token stored for provider "%s"', $providerKey),
                );
            }
        }

        return new VoiceProviderConfig(
            key: $preset->key,
            apiStyle: $preset->apiStyle,
            baseUrl: rtrim((string) config('tts.presets.'.$preset->key.'.base_url', $preset->baseUrl), '/'),
            token: $token,
            model: $preset->model,
            voice: $settings->voice ?? $preset->voice,
            connectTimeoutSec: 10,
            timeoutSec: (int) config('tts.timeout_seconds', 25),
            maxResponseBytes: (int) config('tts.max_response_bytes', 8388608),
        );
    }

    private function resolveCustom(string $botId, TtsSettings $settings): VoiceProviderConfig
    {
        $custom = $settings->customProvider;

        if ($custom === null) {
            throw new ProviderException(ErrorCode::BadRequest, 'Custom provider is not configured');
        }

        // Stored configs were validated on write; re-check the URL posture in
        // case the validation rules changed after storage.
        if (! ProviderRegistry::isAllowedBaseUrl((string) $custom['base_url'])) {
            throw new ProviderException(ErrorCode::BadRequest, 'Custom provider base_url is not allowed');
        }

        $style = TtsApiStyle::from((string) $custom['api_style']);

        return new VoiceProviderConfig(
            key: ProviderRegistry::CUSTOM_KEY,
            apiStyle: $style,
            baseUrl: (string) $custom['base_url'],
            token: $this->vaultToken($botId, ProviderRegistry::CUSTOM_KEY),
            model: isset($custom['model']) ? (string) $custom['model'] : null,
            voice: $settings->voice ?? (isset($custom['voice']) ? (string) $custom['voice'] : null),
            connectTimeoutSec: 10,
            timeoutSec: (int) config('tts.timeout_seconds', 25),
            maxResponseBytes: (int) config('tts.max_response_bytes', 8388608),
        );
    }

    private function vaultToken(string $botId, string $providerKey): ?string
    {
        $row = TtsToken::query()
            ->where('bot_id', $botId)
            ->where('provider_key', $providerKey)
            ->first();

        return $row?->token;
    }
}
