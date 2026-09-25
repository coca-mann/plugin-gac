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

use CommonDBChild;
use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;
use Ticket;

class RepairProtocolItem extends CommonDBChild
{
    public static $itemtype = RepairProtocol::class;
    public static $items_id = 'plugin_gac_repairprotocols_id';
    public static $rightname = 'plugin_gac_pre';
    public $dohistory       = false;

    /**
     * The line has no "name" column; without this GLPI's native history logs the addition or
     * removal of a line as "Item (N/A (id))".
     */
    public function getName($options = [])
    {
        if (empty($this->fields['tickets_id'])) {
            return parent::getName($options);
        }
        return sprintf('#%d · %s', $this->fields['tickets_id'], $this->fields['item_name'] ?? '');
    }

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocolitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }

    public function getStatus(): ItemStatus
    {
        return ItemStatus::from($this->fields['status']);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof RepairProtocol) {
            $count = countElementsInTable(self::getTable(), ['plugin_gac_repairprotocols_id' => $item->getID()]);
            return self::createTabEntry(__('Itens', 'gac'), $count);
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof RepairProtocol) {
            return false;
        }
        self::showForProtocol($item);
        return true;
    }

    public static function showForProtocol(RepairProtocol $protocol): void
    {
        global $CFG_GLPI;

        $status = $protocol->getStatus();
        $rn     = RepairProtocol::$rightname;

        $lines = [];
        $pendingIds = [];
        $atSupplier = 0;
        foreach ($protocol->lines() as $row) {
            $itemStatus = ItemStatus::from($row['status']);
            if ($itemStatus === ItemStatus::PendingSend) {
                $pendingIds[] = (int) $row['id'];
            }
            if ($itemStatus === ItemStatus::AtSupplier) {
                $atSupplier++;
            }
            $lines[] = $row + [
                'ticket_url'        => Ticket::getFormURLWithID((int) $row['tickets_id']),
                'status_label'      => Labels::itemStatus($itemStatus),
                'is_pending_send'   => $itemStatus === ItemStatus::PendingSend,
                'is_at_supplier'    => $itemStatus === ItemStatus::AtSupplier,
                'is_returned'       => $itemStatus === ItemStatus::Returned,
                'outcome_label'     => $row['outcome'] ? Labels::outcome(Outcome::from($row['outcome'])) : '',
                'destination_label' => $row['destination'] ? Labels::destination(Destination::from($row['destination'])) : '',
                'can_remove_draft'  => StateMachine::canRemoveLine($status, $itemStatus)
                    && !StateMachine::removeRequiresReason($status),
                'can_remove_failed' => StateMachine::canRemoveLine($status, $itemStatus)
                    && StateMachine::removeRequiresReason($status),
            ];
        }

        $viewable = $protocol->canViewItem();
        $canEdit  = $protocol->canUpdateItem();
        $isDraft  = $status === ProtocolStatus::Draft;

        $canSend = $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_SEND);
        $sendMode = '';
        if ($isDraft && $lines !== []) {
            $sendMode = 'start';
        } elseif ($status === ProtocolStatus::Sent) {
            if ($pendingIds !== []) {
                $sendMode = 'continue';
            } elseif ((int) $protocol->fields['documents_id_sent'] === 0) {
                $sendMode = 'finalize';
            }
        }

        $outcomeOptions = [];
        foreach (Outcome::cases() as $o) {
            $outcomeOptions[] = ['value' => $o->value, 'label' => Labels::outcome($o), 'defective' => $o->isDefective()];
        }
        $destinationOptions = [
            ['value' => Destination::Writeoff->value, 'label' => Labels::destination(Destination::Writeoff)],
            ['value' => Destination::KeepDefective->value, 'label' => Labels::destination(Destination::KeepDefective)],
        ];

        TemplateRenderer::getInstance()->display('@gac/pre/items_tab.html.twig', [
            'protocol'           => $protocol,
            'lines'              => $lines,
            'is_draft'           => $isDraft,
            'can_edit'           => $canEdit,
            'can_send'           => $canSend,
            'send_mode'          => $sendMode,
            'pending_line_ids'   => $pendingIds,
            'can_return'         => $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_RETURN),
            'can_reopen'         => $viewable && Session::haveRight($rn, RepairProtocol::RIGHT_REOPEN),
            'is_reopened'        => $status === ProtocolStatus::Partial && RepairProtocolEvent::isReopened((int) $protocol->getID()),
            'candidates'         => ($isDraft && $canEdit) ? EligibleTicketFinder::find($protocol) : [],
            'form_url'           => RepairProtocolItem::getFormURL(),
            'ajax_send_url'      => $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/pre_send.php',
            'pdf_url'            => str_replace('.form.php', '.pdf.php', RepairProtocol::getFormURL()) . '?id=' . (int) $protocol->getID(),
            'can_pdf'            => $viewable && ($isDraft ? $lines !== [] : (int) $protocol->fields['documents_id_sent'] > 0),
            'outcome_options'    => $outcomeOptions,
            'destination_options' => $destinationOptions,
        ]);
    }
}
