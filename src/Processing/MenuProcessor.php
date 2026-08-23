<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Processing;

use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\Processing\Processors\TgModuleProcessorContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiTypeDTOContract;
use BAGArt\TelegramBot\Processing\BotProcessorContext;
use BAGArt\TelegramBot\Processing\ErrorHandling\ProcessorErrorContext;
use BAGArt\TelegramBot\TgApi\Methods\DTO\AnswerCallbackQueryMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBotTts\Access\AccessService;
use BAGArt\TelegramBotTts\I18n\Strings;
use BAGArt\TelegramBotTts\ModuleFactory;
use BAGArt\TelegramBotTts\Provider\ProviderRegistry;
use BAGArt\TelegramBotTts\Settings\TtsSettingsService;
use BAGArt\TelegramBotTts\Ui\CallbackRoute;
use BAGArt\TelegramBotTts\Ui\MenuRenderer;
use BAGArt\TelegramBotTts\Ui\PendingInputService;
use Throwable;

/**
 * Inline-keyboard router for the /voice settings panel. Every press is
 * re-authorized against the embedded chatId; the parsed CallbackQuery DTO
 * carries no usable originating-message payload (MaybeInaccessibleMessage
 * is empty in this platform build), so privacy detection relies on the
 * Telegram chat-id sign convention: positive chat ids are user chats.
 */
class MenuProcessor implements TgModuleProcessorContract
{
    public function __construct(
        private readonly TgSenderContract $sender,
        private readonly TgBotApiDTOClientContract $api,
        private readonly TtsSettingsService $settings,
        private readonly AccessService $access,
        private readonly MenuRenderer $menu,
        private readonly PendingInputService $pending,
        private readonly ProviderRegistry $registry,
    ) {}

    public static function moduleId(): string
    {
        return ModuleFactory::moduleId();
    }

    public static function build(BotProcessorContext $context): self
    {
        return new self(
            sender: $context->tgSender,
            api: app(TgBotApiDTOClientContract::class),
            settings: ModuleFactory::settings(),
            access: ModuleFactory::access(),
            menu: ModuleFactory::menu(),
            pending: ModuleFactory::pending(),
            registry: ModuleFactory::registry(),
        );
    }

    public function support(
        TgApiTypeDTOContract $dto,
        TgBotConfig $botConfig,
        ?string $action = null,
    ): bool {
        return $dto instanceof CallbackQueryTypeDTO
            && $dto->data !== null
            && CallbackRoute::decode($dto->data) !== null;
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
        assert($dto instanceof CallbackQueryTypeDTO);

        if (! ModuleFactory::inLaravel()) {
            return;
        }

        $route = CallbackRoute::decode($dto->data);
        \assert($route !== null);

        $chatId = $route['chatId'];
        $isPrivateChat = $chatId > 0;
        $botId = (string) $botConfig->botId;

        try {
            if (! $this->access->canManage($botConfig, $chatId, $dto->from, $isPrivateChat)) {
                $this->answer($botConfig, $dto, 'Only admins may open this panel.', alert: true);

                return;
            }

            $this->dispatchVerb($dto, $botConfig, $chatId, $isPrivateChat, $route['verb'], $route['arg']);
        } catch (Throwable $e) {
            report($e);
            $this->answer($botConfig, $dto, 'Menu error', alert: true);
        }
    }

