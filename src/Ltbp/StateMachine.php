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

namespace GlpiPlugin\Gac\Ltbp;

/**
 * Pure transition rules of the LTBP (spec section 6). No GLPI dependency.
 * Draft -> AwaitingSignatures -> Signed -> AtPatrimony -> WrittenOff -> Completed;
 * Canceled from AwaitingSignatures, Signed or AtPatrimony (a draft is purged instead, plan decision 6).
 */
final class StateMachine
{
    public static function canEditDraft(Status $s): bool
    {
        return $s === Status::Draft;
    }

    public static function canIssue(Status $s): bool
    {
        return $s === Status::Draft;
    }

    /** First upload moves to Signed; replacing is allowed until it goes to the patrimony (plan decision 7). */
    public static function canUploadSigned(Status $s): bool
    {
        return $s === Status::AwaitingSignatures || $s === Status::Signed;
    }

    public static function canSendToPatrimony(Status $s): bool
    {
        return $s === Status::Signed;
    }

    public static function canConfirmWriteoff(Status $s): bool
    {
        return $s === Status::AtPatrimony;
    }

    public static function canComplete(Status $s): bool
    {
        return $s === Status::WrittenOff;
    }

    /** Nothing cancels after the write-off (spec L10). */
    public static function canCancel(Status $s): bool
    {
        return $s === Status::AwaitingSignatures || $s === Status::Signed || $s === Status::AtPatrimony;
    }

    public static function canPurge(Status $s): bool
    {
        return $s === Status::Draft || $s === Status::Canceled;
    }
}
