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

use Document;
use Document_Item;
use Ticket;
use Toolbox;

/**
 * Files uploaded with a return registration: each one becomes a GLPI Document linked to the
 * line's ticket and to the PRE (the same file, no copy).
 */
final class ReturnAttachments
{
    /**
     * Reads the multi-file input $_FILES[$field] into a flat list, skipping empty slots.
     *
     * @param array<string, mixed> $files usually $_FILES
     * @return list<array{name: string, tmp_name: string, error: int}>
     */
    public static function collect(array $files, string $field = 'attachments'): array
    {
        $raw = $files[$field] ?? null;
        if (!is_array($raw) || !is_array($raw['name'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($raw['name'] as $i => $name) {
            $error = (int) ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => (string) $name,
                'tmp_name' => (string) ($raw['tmp_name'][$i] ?? ''),
                'error'    => $error,
            ];
        }
        return $out;
    }

    /**
     * Stores the files as Documents linked to the ticket and the PRE. Runs after the return is
     * committed, so a rejected file never undoes the return; problems come back as messages.
     *
     * @param list<array{name: string, tmp_name: string, error: int}> $files
     * @return list<string> one message per file that could not be attached
     */
    public static function attach(array $files, RepairProtocol $protocol, RepairProtocolItem $line, Ticket $ticket): array
    {
        $problems = [];

        foreach ($files as $file) {
            $name = basename($file['name']);
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                $problems[] = sprintf(__('%s: o envio do arquivo falhou.', 'gac'), $name);
                continue;
            }

            // GLPI's upload convention: the temp file is "<prefix><name>" and the prefix is passed along.
            $prefix  = bin2hex(random_bytes(4)) . '_';
            $tmpName = $prefix . $name;
            if (!move_uploaded_file($file['tmp_name'], GLPI_TMP_DIR . '/' . $tmpName)) {
                $problems[] = sprintf(__('%s: não foi possível guardar o arquivo.', 'gac'), $name);
                continue;
            }

            try {
                $document = new Document();
                $id = $document->add([
                    'name'             => sprintf('%s - %s', $protocol->fields['number'], pathinfo($name, PATHINFO_FILENAME)),
                    'entities_id'      => (int) $ticket->fields['entities_id'],
                    'is_recursive'     => (int) ($ticket->fields['is_recursive'] ?? 0),
                    '_filename'        => [$tmpName],
                    '_prefix_filename' => [$prefix],
                    'itemtype'         => Ticket::class,
                    'items_id'         => (int) $ticket->getID(),
                ]);
                if (!$id) {
                    $problems[] = sprintf(__('%s: o GLPI recusou o arquivo (tipo ou tamanho não permitido).', 'gac'), $name);
                    continue;
                }

                $linked = (new Document_Item())->add([
                    'documents_id' => $id,
                    'itemtype'     => RepairProtocol::class,
                    'items_id'     => (int) $protocol->getID(),
                ]);
                if (!$linked) {
                    $problems[] = sprintf(__('%s: anexado ao ticket, mas não ao PRE.', 'gac'), $name);
                    continue;
                }

                RepairProtocolEvent::log(
                    (int) $protocol->getID(),
                    'line_document_attached',
                    $name,
                    ['documents_id' => (int) $id],
                    (int) $line->getID()
                );
            } catch (\Throwable $e) {
                Toolbox::logInFile('gac', sprintf("attach %s failed: %s\n", $name, $e->getMessage()));
                $problems[] = sprintf(__('%s: não foi possível anexar.', 'gac'), $name);
            }
        }

        return $problems;
    }
}
