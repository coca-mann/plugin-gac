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

use GlpiPlugin\Gac\Sso\LoginService;

global $CFG_GLPI;

// Same trick as GLPI's own OAuth callback (front/smtp_oauth2_callback.php): with
// session.cookie_samesite = strict the session cookie is not sent on the redirect back from
// Google, so bounce once through a same-site refresh before reading the session.
if (!array_key_exists('cookie_refresh', $_GET)) {
    $url = htmlescape(
        $_SERVER['REQUEST_URI']
        . (str_contains($_SERVER['REQUEST_URI'], '?') ? '&' : '?')
        . 'cookie_refresh'
    );
    echo "<html><head><meta http-equiv=\"refresh\" content=\"0;URL='{$url}'\"/></head><body></body></html>";
    return;
}

// Single use: read and drop the state before the login replaces the session.
$state = isset($_SESSION['gac_sso']) && is_array($_SESSION['gac_sso']) ? $_SESSION['gac_sso'] : null;
unset($_SESSION['gac_sso']);

$result = LoginService::handleCallback($_GET, $state);

if ($result->ok) {
    // Toolbox::manageRedirect() (called by this method) only follows local destinations.
    Auth::redirectIfAuthenticated($result->data['redirect'] !== '' ? $result->data['redirect'] : null);
}

Html::redirect($CFG_GLPI['root_doc'] . '/front/login.php?sso_error=' . (int) ($result->data['event_id'] ?? 0));
