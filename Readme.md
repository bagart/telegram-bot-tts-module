# bagart/telegram-bot-tts-module

Text-to-speech module for the [BAGArt Telegram bot platform](../../../): turns
text into voice notes via `/voice`, plus an opt-in **auto-speak** companion mode
for private chats. RFC: `docs/tasks/todo.tts.md`.

- Module id: `tts` — disabled by default, enabled per chat/bot via `tg:module:enable tts`
- Command: `/voice текст`, reply `/voice` to a text, bare `/voice` → settings panel
- Providers: self-hosted edge-tts wrapper / Kokoro / Speaches (free) and OpenAI (paid)
- DB: 2 tables (`tts_tokens`, `tts_audio_cache`); Redis guard keys under `tts:`

## Installation (host app)

Dev mode (this monorepo): wired via root PSR-4 mapping + path repository;
provider listed in `bootstrap/providers.php` — no `composer require` needed.

```bash
php artisan migrate
# schedule in routes/console.php:
#   $schedule->command('tts:prune')->daily()->withoutOverlapping();
```

Prod mode (servers): `cmd/deps/install --mode=prod` resolves
`bagart/telegram-bot-tts-module` from VCS sources via `composer.prod.json`.

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

## Voice delivery (Track B, current)

Voice notes travel through the **standard transport**: `MediaUploader` builds
a `SendVoiceMethodDTO`/`SendAudioMethodDTO` whose media field carries the
synthesized tmpfile as a `file://` path; the core client
(`TgBotApiDTOClient`) splits that field into `ASKHttpRequest::$files` at the
send point and the transport uploads it as multipart/form-data. The uploader
keeps its own discipline: global upload semaphore shared with the synthesis
budget, single attempt + one transient retry, 429 Retry-After honoring,
metrics. An anti-regression test (`tests/Guard/TrackAFenceTest.php`) fails CI
if direct api.telegram.org access reappears in module src/.

History: before Track B landed, delivery bypassed the core client entirely
("Track A" fenced multipart via Laravel Http) because the platform transport
was JSON-only.

## Bench baseline

`tts:bench --bot=… --count=10` (no Telegram upload). Record p50/p95 here per provider:

| Provider | Date | n | p50 | p95 | ok% |
|---|---|---|---|---|---|
| edge-tts | 2026-08-24 | 10 | 580 ms | 2149 ms | 100% |

SLO gate (todo.tts.md §7): ≥97% ok, p95 ≤25 s.

## Manual QA checklist

- [ ] edge-tts wrapper reachable at `TTS_EDGE_TTS_BASE_URL`; `/voice тест` returns an OGG voice note
- [ ] repeat `/voice тест` makes zero provider calls (cache hit)
- [ ] `/voice` → Голос: picker lists locale voices (edge) or the static catalog (OpenAI-dialect); «Ввести вручную» falls back to text input
- [ ] Kokoro container: wav response → ffmpeg converts (or falls back to SendAudio without ffmpeg)
- [ ] OpenAI preset: paste token → speak → revoke key → AUTH failure text shown
- [ ] quota: lower `daily_quota`, exceed it → refusal text, `tts:qblocked` counter grows
- [ ] kill Redis mid-traffic: quotas refuse (fail-closed), everything else stays usable
- [ ] `php artisan tts:doctor` green; `tts:prune` removes stale cache/files

## Development

```bash
cd misc/BAGArt/telegram-bot-tts-module && composer test   # phpunit suite
```
