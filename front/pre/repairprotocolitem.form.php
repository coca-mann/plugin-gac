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

use GlpiPlugin\Gac\Pre\LineService;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Pre\ReopenService;
use GlpiPlugin\Gac\Pre\ReturnAttachments;
use GlpiPlugin\Gac\Pre\ReturnService;
use GlpiPlugin\Gac\Pre\ServiceResult;

$protocol = new RepairProtocol();
$protocolId = (int) ($_POST['protocol_id'] ?? 0);
if ($protocolId === 0 || !$protocol->getFromDB($protocolId)) {
    Html::displayNotFoundError();
}

$notify = static function (ServiceResult $r): void {
    Session::addMessageAfterRedirect(htmlescape($r->message), false, $r->ok ? INFO : ERROR);
};

if (isset($_POST['import'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::import($protocol, array_map('strval', (array) ($_POST['select'] ?? []))));
} elseif (isset($_POST['remove_line'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::removeDraftLine($protocol, (int) $_POST['remove_line']));
} elseif (isset($_POST['save_descriptions'])) {
    $protocol->check($protocolId, UPDATE);
    $notify(LineService::saveDescriptions($protocol, (array) ($_POST['description'] ?? [])));
} elseif (isset($_POST['return']) || isset($_POST['lost'])) {
    if (
        !$protocol->canViewItem()
        || !Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_RETURN)
    ) {
        Html::displayRightError();
    }
    $lineId = (int) ($_POST['line_id'] ?? 0);
    $notify(isset($_POST['return'])
        ? ReturnService::registerReturn($lineId, $_POST, ReturnAttachments::collect($_FILES))
        : ReturnService::markLost($lineId, (string) ($_POST['reason'] ?? '')));
} elseif (isset($_POST['reopen']) || isset($_POST['correct']) || isset($_POST['finish_corrections'])) {
    if (
        !$protocol->canViewItem()
        || !Session::haveRight(RepairProtocol::$rightname, RepairProtocol::RIGHT_REOPEN)
    ) {
        Html::displayRightError();
    }
    if (isset($_POST['reopen'])) {
        $notify(ReopenService::reopen($protocol, (string) ($_POST['reason'] ?? '')));
    } elseif (isset($_POST['correct'])) {
        $notify(ReopenService::correctLine((int) ($_POST['line_id'] ?? 0), $_POST));
    } else {
        $notify(ReopenService::finishCorrections($protocol));
    }
}


Html::back();
