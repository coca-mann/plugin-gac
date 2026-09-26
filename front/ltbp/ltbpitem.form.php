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

use GlpiPlugin\Gac\Ltbp\CandidateFinder;
use GlpiPlugin\Gac\Ltbp\LineService;
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Shared\ServiceResult;

$laudo   = new Ltbp();
$laudoId = (int) ($_POST['ltbp_id'] ?? 0);
if ($laudoId === 0 || !$laudo->getFromDB($laudoId)) {
    Html::displayNotFoundError();
}

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['add_candidates'])) {
    // UPDATE = edit the draft (the standard right; the laudo is only editable while a draft).
    $laudo->check($laudoId, UPDATE);

    // Never trust the client: re-resolve every selected key against the current candidates.
    $wanted  = array_map('strval', (array) ($_POST['select'] ?? []));
    $assets  = [];
    $origins = [];
    foreach (CandidateFinder::find($laudo) as $c) {
        if (in_array($c['key'], $wanted, true)) {
            $assets[]           = ['itemtype' => $c['itemtype'], 'items_id' => $c['items_id']];
            $origins[$c['key']] = $c;
        }
    }
    $notify($assets === []
        ? ServiceResult::fail(__('Nenhum ativo válido foi selecionado.', 'gac'))
        : LineService::addAssets($laudo, $assets, $origins));
} elseif (isset($_POST['add_asset'])) {
    $laudo->check($laudoId, UPDATE);
    $type = (string) ($_POST['asset_itemtype'] ?? '');
    $id   = (int) ($_POST['asset_items_id'] ?? 0);
    $notify($type !== '' && $id > 0
        ? LineService::addAssets($laudo, [['itemtype' => $type, 'items_id' => $id]])
        : ServiceResult::fail(__('Escolha o tipo e o ativo.', 'gac')));
} elseif (isset($_POST['save_reasons'])) {
    $laudo->check($laudoId, UPDATE);
    $notify(LineService::saveReasons($laudo, (array) ($_POST['reason'] ?? [])));
} elseif (isset($_POST['remove_line'])) {
    $laudo->check($laudoId, UPDATE);
    $notify(LineService::removeLine($laudo, (int) $_POST['remove_line']));
}

Html::back();
