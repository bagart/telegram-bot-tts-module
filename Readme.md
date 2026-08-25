# bagart/telegram-bot-tts-module

Text-to-speech module for the [BAGArt Telegram bot platform](../../../): turns
text into Telegram voice notes via `/voice`, plus an opt-in **auto-speak**
companion mode for private chats. Disabled by default; opt-in per bot/chat.

- Module id: `tts` · composer: `bagart/telegram-bot-tts-module` ·
  PSR-4: `BAGArt\TelegramBotTts\`
- Command: `/voice текст`, reply `/voice` to a text, bare `/voice` → settings panel
- Providers: self-hosted edge-tts wrapper / Kokoro / Speaches (free) and OpenAI (paid);
  two wire drivers (`edge-tts`, `openai-tts`) cover the whole catalog — a new vendor is a preset row
- DB: 2 tables (`tts_tokens`, `tts_audio_cache`); Redis guard keys under `tts:`

---

## 1. User experience

```
Explicit:  "/voice Перезвоню через час"   → voice note, caption = source text
           reply "/voice" to a text       → voice note of that text
Auto:      incoming private text          → companion voice note (opt-in)
Panel:     bare "/voice"                  → settings panel (private = own settings,
                                            groups = admins only, Q4/T12)
```

- `/voice` with no argument and no text reply opens the panel instead of guessing.
- Caption policy (`caption` setting): `none | original | truncated` (≤1024 chars,
  `SendVoiceMethodDTO` cap). Default `original`.
- Failure surface per error code (`on_error` setting): `silent | emoji (😕) |
  message`; `auto` resolves to emoji in groups, full message in private chats.
  One taxonomy code → one localized string → one metric label:
  `AUTH, QUOTA_PROVIDER, RATE_LIMITED, BAD_REQUEST, UNSUPPORTED_INPUT,
  UNAVAILABLE, EMPTY_RESULT, PAYLOAD_TOO_LARGE`.

### Settings panel

Callback grammar `"tv:<chatId>:<verb>[:arg]"` mirrors the Summarizer
`CallbackRoute`: verb `^[a-z]{1,6}$`, 64-byte `callback_data` cap, `chatId`
cross-checked against the actual callback chat. Verbs: panel render (`m`),
auto-speak toggle (`son`/`soff`), provider select (`ptts`), provider test
(`tst`), custom-provider JSON form (`pjc`), voice picker/input (`voc`),
caption (`cap`), error mode (`err`), close (`x`). Text inputs (custom JSON,
token paste, voice name) use a cache-backed pending-input store with native
TTL (`tts.pending_input_ttl_seconds`, default 900 s). Access: groups use the
Summarizer `canManage()` semantics (superadmin / inviter / admin with delete
rights); in private chats the peer user manages their own settings.

## 2. Installation (host app)

Dev mode (this monorepo): wired via root PSR-4 mapping + path repository;
the Laravel provider is listed in `bootstrap/providers.php` — no
`composer require` needed. Prod mode (servers): `cmd/deps/install --mode=prod`
resolves versioned packages from VCS via `composer.prod.json`.

```bash
php artisan migrate        # tts_tokens, tts_audio_cache
# host routes/console.php schedules:
#   $schedule->command('tts:prune')->daily()->withoutOverlapping()
#       ->when(config('tts.schedule_prune_enabled', true));
```

Enable per bot/chat: `php artisan tg:module:enable tts --bot=<bot_id>`.
Rollback = `tg:module:disable tts` (instant, cache-busted; tables persist harmlessly).

### Running without a public webhook (local dev)

The webhook path needs a public HTTPS URL. For device testing over
**long-polling** use the module-aware poller (boots the Laravel kernel, so all
bootloaded modules + enablement filtering apply):

```sh
docker exec -e TELEGRAM_BOT_TOKEN=… <php-fpm-container> sh -c \
  'cd /var/www && php misc/BAGArt/telegram-bot-lib/commands/debug/poller-modules.php'
