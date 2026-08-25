<?php

declare(strict_types=1);

use BAGArt\ASKClient\Contracts\Pipeline\ASKFutureContract;
use BAGArt\TelegramBot\Configs\TgBotConfig;
use BAGArt\TelegramBot\Configs\TgServiceConfig;
use BAGArt\TelegramBot\Contracts\ApiCommunication\TgBotApiDTOClientContract;
use BAGArt\TelegramBot\Contracts\Modules\ModuleEnablementContract;
use BAGArt\TelegramBot\Contracts\Outbound\TgSenderContract;
use BAGArt\TelegramBot\Contracts\TgApi\TgApiMethodDTOContract;
use BAGArt\TelegramBot\Http\Pure\TgApiResponse;
use BAGArt\TelegramBot\Modules\TgCommandRegistry;
use BAGArt\TelegramBot\Modules\TgModuleRegistry;
use BAGArt\TelegramBot\Processing\RegisteredUpdateProcessorSelector;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendMessageMethodDTO;
use BAGArt\TelegramBot\TgApi\Methods\DTO\SendVoiceMethodDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\ChatTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UpdateTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\UserTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\Enum\ChatPropTypeEnum;
use BAGArt\TelegramBot\TgBotSetupFactory;
use BAGArt\TelegramBotManagement\Models\TgBot;
use BAGArt\TelegramBotTts\Guard\ArrayGuardStore;
use BAGArt\TelegramBotTts\Guard\GuardStoreContract;
use BAGArt\TelegramBotTts\ModuleFactory;
use BAGArt\TelegramBotTts\Processing\AutoSpeakProcessor;
use BAGArt\TelegramBotTts\Processing\MenuProcessor;
use BAGArt\TelegramBotTts\Processing\VoiceCommandProcessor;
use BAGArt\TelegramBotTts\Provider\AdapterSelectorContract;
use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\Dto\TtsResult;
use BAGArt\TelegramBotTts\Provider\TtsProviderContract;
use BAGArt\TelegramBotTts\Provider\VoiceCatalog;
use BAGArt\TelegramBotTts\Provider\VoiceProviderConfig;
use BAGArt\TelegramBotTts\Ui\CallbackRoute;
use BAGArt\TelegramBotTts\Ui\PendingInputService;
use Illuminate\Support\Facades\Http;

/*
 * TTS module E2E: webhook-shaped updates through the real processor
 * selector plus direct processor runs with a sender spy for message-content
 * assertions. Redis is replaced by ArrayGuardStore, the provider by a canned
 * fake, and the Track A upload path is captured via Http::fake.
 */

beforeEach(function () {
    config('telegram.modules'); // force module config scan
    config(['tts.superadmins' => []]);
    config(['tts.storage_path' => sys_get_temp_dir().'/tts-e2e-'.bin2hex(random_bytes(4))]);

    $this->box = new class
    {
        public int $calls = 0;
    };

    $this->app->instance(GuardStoreContract::class, new ArrayGuardStore);
    $this->app->instance(AdapterSelectorContract::class, ttsFakeSelector($this->box));
    $fakeClient = ttsFakeApiClient();
    TtsRecordingFakeApiClient::$uploaded = [];
    $this->app->instance(TgBotApiDTOClientContract::class, $fakeClient);

    TgBot::create(['bot_id' => 'test_bot', 'token' => '123:test']);

    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
    ]);

    ModuleFactory::settings()->patch('test_bot', 777, ['enabled' => true]);
});

afterEach(function () {
    if (is_dir((string) config('tts.storage_path'))) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator((string) config('tts.storage_path'), FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir((string) config('tts.storage_path'));
    }
});

function ttsFakeSelector(object $box): AdapterSelectorContract
{
    return new class($box) implements AdapterSelectorContract
    {
        public function __construct(
            private readonly object $box,
        ) {}

        public function for(VoiceProviderConfig $config): TtsProviderContract
        {
            return new class($this->box) implements TtsProviderContract
            {
                public function __construct(
                    private readonly object $box,
                ) {}

                public function synthesize(TtsRequest $request): TtsResult
                {
                    $this->box->calls++;

                    return new TtsResult(
                        binary: str_repeat('RIFF', 128),
                        mimeType: 'audio/mpeg',
                        providerKey: $request->config->key,
                        latencyMs: 42,
                    );
                }
            };
        }
    };
}

final class TtsRecordingFakeApiClient implements TgBotApiDTOClientContract
{
    /** @var list<TgApiMethodDTOContract> */
    public static array $uploaded = [];

    public function request(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): TgApiResponse
    {
        if (in_array($dto::tgApiEntity()->name, ['sendVoice', 'sendAudio'], true)) {
            self::$uploaded[] = $dto;
        }

        return new TgApiResponse(ok: true, possibleResultTypes: [], result: null);
    }

