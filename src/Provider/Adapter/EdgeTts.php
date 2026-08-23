<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider\Adapter;

use BAGArt\TelegramBotTts\Provider\Dto\TtsRequest;
use BAGArt\TelegramBotTts\Provider\Dto\TtsResult;
use BAGArt\TelegramBotTts\Provider\ErrorCode;
use BAGArt\TelegramBotTts\Provider\ProviderException;
use BAGArt\TelegramBotTts\Provider\TtsProviderContract;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Self-hosted edge-tts wrapper dialect driver (our documented contract):
 *   GET  /v1/voices → [{id,lang,gender}…]
 *   POST /v1/tts {text,voice,rate,pitch} → 200 audio/mpeg (MP3)
 *
 * HTTP discipline mirrors OpenAiCompatibleTts: MAX_ATTEMPTS=2, Retry-Afte
 * cap 30 s, connect timeout 10 s, size-capped body, token never logged.
 * Reference docker-compose recipe lives in the module Readme.
 */
class EdgeTts implements TtsProviderContract
{
    private const MAX_ATTEMPTS = 2;

    private const RETRY_DELAY_MS = 1500;

    private const RETRY_AFTER_CAP_SECONDS = 30;

    public function synthesize(TtsRequest $request): TtsResult
    {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->attempt($request);
            } catch (RateLimitedException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::QuotaProvider, 'edge-tts throttled the request', $e);
                }

                Sleep::for(min(max(1, $e->retryAfterSeconds), self::RETRY_AFTER_CAP_SECONDS))->seconds();
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ProviderException(ErrorCode::Unavailable, 'edge-tts connection failed', $e);
                }

                Sleep::for(self::RETRY_DELAY_MS)->milliseconds();
            } catch (ProviderException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new ProviderException(ErrorCode::Unavailable, 'edge-tts call failed: '.$e::class, $e);
            }
        }
    }

    /**
     * Voice catalog for the settings panel voice picker. Failures surface as
     * UNAVAILABLE so the menu can show a graceful message instead of dying.
     *
     * @return list<array{id: string, lang?: string, gender?: string}>
     */
    public function voices(string $baseUrl, int $timeoutSec): array
    {
        try {
            $response = Http::baseUrl(rtrim($baseUrl, '/'))
                ->connectTimeout(10)
                ->timeout($timeoutSec)
                ->get('/v1/voices');
        } catch (ConnectionException $e) {
            throw new ProviderException(ErrorCode::Unavailable, 'edge-tts connection failed', $e);
        }

        if ($response->failed()) {
            throw new ProviderException(ErrorCode::Unavailable, 'edge-tts voices HTTP '.$response->status());
        }

        $data = json_decode((string) $response->body(), true);

        if (! is_array($data)) {
            throw new ProviderException(ErrorCode::EmptyResult, 'edge-tts returned no voice catalog');
        }

        $voices = [];

        foreach ($data as $entry) {
            if (is_array($entry) && is_string($entry['id'] ?? null)) {
                $voices[] = [
                    'id' => $entry['id'],
                    'lang' => isset($entry['lang']) ? (string) $entry['lang'] : null,
                    'gender' => isset($entry['gender']) ? (string) $entry['gender'] : null,
                ];
            }
        }

        return $voices;
    }

    private function attempt(TtsRequest $request): TtsResult
    {
        $config = $request->config;
        $http = Http::baseUrl($config->baseUrl)
            ->connectTimeout($config->connectTimeoutSec)
            ->timeout($config->timeoutSec)
            ->asJson();

        if ($config->token !== null && $config->token !== '') {
            $http = $http->withToken($config->token);
        }

        $startedAt = microtime(true);
        $response = $http->post('/v1/tts', [
            'text' => $request->text,
            'voice' => $request->voice ?? $config->voice ?? 'ru-RU-SvetlanaNeural',
        ]);
        $latencyMs = (int) ((microtime(true) - $startedAt) * 1000);

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ProviderException(ErrorCode::Auth, 'edge-tts rejected credentials');
        }

        if ($response->status() === 429) {
            throw new RateLimitedException(max(1, (int) $response->header('retry-after')));
        }

        if ($response->status() === 400 || $response->status() === 422) {
            throw new ProviderException(ErrorCode::UnsupportedInput, 'edge-tts rejected the request body');
        }

        if ($response->failed()) {
            throw new ProviderException(ErrorCode::Unavailable, 'edge-tts error HTTP '.$response->status());
        }

        $body = (string) $response->body();

        if ($body === '') {
            throw new ProviderException(ErrorCode::EmptyResult, 'edge-tts returned an empty body');
        }

        if (strlen($body) > $config->maxResponseBytes) {
            throw new ProviderException(ErrorCode::PayloadTooLarge, 'edge-tts response exceeds size limit');
        }

        return new TtsResult(
            binary: $body,
            mimeType: OpenAiCompatibleTts::normalizeMime((string) $response->header('Content-Type')),
            providerKey: $config->key,
            latencyMs: $latencyMs,
        );
    }
}
