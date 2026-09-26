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

use CommonGLPI;
use Dropdown;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Gac\Shared\ReportFormatter;
use Session;
use Supplier;

/**
 * "Andamento" tab (plan decision 15): where the laudo moves through its lifecycle. Shows the
 * stepper and only the action that fits the current status and the user's rights.
 */
class LtbpProgress extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Andamento', 'gac');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Ltbp && !$item->isNewItem()) {
            return self::createTabEntry(__('Andamento', 'gac'), 0, null, 'ti ti-route');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof Ltbp) {
            return false;
        }
        self::show($item);
        return true;
    }

    public static function show(Ltbp $laudo): void
    {
        global $CFG_GLPI;

        $status   = $laudo->getStatus();
        $rn       = Ltbp::$rightname;
        $viewable = $laudo->canViewItem();
        $advance  = $viewable && Session::haveRight($rn, Ltbp::RIGHT_ISSUE);
        $settings = LtbpConfig::load();

        $supplierDropdown = '';
        if ($advance && StateMachine::canComplete($status)) {
            $supplierDropdown = Dropdown::show(Supplier::class, [
                'name'        => 'suppliers_id',
                'entity'      => (int) $laudo->fields['entities_id'],
                'entity_sons' => true,
                'display'     => false,
                'required'    => true,
            ]);
        }

        $pdfBase = str_replace('.form.php', '.pdf.php', Ltbp::getFormURL()) . '?id=' . (int) $laudo->getID();

        TemplateRenderer::getInstance()->display('@gac/ltbp/progress.html.twig', [
            'laudo'              => $laudo,
            'status_value'       => $status->value,
            'status_label'       => Labels::status($status),
            'steps'              => self::steps($laudo, $status),
            'canceled_on'        => self::day((string) ($laudo->fields['date_canceled'] ?? '')),
            'problems'           => StateMachine::canIssue($status) ? IssueService::problems($laudo) : [],
            'can_issue'          => $advance && StateMachine::canIssue($status),
            'can_cancel'         => $viewable && Session::haveRight($rn, Ltbp::RIGHT_CANCEL) && StateMachine::canCancel($status),
            'can_upload_signed'  => $advance && StateMachine::canUploadSigned($status),
            'can_send'           => $advance && StateMachine::canSendToPatrimony($status),
            'can_confirm'        => $advance && StateMachine::canConfirmWriteoff($status),
            'can_complete'       => $advance && StateMachine::canComplete($status),
            'require_document'   => LtbpSettings::requireCompletionDocument($settings),
            'supplier_dropdown'  => $supplierDropdown,
            'has_frozen'         => (int) $laudo->fields['documents_id_frozen'] > 0,
            'has_signed'         => (int) $laudo->fields['documents_id_signed'] > 0,
            'frozen_url'         => $pdfBase,
            'signed_url'         => $pdfBase . '&doc=signed',
            'today'              => date('Y-m-d'),
            'step_url'           => $CFG_GLPI['root_doc'] . '/plugins/gac/front/ltbp/ltbpstep.form.php',
        ]);
    }

    /** @return list<array{label: string, state: string, date: string}> state is done, current or todo */
    private static function steps(Ltbp $laudo, Status $status): array
    {
        $order = [
            [Status::Draft, 'date_creation'],
            [Status::AwaitingSignatures, 'date_issued'],
            [Status::Signed, 'date_signed'],
            [Status::AtPatrimony, 'date_sent_patrimony'],
            [Status::WrittenOff, 'date_written_off'],
            [Status::Completed, 'date_completed'],
        ];

        $current = null;
        foreach ($order as $i => [$s]) {
            if ($s === $status) {
                $current = $i;
            }
        }

        $steps = [];
        foreach ($order as $i => [$s, $column]) {
            $state = 'todo';
            if ($current !== null) {
                $state = $status === Status::Completed || $i < $current ? 'done' : ($i === $current ? 'current' : 'todo');
            }
            $steps[] = [
                'label' => Labels::status($s),
                'state' => $state,
                'date'  => $state === 'todo' ? '' : self::day((string) ($laudo->fields[$column] ?? '')),
            ];
        }
        return $steps;
    }

    /** A date or timestamp column as dd/mm/aaaa; empty when unset. */
    private static function day(string $value): string
    {
        return $value === '' ? '' : ReportFormatter::date(substr($value, 0, 10));
    }
}