```

Do NOT use `commands/poller-daemon.php` for modules — it overrides the
processor registry with lib demo processors, consumes updates silently and
never runs `/voice`.

**Platform contract:** every processor that must survive polling needs an
`executionKey()` method — `TgPollerDaemon` calls it unguarded (the webhook
sync path never does). Use the lib's `TgProcessorDefaultTrait` (defaults:
`executionKey() => null`, `isStrictOrdered() => false`, `isNeedUpdateDTO() =>
false`); all three TTS processors do.

## 3. Providers

| Preset | Cost | Key | apiStyle | Default base_url |
|---|---|---|---|---|
| `edge-tts` | free | no | `edge-tts` | `http://localhost:55000` |
| `kokoro` | free | no | `openai-tts` | `http://localhost:8880/v1` |
| `speaches` | free | no | `openai-tts` | `http://localhost:8000/v1` |
| `openai` | paid | yes | `openai-tts` | `https://api.openai.com/v1` |

Wire specs:

```
edge-tts      GET  /v1/voices → [{id,lang,gender}…]
              POST /v1/tts {text,voice} → 200 audio/mpeg
openai-tts    POST {base}/audio/speech {model,input,voice,response_format} → 200 binary audio
```

Fleet-wide repointing of the edge wrapper: `TTS_EDGE_TTS_BASE_URL`.
Custom providers are configured through the panel (`✏️ custom JSON…`);
plain http is allowed only for LAN addresses, link-local/metadata ranges are
always rejected (SSRF posture). Vault tokens live encrypted at rest
(`tts_tokens.token`, Eloquent `encrypted` cast), decrypted only inside
`ConfigResolver`, never logged/serialized into metrics.

### edge-tts wrapper (docker-compose recipe)

```yaml
services:
  edge-tts-http:
    image: ghcr.io/travisvn/edge-tts-server:latest   # any wrapper exposing:
    ports: ["55000:55000"]                            # GET /v1/voices, POST /v1/tts {text,voice} → audio/mpeg
```

The module speaks this documented contract; swap in any wrapper that matches it.
HTTP discipline for both adapters: MAX_ATTEMPTS=2 (single retry), Retry-After
capped at 30 s, connect timeout 10 s, size-capped bodies.

## 4. Pipeline & budgets

Sync execution inside the update dispatcher (same trade-off as the Summarizer).
Step machine: normalize input → char cap → cache lookup → daily quota →
per-chat semaphore + global concurrency → breaker gate → provider synthesis →
persist bytes → bookkeeping (cache row, metrics, one-time third-party notice)
→ deliver → finally release semaphores / unlink tmpfile.

| Step | Cap |
|---|---|
| cache lookup + settings | <50 ms |
| provider call | ≤25 s (`tts.timeout_seconds`) |
| ffmpeg convert | ≤5 s |
| multipart upload | ≤8 s |
| **total request** | **≤30 s** (`tts.budget_seconds`, watchdog aborts UNAVAILABLE) |

Repeat requests are served from the file+row cache
(`sha1(provider|voice|normalized-text)`); cache hits consume zero quota and
zero provider calls, and bump `use_count`/`last_used_at`.

### Voice delivery (Track B)

Voice notes travel through the **standard transport**: `MediaUploader` builds
a `SendVoiceMethodDTO`/`SendAudioMethodDTO` whose media field carries the
synthesized tmpfile as a `file://` path; the core client splits that field
into `ASKHttpRequest::$files` at the send point and the transport uploads it
as multipart/form-data (socket/amp transports ship the inline serializer since
`php-async-kernel-client` v0.1.1). The uploader keeps its own discipline:
global upload semaphore shared with the synthesis budget, single attempt +
one transient retry, 429 Retry-After honoring, upload metrics.

Mime branching (`MimePolicy`): OGG/OPUS/MP3/M4A/AAC → SendVoice as-is;
WAV/FLAC/AIFF → ffmpeg converts to OGG/Opus when available (`tts.ffmpeg_path`,
autodetected from PATH otherwise), else falls back to SendAudio. Synthesized
bytes live in `tts.storage_path` (mode 0600), deleted after delivery or on
failure; binaries are never stored in the DB.

