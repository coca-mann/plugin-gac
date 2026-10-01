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

use GlpiPlugin\Gac\GacMenu;
use GlpiPlugin\Gac\Monitor\MonitorConfig;
use GlpiPlugin\Gac\Monitor\MonitorScreen;
use GlpiPlugin\Gac\Monitor\MonitorSettings;
use Glpi\Application\View\TemplateRenderer;

if (!MonitorScreen::canView()) {
    Html::displayRightError();
}

$screen = new MonitorScreen();
if (!$screen->getFromDB((int) ($_GET['id'] ?? 0)) || !$screen->fields['is_active']) {
    Html::displayNotFoundError();
}

Html::header(
    $screen->fields['name'],
    $_SERVER['PHP_SELF'],
    GacMenu::SECTOR,
    GacMenu::ITEM_MONITOR
);

global $CFG_GLPI;
$settings = MonitorConfig::load();
$version  = Plugin::getPluginFilesVersion('gac');

TemplateRenderer::getInstance()->display('@gac/monitor/display.html.twig', [
    'screen'          => $screen,
    'ajax_url'        => $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/monitor/data.php?id=' . $screen->getID(),
    'poll_interval'   => $screen->pollIntervalSeconds($settings),
    'alert_enabled'   => (bool) $screen->fields['alert_enabled'],
    // Empty when not configured: public/sounds/ is not guaranteed to have a bundled file (Task
    // 9, Step 3). The JS's play() call already swallows a missing/empty source silently.
    'alert_sound_url' => MonitorSettings::alertSoundUrl($settings),
    'asset_js'        => $CFG_GLPI['root_doc'] . '/plugins/gac/js/monitor.js?v=' . $version,
    'asset_css'       => $CFG_GLPI['root_doc'] . '/plugins/gac/css/monitor.css?v=' . $version,
]);

Html::footer();
