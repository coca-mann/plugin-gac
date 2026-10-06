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

use GlpiPlugin\Gac\Sso\GoogleClient;
use GlpiPlugin\Gac\Sso\SsoConfig;
use GlpiPlugin\Gac\Sso\SsoSettings;

global $CFG_GLPI;

$settings = SsoConfig::load();
if (!SsoSettings::isConfigured($settings)) {
    Html::redirect($CFG_GLPI['root_doc'] . '/');
}

$client = new GoogleClient($settings, SsoSettings::redirectUri($settings, (string) $CFG_GLPI['url_base']));
$flow   = $client->begin();

$redirect = $_GET['redirect'] ?? '';
$_SESSION['gac_sso'] = [
    'state'    => $flow['state'],
    'nonce'    => $flow['nonce'],
    'pkce'     => $flow['pkce'],
    'redirect' => is_string($redirect) ? substr($redirect, 0, 2000) : '',
    't'        => time(),
];

Html::redirect($flow['url']);
