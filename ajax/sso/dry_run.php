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

// Somente leitura, GET: não precisa de Session::checkCSRF().
use GlpiPlugin\Gac\Features;
use GlpiPlugin\Gac\Sso\DryRun;
use GlpiPlugin\Gac\Sso\SsoIdentity;

header('Content-Type: application/json; charset=utf-8');

if (!Features::canConfigure(SsoIdentity::$rightname)) {
    http_response_code(403);
    echo json_encode(['error' => __('Acesso negado.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = trim((string) ($_GET['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => __('Informe um e-mail válido.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    echo json_encode(DryRun::run($email), JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'sso dry_run.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao executar o teste.', 'gac')], JSON_UNESCAPED_UNICODE);
}
