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

use GlpiPlugin\Gac\Monitor\MonitorPage;
use GlpiPlugin\Gac\Monitor\MonitorScreen;

$item = new MonitorPage();

// The form itself is rendered inside the Tela's "Páginas" tab (MonitorPage::displayTabContentForItem),
// which keeps the Tela's side menu and GLPI's standard form footer. This script only handles the
// actions and, for a plain GET, hands over to that tab.
if (isset($_POST['add'])) {
    $item->check(-1, CREATE, $_POST);
    $screenId = (int) $_POST[MonitorPage::$items_id];
    if (!$item->add($_POST)) {
        MonitorPage::requestForm(-1, $screenId);
    }
    Html::redirect(MonitorPage::tabUrl($screenId));
} elseif (isset($_POST['update'])) {
    $item->check($_POST['id'], UPDATE);
    $screenId = (int) $item->fields[MonitorPage::$items_id];
    if (!$item->update($_POST)) {
        MonitorPage::requestForm((int) $_POST['id'], $screenId);
    }
    Html::redirect(MonitorPage::tabUrl($screenId));
} elseif (isset($_POST['purge'])) {
    $item->check($_POST['id'], PURGE);
    $screenId = (int) $item->fields[MonitorPage::$items_id];
    $item->delete($_POST, 1);
    Html::redirect(MonitorPage::tabUrl($screenId));
} else {
    $id       = (int) ($_GET['id'] ?? -1);
    $screenId = (int) ($_GET[MonitorPage::$items_id] ?? 0);
    if ($id > 0) {
        if (!$item->getFromDB($id)) {
            Html::displayNotFoundError();
        }
        $screenId = (int) $item->fields[MonitorPage::$items_id];
    }
    if ($screenId <= 0) {
        Html::displayNotFoundError();
    }
    if (!MonitorScreen::canView()) {
        Html::displayRightError();
    }
    MonitorPage::requestForm($id > 0 ? $id : -1, $screenId);
    Html::redirect(MonitorPage::tabUrl($screenId));
}
