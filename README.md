# SuperScreen

API-driven grid display for a TV, running on a Raspberry Pi. Content is pushed to
an HTTP API with a grid position, size, and duration; a fullscreen browser kiosk
renders the layout and refreshes within a few seconds.

> **Status:** design phase — the repo currently holds design docs only.

## Documentation

- [Design Overview](docs/README.md) — goals, architecture, domain model, risks.
- [Backend](docs/BACKEND.md) — API, state, TTL, change detection (PHP).
- [Frontend](docs/FRONTEND.md) — the display page (Chromium kiosk + CSS Grid).
- [Operations](docs/OPERATIONS.md) — provisioning, deployment, supervision, monitoring.
- [Pull-Sourced Tiles](docs/PULL-SOURCED-TILES.md) — *optional/future*: server-side polling of source URLs.
