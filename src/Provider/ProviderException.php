<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Provider;

/**
 * The only exception type adapters are allowed to throw. Carries a taxonomy
 * code; the message is operator-facing diagnostics only and must neve
 * contain the vault token.
 */
class ProviderException extends \RuntimeException
{
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
