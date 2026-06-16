# SuperScreen — Backend Design

The API and state layer. See [`README.md`](README.md) for the overview and the
shared domain model, and [`FRONTEND.md`](FRONTEND.md) for the display.

Status: **draft** · Last updated: 2026-06-02

---

## 1. Role

The backend is the **single source of truth** for the layout. It:

- accepts content from callers (add / replace / remove tiles),
- translates the API-facing tile (named `size`) into the internal model and
  **places** it on the grid (assigns `x`/`y`),
- holds the current layout durably,
- enforces per-tile expiry,
- serves an atomic layout snapshot to the display, cheaply when unchanged.

The display holds no authoritative state — it only renders what the backend
returns. See [`FRONTEND.md`](FRONTEND.md).

## 2. Technology

- **PHP.** Specific framework/system (plain PHP, Slim, Laravel, Symfony, …) to be
  chosen later. Nothing in this design depends on that choice.
- Aim to keep runtime requirements modest so it can run **on the Pi itself**
  alongside the display.

## 3. API contract

| Method & path            | Purpose                                  | Auth         |
|--------------------------|------------------------------------------|--------------|
| `GET /api/layout`               | Current grid + live (non-expired) tiles. | none         |
| `POST /api/tiles`               | Add or replace a tile (upsert by `id`).  | optional key |
| `PATCH /api/tiles/{id}/position`| Move a placed tile to `{x, y}` (manual override). | optional key |
| `DELETE /api/tiles/{id}`        | Remove a tile.                           | optional key |
| `GET /`                         | The display page itself.                 | none         |

`PATCH /api/tiles/{id}/position` (body `{ "x", "y" }`) is a **human override** of
automatic placement (used by drag-to-move on the display). It keeps the tile's
footprint, evicts any tiles it lands on to the queue (preserving their remaining
TTL) and drains them back into free space. 404 if no placed tile has that id; 422
if the footprint wouldn't fit the grid at the target.

### `POST /api/tiles` — request body
```json
{
  "id": "weather",
  "content": { "type": "iframe", "src": "https://example.com/weather" },
  "size": "medium",
  "duration": 3600
}
```
The write API is **API-facing**: callers send a named `size`
(`small`/`medium`/`large`), **not** `x`/`y`/`w`/`h`. The backend resolves the size
and places the tile (see §3a). `id` is optional — omit or send an empty string and
the backend generates one (truncated SHA-256 hex) and returns it in the response.
`duration` is optional; omit or `null` for a
permanent tile. `expires_at` is computed server-side and never accepted from the
caller.

### `GET /api/layout` — response
```json
{
  "grid": { "cols": 4, "rows": 3, "gap": 8 },
  "tiles": [
    { "id": "weather",
      "html": "<iframe src=\"...\" sandbox=\"...\"></iframe>",
      "position": { "x": 0, "y": 0, "w": 2, "h": 1 },
      "created_at": 1700000000, "expires_at": 1700003600 }
  ]
}
```
Content is **rendered server-side** (one Twig template per type — see
`docs/FRONTEND.md` §5), so each tile carries ready-to-inject `html` rather than a
structured content object. This is the **single snapshot** the display polls — one request returns every
live tile (see "One snapshot, per-tile lifetimes" in the overview). It exposes the
**internal** model (resolved `position` with `x`/`y`/`w`/`h`), because it feeds our
own display, not external callers.

## 3a. Tile model translation & placement

The backend is the bridge between the two tile representations (see the domain
model in the overview):

- **Resolve size → footprint.** Map `size` to `w`/`h` (`small` 1×1, `medium` 2×1,
  `large` 2×2).
- **Assign position.** Pick `x`/`y` for the tile (callers never send coordinates)
  by first-fit. Kept behind a single placement step (`TilePlacer`) so the
  algorithm can change without touching the API or storage.
- **Queue when full.** If the tile doesn't fit, it's queued (not rejected) and
  placed automatically when space frees (drained on `GET /api/layout` and on
  delete; greedy, FIFO). `POST` returns **202 Accepted** in that case. A tile
  larger than the whole grid can never fit and is rejected with **409**.
- **Store the internal model.** Persist the full `{ id, content, position,
  expires_at, created_at }` and serve it from `GET /api/layout`.

Keeping size→position translation server-side means the API stays minimal and the
visual grammar of the screen is controlled centrally.

## 4. State persistence

- A **single JSON file** holds the layout. Adequate for one screen; a database is
  unnecessary.
