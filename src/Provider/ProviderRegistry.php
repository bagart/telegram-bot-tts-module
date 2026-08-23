<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

use InvalidArgumentException;

/**
 * Catalog of TTS providers available for one-click selection plus the
 * validator for admin-authored custom provider configs (JSON editor flow).
 *
 * SSRF posture (§10.3): preset baseUrls are code-reviewed constants; custom
 * baseUrls must use https, except explicit LAN self-hosting which may use
 * http on private ranges — link-local / cloud-metadata ranges are always
 * rejected.
 */
class ProviderRegistry
{
    public const CUSTOM_KEY = 'custom';

    /** @var array<string, VoiceProviderPreset> */
    private array $presets;

    public function __construct()
    {
        $presets = [
            new VoiceProviderPreset(
                'edge-tts',
                'edge-tts (self-hosted)',
                'http://localhost:55000',
                TtsApiStyle::EdgeTts,
                note: 'free · keyless · RU voices',
            ),
            new VoiceProviderPreset(
                'kokoro',
                'Kokoro-FastAPI (self-hosted)',
                'http://localhost:8880/v1',
                TtsApiStyle::OpenaiTts,
                model: 'kokoro',
                voice: 'af_heart',
                note: 'free · self-hosted',
            ),
            new VoiceProviderPreset(
                'speaches',
                'Speaches (self-hosted)',
                'http://localhost:8000/v1',
                TtsApiStyle::OpenaiTts,
                model: 'speaches-ai/hexgrad/Kokoro-82M',
                voice: 'af_heart',
                note: 'free · self-hosted',
            ),
            new VoiceProviderPreset(
                'openai',
                'OpenAI',
                'https://api.openai.com/v1',
                TtsApiStyle::OpenaiTts,
                needsToken: true,
                model: 'gpt-4o-mini-tts',
                voice: 'alloy',
                note: 'paid 🔑',
            ),
        ];

        $this->presets = [];
        foreach ($presets as $preset) {
            $this->presets[$preset->key] = $preset;
        }
    }

    /** @return array<string, VoiceProviderPreset> */
    public function all(): array
    {
        return $this->presets;
    }

    public function has(string $key): bool
    {
        return $key === self::CUSTOM_KEY || isset($this->presets[$key]);
    }

    public function get(string $key): ?VoiceProviderPreset
    {
        return $this->presets[$key] ?? null;
    }

    /**
     * Fixed voice catalog for the OpenAI dialect (voice picker ≤8 → list).
     *
     * @return list<string>
     */
    public static function openaiVoiceCatalog(): array
    {
        return ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];
    }

    /**
     * Pre-generated JSON shown to the admin in the custom-provider editor.
     */
    public function customTemplateJson(): string
    {
        $template = [
            'name' => 'My TTS gateway',
            'base_url' => 'https://tts.example.com/v1',
            'api_style' => 'openai-tts',
            'model' => 'tts-model-id',
            'voice' => null,
        ];

        return json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Validate an admin-submitted custom provider config (assoc array from
     * JSON). Returns normalized config or throws InvalidArgumentException
     * with a human-readable reason.
     *
     * @return array<string, mixed>
     */
    public function validateCustomConfig(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: '.$e->getMessage());
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('JSON must encode an object.');
        }

        $baseUrl = trim((string) ($data['base_url'] ?? ''));

        if ($baseUrl === '' || ! filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('base_url must be a valid absolute URL.');
        }

        if (! self::isAllowedBaseUrl($baseUrl)) {
            throw new InvalidArgumentException(
                'base_url must use https (plain http is allowed only for private/LAN addresses; link-local and metadata ranges are rejected).',
            );
        }

        $style = strtolower(trim((string) ($data['api_style'] ?? TtsApiStyle::OpenaiTts->value)));

        if (! in_array($style, [TtsApiStyle::OpenaiTts->value, TtsApiStyle::EdgeTts->value], true)) {
            throw new InvalidArgumentException("api_style must be 'openai-tts' or 'edge-tts'.");
        }

        $model = trim((string) ($data['model'] ?? ''));
        $voice = trim((string) ($data['voice'] ?? ''));

        return [
            'name' => mb_substr(trim((string) ($data['name'] ?? 'Custom provider')), 0, 60),
            'base_url' => rtrim($baseUrl, '/'),
            'api_style' => $style,
            'model' => $model === '' ? null : mb_substr($model, 0, 100),
            'voice' => $voice === '' ? null : mb_substr($voice, 0, 128),
        ];
    }

    /**
     * Scheme + host validation against the SSRF posture. Public method fo
     * reuse by ConfigResolver when re-validating stored configs.
     */
    public static function isAllowedBaseUrl(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            return false;
        }

        if ($scheme === 'https') {
            return ! self::isForbiddenHost($host);
        }

        if ($scheme !== 'http') {
            return false;
        }

        return self::isPrivateHost($host) && ! self::isForbiddenHost($host);
    }

    /** Link-local and cloud-metadata ranges are never allowed. */
    private static function isForbiddenHost(string $host): bool
    {
        if ($host === 'metadata.google.internal' || str_ends_with($host, '.internal')) {
            return true;
        }

        $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);

        if ($ip === false) {
            return false;
        }

        return str_starts_with($ip, '169.254.')
            || str_starts_with($ip, 'fe80:')
            || str_starts_with(strtolower($ip), 'fd00:ec2:');
    }

    /**
     * RFC1918 + CGNAT + fc00::/7 (explicitly allowed for LAN self-hosting)
     * plus localhost names.
     */
    private static function isPrivateHost(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        $ip = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);

        if ($ip === false) {
            // Hostnames on plain http are not provably private — reject.
            return false;
        }

        foreach (['10.', '192.168.', '127.'] as $prefix) {
            if (str_starts_with($ip, $prefix)) {
                return true;
            }
        }

        if (preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip) === 1) {
            return true;
        }

        if (str_starts_with($ip, '100.64.')) {
            return true;
        }

        $lower = strtolower($ip);

        return str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd');
    }
}
