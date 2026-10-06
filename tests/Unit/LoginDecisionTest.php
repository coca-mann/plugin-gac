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

use GlpiPlugin\Gac\Sso\LoginDecision;
use GlpiPlugin\Gac\Sso\OuBlocklist;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class LoginDecisionTest extends TestCase
{
    private const ALLOWED = ['fimca.com.br', 'grupoaparicio.com.br'];

    public function testBeforeDirectoryPassesAnAllowedVerifiedAccount(): void
    {
        $this->assertNull(LoginDecision::beforeDirectory('ana@fimca.com.br', true, 'fimca.com.br', self::ALLOWED, false, []));
    }

    public function testBeforeDirectoryChecksVerificationBeforeDomain(): void
    {
        $this->assertSame(
            Outcome::EMAIL_UNVERIFIED,
            LoginDecision::beforeDirectory('ana@outro.com', false, '', self::ALLOWED, false, [])
        );
    }

    public function testBeforeDirectoryDeniesForeignDomains(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            LoginDecision::beforeDirectory('ana@outro.com', true, 'outro.com', self::ALLOWED, false, [])
        );
    }

    public function testPilotModeOnlyLetsListedEmailsIn(): void
    {
        $this->assertSame(
            Outcome::PILOT_BLOCKED,
            LoginDecision::beforeDirectory('ana@fimca.com.br', true, 'fimca.com.br', self::ALLOWED, true, ['ti@fimca.com.br'])
        );
        $this->assertNull(
            LoginDecision::beforeDirectory('TI@Fimca.com.br', true, 'fimca.com.br', self::ALLOWED, true, ['ti@fimca.com.br'])
        );
    }

    public function testPilotModeDoesNotBypassTheDomainCheck(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            LoginDecision::beforeDirectory('ti@outro.com', true, 'outro.com', self::ALLOWED, true, ['ti@outro.com'])
        );
    }

    public function testAfterDirectoryBlocksListedOus(): void
    {
        $blocklist = OuBlocklist::fromText('/fimca/fimca.com.br/ies-pvh/professores');

        $this->assertSame(
            Outcome::OU_BLOCKED,
            LoginDecision::afterDirectory('/FIMCA/fimca.com.br/IES-PVH/Professores/Medicina', 'fimca.com.br', $blocklist, 0)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/FIMCA/fimca.com.br/IES-PVH/Financeiro', 'fimca.com.br', $blocklist, 0)
        );
    }

    public function testAfterDirectoryChecksTheDomainSegmentOnlyWhenEnabled(): void
    {
        $empty = new OuBlocklist([]);

        $this->assertSame(
            Outcome::DOMAIN_MISMATCH,
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $empty, 2)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $empty, 0)
        );
        $this->assertNull(
            LoginDecision::afterDirectory('/fimca/fimca.com.br/x', 'fimca.com.br', $empty, 2)
        );
    }

    public function testTheBlocklistWinsOverTheDomainMismatch(): void
    {
        $blocklist = OuBlocklist::fromText('/fimca');

        $this->assertSame(
            Outcome::OU_BLOCKED,
            LoginDecision::afterDirectory('/fimca/grupoaparicio.com.br/x', 'fimca.com.br', $blocklist, 2)
        );
    }

    public function testAfterRules(): void
    {
        $this->assertSame(Outcome::OU_DENIED, LoginDecision::afterRules(true, true));
        $this->assertSame(Outcome::OU_DENIED, LoginDecision::afterRules(true, false));
        $this->assertSame(Outcome::OU_UNMAPPED, LoginDecision::afterRules(false, false));
        $this->assertNull(LoginDecision::afterRules(false, true));
    }
}
