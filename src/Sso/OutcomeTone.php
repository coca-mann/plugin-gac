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

namespace GlpiPlugin\Gac\Sso;

/** The colour family an outcome code is drawn in, in the event list. */
final class OutcomeTone
{
    public const SUCCESS = 'green';
    public const DENIED  = 'orange';
    public const FAILURE = 'red';
    public const INFO    = 'azure';
    public const OTHER   = 'secondary';

    public static function of(string $outcome): string
    {
        return match ($outcome) {
            Outcome::OK => self::SUCCESS,
            Outcome::DOMAIN_DENIED, Outcome::EMAIL_UNVERIFIED, Outcome::OU_BLOCKED, Outcome::OU_DENIED,
            Outcome::OU_UNMAPPED, Outcome::DOMAIN_MISMATCH, Outcome::EMAIL_AMBIGUOUS, Outcome::PILOT_BLOCKED,
            Outcome::CREATE_DISABLED, Outcome::USER_INACTIVE, Outcome::LOCAL_ACCOUNT => self::DENIED,
            Outcome::STATE_INVALID, Outcome::TOKEN_INVALID, Outcome::API_ERROR => self::FAILURE,
            Outcome::REVOKED, Outcome::UNDONE => self::INFO,
            default => self::OTHER,
        };
    }
}
