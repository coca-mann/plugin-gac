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

declare(strict_types=1);

namespace GlpiPlugin\Gac\Sso;

use Dropdown;
use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Html;
use Session;

final class SsoConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'sso';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(SsoIdentity::$rightname);
    }

    public function title(): string
    {
        return __('Login com Google', 'gac');
    }

    public function render(): string
    {
        global $CFG_GLPI;

        $s = SsoConfig::load();

        $redirectUri = SsoSettings::redirectUri($s, (string) $CFG_GLPI['url_base']);

        $general = $this->row(
            __('Ativar o login com Google', 'gac'),
            Dropdown::showYesNo('sso_enabled', SsoSettings::enabled($s) ? 1 : 0, -1, ['display' => false]),
            __('Desligado, o botão some da tela de login e todos entram só com usuário e senha. Quem já está logado não é afetado.', 'gac')
        )
            . $this->row(
                __('Texto do botão', 'gac'),
                Html::input('sso_button_label', ['value' => SsoSettings::buttonLabel($s)]),
                __('O texto que aparece no botão da tela de login.', 'gac')
            )
            . $this->row(
                __('Esconder o login por usuário e senha', 'gac'),
                Dropdown::showYesNo('sso_hide_local_form', SsoSettings::hideLocalForm($s) ? 1 : 0, -1, ['display' => false]),
                __('Sim: a tela mostra só o botão do Google, e o formulário aparece ao clicar em "Entrar com usuário e senha". Não: os dois aparecem juntos. Mantenha esse acesso disponível para contas locais, como a do administrador.', 'gac')
            );

        $oauth = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('URI de redirecionamento a cadastrar no cliente OAuth do Google:', 'gac'))
            . ' <code>' . htmlescape($redirectUri) . '</code></div>'
            . $this->row(
                __('ID do cliente', 'gac'),
                Html::input('sso_client_id', ['value' => SsoSettings::clientId($s)]),
                __('Copie do Google Cloud, em APIs e serviços > Credenciais, no cliente OAuth do tipo "Aplicativo da Web".', 'gac')
            )
            . $this->row(
                __('Segredo do cliente', 'gac'),
                Html::input('sso_client_secret', ['type' => 'password', 'value' => '']),
                SsoSettings::clientSecret($s) !== ''
                    ? __('Já configurado. Fica guardado cifrado e nunca é exibido de volta; deixe em branco para manter o atual.', 'gac')
                    : __('Fica guardado cifrado e nunca é exibido de volta.', 'gac')
            )
            . $this->row(
                __('Endereço de retorno (opcional)', 'gac'),
                Html::input('sso_redirect_uri', ['value' => (string) $s['sso_redirect_uri']]),
                __('Para onde o Google devolve o usuário depois do login. Em branco, usa o endereço do GLPI. Precisa ser igual ao cadastrado no Google Cloud.', 'gac')
            );

        $service = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('Uma única conta de serviço do Google Cloud serve a todos os workspaces: ela precisa ter a delegação em todo o domínio, com somente o escopo admin.directory.user.readonly, autorizada no Admin Console de cada workspace. O administrador representado em cada um é definido no bloco Workspaces.', 'gac'))
            . '</div>'
            . $this->row(
                __('E-mail da conta de serviço', 'gac'),
                Html::input('sso_sa_client_email', ['value' => SsoSettings::saClientEmail($s)]),
                __('Campo client_email do arquivo JSON (termina em iam.gserviceaccount.com). É essa conta que consulta a OU do usuário no Google.', 'gac')
            )
            . $this->row(
                __('Chave privada', 'gac'),
                $this->textarea('sso_sa_private_key', '', 4),
                SsoSettings::saPrivateKey($s) !== ''
                    ? __('Já configurada. Fica guardada cifrada; deixe em branco para manter a atual.', 'gac')
                    : __('Cole o campo private_key do arquivo JSON, com as linhas BEGIN e END. Fica guardada cifrada.', 'gac')
            );

        $rules = "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>"
            . htmlescape(__('O mapeamento de OU para entidade e perfil é feito em Administração > Regras > Regras de autorização, com o critério "OU do Google Workspace". Use somente a condição "é" nesse critério; a regra vale para a OU e todas as OUs abaixo dela. Regras mais específicas devem ter a ação "Parar o processamento" e ficar acima da regra padrão da unidade.', 'gac'))
            . '</div>'
            . $this->row(
                __('OUs bloqueadas', 'gac'),
                $this->textarea('sso_blocked_ou_paths', (string) $s['sso_blocked_ou_paths'], 4),
                __('Um caminho por linha (ex.: /fimca.com.br/ies-pvh/docentes). Quem está nessa OU, ou abaixo dela, é negado antes de qualquer regra. Use para os docentes.', 'gac')
            )
            . $this->row(
                __('Criar o usuário no primeiro login', 'gac'),
                Dropdown::showYesNo('sso_auto_create', SsoSettings::autoCreate($s) ? 1 : 0, -1, ['display' => false]),
                __('Sim: quem entra e ainda não existe no GLPI é criado. Não: só entra quem já tem usuário.', 'gac')
            )
            . $this->row(
                __('Retirar o acesso de quem for bloqueado', 'gac'),
                Dropdown::showYesNo('sso_revoke_on_deny', SsoSettings::revokeOnDeny($s) ? 1 : 0, -1, ['display' => false]),
                __('Sim: se alguém já vinculado cair numa OU bloqueada ou negada, os perfis que o login do Google deu a essa pessoa são removidos. Perfis dados à mão no GLPI não são afetados.', 'gac')
            )
            . $this->row(
                __('Conferir o domínio no caminho da OU', 'gac'),
                Html::input('sso_domain_segment', ['type' => 'number', 'min' => 0, 'value' => SsoSettings::domainSegment($s)]),
                __('Segurança extra, normalmente desligada (0). Se o domínio é uma das pastas do caminho da OU (ex.: /FIMCA/fimca.com.br/ies-pvh, onde ele é a pasta de posição 2), informe a posição e o login só passa se o domínio do e-mail for igual a essa pasta. Com 0, não confere.', 'gac')
            );

        $pilot = $this->row(
            __('Liberar só para o piloto', 'gac'),
            Dropdown::showYesNo('sso_pilot_only', SsoSettings::pilotOnly($s) ? 1 : 0, -1, ['display' => false]),
            __('Sim: só os e-mails da lista abaixo entram pelo Google; os demais são negados. Use para testar com a TI antes de abrir para todos.', 'gac')
        )
            . $this->row(
                __('E-mails do piloto', 'gac'),
                $this->textarea('sso_pilot_emails', (string) $s['sso_pilot_emails'], 3),
                __('Um por linha. Só vale com o modo piloto ligado.', 'gac')
            )
            . $this->row(
                __('Guardar os eventos por (dias)', 'gac'),
                Html::input('sso_event_retention_days', ['type' => 'number', 'min' => 7, 'value' => SsoSettings::eventRetentionDays($s)]),
                __('Eventos de login mais antigos que isso são apagados sozinhos. Mínimo de 7 dias.', 'gac')
            );

        return $this->tabs([
            ['general', 'ti-brand-google', __('Geral', 'gac'),
                $this->block('ti-brand-google', __('Botão e formulário de login', 'gac'), $general)
                . $this->block('ti-flask', __('Piloto e retenção', 'gac'), $pilot)],
            ['google', 'ti-key', __('Google', 'gac'),
                $this->block('ti-key', __('Cliente OAuth', 'gac'), $oauth)
                . $this->block('ti-server', __('Conta de serviço', 'gac'), $service)],
            ['workspaces', 'ti-building', __('Workspaces', 'gac'), $this->workspacesBlock($s)],
            ['rules', 'ti-route', __('Regras e bloqueios', 'gac'), $rules],
            ['dryrun', 'ti-player-play', __('Teste a seco', 'gac'), $this->dryRunBody()],
        ]);
    }

    /**
     * The sections as Bootstrap tabs inside the one form: every pane is submitted together by the
     * single Save button. The active tab is remembered in the browser; without storage the first
     * one is shown.
     *
     * @param list<array{0: string, 1: string, 2: string, 3: string}> $tabs key, icon, title, content
     */
    private function tabs(array $tabs): string
    {
        $nav  = "<ul class='nav nav-tabs mb-4' role='tablist' id='gac-sso-tabs'>";
        $body = "<div class='tab-content'>";
        foreach ($tabs as $i => [$key, $icon, $title, $content]) {
            $id     = 'gac-sso-pane-' . $key;
            $active = $i === 0;
            $nav   .= "<li class='nav-item' role='presentation'>"
                . "<a class='nav-link" . ($active ? ' active' : '') . "' href='#" . $id . "' data-bs-toggle='tab' role='tab'"
                . " data-gac-tab='" . htmlescape($key) . "' aria-controls='" . $id . "' aria-selected='" . ($active ? 'true' : 'false') . "'>"
                . "<i class='ti " . htmlescape($icon) . " me-1'></i>" . htmlescape($title) . '</a></li>';
            $body  .= "<div class='tab-pane fade" . ($active ? ' show active' : '') . "' id='" . $id . "' role='tabpanel'>" . $content . '</div>';
        }

        $script = <<<'HTML'
<script>
(function () {
    var nav = document.getElementById('gac-sso-tabs');
    if (!nav) { return; }
    var storeKey = 'gac_sso_tab';
    try {
        var saved = window.localStorage.getItem(storeKey);
        var link = saved ? nav.querySelector('[data-gac-tab="' + saved + '"]') : null;
        if (link) { link.click(); }
    } catch (e) {
        // storage unavailable: stay on the first tab
    }
    nav.addEventListener('shown.bs.tab', function (event) {
        try { window.localStorage.setItem(storeKey, event.target.dataset.gacTab); } catch (e) { /* not remembered */ }
    });
})();
</script>
HTML;

        return $nav . '</ul>' . $body . '</div>' . $script;
    }

    /** The list of Google workspaces (spec S24): name, domains, admin to impersonate, active. */
    private function workspacesBlock(array $s): string
    {
        $workspaces   = SsoSettings::workspaces($s)->all();
        $workspaces[] = new Workspace('', [], '', true); // one empty row to add a workspace

        $rows = '';
        foreach ($workspaces as $workspace) {
            $rows .= $this->workspaceRow($workspace);
        }

        $note = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('Cada workspace do Google que o GLPI aceita. Os domínios aceitos no login são os dos workspaces ativos, e a OU do usuário é lida com o administrador do workspace dele. Um domínio só pode estar em um workspace. Para remover um, use "Limpar" e salve. Um workspace sem domínio ou sem administrador fica inutilizável.', 'gac'))
            . '</div>';

        $table = "<div class='table-responsive'><table class='table' id='gac-sso-workspaces'><thead><tr>"
            . $this->th(__('Nome', 'gac'), __('Só para identificar o workspace.', 'gac'))
            . $this->th(__('Domínios', 'gac'), __('Um por linha. Cada domínio só pode estar em um workspace.', 'gac'))
            . $this->th(__('Administrador do Google', 'gac'), __('A conta de serviço age em nome dele para ler a OU dos usuários. Precisa ser super administrador; o acesso é só de leitura.', 'gac'))
            . $this->th(__('Ativo', 'gac')) . '<th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . "<button type='button' class='btn btn-sm btn-outline-secondary' id='gac-sso-add-ws'><i class='ti ti-plus me-1'></i>"
            . htmlescape(__('Adicionar workspace', 'gac')) . '</button>';

        $script = <<<'HTML'
