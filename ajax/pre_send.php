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

// CSRF is validated by the GLPI 11 kernel from the X-Glpi-Csrf-Token header; do not add
// Session::checkCSRF() here.
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\RepairProtocolItem;
use GlpiPlugin\Gac\Pre\SendService;
use GlpiPlugin\Gac\Pre\ServiceResult;

header('Content-Type: application/json; charset=utf-8');

$respond = static function (ServiceResult $result, int $http = 200): never {
    http_response_code($http);
    echo json_encode($result->toArray(), JSON_UNESCAPED_UNICODE);
    exit;
};

if (!Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_SEND)) {
    $respond(ServiceResult::fail(__('Acesso negado.', 'gac')), 403);
}

$action = (string) ($_POST['action'] ?? '');

$protocolId = (int) ($_POST['protocol_id'] ?? 0);
if (in_array($action, ['line', 'remove_line'], true)) {
    $line = new RepairProtocolItem();
    if (!$line->getFromDB((int) ($_POST['line_id'] ?? 0))) {
        $respond(ServiceResult::fail(__('Linha não encontrada.', 'gac')), 404);
    }
    $protocolId = (int) $line->fields['plugin_gac_repairprotocols_id'];
}

$protocol = new RepairProtocol();
if (!$protocol->getFromDB($protocolId) || !$protocol->canViewItem()) {
    $respond(ServiceResult::fail(__('Acesso negado.', 'gac')), 403);
}

try {
    $result = match ($action) {
        'start'       => SendService::start($protocol),
        'line'        => SendService::sendLine((int) $_POST['line_id']),
        'finalize'    => SendService::finalize($protocol),
        'remove_line' => SendService::removeFailedLine((int) $_POST['line_id'], (string) ($_POST['reason'] ?? '')),
        default       => ServiceResult::fail(__('Ação inválida.', 'gac')),
    };
} catch (\Throwable $e) {
    Toolbox::logInFile('gac', 'pre_send.php ' . $action . ': ' . $e->getMessage() . "\n");
    $result = ServiceResult::fail(__('Erro inesperado.', 'gac'));
}

$respond($result);
