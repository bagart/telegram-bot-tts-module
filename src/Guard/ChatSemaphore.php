<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Guard;

use RuntimeException;

/**
 * One in-flight synthesis per chat (SET NX PX 60 s). FAIL-OPEN on Redis
 * loss (§9): skip acquire and proceed — the quota, char caps and global
 * concurrency still bound the load.
 */
class ChatSemaphore
{
    private const LOCK_TTL_MILLISECONDS = 60000;

    public function __construct(
        private readonly GuardStoreContract $store,
    ) {}

    public function acquire(string $botId, int $chatId): bool
    {
        try {
            return $this->store->addIfAbsent(
                sprintf('tts:lock:%s:%d', $botId, $chatId),
                (string) time(),
                self::LOCK_TTL_MILLISECONDS,
            );
        } catch (RuntimeException) {
            return true;
        }
    }

    public function release(string $botId, int $chatId): void
    {
        $this->store->delete(sprintf('tts:lock:%s:%d', $botId, $chatId));
    }
}
