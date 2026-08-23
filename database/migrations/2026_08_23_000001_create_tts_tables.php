<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TTS module schema (RFC §11): provider token vault + synthesis cache
 * metadata. Redis guard keys are runtime class — loss acceptable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tts_tokens', function (Blueprint $table) {
            // Secrets: dr.md class "Configuration/secrets" (RPO ≤24h, RTO ≤2h)
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            $table->string('provider_key', 64);
            // Stored with Laravel 'encrypted' cast; never returned in full via API/UI
            $table->text('token');
            $table->timestamps();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'provider_key']);
        });

        Schema::create('tts_audio_cache', function (Blueprint $table) {
            // Derived data only (loss harmless; rebuilt on demand)
            $table->uuid('id')->primary();
            $table->string('bot_id', 20);
            // sha1(provider|voice|normalized-text)
            $table->string('cache_key', 64);
            $table->string('provider_key', 64);
            $table->string('voice', 128)->nullable();
            $table->unsignedInteger('chars');
            $table->string('mime', 64);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('use_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('bot_id')
                ->references('bot_id')->on('tg_bots')
                ->cascadeOnDelete();

            $table->unique(['bot_id', 'cache_key']);
            $table->index(['bot_id', 'last_used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tts_audio_cache');
        Schema::dropIfExists('tts_tokens');
    }
};
