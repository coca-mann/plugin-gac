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

namespace GlpiPlugin\Gac\Ltbp;

use CommonDBTM;

/** The asset data copied into a laudo line (spec 5.2), so the document does not change afterwards. */
final class AssetSnapshot
{
    /** @return array{item_entities_id: int, item_name: string, item_type_label: string, brand: string, model: string, serial: string, otherserial: string} */
    public static function take(CommonDBTM $asset): array
    {
        $f = $asset->fields;

        return [
            'item_entities_id' => (int) ($f['entities_id'] ?? 0),
            'item_name'        => (string) ($f['name'] ?? ''),
            'item_type_label'  => $asset::getTypeName(1),
            'brand'            => self::name('glpi_manufacturers', (int) ($f['manufacturers_id'] ?? 0)),
            'model'            => self::modelName($f),
            'serial'           => (string) ($f['serial'] ?? ''),
            'otherserial'      => (string) ($f['otherserial'] ?? ''),
        ];
    }

    /** The model column is "<type>models_id" and its name depends on the asset type. */
    private static function modelName(array $fields): string
    {
        foreach ($fields as $key => $value) {
            if (str_ends_with((string) $key, 'models_id') && (int) $value > 0) {
                $table = getTableNameForForeignKeyField((string) $key);
                return $table === '' ? '' : self::name($table, (int) $value);
            }
        }
        return '';
    }

    private static function name(string $table, int $id): string
    {
        global $DB;

        if ($id <= 0 || !$DB->tableExists($table)) {
            return '';
        }
        $row = $DB->request(['SELECT' => ['name'], 'FROM' => $table, 'WHERE' => ['id' => $id]])->current();
        return $row === null ? '' : (string) $row['name'];
    }
}
