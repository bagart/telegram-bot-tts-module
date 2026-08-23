<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Settings\TtsSettings;
use PHPUnit\Framework\TestCase;

final class TtsSettingsTest extends TestCase
{
    public function test_defaults_match_the_rfc_settings_table(): void
    {
        $settings = TtsSettings::fromArray([]);

        self::assertFalse($settings->autoSpeak);
        self::assertSame('edge-tts', $settings->providerKey);
        self::assertNull($settings->voice);
        self::assertSame(TtsSettings::CAPTION_ORIGINAL, $settings->caption);
        self::assertSame(1000, $settings->maxChars);
        self::assertSame(TtsSettings::ERROR_MODE_AUTO, $settings->onError);
        self::assertSame(50, $settings->dailyQuota);
        self::assertSame('ru', $settings->locale);
        self::assertFalse($settings->noticeShown);
    }

    public function test_clamps_out_of_range_values(): void
    {
        $settings = TtsSettings::fromArray([
            'max_chars' => 999999,
            'daily_quota' => -5,
            'voice' => str_repeat('v', 500),
        ]);

        self::assertSame(4000, $settings->maxChars);
        self::assertSame(0, $settings->dailyQuota);
        self::assertSame(128, mb_strlen((string) $settings->voice));
    }

    public function test_unknown_enums_fall_back_to_defaults(): void
    {
        $settings = TtsSettings::fromArray([
            'caption' => 'shout',
            'on_error' => 'explode',
            'locale' => 'de',
        ]);

        self::assertSame(TtsSettings::CAPTION_ORIGINAL, $settings->caption);
        self::assertSame(TtsSettings::ERROR_MODE_AUTO, $settings->onError);
        self::assertSame('ru', $settings->locale);
    }

    public function test_zero_quota_means_unlimited(): void
    {
        self::assertTrue((new TtsSettings(dailyQuota: 0)) instanceof TtsSettings);
        self::assertSame(0, TtsSettings::fromArray(['daily_quota' => 0])->dailyQuota);
    }

    public function test_caption_policy_none_original_truncated(): void
    {
        $none = new TtsSettings(caption: TtsSettings::CAPTION_NONE);
        $original = new TtsSettings(caption: TtsSettings::CAPTION_ORIGINAL);
        $truncated = new TtsSettings(caption: TtsSettings::CAPTION_TRUNCATED);

        self::assertNull($none->captionFor('привет'));
        self::assertSame('привет', $original->captionFor('привет'));
        self::assertSame('abcdef', $truncated->captionFor('abcdef'));
        self::assertSame(str_repeat('x', 1024), $truncated->captionFor(str_repeat('x', 5000)));
    }

    public function test_original_caption_caps_at_1024_for_long_text(): void
    {
        $original = new TtsSettings(caption: TtsSettings::CAPTION_ORIGINAL);

        self::assertSame(1024, mb_strlen((string) $original->captionFor(str_repeat('ж', 2000))));
    }

    public function test_resolved_error_mode_auto_depends_on_chat_type(): void
    {
        $auto = new TtsSettings(onError: TtsSettings::ERROR_MODE_AUTO);

        self::assertSame(TtsSettings::ERROR_MODE_MESSAGE, $auto->resolvedErrorMode(isPrivateChat: true));
        self::assertSame(TtsSettings::ERROR_MODE_EMOJI, $auto->resolvedErrorMode(isPrivateChat: false));

        $explicit = new TtsSettings(onError: TtsSettings::ERROR_MODE_SILENT);

        self::assertSame(TtsSettings::ERROR_MODE_SILENT, $explicit->resolvedErrorMode(true));
        self::assertSame(TtsSettings::ERROR_MODE_SILENT, $explicit->resolvedErrorMode(false));
    }
}
