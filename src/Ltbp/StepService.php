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

use GlpiPlugin\Gac\Shared\DocumentStore;
use GlpiPlugin\Gac\Shared\ServiceResult;
use GlpiPlugin\Gac\Shared\StateGuard;
use Session;
use Supplier;
use Toolbox;

/**
 * The steps after the emission (spec section 6): signed PDF, sending to the patrimony,
 * write-off confirmation and completion. Each one checks the status with the StateMachine.
 */
final class StepService
{
    private const TEXT_MAX = 255;

    /** @param array{name: string, tmp_name: string, error: int}|null $file */
    public static function attachSigned(Ltbp $laudo, ?array $file): ServiceResult
    {
        if (!StateMachine::canUploadSigned($laudo->getStatus())) {
            return ServiceResult::fail(__('O PDF assinado só pode ser anexado antes do envio ao patrimônio.', 'gac'));
        }
        if ($file === null) {
            return ServiceResult::fail(__('Escolha o arquivo do laudo assinado.', 'gac'));
        }

        try {
            $documentId = DocumentStore::attachUpload(
                $file,
                sprintf('%s (%s)', $laudo->fields['number'], __('assinado', 'gac')),
                (int) $laudo->fields['entities_id'],
                Ltbp::class,
                (int) $laudo->getID()
            );
        } catch (\RuntimeException $e) {
            return ServiceResult::fail($e->getMessage());
        }

        // The old file stays in the Documents tab; only the pointer moves (plan decision 7).
        $replaced = (int) $laudo->fields['documents_id_signed'] > 0;
        $laudo->changeStatus(Status::Signed, [
            'documents_id_signed' => $documentId,
            'date_signed'         => date('Y-m-d'),
        ]);
        LtbpEvent::log((int) $laudo->getID(), 'signed_uploaded', '', ['documents_id' => $documentId, 'replaced' => $replaced]);

        return ServiceResult::ok($replaced ? __('PDF assinado substituído.', 'gac') : __('PDF assinado anexado.', 'gac'));
    }

