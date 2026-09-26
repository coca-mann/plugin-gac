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

enum Status: string
{
    case Draft = 'draft';
    case AwaitingSignatures = 'awaiting_signatures';
    case Signed = 'signed';
    case AtPatrimony = 'at_patrimony';
    case WrittenOff = 'written_off';
    case Completed = 'completed';
    case Canceled = 'canceled';

    /** A laudo holds its assets in every status but Canceled (spec L8, L21). */
    public function holdsAssets(): bool
    {
        return $this !== self::Canceled;
    }

    /** From the write-off on, the assets are locked against edition (spec L14). */
    public function locksAssets(): bool
    {
        return $this === self::WrittenOff || $this === self::Completed;
    }

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Canceled;
    }

    /** @return list<string> */
    public static function holdingValues(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => $s->holdsAssets())
        ));
    }

    /** @return list<string> */
    public static function lockingValues(): array
    {
        return array_values(array_map(
            static fn(self $s): string => $s->value,
            array_filter(self::cases(), static fn(self $s): bool => $s->locksAssets())
        ));
    }
}
