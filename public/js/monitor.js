(function () {
    'use strict';

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    // Renders the GLPI server's time, not the viewing machine's: `getOffsetMs` returns the
    // drift (server minus local) computed from each poll's `generated_at` (see boot()), so a
    // wrong clock on the TV/kiosk box doesn't show a wrong time here.
    function startClock(root, getOffsetMs) {
        const clock = root.querySelector('[data-gac-monitor-clock]');
        if (!clock) {
            return;
        }
        const tick = function () {
            const now = new Date(Date.now() + getOffsetMs());
            clock.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        };
        tick();
        setInterval(tick, 1000);
    }

    // Picks readable text (white or near-black) for a given background hex — the priority
    // colors are admin-configured (GLPI: Configurações > Valores padrão > Cores das
    // Prioridades) and can be anything from pale yellow to black, so this can't be hardcoded.
    function readableTextColor(hex) {
        const match = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!match) {
            return '#fff';
        }
        const n = parseInt(match[1], 16);
        const r = (n >> 16) & 255;
        const g = (n >> 8) & 255;
        const b = n & 255;
        const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
        return luminance > 0.6 ? '#111' : '#fff';
    }

    // No color-mix(): TV browsers are often old, so the row tint is a plain rgba().
    function hexToRgba(hex, alpha) {
        const match = /^#?([0-9a-f]{6})$/i.exec(hex || '');
        if (!match) {
            return null;
        }
        const n = parseInt(match[1], 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + alpha + ')';
    }

    function renderHeader(root, columns) {
        const headRow = root.querySelector('[data-gac-monitor-head]');
        headRow.innerHTML = '';
        columns.forEach(function (col) {
            const th = document.createElement('th');
            th.textContent = col.label;
            headRow.appendChild(th);
        });
    }

    // Row colour (spec M15/M16): the server sends a tone (a meaning, never a colour). "priority-N"
    // takes the GLPI-configured priority colour; every other tone is a CSS class that defines its
    // own --gac-row-accent/--gac-row-tint.
    function applyTone(tr, tone, priorityColors) {
        if (!tone) {
            return;
        }
        if (tone.indexOf('priority-') === 0) {
            const color = priorityColors && priorityColors[tone.slice('priority-'.length)];
            const tint = hexToRgba(color, 0.22);
            if (color && tint) {
                tr.classList.add('gac-row-toned');
                tr.style.setProperty('--gac-row-accent', color);
                tr.style.setProperty('--gac-row-tint', tint);
            }
            return;
        }
        tr.classList.add('gac-row-toned', 'gac-tone-' + tone);
    }

    function renderRows(root, page, newIds, priorityColors) {
        const body = root.querySelector('[data-gac-monitor-body]');
        body.innerHTML = '';
        page.rows.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.dataset.ticketId = row.id;
            applyTone(tr, row.row_tone, priorityColors);
            if (newIds && newIds.has(String(row.id))) {
                tr.classList.add('gac-monitor-row-new');
            }
            const badgeColor = priorityColors && priorityColors[row.priority_raw];
            page.columns.forEach(function (col) {
                const td = document.createElement('td');
                if (col.key === 'priority' && badgeColor) {
                    const badge = document.createElement('span');
                    badge.className = 'gac-priority-badge';
                    badge.style.backgroundColor = badgeColor;
                    badge.style.color = readableTextColor(badgeColor);
                    badge.textContent = row[col.key] || '';
                    td.appendChild(badge);
                } else {
                    td.textContent = row[col.key] || '';
                }
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
    }

    // Overflow bar (spec M14): counts the rows whose bottom edge passes the visible area (a row cut
    // in half counts as hidden). The bar sits outside the viewport, so showing it shrinks the
    // viewport: measure once without it, and again with it when there is overflow.
    function countHiddenRows(root) {
        const viewport = root.querySelector('[data-gac-monitor-viewport]');
        const limit = viewport.getBoundingClientRect().bottom;
        let hidden = 0;
        root.querySelectorAll('[data-gac-monitor-body] tr').forEach(function (tr) {
            if (tr.getBoundingClientRect().bottom > limit + 1) {
                hidden += 1;
            }
        });
        return hidden;
    }

    function updateOverflow(root) {
        const bar = root.querySelector('[data-gac-monitor-overflow]');
        if (!bar) {
            return;
        }
        bar.hidden = true;
        if (countHiddenRows(root) === 0) {
            return;
        }
        bar.hidden = false;
        const hidden = countHiddenRows(root);
        if (hidden === 0) {
            bar.hidden = true;
            return;
        }
        bar.textContent = '▼ ' + hidden + (hidden === 1 ? ' ticket abaixo' : ' tickets abaixo');
    }

    // The page indicator (spec M12): one small dot per page next to the clock, so it costs no
    // extra row of the screen. The title attribute (page title and ticket count) is only for
    // whoever hovers it; a TV never does.
    function renderDots(root, pages, activeId, flashing) {
        const box = root.querySelector('[data-gac-monitor-dots]');
        box.innerHTML = '';
        if (pages.length <= 1) {
            box.hidden = true;
            return;
        }
        box.hidden = false;
        pages.forEach(function (page) {
            const dot = document.createElement('span');
            dot.className = 'gac-dot'
                + (String(page.id) === activeId ? ' gac-dot-active' : '')
                + (flashing[String(page.id)] ? ' gac-dot-flash' : '');
            dot.title = page.title + ' (' + page.rows.length + ')';
            box.appendChild(dot);
        });
    }

    // Applied on every poll (not just the initial page render), so a theme/font-size change made
    // to the Tela while this screen is already open takes effect on the next cycle.
    function applyAppearance(root, theme, fontSizeRem) {
        root.classList.toggle('gac-theme-light', theme === 'light');
        if (fontSizeRem) {
            root.style.setProperty('--gac-table-font-size', fontSizeRem);
        }
    }

    function setConnectionState(root, ok, when) {
        const indicator = root.querySelector('[data-gac-monitor-status]');
        if (!indicator) {
            return;
        }
        indicator.classList.toggle('gac-monitor-status-ok', ok);
        indicator.classList.toggle('gac-monitor-status-stale', !ok);
        indicator.title = ok
            ? 'Atualizado às ' + when.toLocaleTimeString('pt-BR')
            : 'Dados desatualizados; última atualização bem-sucedida às ' + when.toLocaleTimeString('pt-BR');
    }

    // While a request is in flight the ring itself pulses (full-opacity blink) instead of
    // sitting dead still, so it reads as "waiting" rather than "frozen/broken".
    function setWaitingState(root, waiting) {
        const indicator = root.querySelector('[data-gac-monitor-status]');
        if (!indicator) {
            return;
        }
        indicator.classList.toggle('gac-monitor-waiting', waiting);
    }

    // Drains the ring from full to empty over `durationMs`, so it always shows time left
    // until the next poll — restarted at the start of every cycle, success or failure.
    function restartCountdown(root, durationMs) {
        const circle = root.querySelector('[data-gac-countdown-circle]');
        if (!circle) {
            return;
        }
        circle.style.transition = 'none';
        circle.style.strokeDashoffset = '0';
        // Force a reflow so the next transition is not merged with this reset.
        void circle.getBoundingClientRect();
        circle.style.transition = 'stroke-dashoffset ' + (durationMs / 1000) + 's linear';
        circle.style.strokeDashoffset = '100';
    }

    // Plays the alert sound. A browser that has not had a user gesture yet refuses (NotAllowedError):
    // that is reported through onBlocked so the board can show it, instead of failing silently.
    function playAlert(root, onBlocked, onPlayed) {
        const audio = root.querySelector('[data-gac-monitor-audio]');
        if (!audio || !audio.getAttribute('src')) {
            return;
        }
        audio.currentTime = 0;
        const promise = audio.play();
        if (promise && promise.then) {
            promise.then(function () {
                if (onPlayed) {
                    onPlayed();
                }
            }).catch(function (e) {
                if (e && e.name === 'NotAllowedError' && onBlocked) {
                    onBlocked();
                }
            });
        }
    }

    // The new-ticket banner card (spec M18): background in the ticket's priority colour, text in
    // whichever of white/near-black reads on it. Built with textContent only: the data is user text.
    function buildBannerCard(row, more, priorityColors) {
        const info = row.banner || {};
        const color = (priorityColors && priorityColors[row.priority_raw]) || '#2b5fd9';

        function line(className, text) {
            const el = document.createElement('div');
            el.className = className;
            el.textContent = text;
            return el;
        }

        const card = document.createElement('div');
        card.className = 'gac-banner-card';
        card.style.backgroundColor = color;
        card.style.color = readableTextColor(color);

        card.appendChild(line('gac-banner-top', 'Novo ticket #' + row.id + (info.priority_label ? ' · ' + info.priority_label : '')));
        card.appendChild(line('gac-banner-title', info.title || ''));
        const meta = [info.requester, info.entity, info.category].filter(Boolean).join(' · ');
        if (meta) {
            card.appendChild(line('gac-banner-meta', meta));
        }
        if (info.description) {
            card.appendChild(line('gac-banner-description', info.description));
        }
        if (more > 0) {
            card.appendChild(line('gac-banner-more', 'e mais ' + more + (more === 1 ? ' ticket novo' : ' tickets novos')));
        }
        return card;
    }

    function wait(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function boot(root) {
        const url = root.dataset.ajaxUrl;
        // Mutable: a poll_interval_seconds/rotation_seconds change on the Tela takes effect from
        // the next cycle on, same as theme/font size.
        let interval = Math.max(5, parseInt(root.dataset.pollInterval, 10) || 15) * 1000;
        let rotationMs = 20000;
        const alertEnabled = root.dataset.alertEnabled === '1';
        const titleEl = root.querySelector('[data-gac-monitor-title]');
        const screenTitle = titleEl ? titleEl.textContent : '';

        // "Sound blocked" indicator: only meaningful when the alert is on and there is a sound to
        // play. Shown at load when the browser says it will not autoplay (getAutoplayPolicy) and
        // whenever a play() is refused; hidden once the user clicks the page.
        const soundIcon = root.querySelector('[data-gac-monitor-sound]');
        const audioEl = root.querySelector('[data-gac-monitor-audio]');
        const hasSound = alertEnabled && !!audioEl && !!audioEl.getAttribute('src');
        let soundBlocked = false;

        function setSoundBlocked(blocked) {
            soundBlocked = blocked;
            if (soundIcon) {
                soundIcon.hidden = !(hasSound && blocked);
            }
        }

        function announce() {
            playAlert(root, function () { setSoundBlocked(true); }, function () { setSoundBlocked(false); });
        }

        function autoplayDisallowed() {
            try {
                return !!navigator.getAutoplayPolicy && navigator.getAutoplayPolicy(audioEl) !== 'allowed';
            } catch (e) {
                return false;
            }
        }

        if (hasSound) {
            setSoundBlocked(autoplayDisallowed());
            // A real click is what the browser wants: after one, later play() calls are allowed.
            // A muted play is always accepted, so it is a harmless way to use that click.
            const unlock = function () {
                if (!soundBlocked) {
                    return;
                }
                audioEl.muted = true;
                audioEl.play().then(function () {
                    audioEl.pause();
                    audioEl.muted = false;
                    audioEl.currentTime = 0;
                    setSoundBlocked(false);
                }).catch(function () {
                    audioEl.muted = false;
                });
            };
            ['click', 'keydown', 'touchstart'].forEach(function (name) {
                document.addEventListener(name, unlock, { passive: true });
            });
        }

        let clockOffsetMs = 0;
        startClock(root, function () { return clockOffsetMs; });

        let lastSuccess = null;
        let fetching = false;

        // Per-page state (spec M13): the diff of "new ticket" is per page, kept in memory.
        let pages = [];
        let priorityColors = {};
        let activeId = null;
        let rotationTimer = null;
        let fading = false;
        let rotationKey = '';
        const previousIds = {}; // page id => Set of ticket ids seen at the last poll
        const newIds = {};      // page id => Set of ids that appeared at the last poll
        // page id => true while a hidden page holds an alert it has not played yet: its dot blinks
        // and the sound waits until the rotation brings the page to the screen.
        const flashing = {};

        // New-ticket banner (spec M18). A hidden page keeps the tickets that arrived on it in
        // pendingRows, announced (banner and sound) when the rotation brings the page to the screen.
        let bannerEnabled = false;
        let bannerSeconds = 10;
        const pendingRows = {};
        const bannerQueue = [];
        let bannerBusy = false;
        const BANNER_BATCH_MAX = 3;
        // A Tela deactivated (or its public link turned off) while it is on screen: see clearScreen().
        let unavailable = false;
        const emptyEl = root.querySelector('[data-gac-monitor-empty]');
        const emptyDefaultText = emptyEl ? emptyEl.textContent : '';
        const BANNER_QUEUE_MAX = 6;
        // Keep in step with the transition time of .gac-monitor-viewport in monitor.css.
        const PAGE_FADE_MS = 300;
        const reducedMotion = typeof window.matchMedia === 'function'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Returns the tickets the page ON SCREEN got that it did not have at the previous poll (to be
        // announced now). A hidden page only keeps them as pending until the rotation brings it to the
        // screen. The first load of each page never counts (no previous set yet).
        function diffPages(payloadPages) {
            const activeRows = [];
            const live = {};
            payloadPages.forEach(function (page) {
                const pid = String(page.id);
                live[pid] = true;
                const ids = page.rows.map(function (row) { return String(row.id); });
                const fresh = new Set();
                if (previousIds[pid]) {
                    ids.forEach(function (id) {
                        if (!previousIds[pid].has(id)) {
                            fresh.add(id);
                        }
                    });
                }
                previousIds[pid] = new Set(ids);
                newIds[pid] = fresh;
                if (fresh.size > 0) {
                    if (pid === activeId) {
                        page.rows.forEach(function (row) {
                            if (fresh.has(String(row.id))) {
                                activeRows.push(row);
                            }
                        });
                    } else {
                        flashing[pid] = true;
                        pendingRows[pid] = pendingRows[pid] || {};
                        fresh.forEach(function (id) { pendingRows[pid][id] = true; });
                    }
                }
                // A pending alert for a page that has since emptied has nothing left to announce.
                if (ids.length === 0) {
                    delete flashing[pid];
                    delete pendingRows[pid];
                }
            });
            Object.keys(previousIds).forEach(function (pid) {
                if (!live[pid]) {
                    delete previousIds[pid];
                    delete newIds[pid];
                    delete flashing[pid];
                    delete pendingRows[pid];
                }
            });
            return activeRows;
        }

        function activePage() {
            for (let i = 0; i < pages.length; i += 1) {
                if (String(pages[i].id) === activeId) {
                    return pages[i];
                }
            }
            return null;
        }

        // Shows the active page. Returns whether it brings an alert that was waiting for it, and the
        // tickets that arrived on it while it was hidden (only those still on the page).
        function showActive() {
            const table = root.querySelector('[data-gac-monitor-viewport] table');
            const empty = root.querySelector('[data-gac-monitor-empty]');
            if (pages.length === 0) {
                activeId = null;
                table.hidden = true;
                empty.hidden = false;
                if (titleEl) {
                    titleEl.textContent = screenTitle;
                }
                renderDots(root, pages, activeId, flashing);
                updateOverflow(root);
                return { alert: false, rows: [] };
            }
            if (activePage() === null) {
                activeId = String(pages[0].id);
            }
            const page = activePage();
            const arrivedWithAlert = flashing[activeId] === true;
            const waiting = pendingRows[activeId] || {};
            delete flashing[activeId];
            delete pendingRows[activeId];
            table.hidden = false;
            empty.hidden = true;
            // With several pages the page's own title replaces the main one (spec M12); a page
            // without a title keeps the Tela's name.
            if (titleEl) {
                titleEl.textContent = (pages.length > 1 && page.own_title) ? page.own_title : screenTitle;
            }
            renderHeader(root, page.columns);
            renderRows(root, page, newIds[activeId], priorityColors);
            renderDots(root, pages, activeId, flashing);
            updateOverflow(root);
            return {
                alert: arrivedWithAlert,
                rows: page.rows.filter(function (row) { return waiting[String(row.id)]; }),
            };
        }

        // Announces new tickets. With the banner on, each ticket gets its own banner, one after the
        // other and most urgent first, and the sound plays with each banner; otherwise the sound plays
        // once per cycle, as before.
        function enqueueBanners(rows) {
            const withData = rows.filter(function (row) { return row.banner; });
            if (withData.length === 0) {
                return;
            }
            const sorted = withData.slice().sort(function (a, b) { return (b.priority_raw || 0) - (a.priority_raw || 0); });
            const batch = sorted.slice(0, BANNER_BATCH_MAX);
            const extra = sorted.length - batch.length;
            batch.forEach(function (row, index) {
                if (bannerQueue.length < BANNER_QUEUE_MAX) {
                    bannerQueue.push({ row: row, more: index === batch.length - 1 ? extra : 0 });
                }
            });
            runBanners();
        }

        async function runBanners() {
            if (bannerBusy) {
                return;
            }
            bannerBusy = true;
            while (bannerQueue.length > 0) {
                await showBanner(bannerQueue.shift());
            }
            bannerBusy = false;
        }

        async function showBanner(item) {
            const layer = root.querySelector('[data-gac-monitor-banner]');
            if (!layer) {
                return;
            }
            const card = buildBannerCard(item.row, item.more, priorityColors);
            layer.innerHTML = '';
            layer.appendChild(card);
            layer.hidden = false;
            void card.offsetWidth; // so the entrance transition runs from the hidden state
            card.classList.add('gac-banner-in');
            if (alertEnabled) {
                announce();
            }
            await wait(bannerSeconds * 1000);
            card.classList.remove('gac-banner-in');
            await wait(600); // lets the exit transition finish
            layer.hidden = true;
            layer.innerHTML = '';
            await wait(250);
        }

        // What to do about tickets that are new on the page now on screen.
        function announceArrivals(rows, soundNeeded) {
            if (bannerEnabled) {
                enqueueBanners(rows);
            } else if (soundNeeded && alertEnabled) {
                announce();
            }
        }

        // The Tela stopped being available while it is open (spec M20): drop everything held in memory
        // (pages, the per-page ticket sets, pending alerts, queued banners, the rotation timer), empty
        // the table and say why. Polling goes on at the usual pace, so the board comes back by itself
        // if the Tela is reactivated; what it then loads counts as a first load (no alert).
        function clearScreen() {
            clearTimeout(rotationTimer);
            rotationTimer = null;
            rotationKey = '';
            pages = [];
            activeId = null;
            priorityColors = {};
            [previousIds, newIds, flashing, pendingRows].forEach(function (holder) {
                Object.keys(holder).forEach(function (key) { delete holder[key]; });
            });
            bannerQueue.length = 0;
            const layer = root.querySelector('[data-gac-monitor-banner]');
            if (layer) {
                layer.hidden = true;
                layer.innerHTML = '';
            }
            root.querySelector('[data-gac-monitor-head]').innerHTML = '';
            root.querySelector('[data-gac-monitor-body]').innerHTML = '';
            root.querySelector('[data-gac-monitor-viewport] table').hidden = true;
            if (titleEl) {
                titleEl.textContent = screenTitle;
            }
            renderDots(root, pages, activeId, flashing);
            const bar = root.querySelector('[data-gac-monitor-overflow]');
            if (bar) {
                bar.hidden = true;
            }
            if (emptyEl) {
                emptyEl.textContent = 'Esta Tela foi desativada ou não está mais disponível.';
                emptyEl.classList.add('gac-monitor-empty-alert');
                emptyEl.hidden = false;
            }
            unavailable = true;
        }

        // Moves to the next page and announces what arrived on it while it was hidden (banner and
        // sound). Looked up when it runs, not when the fade starts: a poll may change the pages
        // meanwhile.
        function goToNextPage() {
            fading = false;
            if (pages.length > 1) {
                let index = 0;
                for (let i = 0; i < pages.length; i += 1) {
                    if (String(pages[i].id) === activeId) {
                        index = i;
                    }
                }
                activeId = String(pages[(index + 1) % pages.length].id);
                const arrival = showActive();
                announceArrivals(arrival.rows, arrival.alert);
            }
            root.classList.remove('gac-page-fading');
        }

        // A short fade out, the swap, then the fade in (the CSS transition does both). Skipped for
        // people who asked their system for less motion.
        function rotate() {
            if (pages.length > 1) {
                if (reducedMotion || fading) {
                    goToNextPage();
                } else {
                    fading = true;
                    root.classList.add('gac-page-fading');
                    setTimeout(goToNextPage, PAGE_FADE_MS);
                }
            }
            scheduleRotation();
        }

        function scheduleRotation() {
            clearTimeout(rotationTimer);
            rotationTimer = null;
            if (pages.length > 1) {
                rotationTimer = setTimeout(rotate, rotationMs);
            }
        }

        // The ring represents idle wait time, not "time since the request was sent": it only
        // starts draining once a response actually comes back (see the finally block below),
        // and a setTimeout chain (not setInterval) means the next request only fires once that
        // drain finishes. While a request is in flight the ring just sits still, wherever the
        // previous drain left it — a slow or hung connection is then visible as the ring simply
        // not moving, instead of ticking along as if nothing were wrong.
        async function tick() {
            if (fetching) {
                return;
            }
            fetching = true;
            let nextDelay = interval;
            setWaitingState(root, true);
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (response.status === 429) {
                    // Rate limited (spec M21): keep the board as it is and wait at least as long as asked.
                    const retry = parseInt(response.headers.get('Retry-After'), 10);
                    if (!Number.isNaN(retry) && retry > 0) {
                        nextDelay = Math.max(interval, Math.min(retry, 300) * 1000);
                    }
                }
                const payload = await response.json();
                if (response.status === 404 && payload.code === 'screen_unavailable') {
                    clearScreen();
                    setConnectionState(root, false, lastSuccess || new Date());
                    return;
                }
                if (!response.ok || payload.error) {
                    throw new Error(payload.error || ('HTTP ' + response.status));
                }
                const serverNow = Date.parse(payload.generated_at);
                if (!Number.isNaN(serverNow)) {
                    clockOffsetMs = serverNow - Date.now();
                }
                const pollSeconds = parseInt(payload.poll_interval_seconds, 10);
                if (!Number.isNaN(pollSeconds) && pollSeconds > 0) {
                    interval = Math.max(5, pollSeconds) * 1000;
                }
                const rotationSeconds = parseInt(payload.rotation_seconds, 10);
                if (!Number.isNaN(rotationSeconds) && rotationSeconds > 0) {
                    rotationMs = Math.max(5, rotationSeconds) * 1000;
                }
                applyAppearance(root, payload.theme, payload.font_size_rem);
                if (unavailable) {
                    unavailable = false;
                    if (emptyEl) {
                        emptyEl.textContent = emptyDefaultText;
                        emptyEl.classList.remove('gac-monitor-empty-alert');
                    }
                }
                priorityColors = payload.priority_colors || {};
                bannerEnabled = payload.banner_enabled === true;
                const configuredSeconds = parseInt(payload.banner_seconds, 10);
                if (!Number.isNaN(configuredSeconds) && configuredSeconds > 0) {
                    bannerSeconds = configuredSeconds;
                }
                pages = payload.pages || [];
                // One alert per cycle, not one per new ticket (plan "Decisões de implementação" item 8).
                const freshRows = diffPages(pages);
                const arrival = showActive();
                // Restart the rotation timer only when its parameters change: restarting it on
                // every poll would postpone the rotation forever whenever polling is faster.
                const key = rotationMs + '|' + pages.length;
                if (key !== rotationKey) {
                    rotationKey = key;
                    scheduleRotation();
                }
                lastSuccess = new Date();
                setConnectionState(root, true, lastSuccess);
                announceArrivals(freshRows.concat(arrival.rows), freshRows.length > 0 || arrival.alert);
            } catch (e) {
                setConnectionState(root, false, lastSuccess || new Date());
            } finally {
                setWaitingState(root, false);
                restartCountdown(root, nextDelay);
                fetching = false;
                setTimeout(tick, nextDelay);
            }
        }

        window.addEventListener('resize', function () { updateOverflow(root); });

        tick();
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
