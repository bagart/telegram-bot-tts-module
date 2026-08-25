<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\I18n;

use BAGArt\TelegramBotTts\Settings\TtsSettings;

/**
 * ru/en string catalog (§7 failure texts + panel labels). Locale comes from
 * the per-chat `locale` setting.
 */
final class Strings
{
    /**
     * @var array<string, array<string, string>>
     */
    private const CATALOG = [
        'ru' => [
            'panel.title' => '🎙 Озвучка текста',
            'panel.auto_speak' => 'Автоозвучка (личка)',
            'panel.provider' => 'Провайдер',
            'panel.voice' => 'Голос',
            'panel.voice_default' => '(по умолчанию провайдера)',
            'panel.voice_manual' => 'Ввести вручную',
            'panel.caption' => 'Подпись',
            'panel.error_mode' => 'При ошибке',
            'panel.close' => 'Закрыть',
            'panel.off' => 'ВЫКЛ',
            'panel.on' => 'ВКЛ',
            'denied.group' => '⛔️ В группах /voice доступен админам с правом удаления сообщений.',
            'empty_input' => 'Пришли /voice с текстом или ответь командой /voice на сообщение.',
            'too_long' => 'Текст слишком длинный (лимит {max} символов).',
            'busy' => 'Уже озвучиваю предыдущий запрос — секунду.',
            'quota' => 'Дневной лимит озвучки в этом чате исчерпан ({quota}).',
            'provider_unconfigured' => 'Озвучка не настроена: выбери провайдера в панели /voice.',
            'notice' => 'ℹ️ Текст отправляется провайдеру «{provider}» для озвучки. Изменить: /voice → Провайдер.',
            'err.AUTH' => '😕 Провайдер отклонил ключ доступа.',
            'err.QUOTA_PROVIDER' => '😕 Лимит провайдера — попробуй позже.',
            'err.RATE_LIMITED' => '😕 Лимит провайдера — попробуй позже.',
            'err.BAD_REQUEST' => '😕 Провайдер настроен неверно.',
            'err.UNSUPPORTED_INPUT' => '😕 Текст слишком длинный.',
            'err.PAYLOAD_TOO_LARGE' => '😕 Текст слишком длинный.',
            'err.UNAVAILABLE' => '😕 Озвучка сейчас недоступна.',
            'err.EMPTY_RESULT' => '😕 Провайдер вернул пустой результат.',
            'saved' => 'Сохранено',
            'input.ask_json' => 'Пришли JSON конфигурацию кастомного провайдера (≤2KB):',
            'input.ask_token' => 'Пришли API-ключ для провайдера «{provider}»:',
            'input.ask_voice' => 'Пришли название голоса:',
            'input.bad_json' => 'Не удалось разобрать JSON: {reason}',
            'input.done' => 'Принято ✅',
        ],
        'en' => [
            'panel.title' => '🎙 Voice from text',
            'panel.auto_speak' => 'Auto-speak (private)',
            'panel.provider' => 'Provider',
            'panel.voice' => 'Voice',
            'panel.voice_default' => '(provider default)',
            'panel.voice_manual' => 'Enter manually',
            'panel.caption' => 'Caption',
            'panel.error_mode' => 'On error',
            'panel.close' => 'Close',
            'panel.off' => 'OFF',
            'panel.on' => 'ON',
            'denied.group' => '⛔️ In groups /voice is available to admins with the delete-messages right.',
            'empty_input' => 'Send /voice followed by text, or reply /voice to a message.',
            'too_long' => 'Text is too long (limit is {max} characters).',
            'busy' => 'Still voicing the previous request — one moment.',
            'quota' => 'Daily voice quota for this chat is used up ({quota}).',
            'provider_unconfigured' => 'TTS is not configured: pick a provider in the /voice panel.',
            'notice' => 'ℹ️ Your text is sent to the "{provider}" provider for voicing. Change it: /voice → Provider.',
            'err.AUTH' => '😕 The provider rejected the access key.',
            'err.QUOTA_PROVIDER' => '😕 Provider limit reached — try later.',
            'err.RATE_LIMITED' => '😕 Provider limit reached — try later.',
            'err.BAD_REQUEST' => '😕 The provider is misconfigured.',
            'err.UNSUPPORTED_INPUT' => '😕 Text is too long.',
            'err.PAYLOAD_TOO_LARGE' => '😕 Text is too long.',
            'err.UNAVAILABLE' => '😕 Voicing is unavailable right now.',
            'err.EMPTY_RESULT' => '😕 The provider returned nothing.',
            'saved' => 'Saved',
            'input.ask_json' => 'Send the custom provider JSON config (≤2KB):',
            'input.ask_token' => 'Send the API key for provider "{provider}":',
            'input.ask_voice' => 'Send the voice name:',
            'input.bad_json' => 'Could not parse JSON: {reason}',
            'input.done' => 'Accepted ✅',
        ],
    ];

    public static function get(string $locale, string $key, array $replacements = []): string
    {
        $catalog = self::CATALOG[$locale] ?? self::CATALOG[TtsSettings::LOCALE_RU];
        $line = $catalog[$key] ?? self::CATALOG[TtsSettings::LOCALE_RU][$key] ?? $key;

        foreach ($replacements as $placeholder => $value) {
            $line = str_replace('{'.$placeholder.'}', (string) $value, $line);
        }

        return $line;
    }
}
