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

use CommonDBChild;
use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use SavedSearch;
use Session;

/** One page of a MonitorScreen's rotation: its own saved search and columns (spec M11). */
class MonitorPage extends CommonDBChild
{
    public static $itemtype  = MonitorScreen::class;
    public static $items_id  = 'plugin_gac_monitorscreens_id';
    public static $rightname = 'plugin_gac_monitor';
    public $dohistory        = false;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_monitorpages';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Página', 'Páginas', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-layout-list';
    }

    /** The form has no "name" column; without this GLPI logs "Item (N/A (id))". */
    public function getName($options = [])
    {
        $title = trim((string) ($this->fields['title'] ?? ''));
        return $title !== '' ? $title : parent::getName($options);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof MonitorScreen && !$item->isNewItem()) {
            $count = countElementsInTable(self::getTable(), [self::$items_id => $item->getID()]);
            return self::createTabEntry(self::getTypeName(2), $count, null, 'ti ti-layout-list');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof MonitorScreen) {
            return false;
        }
        self::showForScreen($item);
        return true;
    }

    public static function showForScreen(MonitorScreen $screen): void
    {
        $rows = [];
        foreach ($screen->pages() as $page) {
            $rows[] = [
                'url'           => self::getFormURLWithID((int) $page->getID()),
                'position'      => (int) $page->fields['position'],
                'title'         => $page->displayTitle(),
                'saved_search'  => $page->savedSearchName(),
                'columns_count' => count($page->columns()),
            ];
        }

        $canEdit = $screen->canUpdateItem();
        TemplateRenderer::getInstance()->display('@gac/monitor/pages_tab.html.twig', [
            'pages'   => $rows,
            'can_add' => $canEdit && PageRotation::canAddPage(count($rows)),
            'is_full' => !PageRotation::canAddPage(count($rows)),
            'max'     => PageRotation::MAX_PAGES,
            'add_url' => self::getFormURL() . '?' . self::$items_id . '=' . $screen->getID(),
        ]);
    }

    public function prepareInputForAdd($input)
    {
        $input = parent::prepareInputForAdd($input);
        if ($input === false) {
            return false;
        }

        $screenId  = (int) ($input[self::$items_id] ?? 0);
        $positions = self::positionsFor($screenId);
        if (!PageRotation::canAddPage(count($positions))) {
            Session::addMessageAfterRedirect(
                sprintf(__('Uma Tela aceita no máximo %d páginas.', 'gac'), PageRotation::MAX_PAGES),
                false,
                ERROR
            );
            return false;
        }
        if (!isset($input['position']) || $input['position'] === '') {
            $input['position'] = PageRotation::nextPosition($positions);
        }

        return $this->prepareCommonInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareCommonInput($input);
    }

    /** @return list<int> positions already used by a Tela's pages */
    private static function positionsFor(int $screenId): array
    {
        global $DB;
        $positions = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => [self::$items_id => $screenId]]) as $row) {
            $positions[] = (int) $row['position'];
        }
        return $positions;
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

        if (array_key_exists('title', $input)) {
            $input['title'] = mb_substr(trim((string) $input['title']), 0, 255);
        }

        if (array_key_exists('position', $input)) {
            $input['position'] = max(1, (int) $input['position']);
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

    /** @return list<string> */
    public function columns(): array
    {
        $decoded = json_decode((string) ($this->fields['display_columns'] ?? '[]'), true);
        return ColumnCatalog::sanitize(is_array($decoded) ? $decoded : []);
    }

    public function savedSearchName(): string
    {
        $saved = new SavedSearch();
        $id    = (int) ($this->fields['savedsearches_id'] ?? 0);
        return ($id > 0 && $saved->getFromDB($id)) ? (string) $saved->fields['name'] : '';
    }

    public function displayTitle(): string
    {
        return PageRotation::pageTitle((string) ($this->fields['title'] ?? ''), $this->savedSearchName());
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        $screenId = $this->isNewItem()
            ? (int) ($options[self::$items_id] ?? 0)
            : (int) $this->fields[self::$items_id];
        if ($this->isNewItem()) {
            // CommonDBChild::canCreateItem() asks the parent through this field; without it the
            // form would render without the "Adicionar" button.
            $this->fields[self::$items_id] = $screenId;
        }

        $columnChoices = [];
        foreach (ColumnCatalog::allKeys() as $key) {
            $columnChoices[$key] = MonitorLabels::column($key);
        }
        // Chosen columns first, in their saved order, then the rest of the catalog.
        $chosen         = $this->isNewItem() ? ColumnCatalog::DEFAULT_COLUMNS : $this->columns();
        $orderedColumns = array_values(array_unique([...$chosen, ...ColumnCatalog::allKeys()]));

        TemplateRenderer::getInstance()->display('@gac/monitor/monitorpage.form.html.twig', [
            'item'            => $this,
            'params'          => $options,
            'screen_id'       => $screenId,
            'next_position'   => PageRotation::nextPosition(self::positionsFor($screenId)),
            'columns_ordered' => $orderedColumns,
            'chosen'          => $chosen,
            'column_choices'  => $columnChoices,
            'saved_searches'  => ['' => Dropdown::EMPTY_VALUE] + self::sharedTicketSavedSearches(),
        ]);

        return true;
    }
}
