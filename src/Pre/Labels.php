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

final class Labels
{
    public static function protocolStatus(ProtocolStatus $s): string
    {
        return match ($s) {
            ProtocolStatus::Draft    => __('Rascunho', 'gac'),
            ProtocolStatus::Sent     => __('Enviado', 'gac'),
            ProtocolStatus::Partial  => __('Retorno parcial', 'gac'),
            ProtocolStatus::Closed   => __('Encerrado', 'gac'),
            ProtocolStatus::Canceled => __('Cancelado', 'gac'),
        };
    }

    public static function itemStatus(ItemStatus $s): string
    {
        return match ($s) {
            ItemStatus::PendingSend => __('Aguardando envio', 'gac'),
            ItemStatus::Sending     => __('Enviando', 'gac'),
            ItemStatus::AtSupplier  => __('Na assistência', 'gac'),
            ItemStatus::Returned    => __('Devolvida', 'gac'),
            ItemStatus::Lost        => __('Extraviada', 'gac'),
        };
    }

    public static function outcome(Outcome $o): string
    {
        return match ($o) {
            Outcome::Repaired      => __('Reparado', 'gac'),
            Outcome::NoFault       => __('Sem defeito encontrado', 'gac'),
            Outcome::Unrepairable  => __('Sem conserto', 'gac'),
            Outcome::QuoteRejected => __('Orçamento não aprovado', 'gac'),
        };
    }

    public static function destination(Destination $d): string
    {
        return match ($d) {
            Destination::Writeoff      => __('Encaminhar para baixa', 'gac'),
            Destination::KeepDefective => __('Manter com defeito', 'gac'),
            Destination::None          => __('Nenhum', 'gac'),
        };
    }

    public static function event(string $event): string
    {
        return match ($event) {
            'created'        => __('PRE criado', 'gac'),
            'sent'           => __('Envio iniciado', 'gac'),
            'send_finalized' => __('PDF de envio gerado', 'gac'),
            'line_returned'  => __('Retorno registrado', 'gac'),
            'line_lost'      => __('Linha marcada como extraviada', 'gac'),
            'line_removed'   => __('Linha removida', 'gac'),
            'line_corrected' => __('Dados de retorno corrigidos', 'gac'),
            'closed'         => __('PRE encerrado', 'gac'),
            'reopened'       => __('PRE reaberto', 'gac'),
            'canceled'       => __('PRE cancelado', 'gac'),
            default          => $event,
        };
    }
}
