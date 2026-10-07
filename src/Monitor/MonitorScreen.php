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
use Entity;
use GlpiPlugin\Gac\Features;
use Glpi\Application\View\TemplateRenderer;
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
        // The sound columns are only ever set by applyAlertSound(), never straight from the form.
        unset($input['alert_sound_file'], $input['alert_sound_name']);
        $input = $this->applyAlertSound($input);

        if (array_key_exists('sort_mode', $input)) {
            $mode = (string) $input['sort_mode'];
            $input['sort_mode'] = TicketSortOrder::isValidMode($mode) ? $mode : TicketSortOrder::DEFAULT_MODE;
        }

        if (array_key_exists('row_color_mode', $input)) {
            $mode = (string) $input['row_color_mode'];
            $input['row_color_mode'] = RowTone::isValidMode($mode) ? $mode : RowTone::DEFAULT_MODE;
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

        foreach (['is_recursive', 'is_public', 'alert_enabled', 'banner_enabled', 'is_active'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $input[$flag] = ((string) $input[$flag]) === '1' ? 1 : 0;
            }
        }

        if (array_key_exists('poll_interval_seconds', $input)) {
            // Empty or 0 (GLPI's number field renders NULL as 0) means "use the global default".
            $seconds = (is_numeric($input['poll_interval_seconds']) && (int) $input['poll_interval_seconds'] > 0)
                ? (int) $input['poll_interval_seconds']
                : null;
            $input['poll_interval_seconds'] = MonitorSettings::clampPollInterval($seconds);
        }

        if (array_key_exists('rotation_seconds', $input)) {
            // Empty or 0 (GLPI's number field renders NULL as 0) means "use the global default".
            $seconds = (is_numeric($input['rotation_seconds']) && (int) $input['rotation_seconds'] > 0)
                ? (int) $input['rotation_seconds']
                : null;
            $input['rotation_seconds'] = PageRotation::clampRotation($seconds);
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

    /**
     * The Tela's own alert sound (spec M19): turns the uploaded file (or the "remove" box) of the
     * form into the two sound columns. A sound that fails validation leaves the current one alone and
     * the rest of the form is still saved.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function applyAlertSound(array $input): array
    {
        $previous = (string) ($this->fields['alert_sound_file'] ?? '');
        $screenId = $this->isNewItem() ? null : (int) $this->getID();

        if (!empty($input['alert_sound_remove'])) {
            $input['alert_sound_file'] = '';
            $input['alert_sound_name'] = '';
            if ($previous !== '') {
                AlertSound::deleteIfUnused($previous, $screenId);
            }
        }
        unset($input['alert_sound_remove']);

        $upload = $_FILES['alert_sound_file'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $result = AlertSound::storeUpload($upload);
            if ($result['error'] !== null) {
                Session::addMessageAfterRedirect($result['error'], false, ERROR);
            } else {
                $input['alert_sound_file'] = $result['stored'];
                $input['alert_sound_name'] = $result['name'];
                if ($previous !== '' && $previous !== $result['stored']) {
                    AlertSound::deleteIfUnused($previous, $screenId);
                }
            }
        }
        return $input;
    }

    /** Explicit, separate action: invalidates the current public URL. Never implicit on save. */
    public function regenerateToken(): void
    {
        global $DB;
        $DB->update(self::getTable(), ['public_token' => PublicToken::generate()], ['id' => $this->getID()]);
        $this->getFromDB($this->getID());
    }

    /** @param array<string, string> $settings MonitorSettings-normalized global settings */
    public function pollIntervalSeconds(array $settings): int
    {
        $own = (int) ($this->fields['poll_interval_seconds'] ?? 0);
        return $own > 0 ? $own : MonitorSettings::defaultPollIntervalSeconds($settings);
    }

    /** @param array<string, string> $settings MonitorSettings-normalized global settings */
    public function rotationSeconds(array $settings): int
    {
        $own = (int) ($this->fields['rotation_seconds'] ?? 0);
        return $own > 0 ? $own : MonitorSettings::defaultRotationSeconds($settings);
    }

    /** Whether a new ticket also opens the big banner with its details (spec M18). */
    public function bannerEnabled(): bool
    {
        return (int) ($this->fields['banner_enabled'] ?? 0) === 1;
    }

    public function rowColorMode(): string
    {
        $mode = (string) ($this->fields['row_color_mode'] ?? '');
        return RowTone::isValidMode($mode) ? $mode : RowTone::DEFAULT_MODE;
    }

    /** @return list<MonitorPage> the Tela's pages in rotation order */
    public function pages(): array
    {
        global $DB;
        $pages = [];
        foreach ($DB->request([
            'FROM'  => MonitorPage::getTable(),
            'WHERE' => [MonitorPage::$items_id => $this->getID()],
            'ORDER' => ['position ASC', 'id ASC'],
        ]) as $row) {
            $page         = new MonitorPage();
            $page->fields = $row;
            $pages[]      = $page;
        }
        return $pages;
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(MonitorPage::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function cleanDBonPurge()
    {
        $sound = (string) ($this->fields['alert_sound_file'] ?? '');
        $this->deleteChildrenAndRelationsFromDb([MonitorPage::class]);
        if ($sound !== '') {
            AlertSound::deleteIfUnused($sound, (int) $this->getID());
        }
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        global $CFG_GLPI;

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

        $rowColorChoices = [];
        foreach (RowTone::MODES as $mode) {
            $rowColorChoices[$mode] = MonitorLabels::rowColorMode($mode);
        }

        $settings = MonitorConfig::load();
        $own      = (string) ($this->fields['alert_sound_file'] ?? '');
        $ownPath  = $own !== '' ? AlertSound::pathOf($own) : null;
        $pluginFile = AlertSound::path($settings);
        $source   = AlertSound::sourceFor($settings, $this);
        $effective = match ($source) {
            AlertSoundChoice::SCREEN_FILE => sprintf(__('Vale o som desta Tela: %s.', 'gac'), (string) ($this->fields['alert_sound_name'] ?? '')),
            AlertSoundChoice::PLUGIN_FILE => sprintf(__('Esta Tela não tem som próprio: vale o arquivo do plugin (%s).', 'gac'), MonitorSettings::alertSoundFileName($settings)),
            AlertSoundChoice::PLUGIN_URL  => __('Esta Tela não tem som próprio: vale a URL de som da configuração do plugin.', 'gac'),
            default                       => __('Sem som: o alerta fica só visual. Envie um arquivo aqui ou na configuração do plugin.', 'gac'),
        };
        $sound = [
            'own_name'  => $ownPath !== null ? (string) ($this->fields['alert_sound_name'] ?? '') : '',
            'own_kb'    => $ownPath !== null ? max(1, (int) round(filesize($ownPath) / 1024)) : 0,
            'own_url'   => $ownPath !== null ? AlertSound::fileUrl($own) : '',
            'effective' => $effective,
            'max_kb'    => intdiv(AlertSoundFile::MAX_BYTES, 1024),
        ];

        TemplateRenderer::getInstance()->display('@gac/monitor/monitorscreen.form.html.twig', [
            'sound'             => $sound,
            'item'              => $this,
            'params'            => $options,
            'sort_mode_choices' => $sortModeChoices,
            'theme_choices'     => $themeChoices,
            'font_size_choices' => $fontSizeChoices,
            'entity_levels_choices' => $entityLevelsChoices,
            'row_color_choices' => $rowColorChoices,
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
            ['id' => 4, 'table' => $t, 'field' => 'is_public', 'name' => __('Exibição pública', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'is_active', 'name' => __('Tela ativa', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
            [
                'id' => 6, 'table' => $t, 'field' => 'poll_interval_seconds', 'name' => __('Atualizar a cada (s)', 'gac'),
                'datatype' => 'number', 'massiveaction' => false,
            ],
            [
                'id' => 7, 'table' => $t, 'field' => 'sort_mode', 'name' => __('Ordem das linhas', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 8, 'table' => $t, 'field' => 'theme', 'name' => __('Tema (claro ou escuro)', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 9, 'table' => $t, 'field' => 'font_size', 'name' => __('Tamanho do texto da tabela', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 10, 'table' => $t, 'field' => 'entity_levels', 'name' => __('Níveis da entidade na coluna Entidade', 'gac'),
                'datatype' => 'specific', 'massiveaction' => false,
            ],
            [
                'id' => 11, 'table' => $t, 'field' => 'rotation_seconds', 'name' => __('Tempo de cada página (s)', 'gac'),
                'datatype' => 'number', 'massiveaction' => false,
            ],
            [
                'id' => 12, 'table' => $t, 'field' => 'row_color_mode', 'name' => __('Pintar as linhas por', 'gac'),
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
     * Log::getHistoryData() renders a changed 'specific' field by calling
     * CommonDBTM::getValueToDisplay($searchopt, $value), which resolves the target class via
     * getItemTypeForTable($searchopt['table']) instead of using the search option's own
     * 'itemtype' (set to self::class by rawSearchOptions() above, precisely for this sub-namespace
     * problem). That table-name guesser turns 'glpi_plugin_gac_monitorscreens' into
     * 'GlpiPlugin\Gac\Monitorscreen', which does not exist (the real class sits one level deeper,
     * in the Monitor sub-namespace) — class_exists() fails, getItemTypeForTable() returns null,
     * and getValueToDisplay() silently falls back to the raw stored value instead of ever calling
     * getSpecificValueToDisplay() below. Short-circuiting here for our own 'specific' fields (the
     * only case Log.php exercises, always passing the full search option array) fixes the history
     * tab without touching GLPI core.
     */
    public function getValueToDisplay($field_id_or_search_options, $values, $options = [])
    {
        if (
            is_array($field_id_or_search_options)
            && ($field_id_or_search_options['datatype'] ?? null) === 'specific'
            && ($field_id_or_search_options['table'] ?? null) === self::getTable()
        ) {
            $field   = $field_id_or_search_options['field'];
            $rawval  = is_array($values) ? ($values[$field] ?? null) : $values;
            $specific = self::getSpecificValueToDisplay($field, [$field => $rawval], $options);
            if ($specific !== '') {
                return $specific;
            }
        }
        return parent::getValueToDisplay($field_id_or_search_options, $values, $options);
    }

    /**
     * Translates the raw stored value of sort_mode/theme/font_size/entity_levels (search options
     * 7-10) into their pt-BR label — same convention CommonITILObject uses for Ticket's own enum
     * fields (status, urgency, ...). Called directly by getValueToDisplay() above for the item's
     * own history tab, and by GLPI core wherever getSpecificValueToDisplay() is reachable normally
     * (e.g. search results).
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
            'row_color_mode' => htmlescape(MonitorLabels::rowColorMode((string) $values[$field])),
            default     => parent::getSpecificValueToDisplay($field, $values, $options),
        };
    }
}
