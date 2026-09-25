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

use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\RepairProtocol;

/**
 * Plugin install process. Same function runs for install and update, so every step checks
 * the current state first.
 */
function plugin_gac_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_GAC_VERSION);

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    $protocols = 'glpi_plugin_gac_repairprotocols';
    if (!$DB->tableExists($protocols)) {
        $DB->doQuery("CREATE TABLE `$protocols` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `number` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `suppliers_id` INT {$sign} NOT NULL DEFAULT '0',
            `supplier_name` VARCHAR(255) DEFAULT NULL,
            `users_id_tech` INT {$sign} NOT NULL DEFAULT '0',
            `date_issued` DATE DEFAULT NULL,
            `date_sent` TIMESTAMP NULL DEFAULT NULL,
            `date_closed` TIMESTAMP NULL DEFAULT NULL,
            `documents_id_sent` INT {$sign} NOT NULL DEFAULT '0',
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `number` (`number`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $items = 'glpi_plugin_gac_repairprotocolitems';
    if (!$DB->tableExists($items)) {
        $DB->doQuery("CREATE TABLE `$items` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_repairprotocols_id` INT {$sign} NOT NULL DEFAULT '0',
            `tickets_id` INT {$sign} NOT NULL DEFAULT '0',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_name` VARCHAR(255) DEFAULT NULL,
            `item_type_label` VARCHAR(255) DEFAULT NULL,
            `serial` VARCHAR(255) DEFAULT NULL,
            `otherserial` VARCHAR(255) DEFAULT NULL,
            `ticket_title` TEXT DEFAULT NULL,
            `ticket_observation` TEXT DEFAULT NULL,
            `description_supplier` TEXT DEFAULT NULL,
            `states_id_before` INT {$sign} DEFAULT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'pending_send',
            `outcome` VARCHAR(20) DEFAULT NULL,
            `destination` VARCHAR(20) DEFAULT NULL,
            `date_return` DATE DEFAULT NULL,
            `service_description` TEXT DEFAULT NULL,
            `cost` DECIMAL(20,4) DEFAULT NULL,
            `supplier_ref` VARCHAR(255) DEFAULT NULL,
            `warranty_until` DATE DEFAULT NULL,
            `ticketcosts_id` INT {$sign} NOT NULL DEFAULT '0',
            `lost_reason` TEXT DEFAULT NULL,
            `last_error` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_gac_repairprotocols_id`, `tickets_id`, `itemtype`, `items_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `status` (`status`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $events = 'glpi_plugin_gac_repairprotocolevents';
    if (!$DB->tableExists($events)) {
        $DB->doQuery("CREATE TABLE `$events` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_repairprotocols_id` INT {$sign} NOT NULL DEFAULT '0',
            `plugin_gac_repairprotocolitems_id` INT {$sign} NOT NULL DEFAULT '0',
            `event` VARCHAR(30) NOT NULL,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason` TEXT DEFAULT NULL,
            `details` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_gac_repairprotocols_id` (`plugin_gac_repairprotocols_id`),
            KEY `plugin_gac_repairprotocolitems_id` (`plugin_gac_repairprotocolitems_id`),
            KEY `event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $sequences = 'glpi_plugin_gac_protocolsequences';
    if (!$DB->tableExists($sequences)) {
        $DB->doQuery("CREATE TABLE `$sequences` (
            `year` SMALLINT UNSIGNED NOT NULL,
            `last` INT UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // Default configuration: only keys that do not exist yet, so an update never overwrites
    // what an administrator already configured.
    $current = Config::getConfigurationValues('plugin:gac');
    $missing = array_diff_key(PreSettings::defaults(), $current);
    if ($missing !== []) {
        Config::setConfigurationValues('plugin:gac', $missing);
    }

    // Profile right. addProfileRights() inserts one row per existing profile, so existence
    // must be checked by counting. Full access only for profiles that already hold the native
    // 'config' right; every other profile starts without access and an administrator grants
    // it in Administração > Perfis (aba do Gac).
    $right = RepairProtocol::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $right]) === 0) {
        ProfileRight::addProfileRights([$right]);

        $admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['>', 0]],
            ])),
            'profiles_id'
        );
        if ($admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT
                    | RepairProtocol::RIGHT_SEND
                    | RepairProtocol::RIGHT_RETURN
                    | RepairProtocol::RIGHT_REOPEN],
                ['name' => $right, 'profiles_id' => $admin_profiles]
            );
        }
    }

    // The definitive PDF is stored through Document, which only accepts registered types.
    if (countElementsInTable('glpi_documenttypes', ['ext' => 'pdf']) === 0) {
        $DB->insert('glpi_documenttypes', [
            'name'          => 'PDF',
            'ext'           => 'pdf',
            'mime'          => 'application/pdf',
            'is_uploadable' => 1,
        ]);
    }

    // Default list columns (users_id = 0 is the global default): the PRE number is always
    // shown by GLPI; add the status. Only on first install, never over an admin's choice.
    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]) === 0) {
        $DB->insert('glpi_displaypreferences', [
            'itemtype' => RepairProtocol::class,
            'num'      => 3, // search option 3 = status (see RepairProtocol::rawSearchOptions())
            'rank'     => 1,
            'users_id' => 0,
        ]);
    }

    $migration->executeMigration();

    return true;
}

/**
 * Plugin uninstall process
 */
function plugin_gac_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_gac_repairprotocols',
        'glpi_plugin_gac_repairprotocolitems',
        'glpi_plugin_gac_repairprotocolevents',
        'glpi_plugin_gac_protocolsequences',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete(ProfileRight::getTable(), ['name' => RepairProtocol::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]);
    Config::deleteConfigurationValues('plugin:gac', array_keys(PreSettings::defaults()));

    return true;
}
