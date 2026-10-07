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

use GlpiPlugin\Gac\Monitor\ClientIp;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    public function testWithoutTrustedProxiesTheConnectionAddressIsUsedAndTheHeaderIgnored(): void
    {
        $this->assertSame('203.0.113.9', ClientIp::resolve('203.0.113.9', '198.51.100.7', []));
        // A client that talks straight to the server cannot choose its own address through the header.
        $this->assertSame('203.0.113.9', ClientIp::resolve('203.0.113.9', '10.0.0.1, 198.51.100.7', []));
    }

    public function testAnUntrustedPeerCannotSpoofTheHeader(): void
    {
        $this->assertSame('203.0.113.9', ClientIp::resolve('203.0.113.9', '198.51.100.7', ['10.0.0.5']));
    }

    public function testATrustedProxyYieldsTheClientFromTheHeader(): void
    {
        $this->assertSame('198.51.100.7', ClientIp::resolve('10.0.0.5', '198.51.100.7', ['10.0.0.5']));
    }

    public function testTheRightmostUntrustedHopWins(): void
    {
        // The client forged "1.1.1.1"; nginx appended the address it really saw, "198.51.100.7".
        $this->assertSame('198.51.100.7', ClientIp::resolve('10.0.0.5', '1.1.1.1, 198.51.100.7', ['10.0.0.5']));
        // A second trusted proxy in the chain is skipped.
        $this->assertSame('198.51.100.7', ClientIp::resolve('10.0.0.5', '198.51.100.7, 10.0.0.6', ['10.0.0.5', '10.0.0.6']));
    }

    public function testWhenEveryHopIsTrustedTheLeftmostIsUsed(): void
    {
        $this->assertSame('10.0.0.6', ClientIp::resolve('10.0.0.5', '10.0.0.6', ['10.0.0.0/24']));
    }

    public function testAMissingOrEmptyHeaderFallsBackToTheConnectionAddress(): void
    {
        $this->assertSame('10.0.0.5', ClientIp::resolve('10.0.0.5', '', ['10.0.0.5']));
        $this->assertSame('10.0.0.5', ClientIp::resolve('10.0.0.5', '   ', ['10.0.0.5']));
    }

    public function testAGarbageEntryStopsTheWalkAtTheLastKnownHop(): void
    {
        $this->assertSame('10.0.0.5', ClientIp::resolve('10.0.0.5', 'not-an-ip', ['10.0.0.5']));
        $this->assertSame('10.0.0.6', ClientIp::resolve('10.0.0.5', 'garbage, 10.0.0.6', ['10.0.0.0/24']));
    }

    public function testCidrRangesMatch(): void
    {
        $this->assertSame('198.51.100.7', ClientIp::resolve('192.168.1.20', '198.51.100.7', ['192.168.1.0/24']));
        $this->assertSame('192.168.2.20', ClientIp::resolve('192.168.2.20', '198.51.100.7', ['192.168.1.0/24']));
    }

    public function testIpv6AndMappedIpv4(): void
    {
        $this->assertSame('2001:db8::7', ClientIp::resolve('::1', '2001:db8::7', ['::1']));
        $this->assertSame('2001:db8::7', ClientIp::resolve('fd00::5', '2001:db8::7', ['fd00::/8']));
        $this->assertSame('198.51.100.7', ClientIp::resolve('::ffff:10.0.0.5', '198.51.100.7', ['10.0.0.5']));
    }

    public function testParseTrustedKeepsOnlyValidEntries(): void
    {
        $this->assertSame(
            ['10.0.0.5', '192.168.0.0/16', '::1'],
            ClientIp::parseTrusted("10.0.0.5, 192.168.0.0/16;  ::1\nnope 300.1.1.1 10.0.0.0/99")
        );
        $this->assertSame([], ClientIp::parseTrusted(''));
    }
}
