<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\Models\TtsToken;
use BAGArt\TelegramBotTts\ModuleFactory;
use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use BAGArt\TelegramBotTts\Settings\TtsSettingsService;
use BAGArt\TelegramBotTts\Ui\PendingInputService;
use InvalidArgumentException;
use Throwable;

/**
 * Private-text processor (US2) + pending editor-input consumer:
 * 1. applies pending text-input flows (custom JSON, token paste, voice);
 * 2. auto-speak: companion voice note under incoming private texts
 *    (opt-in, off by default, private only — group auto-speak is a spam
 *    cannon, Q1/T10).
 */
class AutoSpeakProcessor implements TgModuleProcessorContract
{
    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly TtsSettingsService $settings,
        private readonly PendingInputService $pending,
        private readonly SpeechPipeline $pipeline,
        private readonly ProviderRegistry $registry,
    ) {
    }

    public static function moduleId(): string
    {
        return ModuleFactory::moduleId();
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            settings: ModuleFactory::settings(),
            pending: ModuleFactory::pending(),
            pipeline: ModuleFactory::pipeline($context),
            registry: ModuleFactory::registry(),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->from !== null
            && $dto->text !== null
            && trim($dto->text) !== ''
            && TgCommandRegistry::parseCommandName($dto->text) === null
            && in_array($dto->chat->type, [ChatPropTypeEnum::PRIVATE, ChatPropTypeEnum::GROUP, ChatPropTypeEnum::SUPERGROUP], true);
    }

    public function isStrictOrdered(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return false;
    }

    public function process(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): void {
        assert($dto instanceof MessageTypeDTO);

        if (! ModuleFactory::inLaravel() || $dto->from === null || $dto->text === null) {
            return;
        }

        $chatId = (int) $dto->chat->id;
        $botId = (string) $botConfig->botId;
        $userTgId = (int) $dto->from->id;
        $isPrivate = $dto->chat->type === ChatPropTypeEnum::PRIVATE;

        try {
            // Pending editor flows win over speaking.
            $input = $this->pending->pop($botId, $chatId, $userTgId);

            if ($input !== null) {
                $locale = $this->settings->get($botId, $chatId)->locale;
                $this->applyInput($botConfig, $chatId, (string) $dto->text, $input, $locale);

                return;
            }

            if (! $isPrivate) {
                return;
            }

            if (! $this->settings->get($botId, $chatId)->autoSpeak) {
                return;
            }

            $this->pipeline->speak(
                botConfig: $botConfig,
                chatId: $chatId,
                userTgId: $userTgId,
                rawText: (string) $dto->text,
                isPrivateChat: true,
                trigger: 'auto',
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array{action: string, payload: array<string, mixed>}  $input
     */
    private function applyInput(TgBotConfig $botConfig, int $chatId, string $text, array $input, string $locale): void
    {
        $botId = (string) $botConfig->botId;

        try {
            switch ($input['action']) {
                case PendingInputService::ACTION_PROVIDER_JSON:
                    $normalized = $this->registry->validateCustomConfig(mb_substr($text, 0, 2048));
                    $this->settings->patch($botId, $chatId, [
                        'provider_key' => ProviderRegistry::CUSTOM_KEY,
                        'custom_provider' => $normalized,
                    ]);

                    break;

                case PendingInputService::ACTION_TOKEN:
                    $providerKey = (string) ($input['payload']['provider_key'] ?? '');

                    if (! $this->registry->has($providerKey)) {
                        return;
                    }

                    TtsToken::query()->updateOrCreate(
                        ['bot_id' => $botId, 'provider_key' => $providerKey],
                        ['token' => trim(mb_substr($text, 0, 512))],
                    );

                    break;

                case PendingInputService::ACTION_VOICE:
                    $voice = trim(mb_substr($text, 0, 128));

                    if ($voice !== '') {
                        $this->settings->patch($botId, $chatId, ['voice' => $voice]);
                    }

                    break;
            }
        } catch (InvalidArgumentException $e) {
            $this->senderReply($botConfig, $chatId, Strings::get($locale, 'input.bad_json', [
                'reason' => $e->getMessage(),
            ]));

            return;
        }

        $this->senderReply($botConfig, $chatId, Strings::get($locale, 'input.done'));
    }

    private function senderReply(TgBotConfig $botConfig, int $chatId, string $text): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $text,
        ));
    }

    public function onException(ProcessorErrorContext $context): void
    {
    }
}
