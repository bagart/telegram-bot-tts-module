<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use BAGArt\TelegramBotTts\Provider\TtsApiStyle;
use Generator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderRegistryTest extends TestCase
{
    public function test_ships_presets_for_the_documented_catalog(): void
    {
        $registry = new ProviderRegistry();
        $keys = array_keys($registry->all());

        foreach (['edge-tts', 'kokoro', 'speaches', 'openai'] as $expected) {
            self::assertContains($expected, $keys);
        }

        self::assertSame(TtsApiStyle::EdgeTts, $registry->get('edge-tts')?->apiStyle);

        foreach ($registry->all() as $preset) {
            self::assertNotSame('', $preset->name);
            self::assertNotSame('', $preset->baseUrl);
            self::assertContains($preset->apiStyle, [TtsApiStyle::OpenaiTts, TtsApiStyle::EdgeTts]);
        }
    }

    public function test_only_openai_preset_needs_a_token(): void
    {
        foreach ((new ProviderRegistry())->all() as $preset) {
            self::assertSame($preset->key === 'openai', $preset->needsToken);
        }
    }

    public function test_generates_a_custom_provider_template_that_passes_validation(): void
    {
        $config = (new ProviderRegistry())->validateCustomConfig((new ProviderRegistry())->customTemplateJson());

        self::assertSame('https://tts.example.com/v1', $config['base_url']);
        self::assertSame('openai-tts', $config['api_style']);
    }

    #[DataProvider('invalidConfigs')]
    public function test_rejects_invalid_custom_configs(string $json): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProviderRegistry())->validateCustomConfig($json);
    }

    public static function invalidConfigs(): Generator
    {
        yield 'broken json' => ['{broken json'];
        yield 'array json' => ['[1,2]'];
        yield 'missing base_url' => [json_encode(['name' => 'x'])];
        yield 'non-url base_url' => [json_encode(['base_url' => 'not a url'])];
        yield 'plain-http remote host' => [json_encode(['base_url' => 'http://evil.test/v1'])];
        yield 'link-local metadata range' => [json_encode(['base_url' => 'http://169.254.169.254/latest/meta-data'])];
        yield 'gcp metadata hostname' => [json_encode(['base_url' => 'http://metadata.google.internal/computeMetadata'])];
        yield 'internal hostname over http' => [json_encode(['base_url' => 'http://vault.internal:8200'])];
        yield 'unknown api_style' => [json_encode(['base_url' => 'https://x.test/v1', 'api_style' => 'graphql'])];
    }

    public function test_accepts_http_for_lan_self_hosting_including_fc00(): void
    {
        foreach ([
            'http://localhost:55000',
            'http://127.0.0.1:8880/v1',
            'http://192.168.1.10:8000/v1',
            'http://10.0.0.5',
            'http://172.16.3.9:9000',
            'http://[fd00::5]:8000/v1',
            'http://100.64.0.7',
        ] as $allowed) {
            self::assertTrue(
                ProviderRegistry::isAllowedBaseUrl($allowed),
                "expected {$allowed} to be allowed",
            );
        }
    }

    public function test_rejects_metadata_and_public_plain_http(): void
    {
        foreach ([
            'http://169.254.169.254/',
            'http://example.com/v1',
            'ftp://files.test/audio',
            'https://169.254.1.1/v1',
        ] as $rejected) {
            self::assertFalse(
                ProviderRegistry::isAllowedBaseUrl($rejected),
                "expected {$rejected} to be rejected",
            );
        }
    }

    public function test_normalizes_trailing_slashes_and_clamps_lengths(): void
    {
        $config = (new ProviderRegistry())->validateCustomConfig(json_encode([
            'name' => str_repeat('N', 200),
            'base_url' => 'https://gw.test/v1/',
            'model' => str_repeat('m', 500),
            'voice' => str_repeat('v', 500),
        ]));

        self::assertSame('https://gw.test/v1', $config['base_url']);
        self::assertSame(60, mb_strlen((string) $config['name']));
        self::assertSame(100, mb_strlen((string) $config['model']));
        self::assertSame(128, mb_strlen((string) $config['voice']));
    }
}
