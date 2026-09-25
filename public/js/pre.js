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
    // Destination only applies to defective outcomes; pre-select the usual one (spec 6.3).
    const DEFAULT_DESTINATION = { unrepairable: 'writeoff', quote_rejected: 'keep_defective' };

    document.addEventListener('change', function (event) {
        const select = event.target.closest('[data-gac-outcome]');
        if (!select) {
            return;
        }
        const form = select.closest('[data-gac-return-form]');
        const group = form.querySelector('[data-gac-destination-group]');
        const destination = form.querySelector('[data-gac-destination]');
        const defective = select.selectedOptions[0] && select.selectedOptions[0].dataset.defective === '1';
        group.hidden = !defective;
        destination.disabled = !defective;
        if (defective && DEFAULT_DESTINATION[select.value]) {
            destination.value = DEFAULT_DESTINATION[select.value];
        }
    });

})();