    public function requestAsync(TgBotConfig $botConfig, TgApiMethodDTOContract $dto, ?int $timeout = null): ASKFutureContract
    {
        throw new RuntimeException('not used in tests');
    }

    public function tickable(): array
    {
        return [];
    }
}

function ttsFakeApiClient(): TgBotApiDTOClientContract
{
    return new TtsRecordingFakeApiClient;
}

function ttsBotConfig(): TgBotConfig
{
    return new TgBotConfig(token: '123:test', botId: 'test_bot');
}

function ttsUser(int $id): UserTypeDTO
{
    return new UserTypeDTO(id: (string) $id, isBot: false, firstName: 'Tester', username: 'tester');
}

function ttsPrivateMessage(int $userId, string $text, int $messageId = 10): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: (string) $userId, type: ChatPropTypeEnum::PRIVATE),
        from: ttsUser($userId),
        text: $text,
    );
}

function ttsGroupMessage(int $userId, string $text, int $messageId = 10): MessageTypeDTO
{
    return new MessageTypeDTO(
        messageId: $messageId,
        date: time(),
        chat: new ChatTypeDTO(id: '-100100', type: ChatPropTypeEnum::SUPERGROUP),
        from: ttsUser($userId),
        text: $text,
    );
}

function ttsSenderSpy(): TgSenderContract
{
    return new class implements TgSenderContract
    {
        /** @var list<TgApiMethodDTOContract> */
        public array $sent = [];

        public function send(TgBotConfig $botConfig, TgApiMethodDTOContract $dto): void
        {
            $this->sent[] = $dto;
        }
    };
}

/**
 * @return list<string>
 */
function ttsTexts(object $spy): array
{
    return collect($spy->sent)
        ->filter(fn ($dto) => $dto instanceof SendMessageMethodDTO)
        ->map(fn (SendMessageMethodDTO $dto) => $dto->text)
        ->values()
        ->all();
}

/**
 * @return list<TgApiMethodDTOContract>
 */
function ttsUploadRequests(): array
{
    return TtsRecordingFakeApiClient::$uploaded;
}

/**
 * @return list<string> 'sendVoice'|'sendAudio' per recorded upload
 */
function ttsUploadMethodNames(): array
{
    return collect(ttsUploadRequests())
        ->map(fn ($dto) => $dto::tgApiEntity()->name)
        ->values()
        ->all();
}

function ttsProcessorWithSender(string $class, TgSenderContract $spy): object
{
    $pipeline = ModuleFactory::pipelineWithSender($spy);

    return match ($class) {
        VoiceCommandProcessor::class => new VoiceCommandProcessor(
            sender: $spy,
            settings: ModuleFactory::settings(),
            access: ModuleFactory::access(),
            menu: ModuleFactory::menu(),
            pipeline: $pipeline,
        ),
        AutoSpeakProcessor::class => new AutoSpeakProcessor(
            sender: $spy,
            settings: ModuleFactory::settings(),
            pending: ModuleFactory::pending(),
            pipeline: $pipeline,
            registry: ModuleFactory::registry(),
        ),
        default => throw new InvalidArgumentException($class),
    };
}

it('discovers the tts module with the /voice command registered', function () {
    expect(app(TgModuleRegistry::class)->has('tts'))->toBeTrue()
        ->and(app(TgModuleRegistry::class)->defaultEnabledOf('tts'))->toBeFalse()
        ->and(app(TgCommandRegistry::class)->has('voice'))->toBeTrue();
});

it('speaks /voice in a private chat and uploads a voice note (US1)', function () {
    $spy = ttsSenderSpy();

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)
        ->process(ttsPrivateMessage(777, '/voice Перезвоню через час'), ttsBotConfig());

    $uploads = ttsUploadRequests();

    expect(ttsUploadMethodNames())->toBe(['sendVoice'])
        ->and($uploads[0])->toBeInstanceOf(SendVoiceMethodDTO::class)
        ->and($uploads[0]->voice)->toStartWith('file://')
        ->and(is_file(substr((string) $uploads[0]->voice, 7)))->toBeTrue();
});

it('speaks the replied-to text when bare /voice is a reply (US1)', function () {
    $spy = ttsSenderSpy();

    $repliedText = 'Реплай на это сообщение должен быть озвучен';
    $message = new MessageTypeDTO(
        messageId: 21,
        date: time(),
        chat: new ChatTypeDTO(id: '777', type: ChatPropTypeEnum::PRIVATE),
        from: ttsUser(777),
        text: '/voice',
        replyToMessage: ttsPrivateMessage(777, $repliedText, messageId: 20),
    );

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)->process($message, ttsBotConfig());

    expect(ttsUploadMethodNames())->toBe(['sendVoice']);
});

