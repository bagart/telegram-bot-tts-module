<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts;

use BAGArt\TelegramBot\Modules\TgModuleCapability;
use BAGArt\TelegramBot\Modules\TgModuleContract;
use BAGArt\TelegramBot\Modules\TgModuleDescriptor;
use BAGArt\TelegramBot\Modules\TgModuleRegistrar;
use BAGArt\TelegramBot\TgApi\Types\DTO\CallbackQueryTypeDTO;
use BAGArt\TelegramBot\TgApi\Types\DTO\MessageTypeDTO;
use BAGArt\TelegramBotTts\Processing\AutoSpeakProcessor;
use BAGArt\TelegramBotTts\Processing\MenuProcessor;
use BAGArt\TelegramBotTts\Processing\VoiceCommandProcessor;

/**
 * TTS module: turns text into voice notes. Two symmetric entry mechanics —
 * the explicit /voice command (inline argument, or bare as a reply to a
 * text) and per-chat auto-speak mode (private chats only, opt-in).
 *
 * Disabled by default (opt-in per chat via the settings panel); a dead
 * provider degrades gracefully and never takes the webhook path down.
 */
class TtsModule implements TgModuleContract
{
    public static function descriptor(): TgModuleDescriptor
    {
        return new TgModuleDescriptor(
            id: TtsModuleId::ID,
            name: 'Text to Speech',
            version: '0.1.0',
            capabilities: [
                TgModuleCapability::Processor,
                TgModuleCapability::Command,
            ],
            defaultEnabled: false,
            failClosed: false,
        );
    }

    public static function register(TgModuleRegistrar $registrar): void
    {
        $registrar->processor(
            MessageTypeDTO::class,
            AutoSpeakProcessor::class,
        );

        $registrar->processor(
            CallbackQueryTypeDTO::class,
            MenuProcessor::class,
        );

        $registrar->command(
            VoiceCommandProcessor::NAME,
            VoiceCommandProcessor::class,
        );
    }
}
