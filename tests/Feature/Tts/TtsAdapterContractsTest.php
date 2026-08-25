<?php

declare(strict_types=1);

use BAGArt\TelegramBotTts\Provider\Adapter\EdgeTts;
use BAGArt\TelegramBotTts\Provider\Adapter\OpenAiCompatibleTts;
use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\ErrorCode;
use BAGArt\TelegramBotTts\Provider\ProviderException;
use BAGArt\TelegramBotTts\Provider\TtsApiStyle;
use BAGArt\TelegramBotTts\Provider\VoiceProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/*
 * Adapter wire contracts (todo.tts.md §14): exact JSON body/auth headers,
 * response-shape errors mapped onto the ErrorCode taxonomy, single 429
 * retry, size caps. Sleep is faked so retry backoffs stay instant.
 */

beforeEach(function () {
    Sleep::fake();
});

function ttsAdapterOpenAiConfig(array $overrides = []): VoiceProviderConfig
{
    return new VoiceProviderConfig(...array_merge([
        'key' => 'openai',
        'apiStyle' => TtsApiStyle::OpenaiTts,
        'baseUrl' => 'https://provider.test',
        'token' => 'sk-secret',
        'model' => 'tts-1',
    ], $overrides));
}

function ttsAdapterEdgeConfig(array $overrides = []): VoiceProviderConfig
{
    return new VoiceProviderConfig(...array_merge([
        'key' => 'edge-tts',
        'apiStyle' => TtsApiStyle::EdgeTts,
        'baseUrl' => 'https://edge.test',
    ], $overrides));
}

function ttsAdapterRequest(VoiceProviderConfig $config): TtsRequest
{
    return new TtsRequest(text: 'Привет, мир', config: $config, voice: null, languageHint: null);
}

it('posts the exact openai-tts body with a bearer token and maps the binary reply', function () {
    Http::fake([
        'provider.test/*' => Http::response('MP3BYTES', 200, ['Content-Type' => 'audio/mpeg; charset=binary']),
    ]);

    $result = (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://provider.test/audio/speech'
            && $request['model'] === 'tts-1'
            && $request['input'] === 'Привет, мир'
            && $request['voice'] === 'alloy'
            && $request['response_format'] === 'mp3'
            && $request->hasHeader('Authorization', 'Bearer sk-secret');
    });

    expect($result->binary)->toBe('MP3BYTES')
        ->and($result->mimeType)->toBe('audio/mpeg')
        ->and($result->providerKey)->toBe('openai')
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);
});

