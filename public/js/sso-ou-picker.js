/**
 * Pickers of Google org units and workspaces for the SSO module (spec S29, S30).
 *
 * The first half is pure (no DOM) and is unit tested with `node --test tests/js/`. The second half
 * (added in the next tasks) wires it to the GLPI authorization rule form and to the plugin
 * configuration page.
 */
(function (root) {
    'use strict';

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /** The text of an option: the workspace name, then the path. The stored value is only the path. */
    function optionLabel(workspaceName, path, repeated) {
        return workspaceName + ' - ' + path + (repeated ? ' (repetida)' : '');
    }

    /**
     * @param {{workspaces: Array}} payload the orgunits endpoint answer
     * @param {string} onlyWorkspaceKey narrows to one workspace; empty keeps all
     * @returns {Array<{id: string, text: string, workspace: string}>}
     */
    function buildOptions(payload, onlyWorkspaceKey) {
        var options = [];
        ((payload && payload.workspaces) || []).forEach(function (workspace) {
            if (workspace.error) { return; }
            if (onlyWorkspaceKey && workspace.key !== onlyWorkspaceKey) { return; }
            (workspace.ous || []).forEach(function (ou) {
                options.push({
                    id: ou.path,
                    text: optionLabel(workspace.name, ou.path, ou.repeated),
                    workspace: workspace.key
                });
            });
        });

        return options;
    }

    /** Adds a path as a new line of the blocked OUs text, unless a line already has it. */
    function appendPathLine(text, path) {
        var wanted = String(path).trim();
        var current = String(text);
        if (wanted === '') { return current; }

        var lower = wanted.toLowerCase();
        var present = current.split(/\r?\n/).some(function (line) {
            var trimmed = line.trim();
            return trimmed !== '' && trimmed.charAt(0) !== '#' && trimmed.toLowerCase() === lower;
        });
        if (present) { return current; }

        var base = current.replace(/\s+$/, '');

        return (base === '' ? '' : base + '\n') + wanted;
    }

    // ---- DOM wiring (not unit tested: browser checklist in docs/sso-manual-tests.md) ----

    var TEXT = {
        placeholderOu: 'Escolha uma OU ou digite o caminho',
        refresh: 'Atualizar a lista de OUs',
        unavailable: 'Lista de OUs indisponível agora: digite o caminho da OU.',
        chooseWorkspace: 'Escolha o workspace'
    };

    var cache = {};

    function hasSelect2() {
        return !!(root.jQuery && root.jQuery.fn && root.jQuery.fn.select2);
    }

    function endpoint() {
        return ((root.CFG_GLPI && root.CFG_GLPI.root_doc) || '') + '/plugins/gac/ajax/sso/orgunits.php';
    }

    function fetchData(ruleId, refresh) {
        var key = String(ruleId || 0);
        if (!refresh && cache[key]) { return cache[key]; }

        var url = endpoint() + '?rule_id=' + encodeURIComponent(key) + (refresh ? '&refresh=1' : '');
        cache[key] = root.fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (response) {
                if (!response.ok) { throw new Error('http ' + response.status); }
                return response.json();
            })
            .catch(function (error) {
                delete cache[key];
                throw error;
            });

        return cache[key];
    }

    function currentRuleId() {
        var field = root.document.querySelector('input[name="rules_id"]');
        return field ? (parseInt(field.value, 10) || 0) : 0;
    }

    function showUnavailable(input) {
        if (!input.isConnected || input.parentNode.querySelector('.gac-picker-note')) { return; }
        var note = root.document.createElement('div');
        note.className = 'form-text gac-picker-note';
        note.textContent = TEXT.unavailable;
        input.insertAdjacentElement('afterend', note);
    }

    /** Fills a select with an empty option, the options and, if missing, the value already saved. */
    function fillSelect(select, options, current) {
        select.innerHTML = '';
        select.add(new Option('', '', false, current === ''));
        var seen = false;
        options.forEach(function (option) {
            var selected = option.id === current;
            seen = seen || selected;
            select.add(new Option(option.text, option.id, false, selected));
        });
        if (current !== '' && !seen) {
            select.add(new Option(current, current, true, true));
        }
    }

    function buildOuSelect(input, payload, rid) {
        var current = input.value;
        var wrapper = root.document.createElement('div');
        wrapper.className = 'd-flex gap-1 w-100 align-items-start';
        var select = root.document.createElement('select');
        select.name = input.name;
        select.className = 'form-select';
        wrapper.appendChild(select);

        var narrowed = function (data) { return data.filter_workspace || ''; };
        fillSelect(select, buildOptions(payload, narrowed(payload)), current);
        input.replaceWith(wrapper);

        var $ = root.jQuery;
        $(select).select2({
            width: '100%',
            tags: true,
            placeholder: TEXT.placeholderOu,
            createTag: function (params) {
                var term = String(params.term || '').trim();
                return term === '' ? null : { id: term, text: term };
            }
        });

        var button = root.document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-sm btn-ghost-secondary';
        button.title = TEXT.refresh;
        button.setAttribute('aria-label', TEXT.refresh);
        button.innerHTML = '<i class="ti ti-refresh"></i>';
        button.addEventListener('click', function () {
            button.disabled = true;
            fetchData(rid, true)
                .then(function (fresh) {
                    fillSelect(select, buildOptions(fresh, narrowed(fresh)), select.value);
                    $(select).trigger('change.select2');
                })
                .catch(function () {})
                .then(function () { button.disabled = false; });
        });
        wrapper.appendChild(button);
    }

    function buildWorkspaceSelect(input, payload) {
        var current = input.value;
        var select = root.document.createElement('select');
        select.name = input.name;
        select.className = 'form-select';
        select.required = true;
        select.add(new Option(TEXT.chooseWorkspace, '', current === '', current === ''));
        var seen = false;
        (payload.workspaces || []).forEach(function (workspace) {
            var selected = workspace.key === current;
            seen = seen || selected;
            select.add(new Option(workspace.name + ' (' + workspace.key + ')', workspace.key, false, selected));
        });
        if (current !== '' && !seen) {
            select.add(new Option(current, current, true, true));
        }
        input.replaceWith(select);
    }

    function enhancePattern(input, criterion) {
        input.setAttribute('data-gac-picker', '1');
        var rid = currentRuleId();

        fetchData(rid, false)
            .then(function (payload) {
                if (!input.isConnected) { return; }
                if (criterion === 'GOOGLE_WORKSPACE') {
                    buildWorkspaceSelect(input, payload);
                } else {
                    buildOuSelect(input, payload, rid);
                }
            })
            .catch(function () { showUnavailable(input); });
    }

    function enhanceRuleForm() {
        var criteria = root.document.querySelector('select[name="criteria"]');
        var input = root.document.querySelector('input[name="pattern"]');
        if (!criteria || !input || input.getAttribute('data-gac-picker')) { return; }

        var criterion = criteria.value;
        if (criterion !== 'GOOGLE_OU' && criterion !== 'GOOGLE_WORKSPACE') { return; }
        if (!hasSelect2()) { return; }

        enhancePattern(input, criterion);
    }

    function scan() {
        enhanceRuleForm();
    }

    if (typeof document !== 'undefined') {
        var scheduled = false;
        var schedule = function () {
            if (scheduled) { return; }
            scheduled = true;
            setTimeout(function () { scheduled = false; scan(); }, 0);
        };
        document.addEventListener('DOMContentLoaded', schedule);
        new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
        schedule();
    }

    var api = {
        escapeHtml: escapeHtml,
        optionLabel: optionLabel,
        buildOptions: buildOptions,
        appendPathLine: appendPathLine
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = api;
    }
    root.GacSsoPicker = api;
})(typeof window !== 'undefined' ? window : globalThis);
