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

use GlpiPlugin\Gac\Shared\ServiceResult;
use GlpiPlugin\Gac\Shared\StateGuard;
use Toolbox;

/**
 * Emission (spec 6.1) and cancellation (spec 6.2). The emission is one transaction that also
 * generates the PDF (plan decision 9): a failure undoes the assets and the laudo alike.
 */
final class IssueService
{
    /** @return list<string> translated messages; empty when the laudo can be issued */
    public static function problems(Ltbp $laudo): array
    {
        $settings = LtbpConfig::load();
        $lines    = $laudo->lines();
        $active   = LtbpReason::choices();

        $withoutReason = 0;
        $conflicts     = 0;
        foreach ($lines as $line) {
            if (!isset($active[(int) $line['plugin_gac_ltbpreasons_id']])) {
                $withoutReason++;
            }
            $other = Ltbp::activeLaudoFor((string) $line['itemtype'], (int) $line['items_id']);
            if (
                ($other !== null && $other['id'] !== (int) $laudo->getID())
                || LineService::hasActivePreLine((string) $line['itemtype'], (int) $line['items_id'])
            ) {
                $conflicts++;
            }
        }

        return array_map(self::message(...), EmissionValidator::validate([
            'destination'          => (string) $laudo->fields['destination'],
            'line_count'           => count($lines),
            'lines_without_reason' => $withoutReason,
            'missing_state_roles'  => LtbpSettings::missingStateRoles($settings),
            'directors_missing'    => LtbpSettings::directorsMissing($settings),
            'conflicts'            => $conflicts,
        ]));
    }

    private static function message(string $code): string
    {
        return match ($code) {
            'destination' => __('Defina a destinação do laudo.', 'gac'),
            'no_lines'    => __('Adicione ao menos um ativo ao laudo.', 'gac'),
            'reason'      => __('Escolha um motivo ativo para todos os ativos.', 'gac'),
            'states'      => __('Mapeie os três status do ativo na configuração do LTBP.', 'gac'),
            'directors'   => __('Preencha o nome e o cargo dos dois diretores na configuração do LTBP.', 'gac'),
            'conflicts'   => __('Há ativos que já estão em outro laudo ou em um PRE ativo; remova-os do laudo.', 'gac'),
            default       => $code,
        };
    }

    public static function issue(Ltbp $laudo): ServiceResult
    {
        global $DB;

        if (!StateMachine::canIssue($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível emitir um laudo em rascunho.', 'gac'));
        }
        $problems = self::problems($laudo);
        if ($problems !== []) {
            return ServiceResult::fail(implode(' ', $problems));
        }

        $settings  = LtbpConfig::load();
        $stateIn   = LtbpSettings::stateId($settings, 'in_process');
        $directors = LtbpSettings::directors($settings);
        $lines     = $laudo->lines();

        try {
            $DB->beginTransaction();
            if ($laudo->lockedStatus() !== Status::Draft) {
                throw new \RuntimeException(__('Este laudo já foi processado.', 'gac'));
            }

            LtbpGuard::run(static function () use ($lines, $stateIn, $DB): void {
                foreach ($lines as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        throw new \RuntimeException(sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']));
                    }
                    if (!StateGuard::isUsable($stateIn, (int) ($asset->fields['entities_id'] ?? 0))) {
                        throw new \RuntimeException(sprintf(
                            __('%s: o status "em processo de baixa" não vale para a entidade deste ativo.', 'gac'),
                            $line['item_name']
                        ));
                    }

                    $before = (int) ($asset->fields['states_id'] ?? 0);
                    if ($before !== $stateIn && !$asset->update(['id' => $asset->getID(), 'states_id' => $stateIn])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível alterar o status do ativo.', 'gac'), $line['item_name']));
                    }

                    // Snapshot of the reason (spec L9): editing the catalog later never changes an issued laudo.
                    $reason = LtbpReason::row((int) $line['plugin_gac_ltbpreasons_id']) ?? [];
                    $DB->update(
                        LtbpItem::getTable(),
                        [
                            'states_id_before'   => $before,
                            'reason_code'        => (string) ($reason['code'] ?? ''),
                            'reason_title'       => (string) ($reason['name'] ?? ''),
                            'reason_description' => (string) ($reason['comment'] ?? ''),
                            'last_error'         => null,
                            'date_mod'           => $_SESSION['glpi_currenttime'],
                        ],
                        ['id' => (int) $line['id']]
                    );
                }
            });

            // The directors are copied into the laudo (spec L3): a later change of director does not touch it.
            $laudo->changeStatus(Status::AwaitingSignatures, [
                'date_issued'       => date('Y-m-d'),
                'director_ti_name'  => $directors['ti']['name'],
                'director_ti_role'  => $directors['ti']['role'],
                'director_adm_name' => $directors['adm']['name'],
                'director_adm_role' => $directors['adm']['role'],
            ]);

            // Rendered from the rows just written (same connection); a failure here rolls everything back.
            $documentId = PdfRenderer::attachFrozen($laudo);
            $DB->update(Ltbp::getTable(), ['documents_id_frozen' => $documentId], ['id' => (int) $laudo->getID()]);
            $laudo->getFromDB((int) $laudo->getID());

            LtbpEvent::log((int) $laudo->getID(), 'issued', '', ['documents_id' => $documentId]);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("issue %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        $message = __('Laudo emitido para assinatura.', 'gac');
        $notes   = TicketNotes::milestone($laudo, 'issued');
        if ($notes !== []) {
            $message .= ' ' . __('Avisos nos tickets:', 'gac') . ' ' . implode(' ', $notes);
        }
        return ServiceResult::ok($message);
    }

    public static function cancel(Ltbp $laudo, string $reason): ServiceResult
    {
        global $DB;

        if (!StateMachine::canCancel($laudo->getStatus())) {
            return ServiceResult::fail(__('Este laudo não pode mais ser cancelado.', 'gac'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            return ServiceResult::fail(__('Informe o motivo do cancelamento.', 'gac'));
        }

        $stateIn  = LtbpSettings::stateId(LtbpConfig::load(), 'in_process');
        $warnings = [];

        try {
            $DB->beginTransaction();
            $locked = $laudo->lockedStatus();
            if ($locked === null || !StateMachine::canCancel($locked)) {
                throw new \RuntimeException(__('Este laudo já foi processado.', 'gac'));
            }

            LtbpGuard::run(function () use ($laudo, $stateIn, &$warnings): void {
                foreach ($laudo->lines() as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        $warnings[] = sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']);
                        continue;
                    }
                    // Only restores a status the laudo itself set: a manual change in the meantime stays (spec 6.2).
                    if ((int) ($asset->fields['states_id'] ?? 0) !== $stateIn) {
                        $warnings[] = sprintf(__('%s: o status foi alterado à mão e não foi restaurado.', 'gac'), $line['item_name']);
                        continue;
                    }
                    if (!$asset->update(['id' => $asset->getID(), 'states_id' => (int) ($line['states_id_before'] ?? 0)])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível restaurar o status do ativo.', 'gac'), $line['item_name']));
                    }
                }
            });

            $laudo->changeStatus(Status::Canceled, [
                'date_canceled' => $_SESSION['glpi_currenttime'],
                'cancel_reason' => $reason,
            ]);
            LtbpEvent::log((int) $laudo->getID(), 'canceled', $reason, $warnings === [] ? [] : ['warnings' => $warnings]);

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("cancel %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }

        $message = __('Laudo cancelado.', 'gac');
        if ($warnings !== []) {
            $message .= ' ' . implode(' ', $warnings);
        }
        return ServiceResult::ok($message);
    }
}
