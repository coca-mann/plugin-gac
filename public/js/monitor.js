(function () {
    'use strict';

    function formatTime(date) {
        return date.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
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

    function renderRows(root, columns, rows) {
        const body = root.querySelector('[data-gac-monitor-body]');
        const previousIds = Array.prototype.slice.call(body.children).map(function (tr) {
            return tr.dataset.ticketId;
        });

        body.innerHTML = '';
        let hasNew = false;
        rows.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.dataset.ticketId = row.id;
            if (previousIds.length > 0 && previousIds.indexOf(row.id) === -1) {
                tr.classList.add('gac-monitor-row-new');
                hasNew = true;
            }
            columns.forEach(function (col) {
                const td = document.createElement('td');
                td.textContent = row[col.key] || '';
                tr.appendChild(td);
            });
            body.appendChild(tr);
        });
        return hasNew;
    }

    function setStatus(root, ok, when) {
        const indicator = root.querySelector('[data-gac-monitor-status]');
        if (!indicator) {
            return;
        }
        indicator.classList.toggle('gac-monitor-status-ok', ok);
        indicator.classList.toggle('gac-monitor-status-stale', !ok);
        indicator.title = ok
            ? 'Atualizado às ' + formatTime(when)
            : 'Dados desatualizados; última atualização bem-sucedida às ' + formatTime(when);
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
        const interval = Math.max(5, parseInt(root.dataset.pollInterval, 10) || 15) * 1000;
        const alertEnabled = root.dataset.alertEnabled === '1';

        let lastSuccess = null;
        let fetching = false;
        let firstLoad = true;

        async function tick() {
            if (fetching) {
                return;
            }
            fetching = true;
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                const payload = await response.json();
                if (!response.ok || payload.error) {
                    throw new Error(payload.error || ('HTTP ' + response.status));
                }
                renderHeader(root, payload.columns);
                // Um alerta por ciclo, não um por ticket novo (plan, "Decisões de implementação" item 8).
                const hasNew = renderRows(root, payload.columns, payload.rows);
                lastSuccess = new Date();
                setStatus(root, true, lastSuccess);
                if (hasNew && alertEnabled && !firstLoad) {
                    playAlert(root);
                }
                firstLoad = false;
            } catch (e) {
                setStatus(root, false, lastSuccess || new Date());
            } finally {
                fetching = false;
            }
        }

        tick();
        setInterval(tick, interval);
    }

    document.querySelectorAll('[data-gac-monitor]').forEach(boot);
})();
