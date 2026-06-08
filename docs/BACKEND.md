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
| `GET /api/layout`        | Current grid + live (non-expired) tiles. | none         |
| `POST /api/tiles`        | Add or replace a tile (upsert by `id`).  | optional key |
| `DELETE /api/tiles/{id}` | Remove a tile.                           | optional key |
| `GET /`                  | The display page itself.                 | none         |

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
and places the tile (see §3a). `duration` is optional; omit or `null` for a
permanent tile. `expires_at` is computed server-side and never accepted from the
caller.

### `GET /api/layout` — response
```json
{
  "grid": { "cols": 4, "rows": 3, "gap": 8 },
  "tiles": [
    { "id": "weather", "content": { "type": "iframe", "src": "..." },
      "position": { "x": 0, "y": 0, "w": 2, "h": 1 } }
  ]
}
```
This is the **single snapshot** the display polls — one request returns every
live tile (see "One snapshot, per-tile lifetimes" in the overview). It exposes the
**internal** model (resolved `position` with `x`/`y`/`w`/`h`), because it feeds our
own display, not external callers.

## 3a. Tile model translation & placement

The backend is the bridge between the two tile representations (see the domain
model in the overview):

- **Resolve size → footprint.** Map `size` to `w`/`h` (`small` 1×1, `medium` 2×1,
  `large` 2×2).
- **Assign position.** Pick `x`/`y` for the tile — callers never send coordinates.
  The placement strategy and the "no room" policy are **open questions** (see the
  overview); keep them isolated behind a single placement step so the algorithm
  can change without touching the API or storage.
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

- `size` must be one of `small` / `medium` / `large`. Callers must **not** send
  `position`/`x`/`y`/`w`/`h`; reject or ignore such fields.
- `content.type` must be one of the allowed types (see domain model).
- Required payload fields per content type must be present (e.g. `src` for
  `image`/`video`/`iframe`).
- `duration` is `null` or a positive integer.
- `id` is a non-empty, stable string.
- Placement validity (fit within the grid, overlap, "no room" handling) is the
  backend's responsibility at placement time, not caller input — **TBD**, see open
  questions in the overview.

## 8. Security

- Reads (`GET /api/layout`, `GET /`) are open.
- Writes (`POST`, `DELETE`) may require a shared secret via an `X-Api-Key`
  header; disabled by default for a trusted LAN.
- If exposed beyond the LAN, serve over HTTPS and require the key.
- The `html` content type is an injection vector — only trusted callers should be
  able to write tiles.

## 9. Backend-specific open items

- Choose the PHP framework/system (§2).
- Decide the **placement strategy** (first-fit packing, fixed slots, insertion
  order) and the **"no room" policy** (reject, evict oldest, queue) — see §3a.
- Whether the `size` → footprint mapping is fixed or configurable.
- Whether the grid config is fixed server-side or also settable via the API.
