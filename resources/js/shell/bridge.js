/*
 * Runs on every signed-in panel page. Inside a workspace tab (an iframe) it
 * hides the shell chrome, opens links to other screens as new tabs instead of
 * leaving the tab, reports the tab's title and address, and answers whether
 * the tab holds unsaved changes. On the workspace itself it turns every panel
 * link into a tab.
 */
(function () {
    var cfg = window.aeShellConfig || {};
    var framed = window.self !== window.top;

    function markFramed() {
        if (framed) {
            document.documentElement.classList.add('ae-framed');
        }
    }

    markFramed();

    // Navigating inside a tab swaps the <html> attributes for the new page's; mark it again before it paints.
    document.addEventListener('livewire:navigating', function (event) {
        if (event.detail && typeof event.detail.onSwap === 'function') {
            event.detail.onSwap(markFramed);
        }
    });
    document.addEventListener('livewire:navigated', markFramed);

    // The theme is picked in the workspace's user menu; every open tab follows at once.
    if (framed) {
        window.addEventListener('storage', function (event) {
            if (event.key === 'theme' && event.newValue) {
                window.dispatchEvent(new CustomEvent('theme-changed', { detail: event.newValue }));
            }
        });
    }

    function clean(path) {
        path = String(path || '').replace(/\/+$/, '');

        return path === '' ? '/' : path;
    }

    var home = clean(cfg.home);

    // A session that ended inside a tab signs in again at the top, not inside the tab.
    if (framed && cfg.login && clean(location.pathname) === clean(cfg.login)) {
        window.top.location.replace(location.href);

        return;
    }

    function screenOf(path) {
        path = clean(path);
        var best = null;
        var bestLength = -1;
        var paths = cfg.paths || {};

        Object.keys(paths).forEach(function (prefix) {
            if ((path === prefix || path.indexOf(prefix + '/') === 0) && prefix.length > bestLength) {
                best = paths[prefix];
                bestLength = prefix.length;
            }
        });

        if (best === null && cfg.dashboard && path === clean(cfg.dashboard)) {
            best = 'dashboard';
        }

        return best;
    }

    function panelUrl(href) {
        var url;

        try {
            url = new URL(href, location.href);
        } catch (error) {
            return null;
        }

        if (url.origin !== location.origin) {
            return null;
        }

        if (url.pathname !== home && url.pathname.indexOf(home + '/') !== 0) {
            return null;
        }

        return url;
    }

    function shell() {
        try {
            return framed ? window.parent.aeShell : window.aeShell;
        } catch (error) {
            return null;
        }
    }

    function leavesThisTab(url) {
        if (framed) {
            var target = screenOf(url.pathname);
            var current = screenOf(location.pathname);

            if (target === null) {
                return clean(url.pathname) !== clean(location.pathname);
            }

            return target !== current;
        }

        // On the workspace itself, every other panel page opens as a tab.
        return clean(location.pathname) === home && clean(url.pathname) !== home;
    }

    function openAsTab(url) {
        var target = shell();

        if (!target || typeof target.open !== 'function') {
            return false;
        }

        target.open(url.pathname + url.search + url.hash);

        return true;
    }

    document.addEventListener('click', function (event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;

        // The rail's tiles open their own tabs and close the menu.
        if (!link || link.target === '_blank' || link.hasAttribute('download') || link.closest('.ae-rail')) {
            return;
        }

        var url = panelUrl(link.href);

        if (url && leavesThisTab(url) && openAsTab(url)) {
            event.preventDefault();
            event.stopPropagation();
        }
    }, true);

    document.addEventListener('livewire:navigate', function (event) {
        var destination = event.detail && event.detail.url;
        var url = destination ? panelUrl(destination.href || destination) : null;

        if (url && leavesThisTab(url) && openAsTab(url)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    });

    function report() {
        var target = shell();

        if (framed && target && typeof target.frameChanged === 'function') {
            target.frameChanged(window, { url: location.pathname + location.search, title: document.title });
        }
    }

    document.addEventListener('livewire:navigated', report);
    window.addEventListener('load', report);

    window.aeFrame = {
        // The same test Filament's unsaved-changes alert uses: the form's data against the hash saved with it.
        isDirty: function () {
            try {
                if (!window.Livewire || !window.jsMd5) {
                    return false;
                }

                return window.Livewire.all().some(function (component) {
                    var wire = component.$wire;

                    if (!wire || typeof wire.savedDataHash !== 'string' || wire.savedDataHash === '') {
                        return false;
                    }

                    return window.jsMd5(JSON.stringify(wire.data).replace(/\\/g, '')) !== wire.savedDataHash;
                });
            } catch (error) {
                return false;
            }
        },
    };
})();
