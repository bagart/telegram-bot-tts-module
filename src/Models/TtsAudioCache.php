<?php

declare(strict_types=1);

namespace BAGArt\TelegramBotTts\Models;

use Illuminate\Database\Eloquent\Concerns\HasTimestamps;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Synthesis cache metadata row (derived data only — loss harmless, the
 * binary is re-synthesized on demand; audio blobs are deliberately NOT
 * persisted). No usage-history rows by design (D-T4): this cache IS the
 * persistence.
 *
 * @property string $id
 * @property string $bot_id
 * @property string $cache_key
 * @property string $provider_key
 * @property string|null $voice
 * @property int $chars
 * @property string $mime
 * @property int $size_bytes
 * @property int|null $latency_ms
 * @property int $use_count
 * @property Carbon|null $last_used_at
 */
class TtsAudioCache extends Model
{
    use HasTimestamps;
    use HasUuids;

    protected $fillable = [
        'bot_id',
        'cache_key',
        'provider_key',
        'voice',
        'chars',
        'mime',
        'size_bytes',
        'latency_ms',
        'last_used_at',
    ];

    protected $casts = [
        'chars' => 'integer',
        'size_bytes' => 'integer',
        'latency_ms' => 'integer',
        'use_count' => 'integer',
        'last_used_at' => 'timestamp',
    ];
}