    /** @param array<string, mixed> $data */
    public static function sendToPatrimony(Ltbp $laudo, array $data): ServiceResult
    {
        if (!StateMachine::canSendToPatrimony($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível enviar ao patrimônio um laudo assinado.', 'gac'));
        }
        if ((int) $laudo->fields['documents_id_signed'] <= 0) {
            return ServiceResult::fail(__('Anexe o PDF assinado antes de enviar ao patrimônio.', 'gac'));
        }
        $date = self::date((string) ($data['date_sent'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data do envio.', 'gac'));
        }

        $receivedBy = mb_substr(trim((string) ($data['received_by'] ?? '')), 0, self::TEXT_MAX);
        $laudo->changeStatus(Status::AtPatrimony, ['date_sent_patrimony' => $date, 'received_by' => $receivedBy]);
        LtbpEvent::log((int) $laudo->getID(), 'sent_to_patrimony', $receivedBy === '' ? '' : sprintf(__('Recebido por %s', 'gac'), $receivedBy));

        return ServiceResult::ok(__('Laudo enviado ao patrimônio.', 'gac'));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int}> $files optional proof of the write-off
     */
    public static function confirmWriteoff(Ltbp $laudo, array $data, array $files = []): ServiceResult
    {
        global $DB;

        if (!StateMachine::canConfirmWriteoff($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível confirmar a baixa de um laudo que está no patrimônio.', 'gac'));
        }
        $date = self::date((string) ($data['date_written_off'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data da baixa.', 'gac'));
        }
        $stateOut = LtbpSettings::stateId(LtbpConfig::load(), 'written_off');
        if ($stateOut <= 0) {
            return ServiceResult::fail(__('Mapeie o status "Ativo baixado" na configuração do LTBP.', 'gac'));
        }

        try {
            $DB->beginTransaction();

            // The assets change status first, while the laudo is still not "Baixado", so the lock
            // never sees them; LtbpGuard makes the pass explicit (plan decision 11).
            LtbpGuard::run(static function () use ($laudo, $stateOut): void {
                foreach ($laudo->lines() as $line) {
                    $asset = getItemForItemtype((string) $line['itemtype']);
                    if (!$asset || !$asset->getFromDB((int) $line['items_id'])) {
                        throw new \RuntimeException(sprintf(__('%s: ativo não encontrado.', 'gac'), $line['item_name']));
                    }
                    if (!StateGuard::isUsable($stateOut, (int) ($asset->fields['entities_id'] ?? 0))) {
                        throw new \RuntimeException(sprintf(
                            __('%s: o status "baixado" não vale para a entidade deste ativo.', 'gac'),
                            $line['item_name']
                        ));
                    }
                    if ((int) ($asset->fields['states_id'] ?? 0) !== $stateOut
                        && !$asset->update(['id' => $asset->getID(), 'states_id' => $stateOut])) {
                        throw new \RuntimeException(sprintf(__('%s: não foi possível alterar o status do ativo.', 'gac'), $line['item_name']));
                    }
                }
            });

            $laudo->changeStatus(Status::WrittenOff, [
                'date_written_off'        => $date,
                // Optional (spec L11): today the number does not exist.
                'writeoff_process_number' => mb_substr(trim((string) ($data['writeoff_process_number'] ?? '')), 0, self::TEXT_MAX),
                'writeoff_notes'          => trim((string) ($data['writeoff_notes'] ?? '')),
            ]);
            LtbpEvent::log((int) $laudo->getID(), 'written_off');

            $DB->commit();
        } catch (\Throwable $e) {
            try {
                $DB->rollBack();
            } catch (\Throwable) {
                // not in a transaction
            }
            $laudo->getFromDB((int) $laudo->getID());
            Toolbox::logInFile('gac', sprintf("confirmWriteoff %d failed: %s\n", $laudo->getID(), $e->getMessage()));
            return ServiceResult::fail($e->getMessage());
        }
        WrittenOffLock::flush();

        // After the commit: a rejected file must not undo a write-off that already changed the assets (PRE D19).
        $attached = self::attachAll($laudo, $files, __('baixa', 'gac'));
        $message  = __('Baixa confirmada. Os ativos ficam bloqueados para edição.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array{name: string, tmp_name: string, error: int}> $files proof: certificate of destination or donation term
     */
    public static function complete(Ltbp $laudo, array $data, array $files = []): ServiceResult
    {
        if (!StateMachine::canComplete($laudo->getStatus())) {
            return ServiceResult::fail(__('Só é possível concluir um laudo com a baixa confirmada.', 'gac'));
        }
        $date = self::date((string) ($data['date_completed'] ?? ''));
        if ($date === null) {
            return ServiceResult::fail(__('Informe a data da execução.', 'gac'));
        }

        // The dropdown lists active suppliers of the laudo's entity; the server re-checks that, and the
        // entity access instead of the user's own right on suppliers (a technician may not have it).
        $supplier    = new Supplier();
        $suppliersId = (int) ($data['suppliers_id'] ?? 0);
        if (
            $suppliersId <= 0
            || !$supplier->getFromDB($suppliersId)
            || (int) $supplier->fields['is_active'] !== 1
            || !Session::haveAccessToEntity((int) $supplier->fields['entities_id'], (bool) $supplier->fields['is_recursive'])
        ) {
            return ServiceResult::fail(__('Escolha o beneficiário: um fornecedor ativo (a empresa recicladora ou o donatário).', 'gac'));
        }

        // The proof is stored BEFORE the laudo moves on, so "obrigatório" really is (plan decision 10).
        $attached = self::attachAll($laudo, $files, __('conclusão', 'gac'));
        if (LtbpSettings::requireCompletionDocument(LtbpConfig::load()) && $attached['count'] === 0) {
            return ServiceResult::fail(
                $attached['problems'] !== []
                    ? implode(' ', $attached['problems'])
                    : __('Anexe o comprovante (certificado de destinação ou termo de doação).', 'gac')
            );
        }

        $laudo->changeStatus(Status::Completed, [
            'date_completed'   => $date,
            'suppliers_id'     => $suppliersId,
            'supplier_name'    => (string) $supplier->fields['name'],
            'completion_notes' => trim((string) ($data['completion_notes'] ?? '')),
        ]);
        LtbpEvent::log((int) $laudo->getID(), 'completed', (string) $supplier->fields['name']);
        WrittenOffLock::flush();

        $message = __('Destinação concluída. O laudo está encerrado.', 'gac');
        if ($attached['problems'] !== []) {
            $message .= ' ' . __('Nem todos os documentos foram anexados:', 'gac') . ' ' . implode(' ', $attached['problems']);
        }
        return ServiceResult::ok($message);
    }

    /**
     * Stores each file as a Document linked to the laudo.
     *
     * @param list<array{name: string, tmp_name: string, error: int}> $files
     * @return array{count: int, problems: list<string>}
     */
    private static function attachAll(Ltbp $laudo, array $files, string $kind): array
    {
        $count    = 0;
        $problems = [];
        foreach ($files as $file) {
            try {
                $id = DocumentStore::attachUpload(
                    $file,
                    sprintf('%s - %s - %s', $laudo->fields['number'], $kind, pathinfo(basename($file['name']), PATHINFO_FILENAME)),
                    (int) $laudo->fields['entities_id'],
                    Ltbp::class,
                    (int) $laudo->getID()
                );
                LtbpEvent::log((int) $laudo->getID(), 'document_attached', basename($file['name']), ['documents_id' => $id]);
                $count++;
            } catch (\Throwable $e) {
                Toolbox::logInFile('gac', sprintf("attach %s failed: %s\n", $file['name'], $e->getMessage()));
                $problems[] = $e->getMessage();
            }
        }
        return ['count' => $count, 'problems' => $problems];
    }

    /** A valid Y-m-d date, or null. */
    private static function date(string $value): ?string
    {
        $value = trim($value);
        $d     = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return ($d !== false && $d->format('Y-m-d') === $value) ? $value : null;
    }
}
