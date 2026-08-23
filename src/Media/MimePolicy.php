<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

final class MimePolicy
{
    private const VOICE_QUALIFIED = [
        'audio/ogg',
        'application/ogg',
        'audio/opus',
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/m4a',
        'audio/aac',
    ];

    private const CONVERTIBLE = [
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
        'audio/flac',
        'audio/x-flac',
        'audio/aiff',
    ];

    public static function deliveryFor(string $mimeType): VoiceDelivery
    {
        $mime = strtolower(trim(explode(';', $mimeType)[0]));

        if (in_array($mime, self::VOICE_QUALIFIED, true)) {
            return VoiceDelivery::Voice;
        }

        if (in_array($mime, self::CONVERTIBLE, true)) {
            return VoiceDelivery::Convert;
        }

        return VoiceDelivery::Audio;
    }

    public static function extensionFor(string $mimeType): string
    {
        return match (strtolower(trim(explode(';', $mimeType)[0]))) {
            'audio/ogg', 'application/ogg', 'audio/opus' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/aac' => 'm4a',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/flac', 'audio/x-flac' => 'flac',
            default => 'bin',
        };
    }
}
