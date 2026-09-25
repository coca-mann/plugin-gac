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

/**
 * Dev-only: renders templates/pre/report.html.twig with sample data into var/sample.pdf,
 * without GLPI. Usage: php tools/render-report-sample.php [rows=12] [draft]
 * Needs the plugin's vendor/ (mPDF) and a Twig autoloader: set GLPI_AUTOLOAD to the GLPI
 * vendor/autoload.php (default: ../../vendor/autoload.php, i.e. the GLPI that hosts the plugin).
 */

$plugin = dirname(__DIR__);
require $plugin . '/vendor/autoload.php';
require getenv('GLPI_AUTOLOAD') ?: $plugin . '/../../vendor/autoload.php';

$twig = new \Twig\Environment(new \Twig\Loader\FilesystemLoader($plugin . '/templates/pre'), ['autoescape' => 'html']);
$twig->addFunction(new \Twig\TwigFunction('__', static fn(string $s): string => $s));

$n = (int) ($argv[1] ?? 12);
$rows = [];
for ($i = 1; $i <= $n; $i++) {
    $rows[] = [
        'tickets_id'  => 1000 + $i,
        'type'        => $i % 2 ? 'Notebook' : 'Nobreak',
        'name'        => 'PAT-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'otherserial' => '00' . (48000 + $i),
        'serial'      => 'SN' . strtoupper(dechex(9000000 + $i * 37)),
        'description' => $i % 3 === 0
            ? 'Equipamento não liga após queda de energia; verificar fonte, bateria e placa principal.'
            : 'Tela quebrada, não liga',
    ];
}

$html = $twig->render('report.html.twig', [
    'company'  => [
        'name' => 'Empresa de Exemplo', 'registration_number' => 'CNPJ 00.000.000/0001-00',
        'address_line' => 'Av. Exemplo, 100 — 00000-000 — Cidade/UF', 'phone' => '(00) 0000-0000',
        'logo_data_uri' => null,
    ],
    'protocol' => [
        'number' => 'PRE-2026-001', 'supplier_name' => 'Assistência Técnica Exemplo Ltda',
        'technician' => 'Técnico de Exemplo', 'date_issued' => '24/09/2026',
    ],
    'rows' => $rows,
]);

@mkdir($plugin . '/var/mpdf', 0777, true);
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8', 'format' => 'A4-L',
    'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 16,
    'default_font' => 'dejavusans', 'tempDir' => $plugin . '/var/mpdf',
]);
$mpdf->SetHTMLFooter('<table width="100%" style="font-size:7.5pt;color:#6b7280;"><tr><td>PRE-2026-001</td><td align="right">Página {PAGENO} de {nb}</td></tr></table>');
if (($argv[2] ?? '') === 'draft') {
    $mpdf->SetWatermarkText('RASCUNHO', 0.08);
    $mpdf->showWatermarkText = true;
}
$mpdf->WriteHTML($html);
file_put_contents($plugin . '/var/sample.pdf', $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN));
echo "var/sample.pdf written\n";
