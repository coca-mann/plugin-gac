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
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Gac\Features;
use Session;

/**
 * Catalog of write-off reasons (spec L9): code, title (`name`), description (`comment`), active
 * flag. A reason in use can only be deactivated, never deleted. Managed from the module's
 * configuration, so every right check is the "Configurar" bit.
 */
class LtbpReason extends CommonDBTM
{
    public static $rightname = 'plugin_gac_ltbp';
    public $dohistory        = false;

    private const CODE_MAX = 20;

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpreasons';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Motivo de baixa', 'Motivos de baixa', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-list-check';
    }

    public static function canView(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canCreate(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canUpdate(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canDelete(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    public static function canPurge(): bool
    {
        return Features::canConfigure(self::$rightname);
    }

    /** A reason already used by a laudo line can be deactivated, not deleted (spec L9). */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && !self::isUsed((int) $this->getID());
    }

    public static function isUsed(int $id): bool
    {
        return countElementsInTable(LtbpItem::getTable(), ['plugin_gac_ltbpreasons_id' => $id]) > 0;
    }

    public function prepareInputForAdd($input)
    {
        return $this->checkedInput($input, 0);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->checkedInput($input, (int) ($input['id'] ?? $this->getID()));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|false
     */
    private function checkedInput(array $input, int $selfId): array|false
    {
        if (array_key_exists('code', $input)) {
            $input['code'] = mb_strtoupper(mb_substr(trim((string) $input['code']), 0, self::CODE_MAX));
            if ($input['code'] === '') {
                Session::addMessageAfterRedirect(__('Informe o código do motivo.', 'gac'), false, ERROR);
                return false;
            }
            $duplicates = countElementsInTable(self::getTable(), ['code' => $input['code'], ['NOT' => ['id' => $selfId]]]);
            if ($duplicates > 0) {
                Session::addMessageAfterRedirect(__('Já existe um motivo com este código.', 'gac'), false, ERROR);
                return false;
            }
        }
        if (array_key_exists('name', $input) && trim((string) $input['name']) === '') {
            Session::addMessageAfterRedirect(__('Informe o título do motivo.', 'gac'), false, ERROR);
            return false;
        }
        return $input;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@gac/ltbp/reason.form.html.twig', [
            'item'   => $this,
            'params' => $options,
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'code', 'name' => __('Código', 'gac'),
                // Without 'itemtype' GLPI maps the table back to a class, which fails for a class
                // in a sub-namespace (same reason getTable() is overridden).
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            ['id' => 3, 'table' => $t, 'field' => 'name', 'name' => __('Título', 'gac'), 'datatype' => 'string', 'massiveaction' => false],
            ['id' => 4, 'table' => $t, 'field' => 'comment', 'name' => __('Descrição', 'gac'), 'datatype' => 'text', 'massiveaction' => false],
            ['id' => 5, 'table' => $t, 'field' => 'is_active', 'name' => __('Ativo', 'gac'), 'datatype' => 'bool', 'massiveaction' => false],
        ];

        // Declare the itemtype on every column of this class's table, not only on the link.
        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }

    /** @return array<string, mixed>|null */
    public static function row(int $id): ?array
    {
        global $DB;

        $row = $DB->request(['FROM' => self::getTable(), 'WHERE' => ['id' => $id]])->current();
        return $row === null ? null : (array) $row;
    }

    /**
     * Options of a reason dropdown: id => "COD: título", ordered by code.
     *
     * @return array<int, string>
     */
    public static function choices(bool $activeOnly = true): array
    {
        global $DB;

        $where = $activeOnly ? ['is_active' => 1] : [];
        $out = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'ORDER' => ['code ASC']]) as $row) {
            $out[(int) $row['id']] = sprintf('%s: %s', $row['code'], $row['name']);
        }
        return $out;
    }
}