History: before Track B landed, delivery bypassed the core client entirely
("Track A" fenced multipart via Laravel Http) because the platform transport
was JSON-only. The anti-regression guard (`tests/Guard/TrackAFenceTest.php`)
fails CI if direct api.telegram.org access reappears in module src/.

## 5. Abuse protection (Redis-degraded matrix)

Guards are Redis-backed readonly counters under the `tts:` prefix
(`RedisGuardStore`). Each declares its failure mode:

| Guard | Healthy | Redis down |
|---|---|---|
| `QuotaCounter` (`INCR …:{Ymd}`, TTL 48 h) | enforce `daily_quota` | **fail-closed** — TTS generates outbound media, egress risk differs from STT free reads |
| `ChatSemaphore` (`SET NX PX 60000`) | 1 in-flight per chat | fail-open (proceed; quota+caps still bound) |
| `GlobalConcurrencyLimiter` (cap `tts.global_concurrency`) | enforce | fail-open (FPM pool backstop) |
| `ProviderBreaker` (5 fails ⇒ open 60 s ⇒ half-open probe) | enforce | treated closed + logged |

## 6. Privacy & security

- Third-party notice: first synthesis in a chat announces "текст отправляется
  провайдеру <title>"; once per chat (`notice_shown`), suppressible by admins.
- Tokens never appear in logs, exceptions, DTO dumps or metrics labels.
- SSRF: preset base_urls are code-reviewed constants; custom JSON validates
  scheme + DNS and rejects link-local/metadata ranges (LAN http allowed only
  with explicit self-hosted flag).
- Retention: `tts:prune` removes cache rows and disk files untouched for
  `tts.retention_days` (default 30); Redis keys carry their own TTLs.
- Callback verbs carry chatId, cross-checked against the actual callback chat
  plus the access gate; settings writable only through gated menu verbs.

DB (module-owned migrations): `tts_tokens` — secrets, DR class
"configuration/secrets"; `tts_audio_cache` — derived metadata only
(provider/voice/chars/mime/size/latency/use_count/last_used_at), loss harmless,
rebuilt on demand. No audio blobs, no usage-history rows by design.

## 7. Observability & ops

Prometheus-style series rendered into the host `/health/metrics`:
`tts_total{bot_id,provider,status}`, `tts_quota_blocked_total{bot_id}`,
`tts_breaker{provider}` (0/1/2), `tts_latency_bucket{provider,le}`,
`tts_upload_total{bot_id,status}`, `tts_provider_failures_last{provider}`.

SLO: success ≥97% weekly, total p95 ≤25 s; provider latency is intentional
work, excluded from platform worker SLIs.

Commands:

```bash
php artisan tts:doctor [--bot=…]   # migrations · ffmpeg · presets sanity (+ --net reachability)
                                   # vault token presence · breaker states · redis reachable
                                   # failure counts, budget violations, Track A fence intact
php artisan tts:bench --bot=… [--count=10] [--provider=…]   # latency benchmark, no Telegram upload
php artisan tts:prune              # retention sweep (scheduled daily)
```

## 8. Configuration reference (`config/tts.php`)

| Key / env | Default | Meaning |
|---|---|---|
| `superadmins` / `TTS_SUPERADMIN_TG_IDS` | empty | comma-separated TG ids allowed to manage any chat panel |
| `budget_seconds` / `TTS_BUDGET_SECONDS` | 30 | wall-clock watchdog per request |
| `global_concurrency` / `TTS_GLOBAL_CONCURRENCY` | 4 | shared synthesis+upload in-flight cap |
| `ffmpeg_path` / `TTS_FFMPEG_PATH` | autodetect | ffmpeg binary for convertible mimes |
| `timeout_seconds` / `TTS_TIMEOUT_SECONDS` | 25 | provider call cap |
| `max_response_bytes` / `TTS_MAX_RESPONSE_BYTES` | 8388608 | provider response size cap |
| `storage_path` / `TTS_STORAGE_PATH` | `storage/framework/tts` | synthesized tmpfiles (0600) |
| `retention_days` / `TTS_RETENTION_DAYS` | 30 | prune age for rows/files |
| `pending_input_ttl_seconds` / `TTS_PENDING_INPUT_TTL` | 900 | panel text-input lifetime |
| `schedule_prune_enabled` / `SCHEDULE_TTS_PRUNE_ENABLED` | true | host schedule gate |
| `presets.edge-tts.base_url` / `TTS_EDGE_TTS_BASE_URL` | `http://localhost:55000` | fleet-wide wrapper repoint |

