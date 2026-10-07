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

    function playAlert(root) {
        const audio = root.querySelector('[data-gac-monitor-audio]');
        if (audio) {
            audio.currentTime = 0;
            audio.play().catch(function () { /* autoplay pode estar bloqueado até um gesto do usuário */ });
        }
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

        let clockOffsetMs = 0;
        startClock(root, function () { return clockOffsetMs; });

        let lastSuccess = null;
        let fetching = false;

        // Per-page state (spec M13): the diff of "new ticket" is per page, kept in memory.
        let pages = [];
        let priorityColors = {};
        let activeId = null;
        let rotationTimer = null;
        let rotationKey = '';
        const previousIds = {}; // page id => Set of ticket ids seen at the last poll
        const newIds = {};      // page id => Set of ids that appeared at the last poll
        // page id => true while a hidden page holds an alert it has not played yet: its dot blinks
        // and the sound waits until the rotation brings the page to the screen.
        const flashing = {};

        // Returns true when the page ON SCREEN got a ticket it did not have at the previous poll
        // (to be announced now). A hidden page only gets its alert marked as pending. The first
        // load of each page never counts (no previous set yet).
        function diffPages(payloadPages) {
            let anyNew = false;
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
                        anyNew = true;
                    } else {
                        flashing[pid] = true;
                    }
                }
                // A pending alert for a page that has since emptied has nothing left to announce.
                if (ids.length === 0) {
                    delete flashing[pid];
                }
            });
            Object.keys(previousIds).forEach(function (pid) {
                if (!live[pid]) {
                    delete previousIds[pid];
                    delete newIds[pid];
                    delete flashing[pid];
                }
            });
            return anyNew;
        }

        function activePage() {
            for (let i = 0; i < pages.length; i += 1) {
                if (String(pages[i].id) === activeId) {
                    return pages[i];
                }
            }
            return null;
        }

        // Shows the active page. Returns true when it brings an alert that was waiting for it.
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
                return false;
            }
            if (activePage() === null) {
                activeId = String(pages[0].id);
            }
            const page = activePage();
            const arrivedWithAlert = flashing[activeId] === true;
            delete flashing[activeId];
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
            return arrivedWithAlert;
        }

        function rotate() {
            if (pages.length > 1) {
                let index = 0;
                for (let i = 0; i < pages.length; i += 1) {
                    if (String(pages[i].id) === activeId) {
                        index = i;
                    }
                }
                activeId = String(pages[(index + 1) % pages.length].id);
                // The sound of a ticket that arrived while this page was hidden plays now.
                if (showActive() && alertEnabled) {
                    playAlert(root);
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
            setWaitingState(root, true);
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const payload = await response.json();
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
                priorityColors = payload.priority_colors || {};
                pages = payload.pages || [];
                // One alert per cycle, not one per new ticket (plan "Decisões de implementação" item 8).
                const hasNew = diffPages(pages);
                const arrived = showActive();
                // Restart the rotation timer only when its parameters change: restarting it on
                // every poll would postpone the rotation forever whenever polling is faster.
                const key = rotationMs + '|' + pages.length;
                if (key !== rotationKey) {
                    rotationKey = key;
                    scheduleRotation();
                }
                lastSuccess = new Date();
                setConnectionState(root, true, lastSuccess);
                if ((hasNew || arrived) && alertEnabled) {
                    playAlert(root);
                }
            } catch (e) {
                setConnectionState(root, false, lastSuccess || new Date());
            } finally {
                setWaitingState(root, false);
                restartCountdown(root, interval);
                fetching = false;
                setTimeout(tick, interval);
            }
        }

        window.addEventListener('resize', function () { updateOverflow(root); });

        tick();
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
