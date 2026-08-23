<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * Failure taxonomy shared by adapters. One code → one user string (§7) →
 * one metric label (§12). TitleCase keys per platform convention.
 */
enum ErrorCode: string
{
    case Auth = 'AUTH';
    case QuotaProvider = 'QUOTA_PROVIDER';
    case RateLimited = 'RATE_LIMITED';
    case BadRequest = 'BAD_REQUEST';
    case UnsupportedInput = 'UNSUPPORTED_INPUT';
    case Unavailable = 'UNAVAILABLE';
    case EmptyResult = 'EMPTY_RESULT';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
}
