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

The backend resolves `size` → `w`/`h`, **assigns x/y** via `TilePlacer`, and
computes `expires_at` from `duration`. Grid dimensions are parameters
(`app.grid.cols`/`rows`/`gap`, default 6×4×8 in `config/services.yaml`).

`App\Service\Placement\TilePlacer` — first-fit placement (top-to-bottom,
left-to-right). Throws `NoSpaceException` when the grid is full (controller →
409). See `docs/README.md` §8 for the strategy / "no room" policy (both chosen
defaults, easily swapped).

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

All endpoints are **implemented**:
- `POST /api/tiles` — maps the body to `TileRequest` (`#[MapRequestPayload]`),
  resolves content type, places via `TilePlacer`, computes expiry, persists.
  201 (new) / 200 (updated) with the resolved position; 409 when full; 422 on bad
  size/content type. Re-posting an unchanged footprint keeps its position.
- `DELETE /api/tiles/{id}` — idempotent delete via `TileRepository`.
- `GET /api/layout` — `{grid, tiles}` snapshot; sets a body-hash ETag and returns
  304 via `isNotModified`.

## Display (the renderer)

`App\Controller\DisplayController` serves `GET /` → `templates/display/index.html.twig`.
The page is a vanilla, no-build renderer (assets in `public/display/`):
- `app.js` polls `GET /api/layout` every `%app.poll_interval%` s with
  `If-None-Match`, ignores 304, keeps the last layout on error.
- **Keyed reconciliation by tile id**: unchanged tiles' DOM is left intact (video
  keeps playing, iframe stays loaded); only changed content is rebuilt; position
  is set via CSS grid vars. Renderers per `ContentType`; unknown type shows a
  visible red placeholder.
- `GET /grid-preview` (`GridPreviewController`) remains a static dev aid.

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

1. Explicit validation constraints on `TileRequest` (e.g. non-empty id, required
   content fields per type).
2. Optional `X-Api-Key` auth on writes (`docs/BACKEND.md §8`).
3. Display polish: enter/exit transitions, nightly auto-reload, stale indicator.
4. Pi deployment (`docs/OPERATIONS.md`).
