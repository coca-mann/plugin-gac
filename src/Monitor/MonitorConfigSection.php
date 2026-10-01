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

        return "<div class='card border mb-4'><div class='card-header bg-body-tertiary'>"
            . "<h4 class='card-title mb-1'><i class='ti ti-device-tv me-2'></i>" . htmlescape($this->title()) . '</h4>'
            . "</div><div class='card-body'>" . $body . '</div></div>';
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
        MonitorConfig::save($raw);
        Session::addMessageAfterRedirect(__('Configuração do Monitor salva.', 'gac'));
    }
}