Per-chat settings (`tg_module_enablements.module_settings`, inherited
platform → bot → chat, transactional upsert + cache bust):

| Key | Type | Default |
|---|---|---|
| `auto_speak` | bool | false (private texts only) |
| `provider_key` | string | `edge-tts` (unknown key ⇒ BAD_REQUEST surface) |
| `voice` | ?string ≤128 | null = provider default |
| `caption` | enum | `original` (`none`\|`original`\|`truncated`) |
| `max_chars` | int 1–4000 | 1000 |
| `on_error` | enum | `auto` (`silent`\|`emoji`\|`message`\|`auto`) |
| `daily_quota` | int 0–10000 | 50 (0 = unlimited) |
| `locale` | enum | `ru` (`ru`\|`en`) |
| `notice_shown` | bool | false (internal) |

## 9. Testing

Suites are Pest; run from the repo root (host boots Laravel) or module dir:

```bash
vendor/bin/pest misc/BAGArt/telegram-bot-tts-module/tests          # from monorepo root
cd misc/BAGArt/telegram-bot-tts-module && composer test            # module-local
vendor/bin/pint --format agent misc/BAGArt/telegram-bot-tts-module # style
```

| Suite | Covers |
|---|---|
| `Unit/*` | callback grammar edges, settings clamps, registry preset/custom validation, cache-key normalization, guards matrix (quota fail-closed, semaphores/breaker fail-open), mime classification, file store hygiene |
| `Feature/Tts/TtsAdapterContractsTest` | adapter wire contracts: exact bodies + Bearer, status→taxonomy mapping (401/403/400/404/429/500), 429 retry-once, size caps, empty body, edge voices catalog shapes |
| `Feature/Tts/TtsModuleE2ETest` | webhook-shaped flows: arg/reply/bare-panel, auto-speak on/off, char cap, quota + cache-hit interplay, group denial (Q4/T12), panel verbs incl. key ask + locale-narrowed picker + manual fallback, wav→ogg ffmpeg convert vs SendAudio fallback, AUTH/silent/emoji surfaces |
| `Guard/TrackAFenceTest` | no direct api.telegram.org access outside the standard client path |

## 10. Bench baseline

`tts:bench --bot=… --count=N` (no Telegram upload):

| Provider | Date | n | p50 | p95 | ok% |
|---|---|---|---|---|---|
| edge-tts | 2026-08-24 | 10 | 580 ms | 2149 ms | 100% |
| edge-tts | 2026-08-25 | 3 | 787 ms | 2188 ms | 100% |

SLO gate: ≥97% ok, p95 ≤25 s.

## 11. Design decisions (compressed)

Own repo per module (independence mandate) · drivers keyed by `apiStyle`,
new vendor = preset row · sync-in-webhook execution with budget watchdog ·
cache-only persistence, no history rows or blobs · multipart as first-class
transport capability in the core client, module-local bypass deleted (Track A →
Track B) · edge-tts via self-hosted wrapper, not a reverse-engineered PHP port ·
quota fail-closed on Redis loss (outbound media ≠ STT free reads) · vault via
Eloquent `encrypted` cast · `/voice` dual-purpose (arg/reply = convert, bare =
panel) · auto-speak private-only (group = spam cannon) · deliberate duplication
over a shared media-AI lib until a third consumer appears · group `/voice`
admin-only · not strict-ordered (replies async-queued).

## 12. Relationship with the stt sibling

The `stt` module (`bagart/telegram-bot-stt-module`) is fully independent:
separate repo, separate Redis prefixes (`stt:`), tables (`stt_*`), callback
namespaces (`tc:`) and command namespace (`/text`). Both may run on one bot;
their features compose without interaction. Shared-kernel extraction trigger:
a third media-AI consumer appearing ⇒ extract `bagart/telegram-bot-media-ai-lib`
and rebase both modules; until then no shared package exists.
