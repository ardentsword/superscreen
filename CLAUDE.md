# SuperScreen — Project Guide

API-driven grid display for a TV, running on a Raspberry Pi. Content is pushed to
an HTTP API with a named size and a duration; a fullscreen Chromium kiosk renders
a CSS-grid layout and polls the API to refresh within a few seconds.

**Status:** early implementation. Domain model, JSON store, and the API interface
(stubbed) exist. No placement logic, controller logic, or display frontend yet.

## Tech stack

- **PHP 8.4** (platform pinned to `8.4.22` in `composer.json`; apply modern PHP
  8.4 idioms — `readonly`, enums, typed constants, constructor promotion).
- **Symfony 8.1** (minimal `skeleton`, not `webapp`).
- **PHPUnit** via `symfony/test-pack`.
- **Storage: a single JSON file** (`var/state.json`) — deliberately no database.
  See the storage section below.

## Commands

- Run tests: `php bin/phpunit`
- Console: `php bin/console` (e.g. `debug:router`, `debug:container`, `router:match`)
- Local server (manual): `php -S 127.0.0.1:8000 -t public public/index.php`
  or `symfony serve`
- Lint a file: `php -l <file>`

## Architecture & key decisions

The full design lives in [`docs/`](docs/) — read these before changing direction:

- `docs/README.md` — overview, domain model, decisions, open questions.
- `docs/BACKEND.md` — API contract, state, TTL, ETag.
- `docs/FRONTEND.md` — the display page (vanilla, CSS Grid, keyed reconciliation).
- `docs/OPERATIONS.md` — Pi provisioning, 3-tier deploy, monitoring.
- `docs/PULL-SOURCED-TILES.md` — optional/future server-side polling.

Settled decisions (don't silently reverse — confirm with the user first):

- **One screen, polling (no WebSocket).** A few seconds of delay is acceptable.
- **JSON file storage, not a database.** "Migrations" do not apply.
- **One layout snapshot** (`GET /api/layout`), not per-tile polling. Per-tile TTL
  is enforced server-side (expired tiles are filtered out on read).
- **ETag = hash of the live layout** (`TileRepository::liveHash`) → 304 when
  unchanged. Hashing the body means time-based expiry also triggers a re-render.
- **API-facing vs internal tile model** (see below).

## Domain model

Two tile representations; the backend translates between them.

- **API-facing** (`App\Dto\TileRequest`): what callers POST — `id`, `content`
  (`{type, ...payload}`), `size`, `duration`. **No position.**
- **Internal** (`App\Dto\Tile`): fully resolved — `id`, `ContentType`, content
  payload, `Position` (x/y/w/h), `createdAt`, `expiresAt`.

Supporting types in `App\Tile\`:
- `Size` enum — `small` 1×1, `medium` **2×1**, `large` 2×2 (width × height);
  `footprint()`/`width()`/`height()`.
- `ContentType` enum — `text`, `image`, `video`, `iframe`, `html`.
- `Position` — pure value object (x, y, w, h).

The backend's job (not yet implemented): resolve `size` → `w`/`h`, **assign x/y
(placement)**, compute `expires_at` from `duration`. Placement strategy and the
"no room" policy are open questions — see `docs/README.md` §8.

## Persistence — SimpleDatabase pattern

Borrowed from the `ardent/factorioManager` project: a generic JSON key/value
service plus typed repositories. **DTOs are plain data + getters; the repository
owns the array↔DTO mapping.**

- `App\Service\SimpleDatabase\SimpleDataService` — JSON file key→array store,
  in-memory cache, `#[Autowire]`'d file path (`%kernel.project_dir%/var/state.json`).
  This project added `remove()` and an **atomic write** (temp file + rename).
- `App\Service\SimpleDatabase\TileRepository` — typed repository over it. Tiles
  keyed `tile.<id>`. API: `store`, `delete`, `find`, `findLive(int $now)`
  (server-side TTL), `liveHash(int $now)` (ETag basis). Serialization
  (`toArray`/`fromArray`) lives here.

## HTTP API

`App\Controller\TileApiController` (attribute routes under `/api`):

- `POST /api/tiles` — upsert a tile (by id).
- `DELETE /api/tiles/{id}` — remove a tile.
- `GET /api/layout` — the snapshot the display polls (grid + live tiles + ETag).

Status: `POST /api/tiles` maps the JSON body to `TileRequest` via
`#[MapRequestPayload]` (Serializer installed) — it echoes the parsed payload but
does **not** persist yet (placement + storage still TODO). Invalid enum/type
yields a 422 automatically. `DELETE` and `GET /api/layout` still return
`501 Not Implemented`. No explicit validation constraints added yet.

## Conventions & gotchas

- `declare(strict_types=1)` in every PHP file. `readonly` classes/props where it
  fits. DTOs use private readonly promoted props + getters (mirrors factorio).
- **`config/services.yaml` excludes `src/Dto/` and `src/Tile/` from autowiring**
  (they're DTOs / value objects / enums, not services). New non-service value
  types in those dirs are fine; new services elsewhere autowire automatically.
- Runtime state (`var/`, `var/state.json`) and `vendor/` are gitignored.
- Tests use a plain `TestCase` with a temp state file (no kernel) — fast.
- Never add the Claude co-authored-by signature to commits (global rule).
- Commit style: imperative subject + a short body explaining the why.

## Not yet built (rough next steps)

1. Placement: resolve `size` → `Position` (assign x/y) — the open design question.
2. Controller logic: bind `TileRequest`, validate, persist, build the layout
   snapshot with ETag/304.
3. Grid config (cols/rows/gap, poll interval) as parameters.
4. Display frontend (`GET /`): CSS-grid page + vanilla JS poll/reconcile.