it('opens the panel for bare /voice even when the reply target has no text', function () {
    $spy = ttsSenderSpy();

    $replied = new MessageTypeDTO(
        messageId: 31,
        date: time(),
        chat: new ChatTypeDTO(id: '777', type: ChatPropTypeEnum::PRIVATE),
        from: ttsUser(777),
    );
    $message = new MessageTypeDTO(
        messageId: 32,
        date: time(),
        chat: new ChatTypeDTO(id: '777', type: ChatPropTypeEnum::PRIVATE),
        from: ttsUser(777),
        text: '/voice',
        replyToMessage: $replied,
    );

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)->process($message, ttsBotConfig());

    expect(ttsUploadMethodNames())->toBe([])
        ->and(ttsTexts($spy)[0])->toContain('🎙');
});

it('serves repeated identical /voice from cache without provider calls', function () {
    $processor = ttsProcessorWithSender(VoiceCommandProcessor::class, ttsSenderSpy());

    $processor->process(ttsPrivateMessage(777, '/voice одинаковый текст', messageId: 11), ttsBotConfig());
    $processor->process(ttsPrivateMessage(777, '/voice одинаковый текст', messageId: 12), ttsBotConfig());

    expect($this->box->calls)->toBe(1)
        ->and(ttsUploadRequests())->toHaveCount(2);
});

it('sends an auto-speak companion note in private when enabled (US2)', function () {
    ModuleFactory::settings()->patch('test_bot', 777, ['auto_speak' => true]);
    $spy = ttsSenderSpy();

    ttsProcessorWithSender(AutoSpeakProcessor::class, $spy)
        ->process(ttsPrivateMessage(777, 'обычный текст без команды'), ttsBotConfig());

    expect(ttsUploadRequests())->toHaveCount(1)
        ->and(ttsTexts($spy))->toBe([]); // no echo of the original text
});

it('stays silent when auto-speak is off and ignores slash commands', function () {
    $spy = ttsSenderSpy();
    $processor = ttsProcessorWithSender(AutoSpeakProcessor::class, $spy);

    $processor->process(ttsPrivateMessage(777, 'не озвучивать'), ttsBotConfig());

    $commandUpdate = new UpdateTypeDTO(updateId: 1, message: ttsPrivateMessage(777, '/voice cmd'));

    expect(ttsUploadRequests())->toBe([])
        ->and($this->box->calls)->toBe(0)
        ->and($processor->support($commandUpdate->message, ttsBotConfig()))->toBeFalse();
});

it('refuses texts over the char cap before spending quota or provider calls', function () {
    ModuleFactory::settings()->patch('test_bot', 777, ['max_chars' => 5]);
    $spy = ttsSenderSpy();

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)
        ->process(ttsPrivateMessage(777, '/voice слишком длинный текст'), ttsBotConfig());

    expect(ttsUploadRequests())->toBe([])
        ->and($this->box->calls)->toBe(0)
        ->and(ttsTexts($spy)[0])->toContain('5');
});

it('enforces the daily quota but still serves cache hits', function () {
    ModuleFactory::settings()->patch('test_bot', 777, ['daily_quota' => 1]);
    $spy = ttsSenderSpy();
    $processor = ttsProcessorWithSender(VoiceCommandProcessor::class, $spy);

    $processor->process(ttsPrivateMessage(777, '/voice первый текст', messageId: 21), ttsBotConfig());
    $processor->process(ttsPrivateMessage(777, '/voice второй текст', messageId: 22), ttsBotConfig());
    $processor->process(ttsPrivateMessage(777, '/voice первый текст', messageId: 23), ttsBotConfig());

    expect($this->box->calls)->toBe(1)
        ->and(ttsUploadRequests())->toHaveCount(2)
        ->and(collect(ttsTexts($spy))->first(fn ($t) => str_contains((string) $t, 'лимит')))->not->toBeNull();
});

it('denies group /voice to users without manage rights (Q4/T12)', function () {
    Cache::put('tts:admins:test_bot:-100100', [], 60);

    $spy = ttsSenderSpy();

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)
        ->process(ttsGroupMessage(424242, '/voice привет'), ttsBotConfig());

    expect(ttsUploadRequests())->toBe([])
        ->and($this->box->calls)->toBe(0)
        ->and(ttsTexts($spy)[0])->toContain('админ');
});

it('renders the panel from bare /voice', function () {
    $spy = ttsSenderSpy();

    ttsProcessorWithSender(VoiceCommandProcessor::class, $spy)
        ->process(ttsPrivateMessage(777, '/voice'), ttsBotConfig());

    expect(ttsUploadRequests())->toBe([])
        ->and(ttsTexts($spy)[0])->toContain('🎙');
});

