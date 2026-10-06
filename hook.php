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

use GlpiPlugin\Gac\Ltbp\Ltbp;
use GlpiPlugin\Gac\Ltbp\LtbpReason;
use GlpiPlugin\Gac\Ltbp\LtbpSettings;
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\MonitorSettings;
use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\RepairProtocol;
use GlpiPlugin\Gac\Sso\RuleHooks;
use GlpiPlugin\Gac\Sso\SsoEvent;
use GlpiPlugin\Gac\Sso\SsoIdentity;
use GlpiPlugin\Gac\Sso\SsoLoginButton;
use GlpiPlugin\Gac\Sso\SsoSettings;

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

    $ltbps = 'glpi_plugin_gac_ltbps';
    if (!$DB->tableExists($ltbps)) {
        $DB->doQuery("CREATE TABLE `$ltbps` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `number` VARCHAR(20) NOT NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
            `destination` VARCHAR(20) NOT NULL DEFAULT '',
            `users_id_tech` INT {$sign} NOT NULL DEFAULT '0',
            `date_issued` DATE DEFAULT NULL,
            `date_signed` DATE DEFAULT NULL,
            `date_sent_patrimony` DATE DEFAULT NULL,
            `date_written_off` DATE DEFAULT NULL,
            `date_completed` DATE DEFAULT NULL,
            `date_canceled` TIMESTAMP NULL DEFAULT NULL,
            `director_ti_name` VARCHAR(255) DEFAULT NULL,
            `director_ti_role` VARCHAR(255) DEFAULT NULL,
            `director_adm_name` VARCHAR(255) DEFAULT NULL,
            `director_adm_role` VARCHAR(255) DEFAULT NULL,
            `received_by` VARCHAR(255) DEFAULT NULL,
            `writeoff_process_number` VARCHAR(255) DEFAULT NULL,
            `writeoff_notes` TEXT DEFAULT NULL,
            `suppliers_id` INT {$sign} NOT NULL DEFAULT '0',
            `supplier_name` VARCHAR(255) DEFAULT NULL,
            `completion_notes` TEXT DEFAULT NULL,
            `cancel_reason` TEXT DEFAULT NULL,
            `documents_id_frozen` INT {$sign} NOT NULL DEFAULT '0',
            `documents_id_signed` INT {$sign} NOT NULL DEFAULT '0',
            `comment` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `number` (`number`),
            KEY `entities_id` (`entities_id`),
            KEY `status` (`status`),
            KEY `users_id_tech` (`users_id_tech`),
            KEY `suppliers_id` (`suppliers_id`),
            KEY `date_creation` (`date_creation`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpItems = 'glpi_plugin_gac_ltbpitems';
    if (!$DB->tableExists($ltbpItems)) {
        $DB->doQuery("CREATE TABLE `$ltbpItems` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_ltbps_id` INT {$sign} NOT NULL DEFAULT '0',
            `itemtype` VARCHAR(255) NOT NULL DEFAULT '',
            `items_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `item_name` VARCHAR(255) DEFAULT NULL,
            `item_type_label` VARCHAR(255) DEFAULT NULL,
            `brand` VARCHAR(255) DEFAULT NULL,
            `model` VARCHAR(255) DEFAULT NULL,
            `serial` VARCHAR(255) DEFAULT NULL,
            `otherserial` VARCHAR(255) DEFAULT NULL,
            `plugin_gac_ltbpreasons_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason_code` VARCHAR(20) DEFAULT NULL,
            `reason_title` VARCHAR(255) DEFAULT NULL,
            `reason_description` TEXT DEFAULT NULL,
            `states_id_before` INT {$sign} DEFAULT NULL,
            `pre_items_id` INT {$sign} NOT NULL DEFAULT '0',
            `pre_number` VARCHAR(20) DEFAULT NULL,
            `tickets_id` INT {$sign} NOT NULL DEFAULT '0',
            `last_error` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unicity` (`plugin_gac_ltbps_id`, `itemtype`, `items_id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `plugin_gac_ltbpreasons_id` (`plugin_gac_ltbpreasons_id`),
            KEY `date_mod` (`date_mod`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpEvents = 'glpi_plugin_gac_ltbpevents';
    if (!$DB->tableExists($ltbpEvents)) {
        $DB->doQuery("CREATE TABLE `$ltbpEvents` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `plugin_gac_ltbps_id` INT {$sign} NOT NULL DEFAULT '0',
            `plugin_gac_ltbpitems_id` INT {$sign} NOT NULL DEFAULT '0',
            `event` VARCHAR(30) NOT NULL,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `reason` TEXT DEFAULT NULL,
            `details` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `plugin_gac_ltbps_id` (`plugin_gac_ltbps_id`),
            KEY `plugin_gac_ltbpitems_id` (`plugin_gac_ltbpitems_id`),
            KEY `event` (`event`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpReasons = 'glpi_plugin_gac_ltbpreasons';
    if (!$DB->tableExists($ltbpReasons)) {
        $DB->doQuery("CREATE TABLE `$ltbpReasons` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(20) NOT NULL,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `comment` TEXT DEFAULT NULL,
            `is_active` TINYINT NOT NULL DEFAULT '1',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `code` (`code`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ltbpSequences = 'glpi_plugin_gac_ltbpsequences';
    if (!$DB->tableExists($ltbpSequences)) {
        $DB->doQuery("CREATE TABLE `$ltbpSequences` (
            `year` SMALLINT UNSIGNED NOT NULL,
            `last` INT UNSIGNED NOT NULL DEFAULT '0',
            PRIMARY KEY (`year`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $monitorScreens = 'glpi_plugin_gac_monitorscreens';
    if (!$DB->tableExists($monitorScreens)) {
        $DB->doQuery("CREATE TABLE `$monitorScreens` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` INT {$sign} NOT NULL DEFAULT '0',
            `is_recursive` TINYINT NOT NULL DEFAULT '0',
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `savedsearches_id` INT {$sign} NOT NULL DEFAULT '0',
            `display_columns` TEXT DEFAULT NULL,
            `sort_mode` VARCHAR(20) NOT NULL DEFAULT 'priority',
            `theme` VARCHAR(10) NOT NULL DEFAULT 'dark',
            `font_size` TINYINT UNSIGNED NOT NULL DEFAULT '3',
            `entity_levels` TINYINT UNSIGNED NOT NULL DEFAULT '3',
            `poll_interval_seconds` INT UNSIGNED DEFAULT NULL,
            `is_public` TINYINT NOT NULL DEFAULT '0',
            `public_token` VARCHAR(64) DEFAULT NULL,
            `alert_enabled` TINYINT NOT NULL DEFAULT '1',
            `is_active` TINYINT NOT NULL DEFAULT '1',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `public_token` (`public_token`),
            KEY `entities_id` (`entities_id`),
            KEY `savedsearches_id` (`savedsearches_id`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // theme/font_size were added after the table above first shipped: the CREATE TABLE is
    // skipped once the table exists, so an existing install needs these added explicitly.
    if (!$DB->fieldExists($monitorScreens, 'theme')) {
        $DB->doQuery("ALTER TABLE `$monitorScreens` ADD COLUMN `theme` VARCHAR(10) NOT NULL DEFAULT 'dark' AFTER `sort_mode`");
    }
    if (!$DB->fieldExists($monitorScreens, 'font_size')) {
        $DB->doQuery("ALTER TABLE `$monitorScreens` ADD COLUMN `font_size` TINYINT UNSIGNED NOT NULL DEFAULT '3' AFTER `theme`");
    }
    if (!$DB->fieldExists($monitorScreens, 'entity_levels')) {
        $DB->doQuery("ALTER TABLE `$monitorScreens` ADD COLUMN `entity_levels` TINYINT UNSIGNED NOT NULL DEFAULT '3' AFTER `font_size`");
    }

    // Default configuration: only keys that do not exist yet, so an update never overwrites
    // what an administrator already configured.
    $current = Config::getConfigurationValues('plugin:gac');
    $missing = array_diff_key(
        PreSettings::defaults() + LtbpSettings::defaults() + MonitorSettings::defaults() + SsoSettings::defaults(),
        $current
    );
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
                    | RepairProtocol::RIGHT_REOPEN
                    | RepairProtocol::RIGHT_CONFIG],
                ['name' => $right, 'profiles_id' => $admin_profiles]
            );
        }
    }

    // LTBP right: profiles that can UPDATE the native 'config' right (read-only ones do not)
    // get everything except "Editar ativo baixado", which each administrator grants per profile.
    $ltbpRight = Ltbp::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $ltbpRight]) === 0) {
        ProfileRight::addProfileRights([$ltbpRight]);

        $ltbp_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($ltbp_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT
                    | Ltbp::RIGHT_ISSUE
                    | Ltbp::RIGHT_CANCEL
                    | Ltbp::RIGHT_CONFIG],
                ['name' => $ltbpRight, 'profiles_id' => $ltbp_admin_profiles]
            );
        }
    }

    // Monitor right: profiles that can UPDATE the native 'config' right get full access
    // (standard CRUD + Configurar); every other profile starts without access.
    $monitorRight = MonitorScreen::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $monitorRight]) === 0) {
        ProfileRight::addProfileRights([$monitorRight]);

        $monitor_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($monitor_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => ALLSTANDARDRIGHT | MonitorScreen::RIGHT_CONFIG],
                ['name' => $monitorRight, 'profiles_id' => $monitor_admin_profiles]
            );
        }
    }

    // Settings used to be gated by GLPI's own "config" right; now they have the feature's
    // "Configurar" bit. Once, give it to the profiles that could configure before.
    if (!isset(Config::getConfigurationValues('plugin:gac')['pre_config_right_migrated'])) {
        $config_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($config_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => new QueryExpression($DB::quoteName('rights') . ' | ' . RepairProtocol::RIGHT_CONFIG)],
                ['name' => RepairProtocol::$rightname, 'profiles_id' => $config_profiles]
            );
        }
        Config::setConfigurationValues('plugin:gac', ['pre_config_right_migrated' => '1']);
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

    foreach ([
        Ltbp::class       => [3, 4],
        LtbpReason::class => [3, 5],
    ] as $itemtype => $nums) {
        if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype]) === 0) {
            foreach ($nums as $rank => $num) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => $num,
                    'rank'     => $rank + 1,
                    'users_id' => 0,
                ]);
            }
        }
    }

    if (countElementsInTable('glpi_displaypreferences', ['itemtype' => MonitorScreen::class]) === 0) {
        $DB->insert('glpi_displaypreferences', [
            'itemtype' => MonitorScreen::class,
            'num'      => 80, // search option 80 = entidade (ver MonitorScreen::rawSearchOptions())
            'rank'     => 1,
            'users_id' => 0,
        ]);
    }

    // SSO Google (spec seção 5): identities and the audit log. The rules themselves are native
    // RuleRight rules, so there are no mapping tables.
    $ssoIdentities = 'glpi_plugin_gac_ssoidentities';
    if (!$DB->tableExists($ssoIdentities)) {
        $DB->doQuery("CREATE TABLE `$ssoIdentities` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `google_sub` VARCHAR(255) NOT NULL DEFAULT '',
            `email_at_link` VARCHAR(255) NOT NULL DEFAULT '',
            `prev_authtype` TINYINT NOT NULL DEFAULT '0',
            `prev_auths_id` INT {$sign} NOT NULL DEFAULT '0',
            `removed_authorizations` MEDIUMTEXT DEFAULT NULL,
            `linked_at` TIMESTAMP NULL DEFAULT NULL,
            `last_login_at` TIMESTAMP NULL DEFAULT NULL,
            `last_ou_path` VARCHAR(500) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `users_id` (`users_id`),
            UNIQUE KEY `google_sub` (`google_sub`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    $ssoEvents = 'glpi_plugin_gac_ssoevents';
    if (!$DB->tableExists($ssoEvents)) {
        $DB->doQuery("CREATE TABLE `$ssoEvents` (
            `id` INT {$sign} NOT NULL AUTO_INCREMENT,
            `date` TIMESTAMP NULL DEFAULT NULL,
            `email` VARCHAR(255) NOT NULL DEFAULT '',
            `users_id` INT {$sign} NOT NULL DEFAULT '0',
            `ou_path` VARCHAR(500) NOT NULL DEFAULT '',
            `outcome` VARCHAR(40) NOT NULL DEFAULT '',
            `detail` VARCHAR(1000) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            KEY `date` (`date`),
            KEY `outcome` (`outcome`),
            KEY `ou_path` (`ou_path`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // SSO right: profiles that can UPDATE the native 'config' right get "Ler" + "Configurar";
    // every other profile starts without access.
    $ssoRight = SsoIdentity::$rightname;
    if (countElementsInTable(ProfileRight::getTable(), ['name' => $ssoRight]) === 0) {
        ProfileRight::addProfileRights([$ssoRight]);

        $sso_admin_profiles = array_column(
            iterator_to_array($DB->request([
                'SELECT' => 'profiles_id',
                'FROM'   => ProfileRight::getTable(),
                'WHERE'  => ['name' => 'config', 'rights' => ['&', UPDATE]],
            ])),
            'profiles_id'
        );
        if ($sso_admin_profiles !== []) {
            $DB->update(
                ProfileRight::getTable(),
                ['rights' => READ | SsoIdentity::RIGHT_CONFIG],
                ['name' => $ssoRight, 'profiles_id' => $sso_admin_profiles]
            );
        }
    }

    // Automatic action that purges old events (retention is a setting).
    if (countElementsInTable('glpi_crontasks', ['itemtype' => SsoEvent::class, 'name' => 'SsoPurge']) === 0) {
        CronTask::register(SsoEvent::class, 'SsoPurge', DAY_TIMESTAMP, [
            'comment' => 'Expurgar eventos antigos do login com Google',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
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
        'glpi_plugin_gac_ltbps',
        'glpi_plugin_gac_ltbpitems',
        'glpi_plugin_gac_ltbpevents',
        'glpi_plugin_gac_ltbpreasons',
        'glpi_plugin_gac_ltbpsequences',
        'glpi_plugin_gac_monitorscreens',
        'glpi_plugin_gac_ssoidentities',
        'glpi_plugin_gac_ssoevents',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `$table`");
        }
    }

    $DB->delete(ProfileRight::getTable(), ['name' => RepairProtocol::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => RepairProtocol::class]);
    $DB->delete(ProfileRight::getTable(), ['name' => Ltbp::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => [Ltbp::class, LtbpReason::class]]);
    $DB->delete(ProfileRight::getTable(), ['name' => MonitorScreen::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => MonitorScreen::class]);
    $DB->delete(ProfileRight::getTable(), ['name' => SsoIdentity::$rightname]);
    $DB->delete('glpi_crontasks', ['itemtype' => SsoEvent::class]);
    Config::deleteConfigurationValues('plugin:gac', array_merge(
        array_keys(PreSettings::defaults()),
        array_keys(LtbpSettings::defaults()),
        array_keys(MonitorSettings::defaults()),
        array_keys(SsoSettings::defaults()),
        ['pre_config_right_migrated']
    ));

    return true;
}

/**
 * Hook "getRuleCriteria": adds the "OU do Google Workspace" criterion to RuleRight (SSO, spec S4).
 *
 * @param array<string, mixed> $params
 * @return array<string, array<string, mixed>>
 */
function plugin_gac_getRuleCriteria(array $params): array
{
    return RuleHooks::criteria($params);
}

/**
 * Hook "ruleCollectionPrepareInputDataForProcess": hands the OU ancestors to the rules engine.
 *
 * @param array<string, mixed> $params
 * @return array<string, mixed>
 */
function plugin_gac_ruleCollectionPrepareInputDataForProcess(array $params): array
{
    return RuleHooks::inputData($params);
}

/**
 * Hook "display_login": prints the Google login button beside the login form (SSO, spec S16).
 * GLPI calls hooks with one argument and expects the hook to echo its HTML.
 *
 * @param mixed $params
 */
function plugin_gac_display_login($params = null): void
{
    echo SsoLoginButton::render();
}
