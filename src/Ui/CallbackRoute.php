<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Ui;

/**
 * Encode/decode inline-keyboard callback data ("tv:<chatId>:<verb>[:arg]").
 * Telegram caps callback_data at 64 bytes; chatId is embedded and
 * cross-checked against the actual callback chat by the router (mismatch ⇒
 * ignore). Sibling STT uses "tc:" — prefixes must never collide (§18).
 */
final class CallbackRoute
{
    private const PREFIX = 'tv';

    public const VERB_MENU = 'm';

    public const VERB_AUTOSPEAK_ON = 'son';

    public const VERB_AUTOSPEAK_OFF = 'soff';

    public const VERB_PAGE_PROVIDERS = 'ptts';

    public const VERB_SET_PROVIDER = 'tst';

    public const VERB_CUSTOM_PROVIDER = 'pjc';

    public const VERB_VOICE_INPUT = 'voc';

    public const VERB_VOICE_MANUAL = 'vman';

    public const VERB_SET_VOICE = 'svoc';

    public const VERB_CAPTION = 'cap';

    public const VERB_ERROR_MODE = 'err';

    public const VERB_CLOSE = 'x';

    /** @var list<string> */
    public const VERBS = [
        self::VERB_MENU,
        self::VERB_AUTOSPEAK_ON,
        self::VERB_AUTOSPEAK_OFF,
        self::VERB_PAGE_PROVIDERS,
        self::VERB_SET_PROVIDER,
        self::VERB_CUSTOM_PROVIDER,
        self::VERB_VOICE_INPUT,
        self::VERB_VOICE_MANUAL,
        self::VERB_SET_VOICE,
        self::VERB_CAPTION,
        self::VERB_ERROR_MODE,
        self::VERB_CLOSE,
    ];

    public static function encode(int $chatId, string $verb, ?string $arg = null): string
    {
        $data = self::PREFIX.':'.$chatId.':'.$verb;

        return $arg === null ? $data : $data.':'.$arg;
    }

    /**
     * @return array{chatId: int, verb: string, arg: ?string}|null
     */
    public static function decode(?string $data): ?array
    {
        if ($data === null || ! str_starts_with($data, self::PREFIX.':')) {
            return null;
        }

        $parts = explode(':', $data);

        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }

        [, $chatIdRaw, $verb] = $parts;
        $chatId = filter_var($chatIdRaw, FILTER_VALIDATE_INT);

        if ($chatId === false || $chatId === 0 || ! preg_match('/^[a-z]{1,6}$/', $verb)) {
            return null;
        }

        return [
            'chatId' => $chatId,
            'verb' => $verb,
            'arg' => $parts[3] ?? null,
        ];
    }
}
