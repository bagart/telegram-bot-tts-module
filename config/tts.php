<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| TTS Module
|--------------------------------------------------------------------------
|
| Text-to-speech module (bagart/telegram-bot-tts-module). Per-chat settings
| live in tg_module_enablements.module_settings; these are platform
| defaults and operational limits.
|
*/

return [
    // Telegram user ids allowed to manage any chat's panel: "111,222"
    'superadmins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TTS_SUPERADMIN_TG_IDS', '')),
    ))),

    // Total wall-clock budget for one /voice request (§7); watchdog aborts
    // with UNAVAILABLE when exceeded.
    'budget_seconds' => (int) env('TTS_BUDGET_SECONDS', 30),

    // Global in-flight synthesis + upload cap (shared counter)
    'global_concurrency' => (int) env('TTS_GLOBAL_CONCURRENCY', 4),

    // ffmpeg binary for non-voice mime conversion; empty = autodetect PATH
    'ffmpeg_path' => env('TTS_FFMPEG_PATH', ''),

    // Provider call limits
    'timeout_seconds' => (int) env('TTS_TIMEOUT_SECONDS', 25),
    'max_response_bytes' => (int) env('TTS_MAX_RESPONSE_BYTES', 8388608),

    // Where synthesized audio lives between synthesis and eviction
    'storage_path' => env('TTS_STORAGE_PATH', storage_path('framework/tts')),

    // Cache rows / disk files untouched longer than this are pruned
    'retention_days' => (int) env('TTS_RETENTION_DAYS', 30),

    // Pending text-input lifetime in seconds (token paste, custom JSON…)
    'pending_input_ttl_seconds' => (int) env('TTS_PENDING_INPUT_TTL', 900),

    // Per-preset base_url overrides (e.g. fleet-wide edge wrapper repoint)
    'presets' => [
        'edge-tts' => [
            'base_url' => env('TTS_EDGE_TTS_BASE_URL', 'http://localhost:55000'),
        ],
    ],
];
