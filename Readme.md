# bagart/telegram-bot-tts-module

Text-to-speech module for the [BAGArt Telegram bot platform](../../../): turns
text into voice notes via `/voice`, plus an opt-in **auto-speak** companion mode
for private chats. RFC: `docs/tasks/todo.tts.md`.

- Module id: `tts` — disabled by default, enabled per chat/bot via `tg:module:enable tts`
- Command: `/voice текст`, reply `/voice` to a text, bare `/voice` → settings panel
- Providers: self-hosted edge-tts wrapper / Kokoro / Speaches (free) and OpenAI (paid)
- DB: 2 tables (`tts_tokens`, `tts_audio_cache`); Redis guard keys under `tts:`

## Installation (host app)

```bash
# composer.json: add path repository misc/BAGArt/telegram-bot-tts-module, then:
composer require bagart/telegram-bot-tts-module:@dev
php artisan migrate
# schedule in routes/console.php:
#   $schedule->command('tts:prune')->daily()->withoutOverlapping();
```

## Provider guide

| Preset | Cost | Key | apiStyle | Default base_url |
|---|---|---|---|---|
| `edge-tts` | free | no | `edge-tts` | `http://localhost:55000` |
| `kokoro` | free | no | `openai-tts` | `http://localhost:8880/v1` |
| `speaches` | free | no | `openai-tts` | `http://localhost:8000/v1` |
| `openai` | paid | yes | `openai-tts` | `https://api.openai.com/v1` |

Fleet-wide repointing of the edge wrapper: set `TTS_EDGE_TTS_BASE_URL`.
Custom providers are configured through the panel (`✏️ custom JSON…`);
plain http is allowed only for LAN addresses, link-local/metadata ranges are
always rejected (SSRF posture).

### edge-tts wrapper (docker-compose recipe)

```yaml
services:
  edge-tts-http:
    image: ghcr.io/travisvn/edge-tts-server:latest   # any wrapper exposing:
    ports: ["55000:55000"]                            # GET /v1/voices, POST /v1/tts {text,voice} → audio/mpeg
```

The module speaks this documented contract; swap in any wrapper that matches it.

## Track A fence

The platform transport is JSON-only, so freshly synthesized audio is uploaded
multipart **directly** by one fenced class (`src/Media/MediaUploader.php`),
bypassing queue/rate-limiter/DLQ on purpose. It enforces its own discipline:
global upload semaphore, single attempt + one transport retry, 429 honoring,
metrics. A grep-guard test (`tests/Guard/TrackAFenceTest.php`) fails CI if the
bypass leaks. Track B (multipart support in the core client) will replace it.

## Manual QA checklist

- [ ] edge-tts wrapper reachable at `TTS_EDGE_TTS_BASE_URL`; `/voice тест` returns an OGG voice note
- [ ] repeat `/voice тест` makes zero provider calls (cache hit)
- [ ] Kokoro container: wav response → ffmpeg converts (or falls back to SendAudio without ffmpeg)
- [ ] OpenAI preset: paste token → speak → revoke key → AUTH failure text shown
- [ ] quota: lower `daily_quota`, exceed it → refusal text, `tts:qblocked` counter grows
- [ ] kill Redis mid-traffic: quotas refuse (fail-closed), everything else stays usable
- [ ] `php artisan tts:doctor` green; `tts:prune` removes stale cache/files

## Development

```bash
cd misc/BAGArt/telegram-bot-tts-module && composer test   # phpunit suite
```
