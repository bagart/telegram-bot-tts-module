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
use BAGArt\TelegramBotTts\Access\AccessService;
use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\ModuleFactory;
use BAGArt\TelegramBotTts\Settings\TtsSettingsService;
use BAGArt\TelegramBotTts\Ui\MenuRenderer;
use Throwable;

/**
 * "/voice" — dual-purpose command (T9):
 *   /voice текст        → speak the argument;
 *   reply /voice → text → speak that text;
 *   bare /voice         → settings panel (groups: admins only, Q4).
 */
class VoiceCommandProcessor implements TgModuleProcessorContract
{
    public const NAME = 'voice';

    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly TtsSettingsService $settings,
        private readonly AccessService $access,
        private readonly MenuRenderer $menu,
        private readonly SpeechPipeline $pipeline,
    ) {}

    public static function moduleId(): string
    {
        return ModuleFactory::moduleId();
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            settings: ModuleFactory::settings(),
            access: ModuleFactory::access(),
            menu: ModuleFactory::menu(),
            pipeline: ModuleFactory::pipeline($context),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof MessageTypeDTO
            && $dto->text !== null
            && TgCommandRegistry::parseCommandName($dto->text) === self::NAME;
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

        if (! ModuleFactory::inLaravel() || $dto->from === null) {
            return;
        }

        $chatId = (int) $dto->chat->id;
        $isPrivate = $dto->chat->type === ChatPropTypeEnum::PRIVATE;
        $botId = (string) $botConfig->botId;

        try {
            if (! $isPrivate && ! $this->access->canManage($botConfig, $chatId, $dto->from, isPrivateChat: false)) {
                $locale = $this->settings->get($botId, $chatId)->locale;
                $this->sender->send($botConfig, new SendMessageMethodDTO(
                    chatId: (string) $chatId,
                    text: Strings::get($locale, 'denied.group'),
                ));

                return;
            }

            $argument = $this->argumentOf($dto);

            if ($argument !== null) {
                $this->pipeline->speak(
                    botConfig: $botConfig,
                    chatId: $chatId,
                    userTgId: (int) $dto->from->id,
                    rawText: $argument,
                    isPrivateChat: $isPrivate,
                    trigger: 'command',
                );

                return;
            }

            $settings = $this->settings->get($botId, $chatId);
            $page = $this->menu->main($chatId, $settings, $isPrivate);

            $this->sender->send($botConfig, new SendMessageMethodDTO(
                chatId: (string) $chatId,
                text: $page['text'],
                replyMarkup: $page['keyboard'],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function onException(ProcessorErrorContext $context): void {}

    /**
     * Text after the command token; a reply target's text when the command
     * is bare. Replied non-text (or nothing at all) yields null → panel.
     */
    private function argumentOf(MessageTypeDTO $dto): ?string
    {
        $text = trim((string) $dto->text);
        $parts = preg_split('/\s+/', $text, 2);
        $argument = isset($parts[1]) ? trim($parts[1]) : '';

        if ($argument !== '') {
            return $argument;
        }

        $repliedText = $dto->replyToMessage?->text;

        return ($repliedText !== null && trim($repliedText) !== '') ? trim($repliedText) : null;
    }
}
