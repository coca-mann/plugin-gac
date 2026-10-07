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

use GlpiPlugin\Gac\ConfigSection;
use GlpiPlugin\Gac\Features;
use Html;
use Session;

final class MonitorConfigSection implements ConfigSection
{
    public function key(): string
    {
        return 'monitor';
    }

    public function canConfigure(): bool
    {
        return Features::canConfigure(MonitorScreen::$rightname);
    }

    public function title(): string
    {
        return __('Painel de Monitoramento de Tickets', 'gac');
    }

    public function render(): string
    {
        $s = MonitorConfig::load();

        $body = $this->row(
            __('Intervalo padrão de atualização (segundos)', 'gac'),
            Html::input('monitor_default_poll_interval_seconds', [
                'type'  => 'number',
                'min'   => 5,
                'value' => MonitorSettings::defaultPollIntervalSeconds($s),
            ])
        );
        $body .= $this->row(
            __('Tempo padrão de cada página no rodízio (segundos)', 'gac'),
            Html::input('monitor_default_rotation_seconds', [
                'type'  => 'number',
                'min'   => 5,
                'value' => MonitorSettings::defaultRotationSeconds($s),
            ])
        );
        $body .= $this->row(
            __('Janela de alerta do SLA (minutos antes de vencer)', 'gac'),
            Html::input('monitor_sla_warning_minutes', [
                'type'  => 'number',
                'min'   => 1,
                'value' => MonitorSettings::slaWarningMinutes($s),
            ])
        );
        $body .= $this->row(
            __('Tempo de exibição do banner de ticket novo (segundos)', 'gac'),
            Html::input('monitor_banner_seconds', [
                'type'  => 'number',
                'min'   => MonitorSettings::MIN_BANNER_SECONDS,
                'max'   => MonitorSettings::MAX_BANNER_SECONDS,
                'value' => MonitorSettings::bannerSeconds($s),
            ])
        );
        $body .= $this->row(
            __('Descrição no banner', 'gac'),
            "<input type='hidden' name='monitor_banner_show_description' value='0'>"
            . "<label class='form-check'><input type='checkbox' class='form-check-input' name='monitor_banner_show_description' value='1'"
            . (MonitorSettings::bannerShowDescription($s) ? ' checked' : '') . '>'
            . "<span class='form-check-label'>" . htmlescape(__('Mostrar as primeiras linhas da descrição do ticket. Desmarque se a TV fica à vista de quem não deveria ler o texto dos chamados.', 'gac')) . '</span></label>'
        );
        $body .= $this->row(
            __('Arquivo de som do alerta (mp3, ogg ou wav, até 512 KB)', 'gac'),
            $this->soundFileControl($s)
        );
        $body .= $this->row(
            __('URL do som de alerta (só vale sem arquivo enviado; sem arquivo e sem URL o alerta fica só visual)', 'gac'),
            Html::input('monitor_alert_sound_url', [
                'type'  => 'url',
                'value' => MonitorSettings::alertSoundUrl($s),
            ])
        );
        $out = $this->block('ti-device-tv', $this->title(), '', $body);

        $serviceBody = "<div class='alert alert-info'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(__('As Telas públicas (sem login, para TV) não têm sessão de usuário. Para poder buscar os tickets mesmo assim, elas se autenticam com esta conta, só pelo tempo da consulta. Cadastre um usuário GLPI dedicado, com direito de leitura de tickets nas entidades que as Telas públicas vão usar — sem outros direitos.', 'gac'))
            . '</div>'
            . $this->row(__('Usuário', 'gac'), Html::input('monitor_service_username', [
                'value' => MonitorSettings::serviceUsername($s),
            ]))
            . $this->row(
                MonitorSettings::servicePassword($s) !== ''
                    ? __('Senha (já configurada; deixe em branco para manter)', 'gac')
                    : __('Senha', 'gac'),
                Html::input('monitor_service_password', ['type' => 'password', 'value' => ''])
            );
        $out .= $this->block(
            'ti-key',
            __('Conta de serviço para Telas públicas', 'gac'),
            '',
            $serviceBody
        );

        $out .= $this->rateLimitBlock($s);

        return $out;
    }

    /**
     * Rate limit of the public endpoints (spec M21), with what GLPI sees as the client address of this
     * very request: behind nginx that is the proxy's address until the proxy is listed as trusted.
     *
     * @param array<string, string> $s
     */
    private function rateLimitBlock(array $s): string
    {
        $remote   = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $resolved = PublicRateLimiter::clientIp($s);
        $trusted  = MonitorSettings::trustedProxies($s);

        $privateRemote = filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        $hint = "<div class='alert alert-info mb-3'><i class='ti ti-info-circle me-1'></i>"
            . htmlescape(sprintf(__('Para esta requisição, o GLPI vê a conexão vindo de %1$s e considera que o cliente é %2$s.', 'gac'), $remote, $resolved))
            . '</div>';
        if ($trusted === '' && $privateRemote) {
            $hint .= "<div class='alert alert-warning mb-3'><i class='ti ti-alert-triangle me-1'></i>"
                . htmlescape(__('Esse endereço é interno. Se for o do seu nginx (e não o do seu computador), todas as TVs estão sendo contadas como um só cliente: informe o endereço do nginx em "Proxies confiáveis" e confira que o nginx envia X-Forwarded-For.', 'gac'))
                . '</div>';
        }

        $body = $hint
            . $this->row(
                __('Limite por endereço de cliente (requisições por minuto, 0 desliga)', 'gac'),
                Html::input('monitor_public_rate_ip', [
                    'type'  => 'number',
                    'min'   => 0,
                    'max'   => MonitorSettings::MAX_RATE,
                    'value' => MonitorSettings::publicRateIp($s),
                ])
            )
            . $this->row(
                __('Limite por link público (requisições por minuto, 0 desliga)', 'gac'),
                Html::input('monitor_public_rate_token', [
                    'type'  => 'number',
                    'min'   => 0,
                    'max'   => MonitorSettings::MAX_RATE,
                    'value' => MonitorSettings::publicRateToken($s),
                ])
            )
            . $this->row(
                __('Proxies confiáveis (endereços ou faixas, separados por vírgula)', 'gac'),
                Html::input('monitor_trusted_proxies', ['value' => $trusted, 'placeholder' => '10.0.0.5, 192.168.0.0/24'])
                . "<div class='form-text'>" . htmlescape(__('Só conexões vindas desses endereços têm o cabeçalho X-Forwarded-For aceito para descobrir o cliente real. Deixe em branco se o GLPI recebe os clientes direto (sem proxy reverso).', 'gac')) . '</div>'
            );

        return $this->block(
            'ti-shield-lock',
            __('Proteção das Telas públicas contra excesso de requisições', 'gac'),
            __('Cada TV consulta a cada poucos segundos: o limite por endereço precisa caber todas as TVs de uma mesma rede.', 'gac'),
            $body
        );
    }

