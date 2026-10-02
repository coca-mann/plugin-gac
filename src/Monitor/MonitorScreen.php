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

namespace GlpiPlugin\Gac\Monitor;

use CommonDBTM;
use Dropdown;
use Entity;
use GlpiPlugin\Gac\Features;
use Glpi\Application\View\TemplateRenderer;
use SavedSearch;
use Session;

class MonitorScreen extends CommonDBTM
{
    public static $rightname = 'plugin_gac_monitor';

    public const RIGHT_CONFIG = Features::RIGHT_CONFIG;

    public $dohistory = true;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_monitorscreens';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Tela de Monitoramento', 'Telas de Monitoramento', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-device-tv';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_CONFIG] = __('Configurar', 'gac');
        return $values;
    }

    public function prepareInputForAdd($input)
    {
        $input = $this->prepareCommonInput($input);
        if ($input === false) {
            return false;
        }
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) Session::getActiveEntity();
        }
        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function prepareCommonInput(array $input)
    {
        if (array_key_exists('savedsearches_id', $input)) {
            $id = (int) $input['savedsearches_id'];
            if ($id <= 0 || !self::isSharedTicketSavedSearch($id)) {
                Session::addMessageAfterRedirect(
                    __('Escolha uma Pesquisa Salva de Ticket compartilhada.', 'gac'),
                    false,
                    ERROR
                );
                return false;
            }
        }

        if (array_key_exists('display_columns', $input)) {
            $raw = $input['display_columns'];
            $input['display_columns'] = json_encode(
                ColumnCatalog::sanitize(is_array($raw) ? $raw : []),
                JSON_THROW_ON_ERROR
            );
        }

        if (array_key_exists('sort_mode', $input)) {
            $mode = (string) $input['sort_mode'];
            $input['sort_mode'] = TicketSortOrder::isValidMode($mode) ? $mode : TicketSortOrder::DEFAULT_MODE;
        }

        if (array_key_exists('theme', $input)) {
            $theme = (string) $input['theme'];
            $input['theme'] = BoardAppearance::isValidTheme($theme) ? $theme : BoardAppearance::DEFAULT_THEME;
        }

        if (array_key_exists('font_size', $input)) {
            $size = is_numeric($input['font_size']) ? (int) $input['font_size'] : BoardAppearance::DEFAULT_FONT_SIZE;
            $input['font_size'] = BoardAppearance::isValidFontSize($size) ? $size : BoardAppearance::DEFAULT_FONT_SIZE;
        }

        if (array_key_exists('entity_levels', $input)) {
            $levels = is_numeric($input['entity_levels']) ? (int) $input['entity_levels'] : EntityLevels::DEFAULT_LEVELS;
            $input['entity_levels'] = EntityLevels::sanitize($levels);
        }

        foreach (['is_recursive', 'is_public', 'alert_enabled', 'is_active'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = ((string) $input[$flag]) === '1' ? 1 : 0;
            }
        }

        if (array_key_exists('poll_interval_seconds', $input)) {
            $seconds = ($input['poll_interval_seconds'] !== '' && is_numeric($input['poll_interval_seconds']))
                ? (int) $input['poll_interval_seconds']
                : null;
            $input['poll_interval_seconds'] = MonitorSettings::clampPollInterval($seconds);
        }

        // The public token is never set from the form directly: it is generated the first time
        // "is_public" turns on. update() loads $this->fields from the DB before calling this
        // method, so on an update $this->fields still holds the row's previous state.
        $wantsPublic = (int) ($input['is_public'] ?? $this->fields['is_public'] ?? 0) === 1;
        $hasToken    = !empty($this->fields['public_token'] ?? null) || !empty($input['public_token'] ?? null);
        if ($wantsPublic && !$hasToken) {
            $input['public_token'] = PublicToken::generate();
        }

        return $input;
    }

    private static function isSharedTicketSavedSearch(int $id): bool
    {
        $saved = new SavedSearch();
        if (!$saved->getFromDB($id)) {
            return false;
        }
        return $saved->fields['itemtype'] === 'Ticket'
            && (int) $saved->fields['is_private'] === 0
            && (int) $saved->fields['type'] === SavedSearch::SEARCH;
    }

    /** Explicit, separate action: invalidates the current public URL. Never implicit on save. */
    public function regenerateToken(): void
    {
        global $DB;
        $DB->update(self::getTable(), ['public_token' => PublicToken::generate()], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
    }

    /** @return list<string> */
    public function displayColumns(): array
    {
        $decoded = json_decode((string) ($this->fields['display_columns'] ?? '[]'), true);
        return ColumnCatalog::sanitize(is_array($decoded) ? $decoded : []);
    }

    /** @param array<string, string> $settings MonitorSettings-normalized global settings */
    public function pollIntervalSeconds(array $settings): int
    {
        $own = (int) ($this->fields['poll_interval_seconds'] ?? 0);
        return $own > 0 ? $own : MonitorSettings::defaultPollIntervalSeconds($settings);
    }

    /** @return array<int, string> id => name, Ticket SavedSearches shared (not private) */
    public static function sharedTicketSavedSearches(): array
    {
        global $DB;
        $options = [];
        foreach ($DB->request([
            'FROM'  => SavedSearch::getTable(),
            'WHERE' => ['itemtype' => 'Ticket', 'is_private' => 0, 'type' => SavedSearch::SEARCH],
            'ORDER' => ['name ASC'],
        ]) as $row) {
            $options[(int) $row['id']] = (string) $row['name'];
        }
        return $options;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        global $CFG_GLPI;

        $columnChoices = [];
        foreach (ColumnCatalog::allKeys() as $key) {
            $columnChoices[$key] = MonitorLabels::column($key);
        }

        $sortModeChoices = [];
        foreach (TicketSortOrder::MODES as $mode) {
            $sortModeChoices[$mode] = MonitorLabels::sortMode($mode);
        }

        $themeChoices = [];
        foreach (BoardAppearance::THEMES as $theme) {
            $themeChoices[$theme] = MonitorLabels::theme($theme);
        }

        $fontSizeChoices = [];
        foreach (range(BoardAppearance::MIN_FONT_SIZE, BoardAppearance::MAX_FONT_SIZE) as $size) {
            $fontSizeChoices[$size] = MonitorLabels::fontSize($size);
        }

        $entityLevelsChoices = [];
        foreach (range(EntityLevels::MIN_LEVELS, EntityLevels::MAX_LEVELS) as $levels) {
            $entityLevelsChoices[$levels] = MonitorLabels::entityLevels($levels);
        }

        // Chosen columns first, in their saved order, then the rest of the catalog: the admin
        // sees the current configuration already in place and only has to drag unchecked rows
        // in, not hunt for them.
        $chosen         = $this->isNewItem() ? ColumnCatalog::DEFAULT_COLUMNS : $this->displayColumns();
        $orderedColumns = array_values(array_unique([...$chosen, ...ColumnCatalog::allKeys()]));

        TemplateRenderer::getInstance()->display('@gac/monitor/monitorscreen.form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'columns_ordered'   => $orderedColumns,
            'chosen'            => $chosen,
            'column_choices'    => $columnChoices,
            'sort_mode_choices' => $sortModeChoices,
            'theme_choices'     => $themeChoices,
            'font_size_choices' => $fontSizeChoices,
            'entity_levels_choices' => $entityLevelsChoices,
            'saved_searches'    => ['' => Dropdown::EMPTY_VALUE] + self::sharedTicketSavedSearches(),
            // Absolute (scheme + host), not root_doc (path only): this URL is meant to be
            // opened on another device (a TV), not just fetched from the current page.
            'public_url'     => empty($this->fields['public_token'] ?? null)
                ? ''
                : $CFG_GLPI['url_base'] . '/plugins/gac/front/monitor/public.php?token=' . $this->fields['public_token'],
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'name', 'name' => __('Nome', 'gac'),
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            [
                'id' => 3, 'table' => SavedSearch::getTable(), 'field' => 'name', 'linkfield' => 'savedsearches_id',
                'name' => SavedSearch::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            ['id' => 4, 'table' => $t, 'field' => 'is_public', 'name' => __('Pública', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'is_active', 'name' => __('Ativa', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            [
                'id' => 6, 'table' => $t, 'field' => 'poll_interval_seconds', 'name' => __('Intervalo (s)', 'gac'),
                'datatype' => 'number', 'massiveaction' => false,
            ],
            [
                'id' => 7, 'table' => $t, 'field' => 'sort_mode', 'name' => __('Ordenação', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 8, 'table' => $t, 'field' => 'theme', 'name' => __('Tema', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 9, 'table' => $t, 'field' => 'font_size', 'name' => __('Tamanho da fonte', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 10, 'table' => $t, 'field' => 'entity_levels', 'name' => __('Níveis de entidade exibidos', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename',
                'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
        ];

        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }

    /**
     * Translates the raw stored value of sort_mode/theme/font_size (search options 7-9) into
     * their pt-BR label — same convention CommonITILObject uses for Ticket's own enum fields
     * (status, urgency, ...). Without this, GLPI's generic history log (Log::getHistoryData(),
     * which calls this for any search option whose table matches the item's own table) shows the
     * raw internal code ('priority', 'dark', '3') instead of the label the form itself shows.
     */
    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        return match ($field) {
            'sort_mode' => htmlescape(MonitorLabels::sortMode((string) $values[$field])),
            'theme'     => htmlescape(MonitorLabels::theme((string) $values[$field])),
            'font_size' => htmlescape(MonitorLabels::fontSize((int) $values[$field])),
            'entity_levels' => htmlescape(MonitorLabels::entityLevels((int) $values[$field])),
            default     => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }
}
