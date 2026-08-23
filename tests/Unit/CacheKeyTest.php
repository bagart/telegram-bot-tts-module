<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Support\SynthesisRecorder;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CacheKeyTest extends TestCase
{
    #[DataProvider('keyCases')]
    public function test_cache_key_is_a_stable_sha1_of_provider_voice_text(
        string $provider,
        ?string $voice,
        string $text,
        string $expectedSha,
    ): void {
        self::assertSame($expectedSha, SynthesisRecorder::cacheKey($provider, $voice, $text));
    }

    public static function keyCases(): Generator
    {
        yield 'simple' => ['edge-tts', 'ru-RU-SvetlanaNeural', 'привет', sha1('edge-tts|ru-RU-SvetlanaNeural|привет')];
        yield 'null voice uses empty segment' => ['kokoro', null, 'hello', sha1('kokoro||hello')];
        yield 'text is taken verbatim (pipeline normalizes first)' => [
            'openai', 'alloy', '  spaced  ', sha1('openai|alloy|  spaced  '),
        ];
    }

    public function test_different_inputs_never_collide(): void
    {
        self::assertNotSame(
            SynthesisRecorder::cacheKey('edge-tts', 'a', 'x'),
            SynthesisRecorder::cacheKey('edge-tts', 'a|b', 'x'),
        );
    }
}
