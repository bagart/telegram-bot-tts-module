<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Media\MimePolicy;
use BAGArt\TelegramBotTts\Media\VoiceDelivery;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MimePolicyTest extends TestCase
{
    #[DataProvider('voiceQualified')]
    public function test_voice_qualified_mimes(string $mime): void
    {
        self::assertSame(VoiceDelivery::Voice, MimePolicy::deliveryFor($mime));
    }

    public static function voiceQualified(): Generator
    {
        yield 'ogg' => ['audio/ogg'];
        yield 'ogg generic' => ['application/ogg'];
        yield 'opus' => ['audio/opus'];
        yield 'mp3' => ['audio/mpeg'];
        yield 'm4a' => ['audio/mp4'];
        yield 'with charset suffix' => ['audio/ogg; charset=binary'];
        yield 'uppercase' => ['AUDIO/MPEG'];
    }

    #[DataProvider('convertible')]
    public function test_convertible_mimes_need_ffmpeg(string $mime): void
    {
        self::assertSame(VoiceDelivery::Convert, MimePolicy::deliveryFor($mime));
    }

    public static function convertible(): Generator
    {
        yield 'wav' => ['audio/wav'];
        yield 'x-wav' => ['audio/x-wav'];
        yield 'flac' => ['audio/flac'];
        yield 'aiff' => ['audio/aiff'];
    }

    #[DataProvider('audioFallback')]
    public function test_unknown_mimes_fall_back_to_audio_document(string $mime): void
    {
        self::assertSame(VoiceDelivery::Audio, MimePolicy::deliveryFor($mime));
    }

    public static function audioFallback(): Generator
    {
        yield 'octet stream' => ['application/octet-stream'];
        yield 'empty-ish' => [''];
        yield 'text' => ['text/plain'];
    }

    #[DataProvider('extensionCases')]
    public function test_extension_mapping(string $mime, string $expected): void
    {
        self::assertSame($expected, MimePolicy::extensionFor($mime));
    }

    public static function extensionCases(): Generator
    {
        yield 'ogg' => ['audio/ogg', 'ogg'];
        yield 'mp3' => ['audio/mpeg', 'mp3'];
        yield 'm4a' => ['audio/mp4', 'm4a'];
        yield 'wav' => ['audio/wav', 'wav'];
        yield 'flac' => ['audio/flac', 'flac'];
        yield 'unknown' => ['application/octet-stream', 'bin'];
    }
}
