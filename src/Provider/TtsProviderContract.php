<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Synthesizes text into audio bytes. Adapters translate the generic request
 * into the provider wire dialect and map every failure onto the ErrorCode
 * taxonomy — one code → one user string → one metric label.
 */
interface TtsProviderContract
{
    public function synthesize(TtsRequest $request): TtsResult;
}
