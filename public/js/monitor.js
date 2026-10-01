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

    function renderHeader(root, columns) {
        const headRow = root.querySelector('[data-gac-monitor-head]');
        headRow.innerHTML = '';
        columns.forEach(function (col) {
            const th = document.createElement('th');
            th.textContent = col.label;
            headRow.appendChild(th);
        });
    }

    function renderRows(root, columns, rows, priorityColors) {
        const body = root.querySelector('[data-gac-monitor-body]');
        const previousIds = Array.prototype.slice.call(body.children).map(function (tr) {
            return tr.dataset.ticketId;
        });

        body.innerHTML = '';
        let hasNew = false;
        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.dataset.ticketId = row.id;
            const color = priorityColors && priorityColors[row.priority_raw];
            if (color) {
                tr.style.setProperty('--gac-row-accent', color);
            }
            if (previousIds.length > 0 && previousIds.indexOf(row.id) === -1) {
                tr.classList.add('gac-monitor-row-new');
                hasNew = true;
            }
            columns.forEach(function (col) {
                const td = document.createElement('td');
                if (col.key === 'priority' && color) {
                    const badge = document.createElement('span');
                    badge.className = 'gac-priority-badge';
                    badge.style.backgroundColor = color;
                    badge.style.color = readableTextColor(color);
                    badge.textContent = row[col.key] || '';
                    td.appendChild(badge);
                } else {
                    td.textContent = row[col.key] || '';
                }
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
        return hasNew;
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
        // Mutable: a poll_interval_seconds change on the Tela (see applyAppearance's sibling
        // update in tick()) takes effect from the next cycle on, same as theme/font size.
        let interval = Math.max(5, parseInt(root.dataset.pollInterval, 10) || 15) * 1000;
        const alertEnabled = root.dataset.alertEnabled === '1';

        let clockOffsetMs = 0;
        startClock(root, function () { return clockOffsetMs; });

        let lastSuccess = null;
        let fetching = false;
        let firstLoad = true;

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
                applyAppearance(root, payload.theme, payload.font_size_rem);
                renderHeader(root, payload.columns);
                // Um alerta por ciclo, não um por ticket novo (plan, "Decisões de implementação" item 8).
                const hasNew = renderRows(root, payload.columns, payload.rows, payload.priority_colors);
                lastSuccess = new Date();
                setConnectionState(root, true, lastSuccess);
                if (hasNew && alertEnabled && !firstLoad) {
                    playAlert(root);
                }
                firstLoad = false;
            } catch (e) {
                setConnectionState(root, false, lastSuccess || new Date());
            } finally {
                setWaitingState(root, false);
                restartCountdown(root, interval);
                fetching = false;
                setTimeout(tick, interval);
            }
        }

        tick();
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
