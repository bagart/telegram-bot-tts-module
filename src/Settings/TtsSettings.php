<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Settings;

/**
 * Effective per-chat TTS settings resolved from
 * tg_module_enablements.module_settings with platform defaults applied.
 * All clamps happen here — nothing downstream re-validates (§2.3, §8).
 */
final readonly class TtsSettings
{
    public const CAPTION_NONE = 'none';

    public const CAPTION_ORIGINAL = 'original';

    public const CAPTION_TRUNCATED = 'truncated';

    public const ERROR_MODE_SILENT = 'silent';

    public const ERROR_MODE_EMOJI = 'emoji';

    public const ERROR_MODE_MESSAGE = 'message';

    /** Context-dependent default: emoji in groups, message in private. */
    public const ERROR_MODE_AUTO = 'auto';

    public const LOCALE_RU = 'ru';

    public const LOCALE_EN = 'en';

    public const DEFAULT_MAX_CHARS = 1000;

    public const DEFAULT_DAILY_QUOTA = 50;

    /**
     * @param  array<string, mixed>|null  $customProvider  validated custom provider config
     */
    public function __construct(
        public bool $autoSpeak = false,
        public string $providerKey = 'edge-tts',
        public ?string $voice = null,
        public string $caption = self::CAPTION_ORIGINAL,
        public int $maxChars = self::DEFAULT_MAX_CHARS,
        public string $onError = self::ERROR_MODE_AUTO,
        public int $dailyQuota = self::DEFAULT_DAILY_QUOTA,
        public string $locale = self::LOCALE_RU,
        public bool $noticeShown = false,
        public ?array $customProvider = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            autoSpeak: (bool) ($raw['auto_speak'] ?? false),
            providerKey: (string) ($raw['provider_key'] ?? 'edge-tts'),
            voice: isset($raw['voice']) && $raw['voice'] !== '' ? mb_substr((string) $raw['voice'], 0, 128) : null,
            caption: self::clampEnum(
                (string) ($raw['caption'] ?? self::CAPTION_ORIGINAL),
                [self::CAPTION_NONE, self::CAPTION_ORIGINAL, self::CAPTION_TRUNCATED],
                self::CAPTION_ORIGINAL,
            ),
            maxChars: max(1, min(4000, (int) ($raw['max_chars'] ?? self::DEFAULT_MAX_CHARS))),
            onError: self::clampEnum(
                (string) ($raw['on_error'] ?? self::ERROR_MODE_AUTO),
                [self::ERROR_MODE_SILENT, self::ERROR_MODE_EMOJI, self::ERROR_MODE_MESSAGE, self::ERROR_MODE_AUTO],
                self::ERROR_MODE_AUTO,
            ),
            dailyQuota: max(0, min(10000, (int) ($raw['daily_quota'] ?? self::DEFAULT_DAILY_QUOTA))),
            locale: self::clampEnum((string) ($raw['locale'] ?? self::LOCALE_RU), [self::LOCALE_RU, self::LOCALE_EN], self::LOCALE_RU),
            noticeShown: (bool) ($raw['notice_shown'] ?? false),
            customProvider: is_array($raw['custom_provider'] ?? null) ? $raw['custom_provider'] : null,
        );
    }

    /**
     * Caption policy (§8): none | original | truncated ≤1024 chars.
     */
    public function captionFor(string $sourceText): ?string
    {
        return match ($this->caption) {
            self::CAPTION_NONE => null,
            self::CAPTION_TRUNCATED => mb_substr($sourceText, 0, 1024),
            default => mb_strlen($sourceText) > 1024 ? mb_substr($sourceText, 0, 1024) : $sourceText,
        };
    }

    /**
     * Resolved error surface (§7): auto = emoji in groups, message in private.
     */
    public function resolvedErrorMode(bool $isPrivateChat): string
    {
        if ($this->onError === self::ERROR_MODE_AUTO) {
            return $isPrivateChat ? self::ERROR_MODE_MESSAGE : self::ERROR_MODE_EMOJI;
        }

        return $this->onError;
    }

    private static function clampEnum(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }
}
