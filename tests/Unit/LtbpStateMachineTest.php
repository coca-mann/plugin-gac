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

use GlpiPlugin\Gac\Ltbp\StateMachine;
use GlpiPlugin\Gac\Ltbp\Status;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LtbpStateMachineTest extends TestCase
{
    /** @return array<string, array{string, list<Status>}> method => statuses where it is true */
    public static function rules(): array
    {
        return [
            'canEditDraft'       => ['canEditDraft', [Status::Draft]],
            'canIssue'           => ['canIssue', [Status::Draft]],
            'canUploadSigned'    => ['canUploadSigned', [Status::AwaitingSignatures, Status::Signed]],
            'canSendToPatrimony' => ['canSendToPatrimony', [Status::Signed]],
            'canConfirmWriteoff' => ['canConfirmWriteoff', [Status::AtPatrimony]],
            'canComplete'        => ['canComplete', [Status::WrittenOff]],
            'canCancel'          => ['canCancel', [Status::AwaitingSignatures, Status::Signed, Status::AtPatrimony]],
            'canPurge'           => ['canPurge', [Status::Draft, Status::Canceled]],
        ];
    }

    /** @param list<Status> $allowed */
    #[DataProvider('rules')]
    public function testRuleIsTrueOnlyInItsStatuses(string $method, array $allowed): void
    {
        foreach (Status::cases() as $status) {
            $this->assertSame(
                in_array($status, $allowed, true),
                StateMachine::$method($status),
                sprintf('%s(%s)', $method, $status->value)
            );
        }
    }

    public function testNothingCancelsAfterTheWriteoff(): void
    {
        $this->assertFalse(StateMachine::canCancel(Status::WrittenOff));
        $this->assertFalse(StateMachine::canCancel(Status::Completed));
    }
}
