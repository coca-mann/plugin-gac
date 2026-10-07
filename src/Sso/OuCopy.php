<?php

/**
 * -------------------------------------------------------------------------
 * Gac plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2026 by the Gac plugin team.
 * @license   MIT https://opensource.org/licenses/mit-license.php
 * @link      https://github.com/coca-mann/plugin-gac
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

/**
 * The "copy the OU path" button shown next to every OU of the SSO screens, so the path can be pasted
 * into an authorization rule. One delegated click handler (`script()`) serves all the buttons of a page.
 */
final class OuCopy
{
    /** The button for one OU path; empty when there is no OU. */
    public static function button(string $ou): string
    {
        if ($ou === '') {
            return '';
        }

        $label = htmlescape(__('Copiar o caminho da OU', 'gac'));

        return "<button type='button' class='btn btn-sm btn-ghost-secondary p-1 ms-1 gac-copy-ou' data-copy='"
            . htmlescape($ou) . "' title='" . $label . "' aria-label='" . $label . "'><i class='ti ti-copy'></i></button>";
    }

    /**
     * The handler, plus `window.gacOuCopyButton(ou)` for pages that build their rows in JS. Emit it once
     * per page, after the buttons.
     */
    public static function script(): string
    {
        $title = json_encode(__('Copiar o caminho da OU', 'gac'), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP);

        return <<<HTML
<script>
(function () {
    if (window.gacOuCopyButton) { return; }
    var title = {$title};
    window.gacOuCopyButton = function (ou) {
        if (!ou) { return ''; }
        var value = String(ou).replace(/&/g, '&amp;').replace(/'/g, '&#39;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        return '<button type="button" class="btn btn-sm btn-ghost-secondary p-1 ms-1 gac-copy-ou" data-copy="' + value
            + '" title="' + title + '" aria-label="' + title + '"><i class="ti ti-copy"></i></button>';
    };
    function legacyCopy(text) {
        var area = document.createElement('textarea');
        area.value = text;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(area);
        return ok ? Promise.resolve() : Promise.reject(new Error('copy failed'));
    }
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('.gac-copy-ou') : null;
        if (!button) { return; }
        event.preventDefault();
        var text = button.getAttribute('data-copy') || '';
        var done = navigator.clipboard && window.isSecureContext
            ? navigator.clipboard.writeText(text).catch(function () { return legacyCopy(text); })
            : legacyCopy(text);
        done.then(function () {
            var icon = button.querySelector('i');
            if (!icon) { return; }
            icon.className = 'ti ti-check text-success';
            setTimeout(function () { icon.className = 'ti ti-copy'; }, 1500);
        }).catch(function () {});
    });
})();
</script>
HTML;
    }
}
