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

namespace GlpiPlugin\Gac\Pre;

use CommonDBTM;
use CommonGLPI;
use Entity;
use Glpi\Application\View\TemplateRenderer;
use Session;
use Supplier;

class RepairProtocol extends CommonDBTM
{
    public static $rightname = 'plugin_gac_pre';
    public $dohistory        = true;

    public const RIGHT_SEND   = 256;
    public const RIGHT_RETURN = 512;
    public const RIGHT_REOPEN = 1024;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocols';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Protocolo de Reparo de Equipamento', 'Protocolos de Reparo de Equipamento', $nb, 'gac');
    }

    /** The PRE has no "name" column: the number is what identifies it in titles and logs. */
    public static function getNameField()
    {
        return 'number';
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_SEND]   = __('Enviar', 'gac');
        $values[self::RIGHT_RETURN] = __('Registrar retorno', 'gac');
        $values[self::RIGHT_REOPEN] = __('Reabrir', 'gac');
        return $values;
    }

    public function getStatus(): ProtocolStatus
    {
        return ProtocolStatus::from($this->fields['status']);
    }

    /** The header form is editable only while the PRE is a draft (spec 6.1). */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && ($this->isNewItem() || $this->getStatus() === ProtocolStatus::Draft);
    }

    /** Only drafts and canceled PREs can be purged. */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem()
            && in_array($this->getStatus(), [ProtocolStatus::Draft, ProtocolStatus::Canceled], true);
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['suppliers_id'])) {
            Session::addMessageAfterRedirect(__('Informe o fornecedor.', 'gac'), false, ERROR);
            return false;
        }

        $input['status']        = ProtocolStatus::Draft->value;
        $input['number']        = ProtocolNumberGenerator::next();
        $input['supplier_name'] = self::supplierName((int) $input['suppliers_id']);
        $input['date_issued']   = !empty($input['date_issued']) ? $input['date_issued'] : date('Y-m-d');
        if (empty($input['users_id_tech'])) {
            $input['users_id_tech'] = (int) Session::getLoginUserID();
        }
        if (!isset($input['entities_id'])) {
            $input['entities_id'] = (int) Session::getActiveEntity();
        }

        return $input;
    }

    public function post_addItem()
    {
        RepairProtocolEvent::log((int) $this->getID(), 'created');
        parent::post_addItem();
    }

    public function prepareInputForUpdate($input)
    {
        // These are never edited from the form: they change only through services.
        unset(
            $input['number'],
            $input['status'],
            $input['entities_id'],
            $input['documents_id_sent'],
            $input['date_sent'],
            $input['date_closed'],
            $input['supplier_name']
        );

        if (!empty($input['suppliers_id'])) {
            $input['supplier_name'] = self::supplierName((int) $input['suppliers_id']);
        }

        return $input;
    }

    /** Direct status write for services; not exposed to form input on purpose. */
    public function changeStatus(ProtocolStatus $new, array $extra = []): void
    {
        global $DB;

        $DB->update(
            self::getTable(),
            ['status' => $new->value, 'date_mod' => $_SESSION['glpi_currenttime']] + $extra,
            ['id' => $this->getID()]
        );
        $this->getFromDB($this->getID());
    }

    /** @return list<array<string, mixed>> */
    public function lines(): array
    {
        global $DB;

        $rows = [];
        foreach ($DB->request([
            'FROM'  => RepairProtocolItem::getTable(),
            'WHERE' => ['plugin_gac_repairprotocols_id' => $this->getID()],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function supplierName(int $suppliersId): string
    {
        $supplier = new Supplier();
        return $supplier->getFromDB($suppliersId) ? (string) $supplier->fields['name'] : '';
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            RepairProtocolItem::class,
            RepairProtocolEvent::class,
        ]);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(RepairProtocolItem::class, $tabs, $options);
        $this->addStandardTab(RepairProtocolEvent::class, $tabs, $options);
        $this->addStandardTab('Log', $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@gac/pre/repairprotocol.form.html.twig', [
            'item'           => $this,
            'params'         => $options,
            'status_label'   => $this->isNewItem()
                ? Labels::protocolStatus(ProtocolStatus::Draft)
                : Labels::protocolStatus($this->getStatus()),
            'header_editable' => $this->canUpdateItem() || $this->isNewItem(),
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $t = self::getTable();
        $options = [
            ['id' => 'common', 'name' => self::getTypeName(2)],
            [
                'id' => 1, 'table' => $t, 'field' => 'number', 'name' => __('Número', 'gac'),
                // Without 'itemtype' GLPI maps the table back to a class, which fails for a
                // class in a sub-namespace (same reason getTable() is overridden).
                'datatype' => 'itemlink', 'itemtype' => self::class, 'massiveaction' => false, 'autocomplete' => true,
            ],
            ['id' => 2, 'table' => $t, 'field' => 'id', 'name' => __('ID'), 'datatype' => 'number', 'massiveaction' => false],
            [
                'id' => 3, 'table' => $t, 'field' => 'status', 'name' => __('Status'),
                'datatype' => 'specific', 'searchtype' => ['equals', 'notequals'], 'massiveaction' => false,
            ],
            [
                'id' => 4, 'table' => 'glpi_suppliers', 'field' => 'name', 'linkfield' => 'suppliers_id',
                'name' => __('Fornecedor', 'gac'), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            [
                'id' => 5, 'table' => 'glpi_users', 'field' => 'name', 'linkfield' => 'users_id_tech',
                'name' => __('Técnico responsável', 'gac'), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
            ['id' => 6, 'table' => $t, 'field' => 'date_issued', 'name' => __('Data de emissão', 'gac'), 'datatype' => 'date', 'massiveaction' => false],
            ['id' => 7, 'table' => $t, 'field' => 'date_sent', 'name' => __('Data de envio', 'gac'), 'datatype' => 'datetime', 'massiveaction' => false],
            ['id' => 8, 'table' => $t, 'field' => 'date_closed', 'name' => __('Data de encerramento', 'gac'), 'datatype' => 'datetime', 'massiveaction' => false],
            [
                'id' => 80, 'table' => 'glpi_entities', 'field' => 'completename',
                'name' => Entity::getTypeName(1), 'datatype' => 'dropdown', 'massiveaction' => false,
            ],
        ];

        // GLPI maps a column's table back to a class to render it, which fails for a class in a
        // sub-namespace (same reason getTable() is overridden): declare the itemtype on every
        // column that belongs to the PRE table, not only on the number.
        foreach ($options as &$option) {
            if (($option['table'] ?? null) === $t && !isset($option['itemtype'])) {
                $option['itemtype'] = self::class;
            }
        }
        unset($option);

        return $options;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $status = ProtocolStatus::tryFrom((string) $values[$field]);
            return $status === null ? '' : Labels::protocolStatus($status);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            $choices = [];
            foreach (ProtocolStatus::cases() as $status) {
                $choices[$status->value] = Labels::protocolStatus($status);
            }
            return \Dropdown::showFromArray($name, $choices, $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }
}
