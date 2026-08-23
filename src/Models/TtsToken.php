<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Provider API token vault. The token column uses Laravel's 'encrypted'
 * cast (SummarizerToken parity, T8); decrypted values only ever leave this
 * model inside ConfigResolver and never reach logs or exceptions.
 *
 * @property string $id
 * @property string $bot_id
 * @property string $provider_key
 * @property string $token
 */
class TtsToken extends Model
{
    use HasTimestamps;
    use HasUuids;

    protected $fillable = [
        'bot_id',
        'provider_key',
        'token',
    ];

    protected $casts = [
        'token' => 'encrypted',
    ];
}