it('toggles auto-speak from the panel callback verb through the selector', function () {
    $query = new CallbackQueryTypeDTO(
        id: 'cbq1',
        from: ttsUser(777),
        chatInstance: 'ci',
        data: CallbackRoute::encode(777, CallbackRoute::VERB_AUTOSPEAK_ON),
    );

    $update = new UpdateTypeDTO(updateId: 9, callbackQuery: $query);

    $selector = new RegisteredUpdateProcessorSelector(
        serviceConfig: new TgServiceConfig,
        botSetup: app(TgBotSetupFactory::class)->create(serviceConfig: new TgServiceConfig),
        moduleEnablement: app(ModuleEnablementContract::class),
    );

    foreach ($selector->selectProcessors($update, ttsBotConfig()) as $action => $processors) {
        foreach ($processors as $processor) {
            $processor->process($update->callbackQuery, ttsBotConfig(), $action);
        }
    }

    expect(ModuleFactory::settings()->get('test_bot', 777)->autoSpeak)->toBeTrue();
});

function ttsMenuProcessor(object $spy): MenuProcessor
{
    return new MenuProcessor(
        sender: $spy,
        api: app(TgBotApiDTOClientContract::class),
        settings: ModuleFactory::settings(),
        access: ModuleFactory::access(),
        menu: ModuleFactory::menu(),
        pending: ModuleFactory::pending(),
        registry: ModuleFactory::registry(),
        voiceCatalog: new VoiceCatalog(ModuleFactory::registry()),
    );
}

function ttsCallbackQuery(string $id, int $chatId, string $verb, ?string $arg = null): CallbackQueryTypeDTO
{
    return new CallbackQueryTypeDTO(
        id: $id,
        from: ttsUser($chatId),
        chatInstance: 'ci',
        data: CallbackRoute::encode($chatId, $verb, $arg),
    );
}

it('asks for the API key when selecting a token-needing provider', function () {
    $spy = ttsSenderSpy();

    ttsMenuProcessor($spy)
        ->process(ttsCallbackQuery('cbq2', 777, CallbackRoute::VERB_SET_PROVIDER, 'openai'), ttsBotConfig());

    expect(ModuleFactory::settings()->get('test_bot', 777)->providerKey)->toBe('openai')
        ->and(ttsTexts($spy)[0])->toContain('API-ключ');
});

it('lists the static catalog in the voice picker for an OpenAI-dialect provider', function () {
    ModuleFactory::settings()->patch('test_bot', 777, ['provider_key' => 'kokoro']);

    $spy = ttsSenderSpy();

    ttsMenuProcessor($spy)
        ->process(ttsCallbackQuery('cbq3', 777, CallbackRoute::VERB_VOICE_INPUT), ttsBotConfig());

    $sent = $spy->sent[0];

    expect($sent)->toBeInstanceOf(SendMessageMethodDTO::class)
        ->and($sent->text)->toBe('Голос')
        ->and($sent->replyMarkup)->not->toBeNull()
        ->and(json_encode($sent->replyMarkup, JSON_UNESCAPED_SLASHES))->toContain('alloy')
        ->and(json_encode($sent->replyMarkup, JSON_UNESCAPED_SLASHES))->toContain('shimmer');
});

it('narrows edge-tts voices to the chat locale in the picker', function () {
    Http::fake([
        '*' => Http::response([
            ['id' => 'ru-RU-SvetlanaNeural', 'lang' => 'ru-RU'],
            ['id' => 'ru-RU-DmitryNeural', 'lang' => 'ru-RU'],
            ['id' => 'en-US-AriaNeural', 'lang' => 'en-US'],
            ['id' => 'en-US-GuyNeural', 'lang' => 'en-US'],
        ], 200),
    ]);

    $spy = ttsSenderSpy();

    ttsMenuProcessor($spy)
        ->process(ttsCallbackQuery('cbq4', 777, CallbackRoute::VERB_VOICE_INPUT), ttsBotConfig());

    $keyboardJson = json_encode($spy->sent[0]->replyMarkup, JSON_UNESCAPED_SLASHES);

    expect($keyboardJson)->toContain('ru-RU-SvetlanaNeural')
        ->and($keyboardJson)->toContain('ru-RU-DmitryNeural')
        ->and($keyboardJson)->not->toContain('en-US');
});

it('falls back to manual voice input when the edge catalog is unreachable', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        '*' => Http::response('', 500),
    ]);

    $spy = ttsSenderSpy();

    ttsMenuProcessor($spy)
        ->process(ttsCallbackQuery('cbq5', 777, CallbackRoute::VERB_VOICE_INPUT), ttsBotConfig());

    expect(ttsTexts($spy)[0])->toContain('голос')
        ->and(ModuleFactory::pending()->pop('test_bot', 777, 777)['action'])->toBe(PendingInputService::ACTION_VOICE);
});
