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

namespace GlpiPlugin\Gac\Monitor;

/** pt-BR labels for ColumnCatalog keys. GLPI-bound (uses __()), kept out of the pure catalog. */
final class MonitorLabels
{
    public static function column(string $key): string
    {
        return match ($key) {
            'id'           => __('ID', 'gac'),
            'title'        => __('Título', 'gac'),
            'entity'       => __('Entidade', 'gac'),
            'status'       => __('Status', 'gac'),
            'priority'     => __('Prioridade', 'gac'),
            'category'     => __('Categoria', 'gac'),
            'requester'    => __('Solicitante', 'gac'),
            'technician'   => __('Técnico', 'gac'),
            'group'        => __('Grupo técnico', 'gac'),
            'opening_date' => __('Abertura', 'gac'),
            'elapsed'      => __('Tempo decorrido', 'gac'),
            default        => $key,
        };
    }

    public static function sortMode(string $mode): string
    {
        return match ($mode) {
            TicketSortOrder::MODE_PRIORITY => __('Urgência, status e data (recomendado)', 'gac'),
            TicketSortOrder::MODE_ID       => __('Padrão do GLPI (ID crescente)', 'gac'),
            default                        => $mode,
        };
    }

    public static function theme(string $theme): string
    {
        return match ($theme) {
            BoardAppearance::THEME_DARK  => __('Escuro', 'gac'),
            BoardAppearance::THEME_LIGHT => __('Claro', 'gac'),
            default                      => $theme,
        };
    }

    public static function fontSize(int $size): string
    {
        return match ($size) {
            1       => __('Pequena', 'gac'),
            2       => __('Normal', 'gac'),
            3       => __('Grande (recomendado)', 'gac'),
            4       => __('Muito grande', 'gac'),
            5       => __('Extra grande', 'gac'),
            default => (string) $size,
        };
    }

    public static function entityLevels(int $levels): string
    {
        return match ($levels) {
            1       => __('Somente a entidade do ticket', 'gac'),
            2       => __('Entidade + 1 nível acima', 'gac'),
            3       => __('Entidade + até 2 níveis acima (recomendado)', 'gac'),
            default => (string) $levels,
        };
    }
}
