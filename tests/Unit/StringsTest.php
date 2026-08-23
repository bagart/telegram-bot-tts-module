<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\Provider\ErrorCode;
use PHPUnit\Framework\TestCase;

final class StringsTest extends TestCase
{
    public function test_every_error_code_has_ru_and_en_lines(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $key = 'err.'.$code->value;

            // A miss returns the key itself — both locales must translate it.
            self::assertNotSame($key, Strings::get('ru', $key), "missing ru line for {$code->value}");
            self::assertNotSame($key, Strings::get('en', $key), "missing en line for {$code->value}");
        }
    }

    public function test_unknown_locale_falls_back_to_ru(): void
    {
        self::assertSame(Strings::get('ru', 'empty_input'), Strings::get('de', 'empty_input'));
    }

    public function test_replacements_are_applied(): void
    {
        $line = Strings::get('en', 'too_long', ['max' => 1000]);

        self::assertSame('Text is too long (limit is 1000 characters).', $line);
    }
}
