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

use GlpiPlugin\Gac\Sso\IdentityOrigin;
use PHPUnit\Framework\TestCase;

final class IdentityOriginTest extends TestCase
{
    public function testExternalBeforeTheLinkMeansCreatedByGoogle(): void
    {
        self::assertSame(IdentityOrigin::CREATED, IdentityOrigin::of(4));
        self::assertFalse(IdentityOrigin::isConversion(IdentityOrigin::CREATED));
    }

    public function testLdapBeforeTheLinkMeansAnAdUserConverted(): void
    {
        self::assertSame(IdentityOrigin::LDAP, IdentityOrigin::of(3));
        self::assertTrue(IdentityOrigin::isConversion(IdentityOrigin::LDAP));
    }

    public function testAnyOtherMethodIsAConversion(): void
    {
        self::assertSame(IdentityOrigin::CONVERTED, IdentityOrigin::of(2));
        self::assertSame(IdentityOrigin::CONVERTED, IdentityOrigin::of(1));
        self::assertTrue(IdentityOrigin::isConversion(IdentityOrigin::CONVERTED));
    }

    public function testSplitsTheFormattedDateTime(): void
    {
        self::assertSame(['05-10-2026', '22:33'], IdentityOrigin::splitDateTime('05-10-2026 22:33'));
        self::assertSame(['05-10-2026', ''], IdentityOrigin::splitDateTime('05-10-2026'));
        self::assertSame(['', ''], IdentityOrigin::splitDateTime(''));
    }
}
