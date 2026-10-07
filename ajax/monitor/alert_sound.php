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

// Rota stateless (setup.php): sem sessão GLPI, somente leitura, GET. O som de um alerta não é
// sensível e a exibição pública (TV) não tem login para buscá-lo. `f` é o nome gravado do arquivo
// (só nomes no formato validado e que existam); sem `f` serve o som do plugin, como antes.
use GlpiPlugin\Gac\Monitor\AlertSound;
use GlpiPlugin\Gac\Monitor\MonitorConfig;
use GlpiPlugin\Gac\Monitor\PublicRateLimiter;

$settings = MonitorConfig::load();
PublicRateLimiter::enforceIp($settings, false);

$name = (string) ($_GET['f'] ?? '');
$path = $name !== ''
    ? AlertSound::pathOf($name)
    : AlertSound::path(MonitorConfig::load());
if ($path === null) {
    http_response_code(404);
    exit;
}

AlertSound::send($path);
