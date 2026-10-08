/*
 * The tab strip of the workspace. Every tab is an iframe of an ordinary panel
 * page, created once and only shown or hidden, so its screen keeps its live
 * state. The list of tabs (not what was typed) is kept per user in
 * localStorage and reopened after a reload.
 */
window.aeWorkspace = function (cfg) {
    return {
        cfg: cfg,
        labels: cfg.labels,
        tabs: [],
        active: 'dashboard',

        init() {
            var self = this;

            window.aeShell = {
                open: function (url, title) { self.open(url, title); },
                frameChanged: function (win, info) { self.frameChanged(win, info); },
                home: function () { self.activate('dashboard'); },
            };

            this.restore();
            this.openFromHash();
            window.addEventListener('hashchange', function () { self.openFromHash(); });
        },

        isPanelPath(url) {
            return typeof url === 'string' && url.indexOf(this.cfg.home + '/') === 0;
        },

        restore() {
            var saved = null;

            try {
                saved = JSON.parse(window.localStorage.getItem(this.cfg.storageKey) || 'null');
            } catch (error) {
                saved = null;
            }

            var tabs = [];

            if (saved && Array.isArray(saved.tabs)) {
                saved.tabs.forEach((tab) => {
                    if (tab && this.isPanelPath(tab.url) && tab.id !== 'dashboard' && tabs.length < this.cfg.maxTabs - 1) {
                        tabs.push({ id: String(tab.id), url: tab.url, src: tab.url, title: String(tab.title || this.labels.loading), pinned: false });
                    }
                });
            }

            tabs.unshift({ id: 'dashboard', url: this.cfg.dashboard, src: this.cfg.dashboard, title: this.labels.dashboard, pinned: true });
            this.tabs = tabs;
            this.active = saved && tabs.some((tab) => tab.id === saved.active) ? saved.active : 'dashboard';
        },

        save() {
            try {
                window.localStorage.setItem(this.cfg.storageKey, JSON.stringify({
                    tabs: this.tabs.filter((tab) => !tab.pinned).map((tab) => ({ id: tab.id, url: tab.url, title: tab.title })),
                    active: this.active,
                }));
            } catch (error) {
                // Private windows may refuse storage; the tabs then last until the page is closed.
            }
        },

        openFromHash() {
            var match = window.location.hash.match(/(?:^#|&)open=([^&]+)/);

            if (!match) {
                return;
            }

            window.history.replaceState(null, '', window.location.pathname + window.location.search);
            this.open(decodeURIComponent(match[1]));
        },

        open(url, title) {
            if (!this.isPanelPath(url)) {
                return;
            }

            var existing = this.tabs.find((tab) => tab.url === url || tab.src === url);

            if (existing) {
                this.activate(existing.id);

                return;
            }

            if (this.tabs.length >= this.cfg.maxTabs) {
                var oldest = this.tabs.find((tab) => !tab.pinned && tab.id !== this.active);

                if (!oldest || !window.confirm(this.labels.tooMany) || !this.close(oldest.id)) {
                    return;
                }
            }

            var id = 't' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
            this.animateStrip(() => this.tabs.push({ id: id, url: url, src: url, title: title || this.labels.loading, pinned: false }));
            this.activate(id);
        },

        activate(id) {
            if (this.tabs.some((tab) => tab.id === id)) {
                this.active = id;
                this.save();
            }
        },

        // A tab opening or closing slides the others into place (workspace-motion.js,
        // when loaded); the change itself never waits for the animation.
        animateStrip(change) {
            var motion = window.aeMotion;
            var strip = this.$refs.strip;
            var state = motion ? motion.capture(strip) : null;
            var result = change();

            if (state) {
                this.$nextTick(() => motion.play(state, strip));
            }

            return result;
        },

        // The strip is a tablist: arrow keys, Home and End move between tabs and show them.
        stripKey(event) {
            var moves = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' };

            if (!(event.key in moves) || !event.target.matches('.ae-tab')) {
                return;
            }

            var index = this.tabs.findIndex((tab) => tab.id === event.target.dataset.tab);
            var move = moves[event.key];
            var next = move === 'first' ? 0 : move === 'last' ? this.tabs.length - 1 : (index + move + this.tabs.length) % this.tabs.length;

            event.preventDefault();
            this.activate(this.tabs[next].id);
            this.$nextTick(() => {
                var target = this.$refs.strip.querySelector('.ae-tab[data-tab="' + this.tabs[next].id + '"]');

                if (target) {
                    target.focus();
                }
            });
        },

        frameOf(id) {
            return Array.from(this.$root.querySelectorAll('iframe.ae-frame')).find((frame) => frame.dataset.tab === id) || null;
        },

        close(id) {
            var tab = this.tabs.find((candidate) => candidate.id === id);

            if (!tab || tab.pinned) {
                return false;
            }

            var frame = this.frameOf(id);

            try {
                if (frame && frame.contentWindow.aeFrame && frame.contentWindow.aeFrame.isDirty() && !window.confirm(this.labels.unsaved)) {
                    return false;
                }
            } catch (error) {
                // A frame that cannot answer is closed without asking.
            }

            var index = this.tabs.indexOf(tab);
            this.animateStrip(() => this.tabs.splice(index, 1));

            if (this.active === id) {
                var next = this.tabs[index] || this.tabs[index - 1] || this.tabs[0];
                this.active = next ? next.id : 'dashboard';
            }

            this.save();

            return true;
        },

        frameChanged(win, info) {
            var frame = Array.from(this.$root.querySelectorAll('iframe.ae-frame')).find((candidate) => candidate.contentWindow === win);
            var tab = frame ? this.tabs.find((candidate) => candidate.id === frame.dataset.tab) : null;

            if (!tab) {
                return;
            }

            tab.url = info.url;
            tab.title = this.cleanTitle(info.title) || tab.title;
            this.save();
        },

        cleanTitle(title) {
            var suffix = ' - ' + this.cfg.brand;
            title = String(title || '').trim();

            return title.endsWith(suffix) ? title.slice(0, -suffix.length) : title;
        },
    };
};
