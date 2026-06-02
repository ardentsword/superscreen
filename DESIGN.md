# SuperScreen — Design Document

API-driven grid display for a TV, running on a Raspberry Pi.

Status: **draft** · Last updated: 2026-06-02

---

## 1. Goal

A screen permanently mounted on a TV that shows a configurable **grid** of content
tiles. The layout is controlled remotely through an **HTTP API**: callers push
content with a grid position, size, and a duration (or permanent). The screen
reflects changes within a few seconds.

## 2. Scope & assumptions

These decisions are settled and shape the whole design:

- **One screen.** No multi-device support. A single, global layout state.
- **A few seconds of delay is acceptable.** The display **polls** the API; no
  real-time push (no WebSocket).
- **Runs on a Raspberry Pi** (Pi 4 / 5 recommended) driving a TV over HDMI.
- **Backend in PHP** (8.2+, dependency-free), fits the existing Tremani stack.
- The API is trusted/LAN-side by default; optional shared-secret auth for writes.

Out of scope for now: multiple screens, user accounts, a management UI,
scheduling/playlists. The data model leaves room to add these later.

## 3. Architecture

```
   ┌──────────────┐   POST /api/tiles        ┌─────────────────┐
   │ Any caller   │ ───────────────────────▶ │  PHP backend    │
   │ (scripts,    │   DELETE /api/tiles/{id}  │  - REST API     │
   │  other apps) │ ───────────────────────▶ │  - state on disk│
   └──────────────┘                           │    (JSON file)  │
                                              └────────┬────────┘
                          GET /api/layout (poll ~3s)   │
   ┌──────────────────────────────────────────────────┘
   │   ▲ 304 Not Modified (unchanged)  /  200 + layout (changed)
   ▼   │
   ┌─────────────────────────────────┐
   │ Raspberry Pi                    │
   │  Chromium (kiosk, fullscreen)   │
   │  display page → CSS Grid render │
   └─────────────────────────────────┘
```

The Pi and the backend can run on the **same device** (the Pi serves its own API)
or the backend can live elsewhere on the network. Same-device is simplest and is
the recommended starting point.

### Why a web display
A fullscreen browser renders text, images, video, web pages, fonts, and
animations for free, and **CSS Grid** maps directly onto the tile model
(`grid-column` / `grid-row` with spans). A native app would cost far more for no
real benefit here.

### Why polling (not WebSocket)
Because a few seconds of delay is fine and there is only one screen, polling is
simpler and more robust: no persistent connections, trivial recovery after a
network blip or reboot, and a plain PHP backend with no long-running process.

## 4. Data model

A **tile** is the unit of content placed on the grid:

| Field        | Type                | Notes                                                        |
|--------------|---------------------|--------------------------------------------------------------|
| `id`         | string              | Caller-supplied stable key. Re-posting the same id replaces it (upsert). |
| `content`    | object              | `{ "type": ..., ... }` — see content types below.            |
| `position`   | object              | `{ "x", "y", "w", "h" }` in grid cells (0-indexed origin).   |
| `duration`   | int \| null         | Seconds the tile stays live. `null` = permanent.             |
| `expires_at` | int \| null         | **Computed server-side** = `now + duration`. Not sent by callers. |
| `created_at` | int                 | Server timestamp.                                            |

### Content types
| `type`   | Payload fields        | Rendering                                  |
|----------|-----------------------|--------------------------------------------|
| `text`   | `text`, optional style | Text in a cell.                            |
| `image`  | `src`                 | Image, scaled to cover the cell.           |
| `video`  | `src`                 | Muted autoplay loop (browsers block sound).|
| `iframe` | `src`                 | Embedded web page (see CSP caveat below).  |
| `html`   | `html`                | Raw HTML (trusted callers only — XSS risk).|

### State persistence
A single JSON file (e.g. `data/state.json`), written **atomically**
(write temp file → rename) so the layout survives a backend restart or power
loss. Adequate for one screen; a DB is unnecessary.

## 5. API contract

