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

/**
 * What to do with the GLPI user when the e-mail of a linked Google account changed (spec S32). The
 * login finds the person by the Google "sub", so a new e-mail keeps working, but the GLPI user would
 * keep the old one. Pure.
 */
final class EmailSync
{
    /**
     * @param string $login          the GLPI login (glpi_users.name)
     * @param string $storedEmail    the e-mail stored with the identity (email_at_link)
     * @param string $newEmail       the e-mail in the verified Google token
     * @param bool   $newLoginTaken  another GLPI user already has the new e-mail as login
     * @return array{changed: bool, rename: bool, keptTaken: bool}
     *   changed   - the e-mail differs: update the user's e-mail and the identity
     *   rename    - also rename the login (it was the old e-mail, so it is a Google-created user)
     *   keptTaken - the login would be renamed but another user has that name, so it is kept
     */
    public static function plan(string $login, string $storedEmail, string $newEmail, bool $newLoginTaken): array
    {
        $new = self::norm($newEmail);
        $old = self::norm($storedEmail);

        if ($new === '' || $new === $old) {
            return ['changed' => false, 'rename' => false, 'keptTaken' => false];
        }

        // Only a login that was the old e-mail follows it; an AD login (or any other) stays.
        $loginWasTheEmail = self::norm($login) === $old;

        return [
            'changed'   => true,
            'rename'    => $loginWasTheEmail && !$newLoginTaken,
            'keptTaken' => $loginWasTheEmail && $newLoginTaken,
        ];
    }

    /**
     * The text for the login event; empty when nothing changed.
     *
     * @param array{changed: bool, rename: bool, keptTaken: bool} $plan
     */
    public static function detail(string $oldEmail, string $newEmail, array $plan): string
    {
        if (!$plan['changed']) {
            return '';
        }

        $text = 'email changed: ' . self::norm($oldEmail) . ' -> ' . self::norm($newEmail);

        if ($plan['rename']) {
            return $text . '; login renamed';
        }

        return $text . ($plan['keptTaken'] ? '; login kept, already used by another user' : '; login kept');
    }

    private static function norm(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
