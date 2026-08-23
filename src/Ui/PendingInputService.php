<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Ui;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * One-active-input-per-user store for text-entry flows (custom provide
 * JSON, token paste, voice name). Cache-backed with native TTL (15 min) —
 * RFC §11 keeps the DB schema at two tables, so pending inputs live in the
 * runtime cache where loss is harmless (user re-taps the button).
 */
class PendingInputService
{
    public const ACTION_PROVIDER_JSON = 'provider_json';

    public const ACTION_TOKEN = 'token_paste';

    public const ACTION_VOICE = 'voice_input';

    /** @var list<string> */
    public const ACTIONS = [
        self::ACTION_PROVIDER_JSON,
        self::ACTION_TOKEN,
        self::ACTION_VOICE,
    ];

    private const TTL_SECONDS = 900;

    public function __construct(
        private readonly int $ttlSeconds = self::TTL_SECONDS,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  small context (e.g. provider_key)
     */
    public function start(string $botId, int $chatId, int $userTgId, string $action, array $payload = []): bool
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return false;
        }

        try {
            Cache::put(
                $this->key($botId, $chatId, $userTgId),
                json_encode(['action' => $action, 'payload' => $payload], JSON_THROW_ON_ERROR),
                $this->ttlSeconds,
            );
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Atomically consume the pending input (null when none/expired).
     *
     * @return array{action: string, payload: array<string, mixed>}|null
     */
    public function pop(string $botId, int $chatId, int $userTgId): ?array
    {
        try {
            $raw = Cache::pull($this->key($botId, $chatId, $userTgId));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw)) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($data) || ! isset($data['action']) || ! in_array($data['action'], self::ACTIONS, true)) {
            return null;
        }

        return [
            'action' => (string) $data['action'],
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
        ];
    }

    public function cancel(string $botId, int $chatId, int $userTgId): void
    {
        try {
            Cache::forget($this->key($botId, $chatId, $userTgId));
        } catch (Throwable) {
        }
    }

    private function key(string $botId, int $chatId, int $userTgId): string
    {
        return sprintf('tts:pending:%s:%d:%d', $botId, $chatId, $userTgId);
    }
}
