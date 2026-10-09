/*
 * The public site's one script: the mobile menu, the promo carousel and the
 * scroll-in reveal. Bundled by Vite and loaded from this origin; the site's
 * content policy allows no inline script. Everything degrades: without this
 * file the menu is still reachable through the links, the first slide shows,
 * every section is visible.
 */
document.documentElement.classList.add('js');

const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

// Mobile menu
const button = document.querySelector('[data-site-menu-button]');
const menu = document.getElementById('site-menu');
if (button && menu) {
    button.addEventListener('click', () => {
        const open = menu.hidden;
        menu.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
        button.setAttribute('aria-label', open ? button.dataset.labelClose : button.dataset.labelOpen);
    });
}

// Scroll-in reveal: settle each section once a fifth of it is in view.
const targets = document.querySelectorAll('[data-reveal]');
if (reduceMotion || !('IntersectionObserver' in window)) {
    targets.forEach((el) => el.classList.add('is-in'));
} else {
    const io = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
            }
        }
    }, { threshold: 0.2 });
    targets.forEach((el) => el.classList.add('is-in-wait'));
    targets.forEach((el) => io.observe(el));
}

// Promo carousel: rotates the slides, pauses on hover and focus, stops under reduced motion.
for (const carousel of document.querySelectorAll('[data-carousel]')) {
    const slides = Array.from(carousel.querySelectorAll('[data-slide]'));
    const dots = Array.from(carousel.querySelectorAll('[data-dot]'));
    if (slides.length < 2) continue;
    let current = 0;
    let timer = null;
    const show = (index) => {
        current = (index + slides.length) % slides.length;
        slides.forEach((slide, i) => {
            slide.classList.toggle('is-active', i === current);
            slide.setAttribute('aria-hidden', String(i !== current));
        });
        dots.forEach((dot, i) => dot.setAttribute('aria-current', i === current ? 'true' : 'false'));
    };
    const start = () => { if (!reduceMotion) { stop(); timer = window.setInterval(() => show(current + 1), 6000); } };
    const stop = () => { if (timer) { window.clearInterval(timer); timer = null; } };
    carousel.querySelector('[data-prev]')?.addEventListener('click', () => { show(current - 1); start(); });
    carousel.querySelector('[data-next]')?.addEventListener('click', () => { show(current + 1); start(); });
    dots.forEach((dot, i) => dot.addEventListener('click', () => { show(i); start(); }));
    carousel.addEventListener('mouseenter', stop);
    carousel.addEventListener('mouseleave', start);
    carousel.addEventListener('focusin', stop);
    carousel.addEventListener('focusout', start);
    show(0);
    start();
}