it('sends openai-tts requests without an authorization header when no token is set', function () {
    Http::fake([
        'provider.test/*' => Http::response('MP3', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    (new OpenAiCompatibleTts())->synthesize(
        ttsAdapterRequest(ttsAdapterOpenAiConfig(['token' => null])),
    );

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

it('uses the configured voice over the provider default in openai-tts requests', function () {
    Http::fake([
        'provider.test/*' => Http::response('MP3', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $request = new TtsRequest(
        text: 'текст',
        config: ttsAdapterOpenAiConfig(),
        voice: 'nova',
        languageHint: null,
    );

    (new OpenAiCompatibleTts())->synthesize($request);

    Http::assertSent(fn ($request) => $request['voice'] === 'nova');
});

it('refuses openai-tts synthesis without a configured model before any http call', function () {
    Http::fake();

    try {
        (new OpenAiCompatibleTts())->synthesize(
            ttsAdapterRequest(ttsAdapterOpenAiConfig(['model' => null])),
        );
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::BadRequest);
    }

    Http::assertNothingSent();
});

it('maps openai-tts status codes onto the taxonomy codes', function (int $status, ErrorCode $expected) {
    Http::fake([
        'provider.test/*' => Http::response('nope', $status),
    ]);

    try {
        (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe($expected);
    }
})->with([
    'unauthorized' => [401, ErrorCode::Auth],
    'forbidden' => [403, ErrorCode::Auth],
    'bad request' => [400, ErrorCode::UnsupportedInput],
    'unprocessable' => [422, ErrorCode::UnsupportedInput],
    'not found' => [404, ErrorCode::BadRequest],
    'server error' => [500, ErrorCode::Unavailable],
]);

it('rejects an empty openai-tts body as EMPTY_RESULT', function () {
    Http::fake([
        'provider.test/*' => Http::response('', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    try {
        (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::EmptyResult);
    }
});

it('rejects oversized openai-tts bodies as PAYLOAD_TOO_LARGE', function () {
    Http::fake([
        'provider.test/*' => Http::response(str_repeat('x', 64), 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    try {
        (new OpenAiCompatibleTts())->synthesize(
            ttsAdapterRequest(ttsAdapterOpenAiConfig(['maxResponseBytes' => 8])),
        );
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::PayloadTooLarge);
    }
});

it('retries an openai-tts 429 once and succeeds on the second attempt', function () {
    Http::fake([
        'provider.test/*' => Http::sequence()
            ->push('slow down', 429, ['Retry-After' => '7'])
            ->push('MP3', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $result = (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));

    expect($result->binary)->toBe('MP3');
    Http::assertSentCount(2);
});

it('gives up as RATE_LIMITED after the second openai-tts 429', function () {
    Http::fake([
        'provider.test/*' => Http::response('slow down', 429, ['Retry-After' => '45']),
    ]);

    try {
        (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::RateLimited);
    }

    Http::assertSentCount(2);
});

it('fails as UNAVAILABLE when the openai-tts endpoint is unreachable twice', function () {
    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    try {
        (new OpenAiCompatibleTts())->synthesize(ttsAdapterRequest(ttsAdapterOpenAiConfig()));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::Unavailable);
    }
});

it('posts the exact edge-tts body with the default voice and maps the binary reply', function () {
    Http::fake([
        'edge.test/*' => Http::response('MP3EDGE', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    $result = (new EdgeTts())->synthesize(ttsAdapterRequest(ttsAdapterEdgeConfig()));

    Http::assertSent(function ($request) {
        return $request->url() === 'https://edge.test/v1/tts'
            && $request['text'] === 'Привет, мир'
            && $request['voice'] === 'ru-RU-SvetlanaNeural';
    });

    expect($result->binary)->toBe('MP3EDGE')
        ->and($result->mimeType)->toBe('audio/mpeg');
});

it('maps edge-tts auth rejection onto the AUTH code', function () {
    Http::fake([
        'edge.test/*' => Http::response('denied', 403),
    ]);

    try {
        (new EdgeTts())->synthesize(ttsAdapterRequest(ttsAdapterEdgeConfig(['token' => 'wrapped-token'])));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::Auth);
    }

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer wrapped-token'));
});

it('gives up as QUOTA_PROVIDER after repeated edge-tts throttling', function () {
    Http::fake([
        'edge.test/*' => Http::response('throttled', 429, ['Retry-After' => '1']),
    ]);

    try {
        (new EdgeTts())->synthesize(ttsAdapterRequest(ttsAdapterEdgeConfig()));
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::QuotaProvider);
    }

    Http::assertSentCount(2);
});

it('parses the edge-tts voice catalog and skips malformed entries', function () {
    Http::fake([
        'edge.test/*' => Http::response([
            ['id' => 'ru-RU-SvetlanaNeural', 'lang' => 'ru-RU', 'gender' => 'Female'],
            'garbage',
            ['lang' => 'ru-RU'],
            ['id' => 'en-US-AriaNeural'],
        ], 200),
    ]);

    $voices = (new EdgeTts())->voices('https://edge.test/', 5);

    expect($voices)->toBe([
        ['id' => 'ru-RU-SvetlanaNeural', 'lang' => 'ru-RU', 'gender' => 'Female'],
        ['id' => 'en-US-AriaNeural', 'lang' => null, 'gender' => null],
    ]);
});

it('surfaces edge-tts catalog failures as UNAVAILABLE and empty bodies as EMPTY_RESULT', function () {
    $adapter = new EdgeTts();

    Http::fake([
        'edge.test/*' => Http::sequence()
            ->push('', 500)
            ->push('not json', 200),
    ]);

    try {
        $adapter->voices('https://edge.test', 5);
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::Unavailable);
    }

    try {
        $adapter->voices('https://edge.test', 5);
        $this->fail('Expected ProviderException');
    } catch (ProviderException $e) {
        expect($e->errorCode)->toBe(ErrorCode::EmptyResult);
    }
});
