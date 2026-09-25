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
use Entity;
use Glpi\Application\View\TemplateRenderer;

/**
 * PDF of the PRE (spec section 9): Twig template -> HTML -> mPDF. The final PDF is generated
 * once, at the end of "Enviar", and stored as a Document attached to the PRE (D11).
 */
final class PdfRenderer
{
    private const LOGO_MAX_BYTES = 2_000_000;
    /** Box the logo is fitted into on the report header, in millimetres. */
    private const LOGO_BOX_WIDTH_MM = 60.0;
    private const LOGO_BOX_HEIGHT_MM = 14.0;

    /** @return array{bytes: string, filename: string} */
    public static function render(RepairProtocol $p, bool $draft): array
    {
        self::loadMpdf();

        $html = TemplateRenderer::getInstance()->render('@gac/pre/report.html.twig', self::templateVars($p));

        $tmp = GLPI_TMP_DIR . '/gac-mpdf';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0770, true);
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4-L',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 16,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetHTMLFooter(sprintf(
            '<table width="100%%" style="font-size:7.5pt;color:#6b7280;"><tr><td>%s</td><td align="right">%s {PAGENO} / {nb}</td></tr></table>',
            htmlescape($p->fields['number']),
            htmlescape(__('Página', 'gac'))
        ));
        if ($draft) {
            $mpdf->SetWatermarkText(__('RASCUNHO', 'gac'), 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return [
            'bytes'    => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'filename' => $p->fields['number'] . '.pdf',
        ];
    }

    /** Generates the definitive PDF and attaches it to the PRE. @return int Document id */
    public static function attachFinal(RepairProtocol $p): int
    {
        $rendered = self::render($p, false);

        // GLPI's upload convention: the temp file is "<prefix><name>" and the prefix is passed along.
        $prefix  = bin2hex(random_bytes(4)) . '_';
        $tmpName = $prefix . $rendered['filename'];
        file_put_contents(GLPI_TMP_DIR . '/' . $tmpName, $rendered['bytes']);

        $id = (new Document())->add([
            'name'             => sprintf('%s (%s)', $p->fields['number'], __('envio', 'gac')),
            'entities_id'      => (int) $p->fields['entities_id'],
            '_filename'        => [$tmpName],
            '_prefix_filename' => [$prefix],
            'itemtype'         => RepairProtocol::class,
            'items_id'         => (int) $p->getID(),
        ]);
        if (!$id) {
            throw new \RuntimeException('Could not attach the PDF to the protocol.');
        }
        return (int) $id;
    }

    /**
     * Latest image Document of the configured category linked to the entity (or, failing that,
     * to the nearest ancestor that has one), as a data URI. Null when there is none (spec 10).
     */
    public static function logoDataUri(int $entityId): ?string
    {
        global $DB;

        $categoryId = PreSettings::logoCategoryId(PreConfig::load());
        if ($categoryId === 0) {
            return null;
        }

        $entity = new Entity();
        $visited = [];
        for ($e = $entityId; $e !== null && !isset($visited[$e]) && count($visited) < EntityChain::MAX_DEPTH; ) {
            $visited[$e] = true;
            $row = $DB->request([
                'SELECT'     => ['glpi_documents.filepath', 'glpi_documents.mime'],
                'FROM'       => 'glpi_documents_items',
                'INNER JOIN' => [
                    'glpi_documents' => [
                        'ON' => ['glpi_documents_items' => 'documents_id', 'glpi_documents' => 'id'],
                    ],
                ],
                'WHERE' => [
                    'glpi_documents_items.itemtype'         => 'Entity',
                    'glpi_documents_items.items_id'         => $e,
                    'glpi_documents.documentcategories_id'  => $categoryId,
                    'glpi_documents.is_deleted'             => 0,
                    'glpi_documents.mime'                   => ['image/png', 'image/jpeg', 'image/gif'],
                ],
                'ORDER' => ['glpi_documents.date_creation DESC', 'glpi_documents.id DESC'],
                'LIMIT' => 1,
            ])->current();

            if ($row !== null) {
                $path = GLPI_DOC_DIR . '/' . $row['filepath'];
                if (is_file($path) && filesize($path) <= self::LOGO_MAX_BYTES) {
                    return 'data:' . $row['mime'] . ';base64,' . base64_encode((string) file_get_contents($path));
                }
            }

            if (!$entity->getFromDB($e)) {
                break;
            }
            // The root's parent is -1 or NULL depending on the database: see EntityChain.
            $e = EntityChain::parentOf($e, $entity->fields['entities_id'] ?? null);
        }
        return null;
    }

    /** @return array<string, mixed> */
    private static function templateVars(RepairProtocol $p): array
    {
        $entity = new Entity();
        $entity->getFromDB((int) $p->fields['entities_id']);
        $f = $entity->fields;

        $rows = [];
        foreach ($p->lines() as $line) {
            $rows[] = [
                'tickets_id'  => (int) $line['tickets_id'],
                'type'        => (string) $line['item_type_label'],
                'name'        => (string) $line['item_name'],
                'otherserial' => (string) $line['otherserial'],
                'serial'      => (string) $line['serial'],
                'description' => (string) $line['description_supplier'],
            ];
        }

        // mPDF ignores max-width/max-height on images, so the size is computed here.
        $logo     = self::logoDataUri((int) $p->fields['entities_id']);
        $logoSize = null;
        if ($logo !== null) {
            $info = getimagesizefromstring((string) base64_decode(substr($logo, (int) strpos($logo, ',') + 1), true));
            if ($info !== false) {
                $logoSize = LogoFit::fit((int) $info[0], (int) $info[1], self::LOGO_BOX_WIDTH_MM, self::LOGO_BOX_HEIGHT_MM);
            }
        }

        return [
            'company' => [
                'name'                => (string) ($f['name'] ?? ''),
                'registration_number' => (string) ($f['registration_number'] ?? ''),
                'address_line'        => ReportFormatter::addressLine(
                    (string) ($f['address'] ?? ''),
                    (string) ($f['postcode'] ?? ''),
                    (string) ($f['town'] ?? ''),
                    (string) ($f['state'] ?? '')
                ),
                'phone'               => (string) ($f['phonenumber'] ?? ''),
                'logo_data_uri'       => $logo,
                'logo_size'           => $logoSize,
            ],
            'protocol' => [
                'number'        => (string) $p->fields['number'],
                'supplier_name' => (string) $p->fields['supplier_name'],
                'technician'    => getUserName((int) $p->fields['users_id_tech']),
                'date_issued'   => ReportFormatter::date((string) $p->fields['date_issued']),
            ],
            'rows' => $rows,
        ];
    }

    private static function loadMpdf(): void
    {
        if (class_exists(\Mpdf\Mpdf::class)) {
            return;
        }
        $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new \RuntimeException(
                'mPDF is missing: use the release package or run "composer install --no-dev" in the plugin folder.'
            );
        }
        require_once $autoload;
    }
}
