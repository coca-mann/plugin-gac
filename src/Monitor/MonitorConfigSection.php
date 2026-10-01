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
            __('URL do som de alerta (opcional; em branco o alerta fica só visual)', 'gac'),
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

        return $out;
    }

    /** A titled, bordered block, matching the other modules' config sections. */
    private function block(string $icon, string $title, string $description, string $content): string
    {
        $descriptionHtml = $description === '' ? '' : "<div class='text-muted small'>" . htmlescape($description) . '</div>';
        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'><div>"
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
        $raw['monitor_alert_sound_url'] = trim((string) ($post['monitor_alert_sound_url'] ?? ''));
        $raw['monitor_service_username'] = trim((string) ($post['monitor_service_username'] ?? ''));
        // Blank password on submit means "keep the current one" — the field is never
        // pre-filled with the real secret (see render()), so an empty submit is not a request
        // to clear it, same convention GLPI's own SMTP OAuth secret field uses.
        if (trim((string) ($post['monitor_service_password'] ?? '')) !== '') {
            $raw['monitor_service_password'] = $post['monitor_service_password'];
        }
        MonitorConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do Monitor salva.', 'gac'));
    }
}
