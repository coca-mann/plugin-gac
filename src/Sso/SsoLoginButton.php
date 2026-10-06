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
            . "<a class='btn btn-outline-primary w-100' href='" . htmlescape($startUrl) . "'>"
            . "<i class='ti ti-brand-google me-2'></i>" . htmlescape(SsoSettings::buttonLabel($settings)) . '</a>';

        if ($hide) {
            $localUrl = '?' . http_build_query(array_merge(array_diff_key($_GET, ['sso_error' => 1]), ['local' => 1]));
            $html .= "<div class='mt-2'><a class='small text-muted' href='" . htmlescape($localUrl) . "'>"
                . htmlescape(__('Entrar com usuário e senha', 'gac')) . '</a></div>';
        }
        $html .= '</div>';

        if ($hide) {
            // The login fields sit in the sibling column; hide it. Without this script (or with
            // JS off) the normal form simply stays visible.
            $html .= '<script>(function () {'
                . "var field = document.getElementById('login_name');"
                . "var column = field ? field.closest('.col-md-5') : null;"
                . "if (column) { column.style.display = 'none'; }"
                . '})();</script>';
        }

        return $html;
    }
}
