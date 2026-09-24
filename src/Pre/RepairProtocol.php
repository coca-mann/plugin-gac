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

use CommonDBTM;

class RepairProtocol extends CommonDBTM
{
    public static $rightname = 'plugin_gac_pre';

    public const RIGHT_SEND   = 256;
    public const RIGHT_RETURN = 512;
    public const RIGHT_REOPEN = 1024;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_repairprotocols';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Protocolo de Reparo de Equipamento', 'Protocolos de Reparo de Equipamento', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-tool';
    }

    public function getRights($interface = 'central')
    {
        $values = parent::getRights($interface);
        $values[self::RIGHT_SEND]   = __('Enviar', 'gac');
        $values[self::RIGHT_RETURN] = __('Registrar retorno', 'gac');
        $values[self::RIGHT_REOPEN] = __('Reabrir', 'gac');
        return $values;
    }
}
