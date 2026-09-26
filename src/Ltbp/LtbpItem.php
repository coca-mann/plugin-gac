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

use CommonDBChild;

/** One asset inside a laudo (spec section 5.2). The items tab is added in the pages task. */
class LtbpItem extends CommonDBChild
{
    public static $itemtype  = Ltbp::class;
    public static $items_id  = 'plugin_gac_ltbps_id';
    public static $rightname = 'plugin_gac_ltbp';
    public $dohistory        = false;

    /** The line has no "name" column; this keeps the native history readable when a line is added or removed. */
    public function getName($options = [])
    {
        if (empty($this->fields['item_name'])) {
            return parent::getName($options);
        }
        return sprintf('%s · %s', $this->fields['item_type_label'] ?? '', $this->fields['item_name']);
    }

    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }
        return 'glpi_plugin_gac_ltbpitems';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Item', 'Itens', $nb, 'gac');
    }
}
