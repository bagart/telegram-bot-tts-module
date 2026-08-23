<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Access;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\TgApi\Methods\DTO\GetChatAdministratorsMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberAdministratorTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatMemberOwnerTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides who may use /voice and the settings panel:
 * - private chat: the peer user manages their own chat settings (there are
 *   no admins in a private chat — extension of the Summarizer gate);
 * - groups: Telegram admins holding "delete messages of others" plus
 *   platform superadmins (TTS_SUPERADMIN_TG_IDS). Group /voice is
 *   admin-only by default (Q4/T12).
 */
class AccessService
{
    private const ADMIN_LIST_TTL = 300;

    public function __construct(
        private readonly TgBotApiDTOClientContract $api,
    ) {}

    public function isSuperadmin(int|string $userTgId): bool
    {
        return in_array((string) $userTgId, config('tts.superadmins', []), true);
    }

    public function canManage(TgBotConfig $botConfig, int $chatId, UserTypeDTO $user, bool $isPrivateChat): bool
    {
        if ($isPrivateChat || $this->isSuperadmin($user->id)) {
            return true;
        }

        return $this->hasTelegramDeleteRights($botConfig, $chatId, (int) $user->id);
    }

    /**
     * Live Telegram check (cached): member must be owner or an administrato
     * holding can_delete_messages.
     */
    public function hasTelegramDeleteRights(TgBotConfig $botConfig, int $chatId, int $userTgId): bool
    {
        $admins = $this->administrators($botConfig, $chatId);

        if ($admins === null) {
            // API failure: fail closed for privilege grants
            return false;
        }

        foreach ($admins as $member) {
            if ((int) $member->user->id !== $userTgId) {
                continue;
            }

            if ($member instanceof ChatMemberOwnerTypeDTO) {
                return true;
            }

            return $member instanceof ChatMemberAdministratorTypeDTO && $member->canDeleteMessages;
        }

        return false;
    }

    /**
     * @return list<ChatMemberOwnerTypeDTO|ChatMemberAdministratorTypeDTO>|null
     */
    private function administrators(TgBotConfig $botConfig, int $chatId): ?array
    {
        $cacheKey = sprintf('tts:admins:%s:%d', (string) $botConfig->botId, $chatId);

        try {
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // cache unavailable — fetch without caching below
        }

        try {
            $response = $this->api->request($botConfig, new GetChatAdministratorsMethodDTO(chatId: (string) $chatId));
        } catch (Throwable $e) {
            Log::warning('TTS: getChatAdministrators failed', [
                'chat_id' => $chatId,
                'exception' => $e::class,
            ]);

            return null;
        }

        if (! $response->ok || ! is_array($response->result)) {
            return null;
        }

        $members = [];

        foreach ($response->result as $member) {
            if ($member instanceof ChatMemberOwnerTypeDTO || $member instanceof ChatMemberAdministratorTypeDTO) {
                $members[] = $member;
            }
        }

        try {
            Cache::put($cacheKey, $members, self::ADMIN_LIST_TTL);
        } catch (Throwable) {
        }

        return $members;
    }
}
