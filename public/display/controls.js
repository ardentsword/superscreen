// Page-level toggle (bottom-left eye) that hides every per-tile control for a
// clean wall view. The choice is remembered in localStorage.
import { EYE_OFF_SVG, EYE_SVG } from './icons.js';

const CONTROLS_HIDDEN_STORE = 'controlsHidden';

export function setupControlsToggle() {
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'controls-toggle';
    button.title = 'Show/hide tile buttons';

    const apply = () => {
        const hidden = localStorage.getItem(CONTROLS_HIDDEN_STORE) === '1';
        document.body.classList.toggle('controls-hidden', hidden);
        button.innerHTML = hidden ? EYE_OFF_SVG : EYE_SVG;
        button.setAttribute('aria-pressed', String(hidden));
    };

    button.addEventListener('click', () => {
        const hidden = localStorage.getItem(CONTROLS_HIDDEN_STORE) === '1';
        localStorage.setItem(CONTROLS_HIDDEN_STORE, hidden ? '0' : '1');
        apply();
    });

    apply();
    document.body.appendChild(button);
}
