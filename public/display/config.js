// Runtime config injected by the page (templates/display/index.html.twig), with
// sensible fallbacks for standalone use.
const raw = window.SUPERSCREEN ?? { layoutUrl: '/api/layout', pollInterval: 3 };

export const config = {
    layoutUrl: raw.layoutUrl,
    // Tile mutations live next to the layout: "/api/layout" -> "/api/tiles".
    tilesUrl: raw.layoutUrl.replace(/layout$/, 'tiles'),
    pollInterval: Math.max(1, raw.pollInterval),
};
