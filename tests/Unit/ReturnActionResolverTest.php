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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Pre\Destination;
use GlpiPlugin\Gac\Pre\Outcome;
use GlpiPlugin\Gac\Pre\PreSettings;
use GlpiPlugin\Gac\Pre\ReturnActionResolver;
use PHPUnit\Framework\TestCase;

final class ReturnActionResolverTest extends TestCase
{
    public function testActionKeyMapping(): void
    {
        $this->assertSame('repaired', ReturnActionResolver::actionKey(Outcome::Repaired, Destination::None));
        $this->assertSame('no_fault', ReturnActionResolver::actionKey(Outcome::NoFault, Destination::None));
        $this->assertSame('writeoff', ReturnActionResolver::actionKey(Outcome::Unrepairable, Destination::Writeoff));
        $this->assertSame('writeoff', ReturnActionResolver::actionKey(Outcome::QuoteRejected, Destination::Writeoff));
        $this->assertSame('keep_defective', ReturnActionResolver::actionKey(Outcome::Unrepairable, Destination::KeepDefective));
        $this->assertSame('keep_defective', ReturnActionResolver::actionKey(Outcome::QuoteRejected, Destination::KeepDefective));
    }

    public function testResolveRepairedNeedsNoMapping(): void
    {
        $r = ReturnActionResolver::resolve('repaired', PreSettings::normalize([]));
        $this->assertSame(['type' => 'reopen', 'pendingreasons_id' => 0], $r['ticket']);
        $this->assertSame(['type' => 'restore_previous', 'states_id' => 0], $r['asset']);
    }

    public function testResolveWriteoffUsesMappedIds(): void
    {
        $s = PreSettings::normalize(['pre_reason_awaiting_writeoff' => '4', 'pre_state_awaiting_writeoff' => '6']);
        $r = ReturnActionResolver::resolve('writeoff', $s);
        $this->assertSame(['type' => 'keep_pending', 'pendingreasons_id' => 4], $r['ticket']);
        $this->assertSame(['type' => 'set_state', 'states_id' => 6], $r['asset']);
    }

    public function testResolveFailsWhenAMappingIsMissing(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('reason:awaiting_writeoff');
        ReturnActionResolver::resolve('writeoff', PreSettings::normalize([]));
    }

    public function testResolveHonoursACustomisedAction(): void
    {
        $s = PreSettings::normalize([
            'pre_actions' => json_encode([
                'repaired' => [
                    'ticket' => ['type' => 'solve', 'reason' => ''],
                    'asset'  => ['type' => 'set_state', 'state' => 'defective'],
                ],
            ]),
            'pre_state_defective' => '8',
        ]);
        $r = ReturnActionResolver::resolve('repaired', $s);
        $this->assertSame(['type' => 'solve', 'pendingreasons_id' => 0], $r['ticket']);
        $this->assertSame(['type' => 'set_state', 'states_id' => 8], $r['asset']);
    }

    public function testUnknownActionKeyIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReturnActionResolver::resolve('nope', PreSettings::normalize([]));
    }
}
