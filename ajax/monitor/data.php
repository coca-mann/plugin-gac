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
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\ScreenQuery;

header('Content-Type: application/json; charset=utf-8');

if (!MonitorScreen::canView()) {
    http_response_code(403);
    echo json_encode(['error' => __('Acesso negado.', 'gac')], JSON_UNESCAPED_UNICODE);
    exit;
}

$screen = new MonitorScreen();
if (!$screen->getFromDB((int) ($_GET['id'] ?? 0)) || !$screen->fields['is_active']) {
    http_response_code(404);
    echo json_encode(['error' => __('Tela não encontrada.', 'gac'), 'code' => 'screen_unavailable'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = ScreenQuery::run($screen);
    echo json_encode($result + ['generated_at' => date('c')], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'monitor data.php: ' . $e->getMessage() . "\n");
    http_response_code(500);
    echo json_encode(['error' => __('Erro ao buscar os tickets.', 'gac')], JSON_UNESCAPED_UNICODE);
}
