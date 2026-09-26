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

use Entity;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Gac\Shared\DocumentStore;
use GlpiPlugin\Gac\Shared\LogoFit;
use GlpiPlugin\Gac\Shared\LogoLocator;
use GlpiPlugin\Gac\Shared\MpdfLoader;
use GlpiPlugin\Gac\Shared\ReportFormatter;

/**
 * PDF of the laudo (spec section 9): Twig template -> HTML -> mPDF, in PORTRAIT (spec L16).
 * The definitive PDF is generated once, at the emission, and stored as a Document (spec L17).
 */
final class PdfRenderer
{
    /** Box the logo is fitted into on the report header, in millimetres (same as the PRE). */
    private const LOGO_BOX_WIDTH_MM = 60.0;
    private const LOGO_BOX_HEIGHT_MM = 14.0;

    /** @return array{bytes: string, filename: string} */
    public static function render(Ltbp $l, bool $draft): array
    {
        MpdfLoader::load();

        $html = TemplateRenderer::getInstance()->render('@gac/ltbp/report.html.twig', self::templateVars($l, $draft));

        $tmp = GLPI_TMP_DIR . '/gac-mpdf';
        if (!is_dir($tmp)) {
            mkdir($tmp, 0770, true);
        }
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4', 'orientation' => 'P',
            'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 12, 'margin_bottom' => 18,
            'default_font' => 'dejavusans', 'tempDir' => $tmp,
        ]);
        $mpdf->SetHTMLFooter(sprintf(
            '<table width="100%%" style="font-size:7.5pt;color:#6b7280;"><tr><td width="34%%">%s</td><td width="33%%" align="center">%s</td><td width="33%%" align="right">%s {PAGENO} / {nb}</td></tr></table>',
            htmlescape((string) $l->fields['number']),
            htmlescape(self::applicationUrl()),
            htmlescape(__('Página', 'gac'))
        ));
        if ($draft) {
            $mpdf->SetWatermarkText(__('RASCUNHO', 'gac'), 0.08);
            $mpdf->showWatermarkText = true;
        }
        $mpdf->WriteHTML($html);

        return [
            'bytes'    => $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN),
            'filename' => $l->fields['number'] . '.pdf',
        ];
    }

    /** Generates the definitive PDF and attaches it to the laudo. @return int Document id */
    public static function attachFrozen(Ltbp $l): int
    {
        $rendered = self::render($l, false);

        return DocumentStore::attachBytes(
            $rendered['bytes'],
            $rendered['filename'],
            sprintf('%s (%s)', $l->fields['number'], __('emitido', 'gac')),
            (int) $l->fields['entities_id'],
            Ltbp::class,
            (int) $l->getID()
        );
    }

    /** @return array<string, mixed> */
    private static function templateVars(Ltbp $l, bool $draft): array
    {
        $settings = LtbpConfig::load();

        $entity = new Entity();
        $entity->getFromDB((int) $l->fields['entities_id']);
        $f = $entity->fields;

        // A draft preview reads the live catalog and the configured directors; an issued laudo
        // reads only the snapshots stored in it (spec L3, L9).
        $directors = $draft ? LtbpSettings::directors($settings) : [
            'ti'  => ['name' => (string) $l->fields['director_ti_name'], 'role' => (string) $l->fields['director_ti_role']],
            'adm' => ['name' => (string) $l->fields['director_adm_name'], 'role' => (string) $l->fields['director_adm_role']],
        ];

        $rows   = [];
        $legend = [];
        foreach ($l->lines() as $line) {
            if ($draft) {
                $reason = LtbpReason::row((int) $line['plugin_gac_ltbpreasons_id']) ?? [];
                $code   = (string) ($reason['code'] ?? '');
                $title  = (string) ($reason['name'] ?? '');
                $desc   = (string) ($reason['comment'] ?? '');
            } else {
                $code  = (string) $line['reason_code'];
                $title = (string) $line['reason_title'];
                $desc  = (string) $line['reason_description'];
            }
            $rows[] = [
                'name'        => (string) $line['item_name'],
                'type'        => (string) $line['item_type_label'],
                'brand'       => (string) $line['brand'],
                'model'       => (string) $line['model'],
                'serial'      => (string) $line['serial'],
                'otherserial' => (string) $line['otherserial'],
                'reason_code' => $code,
            ];
            if ($code !== '') {
                $legend[$code] = ['code' => $code, 'title' => $title, 'description' => $desc];
            }
        }
        ksort($legend);

        // mPDF ignores max-width/max-height on images, so the size is computed here.
        $logo     = LogoLocator::dataUri((int) $l->fields['entities_id'], LtbpSettings::logoCategoryId($settings));
        $logoSize = null;
        if ($logo !== null) {
            $info = getimagesizefromstring((string) base64_decode(substr($logo, (int) strpos($logo, ',') + 1), true));
            if ($info !== false) {
                $logoSize = LogoFit::fit((int) $info[0], (int) $info[1], self::LOGO_BOX_WIDTH_MM, self::LOGO_BOX_HEIGHT_MM);
            }
        }

        $issued      = $draft ? date('Y-m-d') : (string) $l->fields['date_issued'];
        $destination = $l->getDestination();

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
                'phone'         => (string) ($f['phonenumber'] ?? ''),
                'logo_data_uri' => $logo,
                'logo_size'     => $logoSize,
            ],
            'laudo' => [
                'number'      => (string) $l->fields['number'],
                'destination' => $destination === null ? '' : Labels::destination($destination),
                'technician'  => getUserName((int) $l->fields['users_id_tech']),
                'date_issued' => ReportFormatter::date($issued),
                // "Cidade/UF, dd/mm/aaaa": town and state come from the laudo's entity (spec L15).
                'place_date'  => PlaceDate::format((string) ($f['town'] ?? ''), (string) ($f['state'] ?? ''), $issued),
            ],
            'directors' => $directors,
            'rows'      => $rows,
            'legend'    => array_values($legend),
        ];
    }

    /** The GLPI "URL da aplicação" (Configurar > Geral), shown in the report footer; empty if unset. */
    private static function applicationUrl(): string
    {
        global $CFG_GLPI;

        return trim((string) ($CFG_GLPI['url_base'] ?? ''));
    }
}
