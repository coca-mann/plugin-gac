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

use GlpiPlugin\Gac\Ltbp\IssueService;
use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Ltbp\StepService;
use GlpiPlugin\Gac\Shared\DocumentStore;
use GlpiPlugin\Gac\Shared\ServiceResult;

$laudo   = new Ltbp();
$laudoId = (int) ($_POST['ltbp_id'] ?? 0);
if ($laudoId === 0 || !$laudo->getFromDB($laudoId)) {
    Html::displayNotFoundError();
}
if (!$laudo->canViewItem()) {
    Html::displayRightError();
}

$need = static function (int $bit): void {
    if (!Session::haveRight(Ltbp::$rightname, $bit)) {
        Html::displayRightError();
    }
};

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['issue'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(IssueService::issue($laudo));
} elseif (isset($_POST['cancel'])) {
    $need(Ltbp::RIGHT_CANCEL);
    $notify(IssueService::cancel($laudo, (string) ($_POST['reason'] ?? '')));
} elseif (isset($_POST['upload_signed'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::attachSigned($laudo, DocumentStore::collect($_FILES, 'signed_file')[0] ?? null));
} elseif (isset($_POST['send_patrimony'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::sendToPatrimony($laudo, $_POST));
} elseif (isset($_POST['confirm_writeoff'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::confirmWriteoff($laudo, $_POST, DocumentStore::collect($_FILES, 'attachments')));
} elseif (isset($_POST['complete'])) {
    $need(Ltbp::RIGHT_ISSUE);
    $notify(StepService::complete($laudo, $_POST, DocumentStore::collect($_FILES, 'attachments')));
}

Html::back();
