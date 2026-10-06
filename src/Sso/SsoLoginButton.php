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
 * What the display_login hook prints in the panel beside the login form: the error box (when a
 * login just failed), the "Entrar com Google" button and, in hidden-form mode, the script that
 * hides the password form unless the URL has ?local=1 (spec S16). Everything here fails open: a
 * module that is off or not configured prints nothing, and the form is only ever hidden by
 * script that this same output carries.
 */
final class SsoLoginButton
{
    public static function render(): string
    {
        global $CFG_GLPI;

        $settings = SsoConfig::load();
        if (!SsoSettings::isConfigured($settings)) {
            return '';
        }

        $startUrl = $CFG_GLPI['root_doc'] . '/plugins/gac/front/sso/start.php';
        $redirect = $_GET['redirect'] ?? '';
        if (is_string($redirect) && $redirect !== '') {
            $startUrl .= '?redirect=' . rawurlencode($redirect);
        }

        $hide = SsoSettings::hideLocalForm($settings) && !isset($_GET['local']);
        $html = '';

        $errorId = $_GET['sso_error'] ?? '';
        if (is_string($errorId) && $errorId !== '' && ctype_digit($errorId)) {
            $html .= "<div class='alert alert-danger text-start' role='alert'>"
                . htmlescape(sprintf(
                    __('Não foi possível entrar com o Google. Procure o DTI informando o código %s.', 'gac'),
                    $errorId
                ))
                . '</div>';
        }

        $html .= "<div class='gac-sso-login mb-3'>"
            . GoogleButton::render($startUrl, SsoSettings::buttonLabel($settings));

        if ($hide) {
            $localUrl = '?' . http_build_query(array_merge(array_diff_key($_GET, ['sso_error' => 1]), ['local' => 1]));
            $html .= "<div class='mt-3' id='gac-sso-local-toggle'><a class='btn btn-ghost-secondary btn-sm' href='" . htmlescape($localUrl) . "'>"
                . "<i class='ti ti-key me-1'></i>"
                . htmlescape(__('Entrar com usuário e senha', 'gac')) . '</a></div>';
        } else {
            $html .= "<div class='gac-sso-or'><span>" . htmlescape(__('ou', 'gac')) . '</span></div>';
        }
        $html .= '</div>';

        if (!$hide) {
            // Form visible: stack it under the Google button (same layout as the unfolded form of
            // the hidden mode) instead of leaving the two side by side. Without the script, or if a
            // piece is missing, the original two columns stay as they are.
            $html .= '<style>'
                . '.gac-sso-stacked{text-align:left}'
                . '.gac-sso-stacked .select2-container{width:100%!important}'
                . '</style>'
                . '<script>(function () {'
                . "var field = document.getElementById('login_name');"
                . "var column = field ? field.closest('.col-md-5') : null;"
                . "var panel = document.querySelector('.gac-sso-login');"
                . 'if (!column || !panel) { return; }'
                . "column.classList.remove('col-md-5');"
                . "column.classList.add('gac-sso-stacked');"
                . 'panel.appendChild(column);'
                . '})();</script>';
        }

        if ($hide) {
            // The login fields sit in the sibling column. Move it under the Google button, folded,
            // and unfold it with a slide when the "usuário e senha" button is clicked. Without this
            // script (or with JS off) the normal form simply stays visible and the link still
            // reloads the page with ?local=1; if any piece is missing nothing is hidden.
            $html .= '<style>'
                . '.gac-sso-local{max-height:0;opacity:0;overflow:hidden;visibility:hidden;text-align:left;'
                . 'transition:max-height .45s ease,opacity .35s ease .1s,visibility 0s linear .45s}'
                . '.gac-sso-local.is-open{opacity:1;visibility:visible;'
                . 'transition:max-height .45s ease,opacity .35s ease .1s,visibility 0s}'
                . '.gac-sso-local .select2-container{width:100%!important}'
                . '</style>'
                . '<script>(function () {'
                . "var field = document.getElementById('login_name');"
                . "var column = field ? field.closest('.col-md-5') : null;"
                . "var toggle = document.getElementById('gac-sso-local-toggle');"
                . "var panel = document.querySelector('.gac-sso-login');"
                . 'if (!column || !toggle || !panel) { return; }'
                . "column.classList.remove('col-md-5');"
                . "column.classList.add('gac-sso-local');"
                . "var separator = document.createElement('div');"
                . "separator.className = 'gac-sso-or';"
                . "separator.innerHTML = '<span></span>';"
                . "separator.firstChild.textContent = " . json_encode(__('ou', 'gac')) . ';'
                . 'column.insertBefore(separator, column.firstChild);'
                . 'panel.appendChild(column);'
                . "toggle.addEventListener('click', function (event) {"
                . 'event.preventDefault();'
                . "column.style.maxHeight = column.scrollHeight + 'px';"
                . "column.classList.add('is-open');"
                . "toggle.style.display = 'none';"
                . "column.addEventListener('transitionend', function done(e) {"
                . "if (e.propertyName !== 'max-height') { return; }"
                . "column.removeEventListener('transitionend', done);"
                . "column.style.maxHeight = 'none';"
                . "column.scrollIntoView({behavior: 'smooth', block: 'nearest'});"
                . 'field.focus();'
                . '});'
                . '});'
                . '})();</script>';
        }

        return $html;
    }
}
