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

include('../../../../inc/includes.php');

use GlpiPlugin\Gac\Pre\PdfRenderer;
use GlpiPlugin\Gac\Pre\ProtocolStatus;
use GlpiPlugin\Gac\Pre\RepairProtocol;

$protocol = new RepairProtocol();
if (!$protocol->getFromDB((int) ($_GET['id'] ?? 0)) || !$protocol->canViewItem()) {
    Html::displayRightError();
}

$status = $protocol->getStatus();

$send = static function (string $bytes, string $filename): never {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    echo $bytes;
    exit;
};

if ($status === ProtocolStatus::Draft) {
    $r = PdfRenderer::render($protocol, true);
    $send($r['bytes'], $r['filename']);
}

$documentId = (int) $protocol->fields['documents_id_sent'];
if ($documentId > 0) {
    $document = new Document();
    if ($document->getFromDB($documentId)) {
        $path = realpath(GLPI_DOC_DIR . '/' . $document->fields['filepath']);
        if ($path !== false && str_starts_with($path, realpath(GLPI_DOC_DIR)) && is_file($path)) {
            $send((string) file_get_contents($path), $protocol->fields['number'] . '.pdf');
        }
    }
}

Html::displayNotFoundError();