<script>
(function () {
    var table = document.getElementById('gac-sso-workspaces');
    var add = document.getElementById('gac-sso-add-ws');
    if (!table || !add) { return; }
    function clearRow(row) {
        row.querySelectorAll('input, textarea').forEach(function (el) { el.value = ''; });
        row.querySelectorAll('select').forEach(function (el) { el.value = '1'; });
    }
    table.addEventListener('click', function (event) {
        var button = event.target.closest('.gac-ws-clear');
        if (button) { clearRow(button.closest('tr')); }
    });
    add.addEventListener('click', function () {
        var rows = table.querySelectorAll('tbody tr');
        var copy = rows[rows.length - 1].cloneNode(true);
        clearRow(copy);
        table.querySelector('tbody').appendChild(copy);
    });
})();
</script>
HTML;

        return $note . $table . $script;
    }

    private function workspaceRow(Workspace $workspace): string
    {
        $active = "<select class='form-select' name='ws_active[]'>"
            . "<option value='1'" . ($workspace->active ? ' selected' : '') . '>' . htmlescape(__('Sim', 'gac')) . '</option>'
            . "<option value='0'" . ($workspace->active ? '' : ' selected') . '>' . htmlescape(__('Não', 'gac')) . '</option></select>';

        return '<tr>'
            . "<td><input class='form-control' name='ws_name[]' value='" . htmlescape($workspace->name) . "'></td>"
            . '<td>' . $this->textarea('ws_domains[]', implode("\n", $workspace->domains), 3) . '</td>'
            . "<td><input class='form-control' type='email' name='ws_admin[]' value='" . htmlescape($workspace->adminSubject) . "'></td>"
            . '<td>' . $active . '</td>'
            . "<td><button type='button' class='btn btn-sm btn-outline-danger gac-ws-clear'>" . htmlescape(__('Limpar', 'gac')) . '</button></td>'
            . '</tr>';
    }

    private function dryRunBody(): string
    {
        global $CFG_GLPI;

        $url = htmlescape($CFG_GLPI['root_doc'] . '/plugins/gac/ajax/sso/dry_run.php');

        $body = "<p class='text-muted'>" . htmlescape(__('Simula um login para o e-mail informado, sem criar sessão nem alterar nada. Salve a configuração antes de testar.', 'gac')) . '</p>'
            . "<div class='input-group mb-3'><input type='email' class='form-control' id='gac-sso-dry-email' placeholder='nome@dominio.com.br'>"
            . "<button type='button' class='btn btn-outline-primary' id='gac-sso-dry-run'>" . htmlescape(__('Testar', 'gac')) . '</button></div>'
            . "<div id='gac-sso-dry-result'></div>"
            . <<<HTML
<script>
(function () {
    var button = document.getElementById('gac-sso-dry-run');
    var input = document.getElementById('gac-sso-dry-email');
    var out = document.getElementById('gac-sso-dry-result');
    if (!button) { return; }
    // Enter in this field must run the test, not submit the surrounding configuration form.
    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); button.click(); }
    });
    function esc(text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; }
    button.addEventListener('click', function () {
        out.innerHTML = '<span class="text-muted">...</span>';
        fetch('{$url}?email=' + encodeURIComponent(input.value), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.error) { out.innerHTML = '<div class="alert alert-danger">' + esc(d.error) + '</div>'; return; }
                var ok = d.outcome === 'ok';
                var html = '<div class="alert alert-' + (ok ? 'success' : 'danger') + '">' + esc(d.message || (ok ? 'Login permitido.' : d.outcome)) + '</div>';
                if (d.workspace) { html += '<div><strong>Workspace:</strong> ' + esc(d.workspace) + '</div>'; }
                if (d.ou) { html += '<div><strong>OU:</strong> <code>' + esc(d.ou) + '</code></div>'; }
                if (d.grants && d.grants.length) {
                    html += '<table class="table table-sm mt-2"><thead><tr><th>Entidade</th><th>Perfil</th><th>Recursivo</th></tr></thead><tbody>';
                    d.grants.forEach(function (g) {
                        html += '<tr><td>' + esc(g.entity) + '</td><td>' + esc(g.profile) + '</td><td>' + (g.is_recursive ? 'sim' : 'não') + '</td></tr>';
                    });
                    html += '</tbody></table>';
                }
                if (d.default_entity) { html += '<div><strong>Entidade padrão:</strong> ' + esc(d.default_entity) + '</div>'; }
                out.innerHTML = html;
            })
            .catch(function () { out.innerHTML = '<div class="alert alert-danger">Falha ao executar o teste.</div>'; });
    });
})();
</script>
HTML;

        return $body;
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = SsoConfig::load();
        // The workspaces come as parallel arrays, one entry per table row (spec S24).
        $rows  = [];
        $names = is_array($post['ws_name'] ?? null) ? array_values($post['ws_name']) : [];
        foreach ($names as $i => $name) {
            $rows[] = [
                'name'          => (string) $name,
                'domains'       => (string) ($post['ws_domains'][$i] ?? ''),
                'admin_subject' => (string) ($post['ws_admin'][$i] ?? ''),
                'is_active'     => ($post['ws_active'][$i] ?? '1') === '1',
            ];
        }
        $registry   = WorkspaceRegistry::fromRows($rows);
        $duplicated = $registry->duplicatedDomains();
        if ($duplicated !== []) {
            Session::addMessageAfterRedirect(
                sprintf(__('Nada foi salvo: o domínio %s está em mais de um workspace.', 'gac'), implode(', ', $duplicated)),
                false,
                ERROR
            );

            return;
        }
        foreach ($registry->all() as $workspace) {
            if ($workspace->active && !$workspace->isUsable()) {
                Session::addMessageAfterRedirect(
                    sprintf(__('O workspace "%s" está sem domínio ou sem administrador e não será usado até ser completado.', 'gac'), $workspace->name),
                    false,
                    WARNING
                );
            }
        }
        $raw['sso_workspaces'] = $registry->toJson();

        foreach ([
            'sso_enabled', 'sso_button_label', 'sso_hide_local_form', 'sso_client_id',
            'sso_redirect_uri', 'sso_sa_client_email', 'sso_blocked_ou_paths',
            'sso_auto_create', 'sso_revoke_on_deny', 'sso_domain_segment', 'sso_pilot_only',
            'sso_pilot_emails', 'sso_event_retention_days',
        ] as $key) {
            if (array_key_exists($key, $post)) {
                $raw[$key] = is_string($post[$key]) ? $post[$key] : (string) $post[$key];
            }
        }

        // A blank secret on submit means "keep the current one": the fields are never pre-filled.
        foreach (SsoSettings::SECURED_KEYS as $key) {
            if (trim((string) ($post[$key] ?? '')) !== '') {
                $raw[$key] = (string) $post[$key];
            }
        }

        SsoConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do login com Google salva.', 'gac'));
    }

    private function textarea(string $name, string $value, int $rows): string
    {
        return "<textarea class='form-control' name='" . htmlescape($name) . "' rows='" . $rows . "'>" . htmlescape($value) . '</textarea>';
    }

    private function block(string $icon, string $title, string $content): string
    {
        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'>"
            . "<h4 class='card-title mb-0'><i class='ti " . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . '</h4>'
            . "</div><div class='card-body'>" . $content . '</div></div>';
    }

    private function row(string $label, string $control, string $help = ''): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control
            . ($help !== '' ? "<div class='form-text'>" . htmlescape($help) . '</div>' : '')
            . '</div></div>';
    }

    /** A table header with a small explanation under the title. */
    private function th(string $title, string $help = ''): string
    {
        return '<th>' . htmlescape($title)
            . ($help !== '' ? "<div class='text-muted fw-normal text-wrap small' style='text-transform:none;letter-spacing:0'>" . htmlescape($help) . '</div>' : '')
            . '</th>';
    }
}
