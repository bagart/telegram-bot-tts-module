<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Tests\Unit;

use BAGArt\TelegramBotTts\Settings\TtsSettings;
use BAGArt\TelegramBotTts\Ui\MenuRenderer;
use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Voice-picker fallback policy (Q2): empty or oversized catalog ⇒ keyboard
 * null → the processor starts the manual text-input flow. Keyboard rendering
 * needs host-lib DTOs and is covered by the host E2E suite instead.
 */
final class MenuRendererTest extends TestCase
{
    public function test_falls_back_to_text_input_when_catalog_is_empty(): void
    {
        $menu = new MenuRenderer(new ProviderRegistry());
        $page = $menu->voices(777, new TtsSettings(), []);

        self::assertNull($page['keyboard']);
        self::assertStringContainsString('голос', mb_strtolower($page['text']));
    }

    public function test_falls_back_to_text_input_when_catalog_exceeds_the_limit(): void
    {
        $menu = new MenuRenderer(new ProviderRegistry());
        $page = $menu->voices(777, new TtsSettings(), array_map(
            fn (int $i): string => 'voice-'.$i,
            range(1, 9),
        ));

        self::assertNull($page['keyboard']);
    }
}
