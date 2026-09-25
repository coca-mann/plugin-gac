(function () {
    'use strict';

    function csrfToken() {
        return typeof window.getAjaxCsrfToken === 'function' ? window.getAjaxCsrfToken() : '';
    }

    async function post(url, params) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Glpi-Csrf-Token': csrfToken(),
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams(params).toString(),
        });
        try {
            return await response.json();
        } catch (e) {
            return { success: false, message: 'Resposta inválida do servidor.', data: {} };
        }
    }

    function show(root, text, level) {
        const box = root.querySelector('[data-gac-progress]');
        if (!box) {
            return;
        }
        box.textContent = text;
        box.className = 'flex-grow-1 ' + (level === 'error' ? 'text-danger' : 'text-muted');
    }

    async function runSend(button) {
        const root = button.closest('[data-gac-pre]');
        const url = root.dataset.ajaxSend;
        const protocolId = root.dataset.protocolId;
        const mode = button.dataset.gacSend;
        button.disabled = true;

        let lineIds = [];
        if (mode === 'start') {
            const started = await post(url, { action: 'start', protocol_id: protocolId });
            if (!started.success) {
                show(root, started.message, 'error');
                button.disabled = false;
                return;
            }
            lineIds = started.data.line_ids;
        } else if (mode === 'continue') {
            lineIds = JSON.parse(button.dataset.lineIds || '[]');
        }

        let failed = 0;
        for (let i = 0; i < lineIds.length; i++) {
            show(root, 'Enviando ' + (i + 1) + ' de ' + lineIds.length + '...', 'info');
            const sent = await post(url, { action: 'line', line_id: lineIds[i] });
            if (!sent.success) {
                failed++;
            }
        }

        if (failed === 0) {
            show(root, 'Gerando o documento de envio...', 'info');
            const finalized = await post(url, { action: 'finalize', protocol_id: protocolId });
            if (!finalized.success) {
                show(root, finalized.message, 'error');
                button.disabled = false;
                return;
            }
        } else {
            show(root, failed + ' linha(s) falharam. Veja o erro na linha; use "Continuar envio" ou remova a linha com falha.', 'error');
            window.setTimeout(function () { window.location.reload(); }, 2500);
            return;
        }
        window.location.reload();
    }

    async function runRemoveFailed(button) {
        const root = button.closest('[data-gac-pre]');
        const group = button.closest('.input-group');
        const reason = group.querySelector('[data-gac-remove-reason]').value;
        const result = await post(root.dataset.ajaxSend, {
            action: 'remove_line',
            line_id: button.dataset.gacRemoveFailed,
            reason: reason,
        });
        if (!result.success) {
            show(root, result.message, 'error');
            return;
        }
        window.location.reload();
    }

    document.addEventListener('click', function (event) {
        const send = event.target.closest('[data-gac-send]');
        if (send) {
            event.preventDefault();
            runSend(send);
            return;
        }
        const remove = event.target.closest('[data-gac-remove-failed]');
        if (remove) {
            event.preventDefault();
            runRemoveFailed(remove);
        }
    });

    // Return, lost and correction forms post via XHR and refresh only the items tab, so the page
    // keeps its scroll position (a PRE can have dozens of lines).
    // Floating, because the user is usually scrolled far from the top of the tab.
    function notice(text, ok) {
        const box = document.createElement('div');
        box.className = 'alert ' + (ok ? 'alert-success' : 'alert-danger') + ' position-fixed bottom-0 end-0 m-3 shadow';
        box.style.zIndex = '2000';
        box.style.maxWidth = '28rem';
        box.setAttribute('role', 'alert');
        box.textContent = text;
        document.body.append(box);
        window.setTimeout(function () { box.remove(); }, ok ? 8000 : 15000);
    }

    document.addEventListener('submit', async function (event) {
        const form = event.target.closest('[data-gac-ajax-form]');
        if (!form) {
            return;
        }
        event.preventDefault();

        const root = form.closest('[data-gac-pre]');
        const tabUrl = root.dataset.tabUrl;
        // Built before the buttons are disabled: a disabled submitter is left out of the data.
        const body = new FormData(form, event.submitter);
        const buttons = form.querySelectorAll('button[type="submit"]');
        buttons.forEach(function (b) { b.disabled = true; });
        const scrollY = window.scrollY;

        let result;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-Glpi-Csrf-Token': csrfToken(),
                },
                body: body,
            });
            result = await response.json();
        } catch (e) {
            result = { success: false, message: 'Não foi possível concluir a operação. Recarregue a página e confira o estado.' };
        }

        if (result.success) {
            try {
                const html = await (await fetch(tabUrl, { credentials: 'same-origin' })).text();
                // A contextual fragment keeps the inline scripts runnable (dropdown and date pickers).
                const fragment = document.createRange().createContextualFragment(html);
                const fresh = fragment.querySelector('[data-gac-pre]');
                if (fresh) {
                    root.replaceWith(fresh);
                }
            } catch (e) {
                window.location.reload();
                return;
            }
        } else {
            buttons.forEach(function (b) { b.disabled = false; });
        }
        notice(result.message, result.success);
        window.scrollTo({ top: scrollY, behavior: 'instant' });
    });

    // Destination only applies to defective outcomes; pre-select the usual one (spec 6.3).
    const DEFAULT_DESTINATION = { unrepairable: 'writeoff', quote_rejected: 'keep_defective' };

    // The outcome is a GLPI (select2) dropdown, which only notifies jQuery handlers.
    window.jQuery(document).on('change', '[data-gac-return-form] select[name="outcome"]', function () {
        const select = this;
        const form = select.closest('[data-gac-return-form]');
        const group = form.querySelector('[data-gac-destination-group]');
        const destination = form.querySelector('select[name="destination"]');
        const defective = JSON.parse(form.dataset.gacDefective || '[]').indexOf(select.value) !== -1;
        group.hidden = !defective;
        window.jQuery(destination).prop('disabled', !defective);
        if (defective && DEFAULT_DESTINATION[select.value]) {
            window.jQuery(destination).val(DEFAULT_DESTINATION[select.value]).trigger('change');
        }
    });

})();
