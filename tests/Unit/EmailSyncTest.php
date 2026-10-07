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

use GlpiPlugin\Gac\Sso\EmailSync;
use PHPUnit\Framework\TestCase;

final class EmailSyncTest extends TestCase
{
    public function testSameEmailChangesNothing(): void
    {
        $plan = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana@a.com', false);

        $this->assertFalse($plan['changed']);
        $this->assertFalse($plan['rename']);
        $this->assertFalse($plan['keptTaken']);
    }

    public function testSameEmailInAnotherCaseOrWithSpacesChangesNothing(): void
    {
        $this->assertFalse(EmailSync::plan('ana@a.com', 'Ana@A.com', ' ANA@a.com ', false)['changed']);
    }

    public function testAnEmptyNewEmailIsIgnored(): void
    {
        $this->assertFalse(EmailSync::plan('ana@a.com', 'ana@a.com', '', false)['changed']);
        $this->assertFalse(EmailSync::plan('ana@a.com', 'ana@a.com', '   ', false)['changed']);
    }

    public function testAChangedEmailRenamesTheLoginWhenTheLoginWasTheOldEmail(): void
    {
        $plan = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana.silva@a.com', false);

        $this->assertTrue($plan['changed']);
        $this->assertTrue($plan['rename'], 'a user created by Google has the e-mail as login');
        $this->assertFalse($plan['keptTaken']);
    }

    public function testTheLoginComparisonIgnoresCase(): void
    {
        $this->assertTrue(EmailSync::plan('Ana@A.com', 'ana@a.com', 'ana.silva@a.com', false)['rename']);
    }

    public function testAnAdLoginIsNeverRenamed(): void
    {
        $plan = EmailSync::plan('ana.silva', 'ana@a.com', 'ana.nova@a.com', false);

        $this->assertTrue($plan['changed'], 'the e-mail is still updated');
        $this->assertFalse($plan['rename'], 'a login that was not the e-mail is kept');
        $this->assertFalse($plan['keptTaken']);
    }

    public function testALoginAlreadyUsedByAnotherUserIsKept(): void
    {
        $plan = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana.silva@a.com', true);

        $this->assertTrue($plan['changed']);
        $this->assertFalse($plan['rename']);
        $this->assertTrue($plan['keptTaken'], 'the event must say why the login was not renamed');
    }

    public function testAnEmptyStoredEmailCountsAsChanged(): void
    {
        $plan = EmailSync::plan('ana.silva', '', 'ana@a.com', false);

        $this->assertTrue($plan['changed']);
        $this->assertFalse($plan['rename']);
    }

    public function testDetailDescribesWhatHappened(): void
    {
        $renamed = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana.silva@a.com', false);
        $this->assertSame(
            'email changed: ana@a.com -> ana.silva@a.com; login renamed',
            EmailSync::detail('ana@a.com', 'ana.silva@a.com', $renamed)
        );

        $kept = EmailSync::plan('ana.silva', 'ana@a.com', 'ana.nova@a.com', false);
        $this->assertSame(
            'email changed: ana@a.com -> ana.nova@a.com; login kept',
            EmailSync::detail('ana@a.com', 'ana.nova@a.com', $kept)
        );

        $taken = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana.silva@a.com', true);
        $this->assertSame(
            'email changed: ana@a.com -> ana.silva@a.com; login kept, already used by another user',
            EmailSync::detail('ana@a.com', 'ana.silva@a.com', $taken)
        );

        $same = EmailSync::plan('ana@a.com', 'ana@a.com', 'ana@a.com', false);
        $this->assertSame('', EmailSync::detail('ana@a.com', 'ana@a.com', $same));
    }
}