| Method & path          | Purpose                                  | Auth        |
|------------------------|------------------------------------------|-------------|
| `GET /api/layout`      | Current grid + live (non-expired) tiles. | none        |
| `POST /api/tiles`      | Add or replace a tile (upsert by `id`).  | optional key|
| `DELETE /api/tiles/{id}` | Remove a tile.                         | optional key|
| `GET /`                | The display page itself.                 | none        |

### `POST /api/tiles` — request body
```json
{
  "id": "weather",
  "content": { "type": "iframe", "src": "https://example.com/weather" },
  "position": { "x": 0, "y": 0, "w": 2, "h": 1 },
  "duration": 3600
}
```

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

### TTL / expiry
Expiry is handled **server-side**: `GET /api/layout` only returns tiles whose
`expires_at` is in the future (or `null`). The display stays "dumb" — it just
renders whatever it receives. Expired tiles can be lazily pruned from the file on
the next write.

### Change detection (ETag)
The layout response carries an **ETag = hash of the response body**. The display
sends `If-None-Match`; the backend returns **304 Not Modified** when nothing
changed, otherwise **200** with the new layout. Hashing the body (not a write
counter) means **time-based expiry** also correctly triggers a re-render, not
just explicit writes.

### Validation rules
- `position` must fit within the configured grid (`x+w ≤ cols`, `y+h ≤ rows`).
- `content.type` must be one of the allowed types.
- `duration` is `null` or a positive integer.
- Overlap handling: TBD — see open questions.

## 6. Display behaviour (the Pi)

- Fullscreen page using CSS Grid sized from `grid.cols` / `grid.rows`.
- Polls `GET /api/layout` every `poll_interval` (~3s) with `If-None-Match`;
  re-renders only on a `200`.
- On load (including after reboot) it fetches the current layout — **state lives
  on the server**, so the screen fully recovers after a power cut or reload.
- **Nightly auto-reload** of the page to counter long-running browser memory
  growth.

## 7. Raspberry Pi setup (operational)

- **Chromium in kiosk mode**, auto-started on boot.
- Disable screen blanking / screensaver.
- Quality SD card or boot from SSD/USB; keep disk writes (logs) low to avoid
  SD wear.
- Configure resolution; correct any TV overscan.
- Optional **HDMI-CEC** to power the TV on/off on a schedule.

## 8. Obstacles & risks

| Risk | Mitigation |
|------|------------|
| Browser memory leak over weeks | Scheduled nightly reload; server-side state restore. |
| Power loss / reboot | Auto-start kiosk; display re-fetches layout on load. |
| SD card wear | Good card or SSD; minimal logging. |
| Sites blocking iframes (`X-Frame-Options`/CSP) | Test each embed; not all external pages will load. |
| Video autoplay blocked with sound | Muted autoplay only. |
| Open API on the network | Optional `X-Api-Key` for writes; serve over HTTPS / keep LAN-only. |
| `html` content type XSS | Restrict to trusted callers. |
| OLED burn-in (static content) | Minor for typical TVs; optional pixel-shift if needed. |

## 9. Tech stack summary

| Layer    | Choice                                             |
|----------|----------------------------------------------------|
| Display  | Chromium kiosk + HTML/CSS Grid/JS (vanilla)        |
| Transport| HTTP polling with ETag/304                         |
| Backend  | PHP 8.2+, no framework, PSR-4 autoloading          |
| Storage  | Single atomic JSON file                            |
| Host     | Raspberry Pi 4/5, backend co-located by default    |

## 10. Open questions

- **Tile overlap:** reject overlapping placements, or allow with z-ordering?
- **Default/empty cells:** show nothing, a background, or a fallback tile?
- **Transitions:** any fade/animation when a tile appears or expires?
- **Grid reconfiguration:** is the grid size fixed in config, or also API-driven?
- **Content sourcing:** who calls the API (manual scripts, cron, other systems)?
- **Backend location:** on the Pi itself, or a separate server?
```

These don't block the design but should be decided before/while building.

## 11. Next steps

1. Resolve the open questions above (at least overlap + empty-cell behaviour).
2. Build the skeleton: front controller, `Store` (JSON + TTL + ETag), display page.
3. Test on target Pi hardware with real content types (especially video + iframes).
4. Harden: auto-start, nightly reload, optional auth.
