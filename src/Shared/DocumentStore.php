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

namespace GlpiPlugin\Gac\Shared;

use Document;

/**
 * Stores bytes or an uploaded file as a GLPI Document linked to an item. GLPI's upload
 * convention: the temp file is "<prefix><name>" and the prefix is passed along.
 */
final class DocumentStore
{
    /** @return int Document id */
    public static function attachBytes(
        string $bytes,
        string $filename,
        string $name,
        int $entityId,
        string $itemtype,
        int $itemsId
    ): int {
        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $filename;
        file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $bytes);

        $id = (new Document())->add([
            'name'             => $name,
            'entities_id'      => $entityId,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => $itemtype,
            'items_id'         => $itemsId,
        ]);
        if (!$id) {
            throw new \RuntimeException('Could not attach the document.');
        }
        return (int) $id;
    }

    /**
     * @param array{name: string, tmp_name: string, error: int} $file one entry of ReturnAttachments-style input
     * @return int Document id
     */
    public static function attachUpload(array $file, string $name, int $entityId, string $itemtype, int $itemsId): int
    {
        $original = basename($file['name']);
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException(sprintf(__('%s: o envio do arquivo falhou.', 'gac'), $original));
        }

        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $original;
        if (!move_uploaded_file($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
            throw new \RuntimeException(sprintf(__('%s: não foi possível guardar o arquivo.', 'gac'), $original));
        }

        $id = (new Document())->add([
            'name'             => $name,
            'entities_id'      => $entityId,
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => $itemtype,
            'items_id'         => $itemsId,
        ]);
        if (!$id) {
            throw new \RuntimeException(sprintf(__('%s: o GLPI recusou o arquivo (tipo ou tamanho não permitido).', 'gac'), $original));
        }
        return (int) $id;
    }
}
