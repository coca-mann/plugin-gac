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

/** Outcome codes recorded in glpi_plugin_gac_ssoevents (spec section 5.2). */
final class Outcome
{
    public const OK               = 'ok';
    public const DOMAIN_DENIED    = 'domain_denied';
    public const EMAIL_UNVERIFIED = 'email_unverified';
    public const OU_BLOCKED       = 'ou_blocked';
    public const OU_DENIED        = 'ou_denied';
    public const OU_UNMAPPED      = 'ou_unmapped';
    public const DOMAIN_MISMATCH  = 'domain_mismatch';
    public const EMAIL_AMBIGUOUS  = 'email_ambiguous';
    public const PILOT_BLOCKED    = 'pilot_blocked';
    public const CREATE_DISABLED  = 'create_disabled';
    public const USER_INACTIVE    = 'user_inactive';
    public const LOCAL_ACCOUNT    = 'local_account';
    public const STATE_INVALID    = 'state_invalid';
    public const TOKEN_INVALID    = 'token_invalid';
    public const API_ERROR        = 'api_error';
    public const REVOKED          = 'revoked';
    public const UNDONE           = 'undone';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::OK, self::DOMAIN_DENIED, self::EMAIL_UNVERIFIED, self::OU_BLOCKED, self::OU_DENIED,
            self::OU_UNMAPPED, self::DOMAIN_MISMATCH, self::EMAIL_AMBIGUOUS, self::PILOT_BLOCKED,
            self::CREATE_DISABLED, self::USER_INACTIVE, self::LOCAL_ACCOUNT, self::STATE_INVALID,
            self::TOKEN_INVALID, self::API_ERROR, self::REVOKED, self::UNDONE,
        ];
    }
}
