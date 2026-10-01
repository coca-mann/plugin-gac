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

namespace GlpiPlugin\Gac\Monitor;

use Auth;
use Session;

/**
 * Logs in as the Monitor service account (spec R-1, found during Task 8) so
 * Search::getDatas('Ticket', ...) has the session shape it implicitly expects
 * (profile, groups — not just entities), for the public, session-less display path.
 * The login/logout happens entirely inside one request: the client (TV) never holds a cookie,
 * and the session is torn down again before the response is sent, so nothing lingers between
 * polls.
 */
final class ServiceSession
{
    /** @return bool true if the service account logged in; false on misconfiguration or bad credentials */
    public static function login(array $settings): bool
    {
        if (!MonitorSettings::hasServiceAccount($settings)) {
            return false;
        }

        $auth = new Auth();
        $ok   = $auth->login(
            MonitorSettings::serviceUsername($settings),
            MonitorSettings::servicePassword($settings),
            true
        );

        if (!$ok || !$auth->auth_succeded) {
            return false;
        }

        // Status/priority labels (ScreenQuery::columnValue()) come from GLPI's translation of
        // the current session language, which otherwise follows the service account's own
        // preference — force pt-BR so the public board matches the rest of the plugin (CLAUDE.md:
        // user-facing strings are pt-BR) regardless of how that account happens to be configured.
        Session::loadLanguage('pt_BR');

        return true;
    }

    /** Tears down whatever login() started: no session file or cookie should outlive the request. */
    public static function logout(): void
    {
        Session::destroy();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
    }
}
