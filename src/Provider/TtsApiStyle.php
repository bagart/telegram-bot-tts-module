<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Wire protocol family spoken by a provider. One adapter per style; a new
 * vendor is usually just another preset row (T2).
 */
enum TtsApiStyle: string
{
    /** POST {base}/audio/speech — OpenAI Text-to-speech dialect. */
    case OpenaiTts = 'openai-tts';

    /** GET /v1/voices + POST /v1/tts — self-hosted edge-tts wrapper dialect. */
    case EdgeTts = 'edge-tts';
}
