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
use Glpi\Asset\AssetDefinition;
use Session;

/**
 * Refuses updates to an asset that was written off (spec L14 and section 8). It is a
 * "pre_item_update" hook: it runs on every ->update() of an asset class, which is the path of the
 * asset form, the REST API, the inventory and GLPI's own "stale agent" action.
 *
 * Custom asset definitions are loaded after the plugins are initialised, so they are not yet in
 * $CFG_GLPI['asset_types'] when the hook is registered: itemtypes() reads them from the definitions
 * table and builds the class name the way AssetDefinition::getCustomObjectClassName() does.
 */
final class AssetUpdateGuard
{
    /** @return list<string> the asset classes the hook is registered for */
    public static function itemtypes(): array
    {
        $types = AssetTypes::all();
        try {
            global $DB;
            $table = AssetDefinition::getTable();
            if ($DB->tableExists($table)) {
                foreach ($DB->request(['SELECT' => ['system_name'], 'FROM' => $table, 'WHERE' => ['is_active' => 1]]) as $row) {
                    $types[] = AssetDefinition::getCustomObjectNamespace() . '\\' . $row['system_name'] . AssetDefinition::getCustomObjectClassSuffix();
                }
            }
        } catch (\Throwable) {
            // Never break the site at init: fall back to the native list.
        }
        return array_values(array_unique($types));
    }

    public static function onPreUpdate(CommonDBTM $item): void
    {
        if (LtbpGuard::isActive() || !is_array($item->input) || $item->input === []) {
            return;
        }
        if (!WrittenOffLock::isLocked($item::class, (int) $item->getID())) {
            return;
        }
        // Only profiles that were granted "Editar ativo baixado" pass. Read the active profile
        // directly: Session::haveRight() must NOT be used, it returns true for inventory, cron,
        // callAsSystem() and disabled rights checks, which are exactly the paths the lock must stop.
        if (((int) ($_SESSION['glpiactiveprofile'][Ltbp::$rightname] ?? 0)) & Ltbp::RIGHT_EDIT_WRITTEN_OFF) {
            return;
        }

        $blocked = LockPolicy::blockedFields($item->input, $item->fields);
        if ($blocked === []) {
            return;
        }

        $item->input = [];
        Session::addMessageAfterRedirect(
            sprintf(
                __('Este ativo foi baixado por um laudo de baixa patrimonial e não pode mais ser editado. Campos recusados: %s.', 'gac'),
                implode(', ', $blocked)
            ),
            false,
            ERROR
        );
    }
}
