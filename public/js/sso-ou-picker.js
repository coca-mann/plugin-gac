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
