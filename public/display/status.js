// The per-tile timeout indicator: a pie that "spins down" (full at creation,
// gone at expiry) for timed tiles. Permanent tiles show nothing.

export function formatRemaining(seconds) {
    if (seconds <= 0) return 'expiring…';
    if (seconds < 60) return `expires in ${seconds}s`;
    const m = Math.floor(seconds / 60);
    if (m < 60) return `expires in ${m}m ${seconds % 60}s`;
    return `expires in ${Math.floor(m / 60)}h ${m % 60}m`;
}

export function createStatusBadge() {
    const badge = document.createElement('span');
    badge.className = 'tile-status';
    return badge;
}

/**
 * Configure the badge for a tile. The depletion is a pure-CSS animation; we set
 * its duration once and use a negative animation-delay so it resumes at the right
 * point (even after a reload), only re-arming when the lifetime changes.
 */
export function applyStatus(badge, tile) {
    // Permanent tiles show no indicator at all.
    if (tile.expires_at == null) {
        badge.style.display = 'none';
        badge.dataset.sig = 'permanent';
        badge.title = '';
        return;
    }

    badge.style.display = ''; // fall back to the CSS (flex) — show the pie
    badge.dataset.mode = 'timed';

    // A signature so we only (re)configure when the lifetime changes — otherwise
    // resetting the animation each poll would make it jump.
    const sig = `${tile.created_at}:${tile.expires_at}`;
    if (badge.dataset.sig !== sig) {
        badge.dataset.sig = sig;
        const total = Math.max(1, tile.expires_at - tile.created_at);
        const elapsed = Math.max(0, Math.floor(Date.now() / 1000) - tile.created_at);
        badge.textContent = '';
        badge.style.animation = 'none';
        void badge.offsetWidth; // reflow so a re-used node restarts cleanly
        badge.style.animation = `tile-spin-down ${total}s linear forwards`;
        badge.style.animationDelay = `-${elapsed}s`;
    }

    badge.title = formatRemaining(tile.expires_at - Math.floor(Date.now() / 1000));
}
