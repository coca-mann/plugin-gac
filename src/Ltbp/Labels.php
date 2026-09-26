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

final class Labels
{
    public static function status(Status $s): string
    {
        return match ($s) {
            Status::Draft              => __('Rascunho', 'gac'),
            Status::AwaitingSignatures => __('Aguardando assinaturas', 'gac'),
            Status::Signed             => __('Assinado', 'gac'),
            Status::AtPatrimony        => __('No patrimônio', 'gac'),
            Status::WrittenOff         => __('Baixado', 'gac'),
            Status::Completed          => __('Concluído', 'gac'),
            Status::Canceled           => __('Cancelado', 'gac'),
        };
    }

    public static function destination(Destination $d): string
    {
        return match ($d) {
            Destination::Disposal => __('Descarte ecológico', 'gac'),
            Destination::Donation => __('Doação', 'gac'),
        };
    }

    public static function event(string $event): string
    {
        return match ($event) {
            'created'                => __('Laudo criado', 'gac'),
            'line_added'             => __('Ativo adicionado', 'gac'),
            'line_removed'           => __('Ativo removido', 'gac'),
            'issued'                 => __('Laudo emitido para assinatura', 'gac'),
            'signed_uploaded'        => __('PDF assinado anexado', 'gac'),
            'sent_to_patrimony'      => __('Enviado ao patrimônio', 'gac'),
            'written_off'            => __('Baixa confirmada pelo patrimônio', 'gac'),
            'completed'              => __('Destinação concluída', 'gac'),
            'canceled'               => __('Laudo cancelado', 'gac'),
            'document_attached'      => __('Documento anexado', 'gac'),
            'line_document_attached' => __('Documento anexado à linha', 'gac'),
            default                  => $event,
        };
    }
}
