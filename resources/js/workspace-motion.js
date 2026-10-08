/*
 * The workspace's one authored motion: when a tab opens or closes, the other
 * tabs slide to their new places instead of jumping. CSS cannot animate
 * siblings reflowing after one is removed, so this uses GSAP's Flip plugin;
 * every other transition in the panel is CSS (theme.css).
 *
 * Bundled by Vite (unlike the inline scripts in js/shell/) and loaded only on
 * the workspace. Durations and the curve come from theme.css's motion tokens.
 */
import { gsap } from 'gsap';
import { Flip } from 'gsap/Flip';

gsap.registerPlugin(Flip);

// theme.css's --ease-smooth-out, cubic-bezier(0.22, 1, 0.36, 1), is the quintic ease-out: GSAP's power4.out.
const ease = 'power4.out';

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');

function seconds(token, fallback) {
    const value = getComputedStyle(document.documentElement).getPropertyValue(token).trim() || fallback;

    return parseFloat(value) / (value.endsWith('ms') ? 1000 : 1);
}

window.aeMotion = {
    // Where the strip's tabs are now; null when there is nothing to animate.
    capture(strip) {
        if (!strip || reduced.matches) {
            return null;
        }

        return Flip.getState(strip.querySelectorAll('.ae-tab'));
    },

    // After the tabs changed: each one slides from where it was, a new one rises in.
    play(state, strip) {
        if (!state || !strip) {
            return;
        }

        const duration = seconds('--duration-fast', '250ms');

        Flip.from(state, {
            targets: strip.querySelectorAll('.ae-tab'),
            duration,
            ease,
            simple: true,
            clearProps: 'transform',
            onEnter: (tabs) => gsap.fromTo(tabs, { autoAlpha: 0, y: 4 }, { autoAlpha: 1, y: 0, duration, ease, clearProps: 'opacity,visibility,transform' }),
        });
    },
};
