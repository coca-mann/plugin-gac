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

        $general = $this->row(__('Login com Google habilitado', 'gac'), Dropdown::showYesNo('sso_enabled', SsoSettings::enabled($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Texto do botão', 'gac'), Html::input('sso_button_label', ['value' => SsoSettings::buttonLabel($s)]))
            . $this->row(__('Ocultar o formulário de usuário e senha (acessível com ?local=1)', 'gac'), Dropdown::showYesNo('sso_hide_local_form', SsoSettings::hideLocalForm($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Domínios permitidos (um por linha)', 'gac'), $this->textarea('sso_allowed_domains', (string) $s['sso_allowed_domains'], 3));

        $oauth = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('URI de redirecionamento a cadastrar no cliente OAuth do Google:', 'gac'))
            . ' <code>' . htmlescape($redirectUri) . '</code></div>'
            . $this->row(__('ID do cliente OAuth', 'gac'), Html::input('sso_client_id', ['value' => SsoSettings::clientId($s)]))
            . $this->row(
                SsoSettings::clientSecret($s) !== '' ? __('Segredo do cliente (já configurado; deixe em branco para manter)', 'gac') : __('Segredo do cliente', 'gac'),
                Html::input('sso_client_secret', ['type' => 'password', 'value' => ''])
            )
            . $this->row(__('URI de redirecionamento (opcional; em branco usa a URL base do GLPI)', 'gac'), Html::input('sso_redirect_uri', ['value' => (string) $s['sso_redirect_uri']]));

        $service = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('Conta de serviço do Google Cloud com delegação em todo o domínio e somente o escopo admin.directory.user.readonly. O GLPI representa o administrador abaixo apenas para ler a OU do usuário.', 'gac'))
            . '</div>'
            . $this->row(__('E-mail da conta de serviço', 'gac'), Html::input('sso_sa_client_email', ['value' => SsoSettings::saClientEmail($s)]))
            . $this->row(
                SsoSettings::saPrivateKey($s) !== '' ? __('Chave privada (já configurada; deixe em branco para manter)', 'gac') : __('Chave privada (cole o campo private_key do JSON)', 'gac'),
                $this->textarea('sso_sa_private_key', '', 4)
            )
            . $this->row(__('E-mail do administrador representado', 'gac'), Html::input('sso_sa_admin_subject', ['value' => SsoSettings::saAdminSubject($s)]));

        $rules = "<div class='alert alert-warning'><i class='ti ti-alert-triangle me-1'></i>"
            . htmlescape(__('O mapeamento de OU para entidade e perfil é feito em Administração > Regras > Regras de autorização, com o critério "OU do Google Workspace". Use somente a condição "é" nesse critério; a regra vale para a OU e todas as OUs abaixo dela. Regras mais específicas devem ter a ação "Parar o processamento" e ficar acima da regra padrão da unidade.', 'gac'))
            . '</div>'
            . $this->row(__('OUs sempre bloqueadas (uma por linha; vale para a OU e as abaixo dela)', 'gac'), $this->textarea('sso_blocked_ou_paths', (string) $s['sso_blocked_ou_paths'], 4))
            . $this->row(__('Criar o usuário automaticamente no primeiro login', 'gac'), Dropdown::showYesNo('sso_auto_create', SsoSettings::autoCreate($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Remover autorizações dinâmicas de quem cair em OU bloqueada ou negada', 'gac'), Dropdown::showYesNo('sso_revoke_on_deny', SsoSettings::revokeOnDeny($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('Posição do domínio no caminho da OU (0 desliga a checagem; 2 para /FIMCA/dominio/...)', 'gac'), Html::input('sso_domain_segment', ['type' => 'number', 'min' => 0, 'value' => SsoSettings::domainSegment($s)]));

        $pilot = $this->row(__('Modo piloto: só os e-mails abaixo entram pelo Google', 'gac'), Dropdown::showYesNo('sso_pilot_only', SsoSettings::pilotOnly($s) ? 1 : 0, -1, ['display' => false]))
            . $this->row(__('E-mails do piloto (um por linha)', 'gac'), $this->textarea('sso_pilot_emails', (string) $s['sso_pilot_emails'], 3))
            . $this->row(__('Dias de retenção dos eventos', 'gac'), Html::input('sso_event_retention_days', ['type' => 'number', 'min' => 7, 'value' => SsoSettings::eventRetentionDays($s)]));

        return $this->block('ti-brand-google', __('Geral', 'gac'), $general)
            . $this->block('ti-key', __('Cliente OAuth', 'gac'), $oauth)
            . $this->block('ti-server', __('Conta de serviço', 'gac'), $service)
            . $this->block('ti-route', __('Regras e bloqueios', 'gac'), $rules)
            . $this->block('ti-flask', __('Piloto e retenção', 'gac'), $pilot)
            . $this->dryRunBlock();
    }

    private function dryRunBlock(): string
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

        return $this->block('ti-player-play', __('Teste a seco', 'gac'), $body);
    }

    public function handlePost(array $post): void
    {
        if (!$this->canConfigure()) {
            return;
        }

        $raw = SsoConfig::load();
        foreach ([
            'sso_enabled', 'sso_button_label', 'sso_hide_local_form', 'sso_allowed_domains', 'sso_client_id',
            'sso_redirect_uri', 'sso_sa_client_email', 'sso_sa_admin_subject', 'sso_blocked_ou_paths',
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

    private function row(string $label, string $control): string
    {
        return "<div class='row mb-3'><label class='col-sm-4 col-form-label'>" . htmlescape($label)
            . "</label><div class='col-sm-8'>" . $control . '</div></div>';
    }
}
