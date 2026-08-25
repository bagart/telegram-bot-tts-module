<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Ui;

use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardButtonTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\InlineKeyboardMarkupTypeDTO;
use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use BAGArt\TelegramBotTts\Settings\TtsSettings;

/**
 * Settings-panel screens (bare /voice). Menus are sent as fresh messages
 * (the parsed CallbackQuery DTO carries no usable originating-message id).
 */
class MenuRenderer
{
    private const VOICE_LIST_LIMIT = 8;

    public function __construct(
        private readonly ProviderRegistry $registry,
    ) {
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function main(int $chatId, TtsSettings $settings, bool $isPrivateChat): array
    {
        $t = fn (string $key, array $repl = []): string => Strings::get($settings->locale, $key, $repl);

        $text = $t('panel.title')."\n"
            .$t('panel.provider').': '.$this->providerLabel($settings)."\n"
            .$t('panel.voice').': '.($settings->voice ?? $t('panel.voice_default'))."\n"
            .$t('panel.caption').': '.$settings->caption."\n"
            .$t('panel.error_mode').': '.$settings->onError;

        if ($isPrivateChat) {
            $text = str_replace(
                $t('panel.title'),
                $t('panel.title')."\n".$t('panel.auto_speak').': '.($settings->autoSpeak ? $t('panel.on') : $t('panel.off')),
                $text,
            );
        }

        $rows = [];

        if ($isPrivateChat) {
            $rows[] = [
                new InlineKeyboardButtonTypeDTO(
                    text: $t('panel.auto_speak').': '.($settings->autoSpeak ? '✅' : '⬜️'),
                    callbackData: CallbackRoute::encode($chatId, $settings->autoSpeak ? CallbackRoute::VERB_AUTOSPEAK_OFF : CallbackRoute::VERB_AUTOSPEAK_ON),
                ),
            ];
        }

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '🔊 '.$t('panel.provider'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_PAGE_PROVIDERS),
            ),
        ];

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '🗣 '.$t('panel.voice'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_VOICE_INPUT),
            ),
            new InlineKeyboardButtonTypeDTO(
                text: '🏷 '.$t('panel.caption'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_CAPTION),
            ),
        ];

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '❗️'.$t('panel.error_mode'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_ERROR_MODE),
            ),
            new InlineKeyboardButtonTypeDTO(
                text: $t('panel.close'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_CLOSE),
            ),
        ];

        return ['text' => $text, 'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows)];
    }

    /**
     * @return array{text: string, keyboard: InlineKeyboardMarkupTypeDTO}
     */
    public function providers(int $chatId, TtsSettings $settings): array
    {
        $t = fn (string $key, array $repl = []): string => Strings::get($settings->locale, $key, $repl);

        $rows = [];

        foreach ($this->registry->all() as $preset) {
            $marker = $settings->providerKey === $preset->key ? '●' : '○';
            $rows[] = [
                new InlineKeyboardButtonTypeDTO(
                    text: sprintf('%s %s · %s', $marker, $preset->name, $preset->note ?? ''),
                    callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_SET_PROVIDER, $preset->key),
                ),
            ];
        }

        $customMarker = $settings->providerKey === ProviderRegistry::CUSTOM_KEY ? '●' : '○';
        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: $customMarker.' ✏️ custom JSON…',
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_CUSTOM_PROVIDER),
            ),
        ];

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '← '.$t('panel.title'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_MENU),
            ),
        ];

        return [
            'text' => $t('panel.provider'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /**
     * Voice picker: preset catalog when ≤ limit, otherwise instructs the
     * text-input flow (Q2 default).
     *
     * @param  list<string>  $voices
     * @return array{text: string, keyboard: ?InlineKeyboardMarkupTypeDTO}
     */
    public function voices(int $chatId, TtsSettings $settings, array $voices): array
    {
        $t = fn (string $key, array $repl = []): string => Strings::get($settings->locale, $key, $repl);

        if ($voices === [] || count($voices) > self::VOICE_LIST_LIMIT) {
            return [
                'text' => $t('input.ask_voice'),
                'keyboard' => null,
            ];
        }

        $rows = [];
        $row = [];

        foreach (array_slice($voices, 0, self::VOICE_LIST_LIMIT) as $voice) {
            $row[] = new InlineKeyboardButtonTypeDTO(
                text: ($settings->voice === $voice ? '● ' : '').$voice,
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_SET_VOICE, $voice),
            );

            if (count($row) === 2) {
                $rows[] = $row;
                $row = [];
            }
        }

        if ($row !== []) {
            $rows[] = $row;
        }

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '✏️ '.$t('panel.voice_manual'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_VOICE_MANUAL),
            ),
        ];

        $rows[] = [
            new InlineKeyboardButtonTypeDTO(
                text: '← '.$t('panel.title'),
                callbackData: CallbackRoute::encode($chatId, CallbackRoute::VERB_MENU),
            ),
        ];

        return [
            'text' => $t('panel.voice'),
            'keyboard' => new InlineKeyboardMarkupTypeDTO(inlineKeyboard: $rows),
        ];
    }

    /** Next value of the caption cycle (original → truncated → none → …). */
    public static function nextCaption(string $current): string
    {
        return match ($current) {
            TtsSettings::CAPTION_ORIGINAL => TtsSettings::CAPTION_TRUNCATED,
            TtsSettings::CAPTION_TRUNCATED => TtsSettings::CAPTION_NONE,
            default => TtsSettings::CAPTION_ORIGINAL,
        };
    }

    /** Next value of the error-mode cycle (auto → emoji → message → silent → …). */
    public static function nextErrorMode(string $current): string
    {
        return match ($current) {
            TtsSettings::ERROR_MODE_AUTO => TtsSettings::ERROR_MODE_EMOJI,
            TtsSettings::ERROR_MODE_EMOJI => TtsSettings::ERROR_MODE_MESSAGE,
            TtsSettings::ERROR_MODE_MESSAGE => TtsSettings::ERROR_MODE_SILENT,
            default => TtsSettings::ERROR_MODE_AUTO,
        };
    }

    private function providerLabel(TtsSettings $settings): string
    {
        if ($settings->providerKey === ProviderRegistry::CUSTOM_KEY) {
            return 'custom'.(isset($settings->customProvider['name']) ? ' ('.$settings->customProvider['name'].')' : '');
        }

        $preset = $this->registry->get($settings->providerKey);

        return $preset?->name ?? $settings->providerKey.' (?)';
    }
}
