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
use GlpiPlugin\Gac\Features;
use Session;

/**
 * The Laudo Técnico de Baixa Patrimonial (spec section 5.1). UI methods (form, tabs, search
 * options) are added in the pages task; the data rules live here.
 */
class Ltbp extends CommonDBTM
{
    public static $rightname = 'plugin_gac_ltbp';
    /** Search option id that labels the events mirrored into the native history. */
    public const HISTORY_OPTION_EVENT = 90;

    public $dohistory = true;

    public const RIGHT_ISSUE            = 256;
    public const RIGHT_CANCEL           = 512;
    public const RIGHT_EDIT_WRITTEN_OFF = 1024;
    public const RIGHT_CONFIG           = Features::RIGHT_CONFIG;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbps';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Laudo Técnico de Baixa Patrimonial', 'Laudos Técnicos de Baixa Patrimonial', $nb, 'gac');
    }

    /** The laudo has no "name" column: the number identifies it in titles and logs. */
    public static function getNameField()
    {
        return 'number';
    }

    public static function getIcon()
    {
        return 'ti ti-file-certificate';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_ISSUE]            = __('Emitir e avançar etapas', 'gac');
        $values[self::RIGHT_CANCEL]           = __('Cancelar', 'gac');
        $values[self::RIGHT_EDIT_WRITTEN_OFF] = __('Editar ativo baixado', 'gac');
        $values[self::RIGHT_CONFIG]           = __('Configurar', 'gac');
        return $values;
    }

    public function getStatus(): Status
    {
        return Status::from($this->fields['status']);
    }

    public function getDestination(): ?Destination
    {
        return Destination::tryFrom((string) ($this->fields['destination'] ?? ''));
    }

    /** The header form is editable only while the laudo is a draft (spec section 6). */
    public function canUpdateItem(): bool
    {
        return parent::canUpdateItem() && ($this->isNewItem() || StateMachine::canEditDraft($this->getStatus()));
    }

    /** Only drafts and canceled laudos can be purged. */
    public function canPurgeItem(): bool
    {
        return parent::canPurgeItem() && StateMachine::canPurge($this->getStatus());
    }

    public function prepareInputForAdd($input)
    {
        if (Destination::tryFrom((string) ($input['destination'] ?? '')) === null) {
            Session::addMessageAfterRedirect(__('Informe a destinação.', 'gac'), false, ERROR);
            return false;
        }

        $input['status'] = Status::Draft->value;
        $input['number'] = NumberGenerator::next();
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
        LtbpEvent::log((int) $this->getID(), 'created');
        parent::post_addItem();
    }

    public function prepareInputForUpdate($input)
    {
        // These change only through services, never from the form.
        unset(
            $input['number'],
            $input['status'],
            $input['entities_id'],
            $input['date_issued'],
            $input['date_signed'],
            $input['date_sent_patrimony'],
            $input['date_written_off'],
            $input['date_completed'],
            $input['date_canceled'],
            $input['director_ti_name'],
            $input['director_ti_role'],
            $input['director_adm_name'],
            $input['director_adm_role'],
            $input['received_by'],
            $input['writeoff_process_number'],
            $input['writeoff_notes'],
            $input['suppliers_id'],
            $input['supplier_name'],
            $input['completion_notes'],
            $input['cancel_reason'],
            $input['documents_id_frozen'],
            $input['documents_id_signed']
        );

        if (isset($input['destination']) && Destination::tryFrom((string) $input['destination']) === null) {
            unset($input['destination']);
        }

        return $input;
    }

    /** Direct status write for services; not exposed to form input on purpose. */
    public function changeStatus(Status $new, array $extra = []): void
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
            'FROM'  => LtbpItem::getTable(),
            'WHERE' => ['plugin_gac_ltbps_id' => $this->getID()],
            'ORDER' => ['id ASC'],
        ]) as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    public function cleanDBonPurge()
    {
        $this->deleteChildrenAndRelationsFromDb([
            LtbpItem::class,
            LtbpEvent::class,
        ]);
    }

    /**
     * The laudo that holds an asset right now (any status but Canceled), or null (spec L8, L21).
     * The PRE items tab reads this to show its "Aguardando laudo" badge (plan decision 14).
     *
     * @return array{id: int, number: string, status: Status}|null
     */
    public static function activeLaudoFor(string $itemtype, int $itemsId): ?array
    {
        global $DB;

        $items  = LtbpItem::getTable();
        $laudos = self::getTable();

        $row = $DB->request([
            'SELECT'     => ["$laudos.id AS laudo_id", "$laudos.number AS laudo_number", "$laudos.status AS laudo_status"],
            'FROM'       => $items,
            'INNER JOIN' => [
                $laudos => ['ON' => [$items => 'plugin_gac_ltbps_id', $laudos => 'id']],
            ],
            'WHERE' => [
                "$items.itemtype"  => $itemtype,
                "$items.items_id"  => $itemsId,
                "$laudos.status"   => Status::holdingValues(),
            ],
            'ORDER' => ["$laudos.id DESC"],
            'LIMIT' => 1,
        ])->current();

        if ($row === null) {
            return null;
        }
        return [
            'id'     => (int) $row['laudo_id'],
            'number' => (string) $row['laudo_number'],
            'status' => Status::from((string) $row['laudo_status']),
        ];
    }
}