- Writes are **atomic** (write a temp file, then rename) so a crash or power loss
  mid-write can't corrupt the layout, and the screen recovers exactly on restart.
- Concurrency is low (occasional writes, periodic reads); a simple file lock on
  write is sufficient.

## 5. TTL / expiry

- Each tile stores its own `expires_at` (= `now + duration`, or `null` for
  permanent). This gives every tile an **independent lifetime**.
- `GET /api/layout` returns only tiles whose `expires_at` is in the future or
  `null`. Expiry is therefore enforced entirely server-side; the display stays
  dumb.
- Expired tiles can be pruned lazily from the file on the next write — no
  background job needed.

## 6. Change detection (ETag / 304)

- The layout response carries an **ETag = hash of the response body**.
- The display sends `If-None-Match`; the backend replies **304 Not Modified**
  when nothing changed, or **200** with the new snapshot otherwise.
- Hashing the body (rather than a write counter) means **time-based expiry** also
  flips the ETag and triggers a re-render — not just explicit writes.

## 7. Validation rules

- A request carries **exactly one** footprint source: a `size`
  (`small`/`medium`/`large`/`xlarge`) **or** both `width` and `height` (explicit
  cells, `1 ≤ each`, `width × height ≤ app.limits.max_tile_area`, default 9).
  Sending both, neither, or only one of width/height → **422**.
- Callers must **not** send
  `position`/`x`/`y`/`w`/`h`; reject or ignore such fields.
- `content.type` must be one of the allowed types (see domain model).
- Required payload fields per content type must be present (e.g. `src` for
  `image`/`video`/`iframe`).
- `duration` is `null` or a positive integer.
- `id` is a non-empty, stable string, at most `app.limits.max_id_length` chars
  (else **413/422**).
- Placement validity (fit within the grid, overlap) is the
  backend's responsibility at placement time, not caller input — **TBD**, see open
  questions in the overview.

### Resource limits (DoS protection)
The write API is a public surface, so storage is bounded:
- **Content size** capped at `app.limits.max_content_bytes` (default 256 KiB) → **413**.
- **id length** capped at `app.limits.max_id_length` (default 128) → **422**.
- **Queue length** capped at `app.limits.max_queue` (default 50); a full queue
  rejects new no-room tiles with **503** (re-queuing an existing id still works).
- **Expired tiles are pruned** from storage on each write (TTL only filters on
  read), so the store can't grow unbounded over time.

Placed tiles are already bounded by grid capacity, so total storage ≈ grid +
queue cap. Note `SimpleDataService` loads the whole store into memory per request,
which is why these caps matter.

## 8. Security

- Reads (`GET /api/layout`, `GET /`) are open — the display never needs a key.
- **Writes** (`POST`/`PATCH`/`DELETE` under `/api`) require an `X-Api-Key` header.
  Enforced by `App\EventSubscriber\ApiKeySubscriber`. Keys are **named, hashed**
  (`App\Service\ApiKey\ApiKeyRepository`): only a SHA-256 hash is stored, in a
  dedicated `var/data/keys.json` (separate from tile state, in the shared deploy
  dir), keyed by a short id. Manage with `app:apikey:create <label>` /
  `app:apikey:list` / `app:apikey:revoke <id>`. The create command prints the
  token **once**.
- **Auto-activates** only once ≥1 key exists; with no keys, writes are open
  (so the API can't lock itself out before a key is created). Compared with
  `hash_equals`. Serve over HTTPS (production does).
- **Per-tile attribution:** the subscriber exposes the matched key id on the
  request; `LayoutService` stamps it on the tile (`apiKeyId`, persisted, carried
  through queue/eviction; `null` in open mode). It's **internal audit metadata —
  not** included in the public `GET /api/layout` response. Auth is a custom
  subscriber rather than Symfony Security: stateless machine keys with no
  users/roles/sessions, so the firewall machinery isn't warranted (yet).
- The `html` content type is rendered inside a **sandboxed `<iframe srcdoc>`**
  (`allow-scripts`, no `allow-same-origin`), so its markup/JS is isolated to that
  tile's opaque-origin frame and can't touch the display, other tiles, or storage
  (see `docs/FRONTEND.md` §5). Still prefer trusted callers for `html`.

## 9. Backend-specific open items

- Choose the PHP framework/system (§2).
- Decide the **placement strategy** (first-fit packing, fixed slots, insertion
  order) and the **"no room" policy** (reject, evict oldest, queue) — see §3a.
- Whether the `size` → footprint mapping is fixed or configurable.
- Whether the grid config is fixed server-side or also settable via the API.
