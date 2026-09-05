<?php

declare(strict_types=1);

use BAGArt\TelegramBotMenu\Testing\TgWebUiContractTest;
use BAGArt\TelegramBotTts\Settings\TtsSettings;
use BAGArt\TelegramBotTts\Web\TtsWebUi;

/**
 * menu_integration.md M-3b: TTS schema manifest + settings round-trip
 * (§8.3) — validate() output feeds TtsSettings::fromArray unmodified.
 */
it('satisfies the TgWebUiContract shape for the tts module', function () {
    TgWebUiContractTest::assertContractShape(TtsWebUi::class, 'tts');
});

it('declares a schema entry over the settings vocabulary', function () {
    $entry = TtsWebUi::manifest()->entry;

    expect($entry->type)->toBe('schema');

    $keys = [];
    foreach ($entry->groups as $group) {
        foreach ($group->fields as $field) {
            $keys[] = $field->key;
        }
    }

    expect($keys)->toBe([
        'auto_speak', 'provider_key', 'voice', 'caption',
        'max_chars', 'daily_quota', 'on_error',
    ]);
});

it('maps schema keys onto TtsSettings raw keys via validate', function () {
    $patch = (new TtsWebUi)->validate([
        'auto_speak' => true,
        'provider_key' => 'kokoro',
        'voice' => ' af_heart ',
        'caption' => 'truncated',
        'max_chars' => '99999',
        'daily_quota' => -5,
        'on_error' => 'silent',
    ]);

    expect($patch['auto_speak'])->toBeTrue()
        ->and($patch['provider_key'])->toBe('kokoro')
        ->and($patch['voice'])->toBe('af_heart')
        ->and($patch['caption'])->toBe('truncated')
        ->and($patch['max_chars'])->toBe(4000)
        ->and($patch['daily_quota'])->toBe(0)
        ->and($patch['on_error'])->toBe('silent');
});

it('feeds the validated patch straight into TtsSettings::fromArray', function () {
    $patch = (new TtsWebUi)->validate([
        'auto_speak' => true,
        'caption' => 'none',
        'max_chars' => 500,
    ]);

    $settings = TtsSettings::fromArray($patch);

    expect($settings->autoSpeak)->toBeTrue()
        ->and($settings->caption)->toBe('none')
        ->and($settings->maxChars)->toBe(500);
});

it('rejects unknown enum and provider values', function () {
    $form = new TtsWebUi;

    expect(fn () => $form->validate(['provider_key' => 'skynet']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['caption' => 'yelled']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $form->validate(['on_error' => 'explode']))
        ->toThrow(InvalidArgumentException::class);
});

it('drops unrelated keys and keeps the module configured', function () {
    $form = new TtsWebUi;

    expect($form->validate(['evil_key' => 'x', 'custom_provider' => ['token' => 'steal']]))->toBe([])
        ->and($form->isConfigured([]))->toBeTrue();
});
