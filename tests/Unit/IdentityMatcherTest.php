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

use GlpiPlugin\Gac\Sso\IdentityMatch;
use GlpiPlugin\Gac\Sso\IdentityMatcher;
use GlpiPlugin\Gac\Sso\Outcome;
use PHPUnit\Framework\TestCase;

final class IdentityMatcherTest extends TestCase
{
    public function testALinkedSubWinsEvenIfTheEmailPointsElsewhere(): void
    {
        $match = IdentityMatcher::decide(7, [9], false);

        $this->assertSame(IdentityMatch::USE_LINKED, $match->action);
        $this->assertSame(7, $match->userId);
    }

    public function testASingleEmailCandidateIsLinked(): void
    {
        $match = IdentityMatcher::decide(null, [12], false);

        $this->assertSame(IdentityMatch::LINK_EXISTING, $match->action);
        $this->assertSame(12, $match->userId);
    }

    public function testDuplicateIdsOfTheSameUserCountOnce(): void
    {
        $match = IdentityMatcher::decide(null, [12, 12], false);

        $this->assertSame(IdentityMatch::LINK_EXISTING, $match->action);
    }

    public function testMoreThanOneCandidateIsDeniedNeverGuessed(): void
    {
        $match = IdentityMatcher::decide(null, [12, 13], true);

        $this->assertSame(IdentityMatch::DENY, $match->action);
        $this->assertSame(Outcome::EMAIL_AMBIGUOUS, $match->outcome);
    }

    public function testNoCandidateCreatesWhenAllowed(): void
    {
        $this->assertSame(IdentityMatch::CREATE, IdentityMatcher::decide(null, [], true)->action);
    }

    public function testNoCandidateIsDeniedWhenCreationIsOff(): void
    {
        $match = IdentityMatcher::decide(null, [], false);

        $this->assertSame(IdentityMatch::DENY, $match->action);
        $this->assertSame(Outcome::CREATE_DISABLED, $match->outcome);
    }

    public function testLocalAccountsAreNeverConvertible(): void
    {
        $this->assertFalse(IdentityMatcher::isConvertible(1)); // Auth::DB_GLPI
        $this->assertTrue(IdentityMatcher::isConvertible(3));  // Auth::LDAP
        $this->assertTrue(IdentityMatcher::isConvertible(4));  // Auth::EXTERNAL
        $this->assertTrue(IdentityMatcher::isConvertible(2));  // Auth::MAIL
    }
}
