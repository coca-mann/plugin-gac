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

use Dropdown;
use DocumentCategory;
use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use ITILCategory;
use PendingReason;
use Session;
use State;

final class PreConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'pre';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(RepairProtocol::$rightname);
    }

    public function title(): string
    {
        return __('Protocolo de Reparo de Equipamentos', 'gac');
    }

    private static function stateRoleLabels(): array
    {
        return [
            'at_supplier'       => __('Ativo na assistência', 'gac'),
            'defective'         => __('Ativo com defeito', 'gac'),
            'awaiting_writeoff' => __('Ativo aguardando baixa', 'gac'),
        ];
    }

    private static function reasonRoleLabels(): array
    {
        return [
            'at_supplier'       => __('Ticket na assistência', 'gac'),
            'awaiting_writeoff' => __('Aguardando baixa patrimonial', 'gac'),
            'awaiting_decision' => __('Aguardando decisão', 'gac'),
        ];
    }

    public function render(): string
    {
        $s = PreConfig::load();
        $out = '';

        $missing = PreSettings::missingRolesForSend($s);
        foreach (PreSettings::ACTION_KEYS as $key) {
            $missing = array_merge($missing, PreSettings::missingRolesForReturn($s, $key));
        }
        $missing = array_values(array_unique($missing));
        if ($missing !== []) {
            $out .= "<div class='alert alert-warning'>"
                . htmlescape(__('Mapeamentos obrigatórios ainda não configurados. O envio fica bloqueado até preenchê-los:', 'gac'))
                . ' <strong>' . htmlescape(implode(', ', $missing)) . '</strong></div>';
        }

        // 1. Eligible categories
        $body = $this->row(__('Categorias ITIL elegíveis', 'gac'), Dropdown::show(ITILCategory::class, [
            // Multiple dropdowns need the explicit "[]" in the name, and take the selected
            // values through "value" (Dropdown::show turns it into "values" itself).
            'name'     => 'pre_category_ids[]',
            'value'    => PreSettings::categoryIds($s),
            'multiple' => true,
            'display'  => false,
        ]));
        $body .= $this->row(__('Incluir subcategorias', 'gac'), Dropdown::showYesNo(
            'pre_include_subcategories',
            PreSettings::includeSubcategories($s) ? 1 : 0,
            -1,
            ['display' => false]
        ));
        $out .= $this->block(
            'ti-ticket',
            __('Tickets elegíveis', 'gac'),
            __('Define quais tickets aparecem na lista de importação ao montar um PRE: apenas tickets abertos, das categorias abaixo, com ativo vinculado.', 'gac'),
            $body
        );

        // 2. Logo
        $out .= $this->block(
            'ti-file-type-pdf',
            __('Relatório', 'gac'),
            __('Configurações do PDF do protocolo. Aqui se define a origem da logomarca; os demais dados do cabeçalho vêm da entidade.', 'gac'),
            "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('Os dados da empresa no cabeçalho do PDF (nome, CNPJ, endereço, CEP, cidade/UF e telefone) são lidos do cadastro da entidade do protocolo. Para alterá-los, edite os campos da entidade em Administração > Entidades. A logomarca vem de um documento anexado à entidade (ou, na falta, à entidade pai mais próxima que tenha um) que esteja na categoria abaixo.', 'gac'))
            . '</div>'
            . $this->row(__('Categoria de documento da logomarca', 'gac'), DocumentCategory::dropdown([
                'name'    => 'pre_logo_documentcategories_id',
                'value'   => PreSettings::logoCategoryId($s),
                'display' => false,
            ]))
        );

        // 3. State roles
        $body = '';
        foreach (self::stateRoleLabels() as $role => $label) {
            $body .= $this->row($label, Dropdown::show(State::class, [
                'name'    => 'pre_state_' . $role,
                'value'   => PreSettings::stateId($s, $role),
                'display' => false,
            ]));
        }
        $out .= $this->block(
            'ti-device-laptop',
            __('Status do ativo', 'gac'),
            __('Status aplicados ao ativo conforme a etapa do protocolo. Escolha um status existente ou crie um novo com o botão + do campo. Crie-os na entidade raiz, com recursividade ligada, para valerem em todas as entidades. Nenhum status é criado sem a sua ação.', 'gac'),
            $body
        );

        // 4. Pending reason roles
        $body = '';
        foreach (self::reasonRoleLabels() as $role => $label) {
            $body .= $this->row($label, Dropdown::show(PendingReason::class, [
                'name'    => 'pre_reason_' . $role,
                'value'   => PreSettings::reasonId($s, $role),
                'display' => false,
            ]));
        }
        $out .= $this->block(
            'ti-clock-pause',
            __('Motivos de pendência do ticket', 'gac'),
            __('Motivos de pendência aplicados ao ticket enquanto o equipamento está com a assistência ou aguardando uma decisão. Escolha um motivo existente ou crie um novo com o botão + do campo.', 'gac'),
            $body
        );

        // 5. Actions per outcome
        $body = "<div class='table-responsive'><table class='table'><thead><tr><th>"
            . htmlescape(__('Resultado', 'gac')) . '</th><th>' . htmlescape(__('Ticket', 'gac')) . '</th><th>'
            . htmlescape(__('Motivo (se pendente)', 'gac')) . '</th><th>' . htmlescape(__('Ativo', 'gac')) . '</th><th>'
            . htmlescape(__('Status (se definir)', 'gac')) . '</th></tr></thead><tbody>';
        $actionLabels = [
            'repaired'       => __('Reparado', 'gac'),
            'no_fault'       => __('Sem defeito encontrado', 'gac'),
            'writeoff'       => __('Com defeito → baixa', 'gac'),
            'keep_defective' => __('Com defeito → manter', 'gac'),
        ];
        $ticketOptions = [
            'reopen'       => __('Reabrir (Em atendimento)', 'gac'),
            'solve'        => __('Solucionar', 'gac'),
            'keep_pending' => __('Manter pendente', 'gac'),
        ];
        $assetOptions = [
            'restore_previous' => __('Restaurar status anterior', 'gac'),
            'set_state'        => __('Definir status', 'gac'),
        ];
        $actions = PreSettings::actions($s);
        foreach (PreSettings::ACTION_KEYS as $key) {
            $a = $actions[$key];
            $body .= '<tr><td>' . htmlescape($actionLabels[$key]) . '</td>';
            $body .= '<td>' . Dropdown::showFromArray("pre_actions[$key][ticket][type]", $ticketOptions, ['value' => $a['ticket']['type'], 'display' => false]) . '</td>';
            $body .= '<td>' . Dropdown::showFromArray("pre_actions[$key][ticket][reason]", ['' => Dropdown::EMPTY_VALUE] + self::reasonRoleLabels(), ['value' => $a['ticket']['reason'], 'display' => false]) . '</td>';
            $body .= '<td>' . Dropdown::showFromArray("pre_actions[$key][asset][type]", $assetOptions, ['value' => $a['asset']['type'], 'display' => false]) . '</td>';
            $body .= '<td>' . Dropdown::showFromArray("pre_actions[$key][asset][state]", ['' => Dropdown::EMPTY_VALUE] + self::stateRoleLabels(), ['value' => $a['asset']['state'], 'display' => false]) . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
        $out .= $this->block(
            'ti-arrow-back-up',
            __('Ações no retorno', 'gac'),
            __('O que acontece com o ticket e com o ativo quando o retorno de uma linha é registrado, conforme o resultado informado pela assistência.', 'gac'),
            $body
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

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = PreConfig::load();

        $ids = [];
        foreach ((array) ($post['pre_category_ids'] ?? []) as $id) {
            $ids[] = (int) $id;
        }
        $raw['pre_category_ids'] = json_encode($ids);
        $raw['pre_include_subcategories'] = (string) (int) ($post['pre_include_subcategories'] ?? 1);
        $raw['pre_logo_documentcategories_id'] = (string) (int) ($post['pre_logo_documentcategories_id'] ?? 0);

        foreach (PreSettings::STATE_ROLES as $role) {
            $raw['pre_state_' . $role] = (string) (int) ($post['pre_state_' . $role] ?? 0);
        }
        foreach (PreSettings::REASON_ROLES as $role) {
            $raw['pre_reason_' . $role] = (string) (int) ($post['pre_reason_' . $role] ?? 0);
        }

        $raw['pre_actions'] = json_encode($post['pre_actions'] ?? []);

        PreConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do PRE salva.', 'gac'));
    }
}