    /**
     * The upload field with the current file (a player to hear it and a way to remove it). A
     * file uploaded here wins over the URL setting (spec M17).
     *
     * @param array<string, string> $s
     */
    private function soundFileControl(array $s): string
    {
        $html = '';
        $path = AlertSound::path($s);
        if ($path !== null) {
            $kb    = max(1, (int) round(filesize($path) / 1024));
            $html .= "<div class='d-flex flex-wrap align-items-center gap-2 mb-2'>"
                . "<i class='ti ti-music'></i><strong>" . htmlescape(MonitorSettings::alertSoundFileName($s)) . '</strong>'
                . "<span class='text-muted small'>(" . $kb . ' KB)</span>'
                . "<audio controls preload='none' src='" . htmlescape(AlertSound::url($s)) . "' style='height:32px'></audio>"
                . '</div>'
                . "<label class='form-check mb-2'><input type='checkbox' class='form-check-input' name='monitor_alert_sound_remove' value='1'>"
                . "<span class='form-check-label'>" . htmlescape(__('Remover o arquivo atual', 'gac')) . '</span></label>';
        }
        $html .= "<input type='file' class='form-control' name='monitor_alert_sound_file' accept='.mp3,.ogg,.wav,audio/*'>"
            . "<div class='form-text'>" . htmlescape(__('Escolher um arquivo novo substitui o atual. O navegador da TV precisa estar liberado para tocar som sem clique (veja o manual).', 'gac')) . '</div>';
        return $html;
    }

    /** A titled, bordered block, matching the other modules' config sections. */
    private function block(string $icon, string $title, string $description, string $content): string
    {
        $descriptionHtml = $description === '' ? '' : "<div class='text-muted small'>" . htmlescape($description) . '</div>';
        return "<div class='card border mb-4'><div class='card-header gac-section-head'><div>"
            . "<h4 class='card-title mb-1'><i class='ti " . htmlescape($icon) . " me-2'></i>" . htmlescape($title) . '</h4>'
            . $descriptionHtml
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
        $raw = MonitorConfig::load();
        $raw['monitor_default_poll_interval_seconds'] = (string) (int) ($post['monitor_default_poll_interval_seconds'] ?? 15);
        $raw['monitor_default_rotation_seconds'] = (string) (int) ($post['monitor_default_rotation_seconds'] ?? 20);
        $raw['monitor_sla_warning_minutes'] = (string) (int) ($post['monitor_sla_warning_minutes'] ?? 60);
        $raw['monitor_banner_seconds'] = (string) (int) ($post['monitor_banner_seconds'] ?? MonitorSettings::DEFAULT_BANNER_SECONDS);
        $raw['monitor_banner_show_description'] = ((string) ($post['monitor_banner_show_description'] ?? '0')) === '1' ? '1' : '0';
        $raw['monitor_alert_sound_url'] = trim((string) ($post['monitor_alert_sound_url'] ?? ''));
        $raw['monitor_service_username'] = trim((string) ($post['monitor_service_username'] ?? ''));
        $raw['monitor_public_rate_ip'] = (string) (int) ($post['monitor_public_rate_ip'] ?? MonitorSettings::DEFAULT_RATE_IP);
        $raw['monitor_public_rate_token'] = (string) (int) ($post['monitor_public_rate_token'] ?? MonitorSettings::DEFAULT_RATE_TOKEN);
        $raw['monitor_trusted_proxies'] = (string) ($post['monitor_trusted_proxies'] ?? '');
        // Blank password on submit means "keep the current one" — the field is never
        // pre-filled with the real secret (see render()), so an empty submit is not a request
        // to clear it, same convention GLPI's own SMTP OAuth secret field uses.
        if (trim((string) ($post['monitor_service_password'] ?? '')) !== '') {
            $raw['monitor_service_password'] = $post['monitor_service_password'];
        }
        if (!empty($post['monitor_alert_sound_remove'])) {
            AlertSound::remove($raw);
        }
        $upload = $_FILES['monitor_alert_sound_file'] ?? null;
        if (is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $problem = AlertSound::store($upload, $raw);
            if ($problem !== null) {
                Session::addMessageAfterRedirect($problem, false, ERROR);
            }
        }
        MonitorConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do Monitor salva.', 'gac'));
    }
}
