<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Ui\CallbackRoute;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CallbackRouteTest extends TestCase
{
    public function test_roundtrips_encode_decode(): void
    {
        $encoded = CallbackRoute::encode(-1001234567890, CallbackRoute::VERB_SET_PROVIDER, 'edge-tts');

        $decoded = CallbackRoute::decode($encoded);

        self::assertNotNull($decoded);
        self::assertSame(-1001234567890, $decoded['chatId']);
        self::assertSame(CallbackRoute::VERB_SET_PROVIDER, $decoded['verb']);
        self::assertSame('edge-tts', $decoded['arg']);
    }

    public function test_arg_is_optional(): void
    {
        $decoded = CallbackRoute::decode(CallbackRoute::encode(42, CallbackRoute::VERB_MENU));

        self::assertNotNull($decoded);
        self::assertSame(42, $decoded['chatId']);
        self::assertNull($decoded['arg']);
    }

    public static function invalidPayloads(): Generator
    {
        yield 'null data' => [null];
        yield 'wrong prefix' => ['sm:1:m'];
        yield 'too few parts' => ['tv:1'];
        yield 'too many parts' => ['tv:1:m:extra:more'];
        yield 'non-numeric chat id' => ['tv:abc:m'];
        yield 'zero chat id' => ['tv:0:m'];
        yield 'uppercase verb' => ['tv:5:M'];
        yield 'overlong verb' => ['tv:5:toolongverb'];
        yield 'numeric verb' => ['tv:5:123'];
    }

    #[DataProvider('invalidPayloads')]
    public function test_rejects_invalid_payloads(?string $data): void
    {
        self::assertNull(CallbackRoute::decode($data));
    }

    public function test_encoded_data_fits_the_64_byte_telegram_cap(): void
    {
        // Worst realistic case: negative 13-digit chat id + longest verb + arg
        $worst = CallbackRoute::encode(-9999999999999, CallbackRoute::VERB_SET_PROVIDER, 'speaches');

        self::assertLessThanOrEqual(64, strlen($worst));
    }
}
