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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\EventMessage;
use PHPUnit\Framework\TestCase;

final class EventMessageTest extends TestCase
{
    public function testComposeJoinsTheParts(): void
    {
        self::assertSame('Retorno registrado (#71 · NB-1): Sem defeito', EventMessage::compose('Retorno registrado', '#71 · NB-1', 'Sem defeito'));
    }

    public function testComposeSkipsEmptyParts(): void
    {
        self::assertSame('PRE reaberto', EventMessage::compose('PRE reaberto'));
        self::assertSame('PRE reaberto: nota errada', EventMessage::compose('PRE reaberto', '', 'nota errada'));
        self::assertSame('Retorno registrado (#1)', EventMessage::compose('Retorno registrado', '#1', ''));
    }

    public function testComposeCutsToTheNativeLimit(): void
    {
        $text = EventMessage::compose('PRE reaberto', '', str_repeat('á', 400));

        self::assertSame(255, mb_strlen($text));
        self::assertStringEndsWith('…', $text);
    }

    public function testDiffListsOnlyChangedFields(): void
    {
        $labels = ['cost' => 'Custo', 'supplier_ref' => 'Nº da OS', 'date_return' => 'Data'];
        $before = ['cost' => '100,00', 'supplier_ref' => 'A1', 'date_return' => '2026-09-25'];
        $after  = ['cost' => '150,00', 'supplier_ref' => 'A1', 'date_return' => '2026-09-25'];

        self::assertSame('Custo 100,00 → 150,00', EventMessage::diff($before, $after, $labels));
    }

    public function testDiffShowsADashForEmptyValues(): void
    {
        $labels = ['supplier_ref' => 'Nº da OS'];

        self::assertSame('Nº da OS — → B2', EventMessage::diff(['supplier_ref' => ''], ['supplier_ref' => 'B2'], $labels));
        self::assertSame('Nº da OS B2 → —', EventMessage::diff(['supplier_ref' => 'B2'], [], $labels));
    }

    public function testDiffIsEmptyWhenNothingChanged(): void
    {
        self::assertSame('', EventMessage::diff(['a' => 'x'], ['a' => 'x'], ['a' => 'A']));
    }
}
