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

use GlpiPlugin\Gac\Sso\DomainPolicy;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class DomainPolicyTest extends TestCase
{
    public function testParseListSplitsLowercasesAndDeduplicates(): void
    {
        $this->assertSame(
            ['a@x.com', 'b@x.com'],
            DomainPolicy::parseList("A@X.com, b@x.com;\n a@x.com  ")
        );
        $this->assertSame([], DomainPolicy::parseList("  \n "));
    }

    public function testParseDomainsStripsLeadingAt(): void
    {
        $this->assertSame(
            ['fimca.com.br', 'grupoaparicio.com.br'],
            DomainPolicy::parseDomains("@Fimca.com.br\ngrupoaparicio.com.br")
        );
    }

    public function testEmailDomain(): void
    {
        $this->assertSame('fimca.com.br', DomainPolicy::emailDomain('Ana@Fimca.com.br'));
        $this->assertSame('', DomainPolicy::emailDomain('sem-arroba'));
        $this->assertSame('', DomainPolicy::emailDomain('a@b@c.com'));
        $this->assertSame('', DomainPolicy::emailDomain('@x.com'));
        $this->assertSame('', DomainPolicy::emailDomain('x@'));
    }

    public function testCheckPassesForAnAllowedVerifiedWorkspaceAccount(): void
    {
        $this->assertNull(DomainPolicy::check('ana@fimca.com.br', true, 'fimca.com.br', ['fimca.com.br']));
    }

    public function testCheckRejectsUnverifiedEmailFirst(): void
    {
        $this->assertSame(
            Outcome::EMAIL_UNVERIFIED,
            DomainPolicy::check('ana@outro.com', false, '', ['fimca.com.br'])
        );
    }

    public function testCheckRejectsDomainsOutsideTheList(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            DomainPolicy::check('ana@outro.com', true, 'outro.com', ['fimca.com.br'])
        );
    }

    public function testCheckRejectsConsumerAccountsWithoutAHostedDomain(): void
    {
        $this->assertSame(
            Outcome::DOMAIN_DENIED,
            DomainPolicy::check('ana@fimca.com.br', true, '', ['fimca.com.br'])
        );
    }

    public function testCheckAcceptsASecondaryDomainOfTheSameWorkspace(): void
    {
        // The hd claim is the Workspace's domain, which can differ from the e-mail's domain.
        $this->assertNull(DomainPolicy::check('ana@grupoaparicio.com.br', true, 'fimca.com.br', ['fimca.com.br', 'grupoaparicio.com.br']));
    }

    public function testDomainSegmentMatches(): void
    {
        $ou = '/FIMCA/fimca.com.br/IES-PVH';

        $this->assertTrue(DomainPolicy::domainSegmentMatches($ou, 'Fimca.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches($ou, 'grupoaparicio.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches('/FIMCA', 'fimca.com.br', 2));
        $this->assertFalse(DomainPolicy::domainSegmentMatches($ou, 'fimca.com.br', 0));
    }

    public function testOutcomeAllListsEveryConstantOnce(): void
    {
        $all = Outcome::all();

        $this->assertContains(Outcome::OK, $all);
        $this->assertContains(Outcome::LOCAL_ACCOUNT, $all);
        $this->assertSame($all, array_values(array_unique($all)));
    }
}
