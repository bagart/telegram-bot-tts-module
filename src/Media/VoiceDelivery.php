<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Media;

/**
 * Telegram sendVoice semantics: OGG/OPUS, MP3, M4A qualify; anything else
 * (e.g. WAV) must be converted via ffmpeg when available, else delivered as
 * SendAudio (§3.2).
 */
enum VoiceDelivery: string
{
    /** Mime qualifies for SendVoiceMethodDTO as-is. */
    case Voice = 'voice';

    /** Mime needs ffmpeg conversion before SendVoice is possible. */
    case Convert = 'convert';

    /** Mime does not qualify; fall back to SendAudioMethodDTO. */
    case Audio = 'audio';
}