    private function dispatchVerb(
        CallbackQueryTypeDTO $query,
        TgBotConfig $botConfig,
        int $chatId,
        bool $isPrivateChat,
        string $verb,
        ?string $arg,
    ): void {
        $botId = (string) $botConfig->botId;
        $userTgId = (int) $query->from->id;
        $locale = $this->settings->get($botId, $chatId)->locale;

        switch ($verb) {
            case CallbackRoute::VERB_MENU:
                $this->renderPage($botConfig, $chatId, $isPrivateChat);
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_AUTOSPEAK_ON:
            case CallbackRoute::VERB_AUTOSPEAK_OFF:
                // Auto-speak is a private-chat feature only (T10); the
                // keyboard hides it in groups anyway.
                if ($isPrivateChat) {
                    $this->settings->patch($botId, $chatId, ['auto_speak' => $verb === CallbackRoute::VERB_AUTOSPEAK_ON]);
                }

                $this->answer($botConfig, $query);
                $this->renderPage($botConfig, $chatId, $isPrivateChat);

                return;

            case CallbackRoute::VERB_PAGE_PROVIDERS:
                $page = $this->menu->providers($chatId, $this->settings->get($botId, $chatId));
                $this->sendPage($botConfig, $chatId, $page);
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_SET_PROVIDER:
                $this->selectProvider($query, $botConfig, $chatId, $userTgId, (string) $arg);

                return;

            case CallbackRoute::VERB_CUSTOM_PROVIDER:
                $started = $this->pending->start($botId, $chatId, $userTgId, PendingInputService::ACTION_PROVIDER_JSON);

                $this->sender->send($botConfig, new SendMessageMethodDTO(
                    chatId: (string) $chatId,
                    text: Strings::get($locale, 'input.ask_json')
                        ."\n\n".$this->registry->customTemplateJson()
                        .($started ? '' : "\n\n(input storage unavailable — try later)"),
                ));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_VOICE_INPUT:
                $this->pending->start($botId, $chatId, $userTgId, PendingInputService::ACTION_VOICE);
                $this->sender->send($botConfig, new SendMessageMethodDTO(
                    chatId: (string) $chatId,
                    text: Strings::get($locale, 'input.ask_voice'),
                ));
                $this->answer($botConfig, $query);

                return;

            case CallbackRoute::VERB_SET_VOICE:
                $voice = trim((string) $arg);

                if ($voice === '' || mb_strlen($voice) > 128) {
                    $this->answer($botConfig, $query, 'Bad voice name', alert: true);

                    return;
                }

                $this->settings->patch($botId, $chatId, ['voice' => $voice]);
                $this->answer($botConfig, $query, Strings::get($locale, 'saved'));
                $this->renderPage($botConfig, $chatId, $isPrivateChat);

                return;

            case CallbackRoute::VERB_CAPTION:
                $current = $this->settings->get($botId, $chatId)->caption;
                $next = MenuRenderer::nextCaption($current);
                $this->settings->patch($botId, $chatId, ['caption' => $next]);
                $this->answer($botConfig, $query, $next);
                $this->renderPage($botConfig, $chatId, $isPrivateChat);

                return;

            case CallbackRoute::VERB_ERROR_MODE:
                $current = $this->settings->get($botId, $chatId)->onError;
                $next = MenuRenderer::nextErrorMode($current);
                $this->settings->patch($botId, $chatId, ['on_error' => $next]);
                $this->answer($botConfig, $query, $next);
                $this->renderPage($botConfig, $chatId, $isPrivateChat);

                return;

            case CallbackRoute::VERB_CLOSE:
                $this->answer($botConfig, $query);

                return;
        }

        $this->answer($botConfig, $query, 'Unknown action', alert: true);
    }

    private function selectProvider(
        CallbackQueryTypeDTO $query,
        TgBotConfig $botConfig,
        int $chatId,
        int $userTgId,
        string $providerKey,
    ): void {
        $botId = (string) $botConfig->botId;
        $locale = $this->settings->get($botId, $chatId)->locale;

        if (! $this->registry->has($providerKey)) {
            $this->answer($botConfig, $query, 'Unknown provider', alert: true);

            return;
        }

        $this->settings->patch($botId, $chatId, [
            'provider_key' => $providerKey,
            'custom_provider' => null,
        ]);

        $preset = $this->registry->get($providerKey);

        if ($preset !== null && $preset->needsToken) {
            $hasToken = TtsToken::query()
                ->where('bot_id', $botId)
                ->where('provider_key', $providerKey)
                ->exists();

            if (! $hasToken) {
                $this->pending->start($botId, $chatId, $userTgId, PendingInputService::ACTION_TOKEN, [
                    'provider_key' => $providerKey,
                ]);

                $this->sender->send($botConfig, new SendMessageMethodDTO(
                    chatId: (string) $chatId,
                    text: Strings::get($locale, 'input.ask_token', ['provider' => $preset->name]),
                ));
            }
        }

        $this->answer($botConfig, $query);
        $this->renderPage($botConfig, $chatId, $chatId > 0);
    }

    /**
     * @param  array{text: string, keyboard: mixed}  $page
     */
    private function sendPage(TgBotConfig $botConfig, int $chatId, array $page): void
    {
        $this->sender->send($botConfig, new SendMessageMethodDTO(
            chatId: (string) $chatId,
            text: $page['text'],
            replyMarkup: $page['keyboard'],
        ));
    }

    private function renderPage(TgBotConfig $botConfig, int $chatId, bool $isPrivateChat): void
    {
        $page = $this->menu->main(
            $chatId,
            $this->settings->get((string) $botConfig->botId, $chatId),
            $isPrivateChat,
        );

        $this->sendPage($botConfig, $chatId, $page);
    }

    private function answer(
        TgBotConfig $botConfig,
        CallbackQueryTypeDTO $query,
        ?string $text = null,
        bool $alert = false,
    ): void {
        try {
            $this->api->request($botConfig, new AnswerCallbackQueryMethodDTO(
                callbackQueryId: $query->id,
                text: $text,
                showAlert: $text !== null && $alert,
            ));
        } catch (Throwable) {
            // Answer callbacks are cosmetic; never fail on them.
        }
    }

    public function onException(ProcessorErrorContext $context): void {}
}
