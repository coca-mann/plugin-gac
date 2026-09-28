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

use Entity;

/**
 * Latest image Document of a document category linked to an entity (or, failing that, to the
 * nearest ancestor that has one), as a data URI. Null when there is none (PRE spec section 10).
 */
final class LogoLocator
{
    private const MAX_BYTES = 2_000_000;

    public static function dataUri(int $entityId, int $categoryId): ?string
    {
        global $DB;

        if ($categoryId <= 0) {
            return null;
        }

        $entity  = new Entity();
        $visited = [];
        for ($e = $entityId; $e !== null && !isset($visited[$e]) && count($visited) < EntityChain::MAX_DEPTH; ) {
            $visited[$e] = true;
            $row = $DB->request([
                'SELECT'     => ['glpi_documents.filepath', 'glpi_documents.mime'],
                'FROM'       => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_documents' => [
                        'ON' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'glpi_documents_items.itemtype'        => 'Entity',
                    'glpi_documents_items.items_id'        => $e,
                    'glpi_documents.documentcategories_id' => $categoryId,
                    'glpi_documents.is_deleted'            => 0,
                    'glpi_documents.mime'                  => ['image/png', 'image/jpeg', 'image/gif'],
                ],
                'ORDER' => ['glpi_documents.date_creation DESC', 'glpi_documents.id DESC'],
                'LIMIT' => 1,
            ])->current();

            if ($row !== null) {
                $path = GLPI_DOC_DIR . '/' . $row['filepath'];
                if (is_file($path) && filesize($path) <= self::MAX_BYTES) {
                    return 'data:' . $row['mime'] . ';base64,' . base64_encode((string) file_get_contents($path));
                }
            }

            if (!$entity->getFromDB($e)) {
                break;
            }
            // The root's parent is -1 or NULL depending on the database: see EntityChain.
            $e = EntityChain::parentOf($e, $entity->fields['entities_id'] ?? null);
        }
        return null;
    }
}
