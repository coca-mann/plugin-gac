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

use CommonGLPI;
use Html;
use Profile;
use Session;

class ProfileRights extends CommonGLPI
{
    public static function getTypeName($nb = 0)
    {
        return __('Plugin - DTI GAC - Protocolo de Reparo', 'gac');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            return self::createTabEntry(self::getTypeName());
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            self::showForProfile($item);
        }
        return true;
    }

    private static function showForProfile(Profile $profile): void
    {
        $canedit = Session::haveRightsOr(Profile::$rightname, [UPDATE, CREATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . htmlescape($profile->getFormURL()) . "' data-track-changes='true'>";
        }

        $profile->displayRightsChoiceMatrix([
            [
                'itemtype' => RepairProtocol::class,
                'label'    => RepairProtocol::getTypeName(2),
                'field'    => RepairProtocol::$rightname,
            ],
        ], [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => self::getTypeName(),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo "<input type='hidden' name='id' value='" . (int) $profile->fields['id'] . "'>";
            echo Html::submit(_sx('button', 'Save'), [
                'class' => 'btn btn-primary mt-2',
                'name'  => 'update',
                'icon'  => 'fas fa-save',
            ]);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
