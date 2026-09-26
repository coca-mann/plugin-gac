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

use Dropdown;
use DocumentCategory;
use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Session;
use State;

final class LtbpConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'ltbp';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(Ltbp::$rightname);
    }

    public function title(): string
    {
        return __('Laudo Técnico de Baixa Patrimonial', 'gac');
    }

    /** @return array<string, string> */
    private static function stateRoleLabels(): array
    {
        return [
            'awaiting_writeoff' => __('Ativo aguardando baixa', 'gac'),
            'in_process'        => __('Ativo em processo de baixa', 'gac'),
            'written_off'       => __('Ativo baixado', 'gac'),
        ];
    }

    public function render(): string
    {
        $s   = LtbpConfig::load();
        $out = '';

        $missing = LtbpSettings::missingStateRoles($s);
        if ($missing !== []) {
            $labels = self::stateRoleLabels();
            $names  = array_map(static fn(string $role): string => $labels[$role], $missing);
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Mapeamentos obrigatórios ainda não configurados. A emissão do laudo fica bloqueada até preenchê-los:', 'gac'))
                . ' <strong>' . htmlescape(implode(', ', $names)) . '</strong></div>';
        }
        if (LtbpSettings::directorsMissing($s)) {
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Preencha o nome e o cargo dos dois diretores. A emissão do laudo fica bloqueada até lá.', 'gac'))
                . '</div>';
        }

        // 1. State roles
        $body = '';
        foreach (self::stateRoleLabels() as $role => $label) {
            $body .= $this->row($label, Dropdown::show(State::class, [
                'name'    => 'ltbp_state_' . $role,
                'value'   => LtbpSettings::stateId($s, $role),
                'display' => false,
            ]));
        }
        $out .= $this->block(
            'ti-device-laptop',
            __('Status do ativo', 'gac'),
            __('Status aplicados ao ativo em cada etapa do laudo. "Aguardando baixa" costuma ser o mesmo status usado pelo PRE. Escolha um status existente ou crie um novo com o botão + do campo; nenhum é criado sem a sua ação. Crie-os na entidade raiz, com recursividade ligada.', 'gac'),
            $body
        );

        // 2. Directors
        $d    = LtbpSettings::directors($s);
        $body = $this->row(__('Diretor de TI: nome', 'gac'), $this->text('ltbp_director_ti_name', $d['ti']['name']))
            . $this->row(__('Diretor de TI: cargo', 'gac'), $this->text('ltbp_director_ti_role', $d['ti']['role']))
            . $this->row(__('Diretor Administrativo: nome', 'gac'), $this->text('ltbp_director_adm_name', $d['adm']['name']))
            . $this->row(__('Diretor Administrativo: cargo', 'gac'), $this->text('ltbp_director_adm_role', $d['adm']['role']));
        $out .= $this->block(
            'ti-signature',
            __('Assinaturas do laudo', 'gac'),
            __('Nome e cargo impressos nos blocos de assinatura. São copiados para o laudo na emissão: trocar de diretor não altera laudos já emitidos.', 'gac'),
            $body
        );

        // 3. Completion and ticket behaviour
        $reasons = ['' => Dropdown::EMPTY_VALUE] + LtbpReason::choices();
        $body  = $this->row(__('Exigir comprovante para concluir', 'gac'), Dropdown::showYesNo(
            'ltbp_completion_require_document',
            LtbpSettings::requireCompletionDocument($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));
        $body .= $this->row(__('Solucionar o ticket de origem ao concluir', 'gac'), Dropdown::showYesNo(
            'ltbp_solve_ticket_on_completion',
            LtbpSettings::solveTicketOnCompletion($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));
        $body .= $this->row(__('Motivo padrão para "Sem conserto"', 'gac'), Dropdown::showFromArray('ltbp_default_reason_unrepairable', $reasons, [
            'value'   => LtbpSettings::defaultReasonId($s, 'unrepairable') ?: '',
            'display' => false,
        ]));
        $body .= $this->row(__('Motivo padrão para "Orçamento não aprovado"', 'gac'), Dropdown::showFromArray('ltbp_default_reason_quote_rejected', $reasons, [
            'value'   => LtbpSettings::defaultReasonId($s, 'quote_rejected') ?: '',
            'display' => false,
        ]));
        $out .= $this->block(
            'ti-adjustments',
            __('Andamento', 'gac'),
            __('Com o padrão desligado, o ticket de origem só recebe acompanhamentos; solucioná-lo continua sendo uma ação do técnico. Os motivos padrão pré-selecionam o motivo de uma linha que veio do PRE com aquele resultado.', 'gac'),
            $body
        );

        // 4. Report logo
        $out .= $this->block(
            'ti-file-type-pdf',
            __('Relatório', 'gac'),
            __('Os dados da empresa no cabeçalho do PDF (nome, CNPJ, endereço, cidade/UF e telefone) vêm do cadastro da entidade do laudo. A cidade e a UF também formam o local impresso acima das assinaturas.', 'gac'),
            $this->row(__('Categoria de documento da logomarca', 'gac'), DocumentCategory::dropdown([
                'name'    => 'ltbp_logo_documentcategories_id',
                'value'   => LtbpSettings::logoCategoryId($s),
                'display' => false,
            ]))
            . "<div class='text-muted small'>" . htmlescape(__('Costuma ser a mesma categoria configurada no PRE: a logomarca é um documento anexado à entidade.', 'gac')) . '</div>'
        );

        // 5. Reasons catalog
        $out .= $this->block(
            'ti-list-check',
            __('Motivos de baixa', 'gac'),
            __('Cadastro dos motivos que o técnico escolhe em cada ativo do laudo. Um motivo já usado só pode ser inativado.', 'gac'),
            "<a class='btn btn-outline-primary' href='" . htmlescape(LtbpReason::getSearchURL()) . "'>"
            . "<i class='ti ti-list-check me-1'></i>" . htmlescape(__('Gerenciar motivos de baixa', 'gac')) . '</a>'
        );

        return $out;
    }

    /** A titled, bordered block with a short description right below the title. */
    private function block(string $icon, string $title, string $description, string $content): string
    {
        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'><div>"
            . "<h4 class='card-title mb-1'><i class='ti " . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . '</h4>'
            . "<div class='text-muted small'>" . htmlescape($description) . '</div>'
            . "</div></div><div class='card-body'>" . $content . '</div></div>';
    }

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }

    private function text(string $name, string $value): string
    {
        return "<input type='text' class='form-control' maxlength='255' name='" . htmlescape($name)
            . "' value='" . htmlescape($value) . "'>";
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = LtbpConfig::load();

        foreach (LtbpSettings::STATE_ROLES as $role) {
            $raw['ltbp_state_' . $role] = (string) (int) ($post['ltbp_state_' . $role] ?? 0);
        }
        foreach (['ltbp_director_ti_name', 'ltbp_director_ti_role', 'ltbp_director_adm_name', 'ltbp_director_adm_role'] as $key) {
            $raw[$key] = (string) ($post[$key] ?? '');
        }
        $raw['ltbp_completion_require_document'] = (string) (int) ($post['ltbp_completion_require_document'] ?? 1);
        $raw['ltbp_solve_ticket_on_completion']  = (string) (int) ($post['ltbp_solve_ticket_on_completion'] ?? 0);
        $raw['ltbp_default_reason_unrepairable']   = (string) (int) ($post['ltbp_default_reason_unrepairable'] ?? 0);
        $raw['ltbp_default_reason_quote_rejected'] = (string) (int) ($post['ltbp_default_reason_quote_rejected'] ?? 0);
        $raw['ltbp_logo_documentcategories_id']  = (string) (int) ($post['ltbp_logo_documentcategories_id'] ?? 0);

        LtbpConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do LTBP salva.', 'gac'));
    }
}
