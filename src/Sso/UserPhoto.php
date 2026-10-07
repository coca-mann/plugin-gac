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
 * Copies the Google account photo to the GLPI user, the way GLPI itself stores a user picture (a
 * file under GLPI_PICTURE_DIR, a "_min" thumbnail and the relative path in glpi_users.picture), as
 * its LDAP photo sync does. Spec S33. A failure here never stops a login.
 */
final class UserPhoto
{
    /**
     * @param array<string, mixed> $identity the user's identity row (id, photo_etag, photo_path)
     * @return bool true when a photo was written
     */
    public static function sync(\User $user, array $identity, DirectoryClient $directory, string $email, string $googleEtag): bool
    {
        global $DB;

        $current  = (string) ($user->fields['picture'] ?? '');
        $decision = PhotoPolicy::decide($googleEtag, (string) ($identity['photo_etag'] ?? ''), $current, (string) ($identity['photo_path'] ?? ''));
        if ($decision !== PhotoPolicy::FETCH) {
            return false;
        }

        $bytes = $directory->photo($email);
        if ($bytes === null) {
            return false;
        }

        $extension = PhotoImage::extension($bytes);
        if ($extension === null) {
            \Toolbox::logInFile('gac', 'sso photo: the Directory photo of user ' . $user->getID() . " is not a valid JPEG or PNG\n");

            return false;
        }

        $path = self::write((int) $user->getID(), $bytes, $extension);
        if ($path === null) {
            return false;
        }

        $DB->update('glpi_users', ['picture' => $path], ['id' => $user->getID()]);
        if ($current !== '') {
            \User::dropPictureFiles($current);
        }
        SsoIdentity::setPhoto((int) $identity['id'], $googleEtag, $path);
        $user->fields['picture'] = $path;

        return true;
    }

    /** @return ?string the path relative to GLPI_PICTURE_DIR, null when the file could not be stored */
    private static function write(int $usersId, string $bytes, string $extension): ?string
    {
        $temporary = GLPI_TMP_DIR . '/gac_photo_' . bin2hex(random_bytes(6)) . '.' . $extension;
        if (file_put_contents($temporary, $bytes) === false) {
            return null;
        }

        // savePicture() moves the file into its folder and returns the relative path.
        $relative = \Toolbox::savePicture($temporary, $usersId . '_');
        if ($relative === false) {
            @unlink($temporary);

            return null;
        }

        $thumbnail = GLPI_PICTURE_DIR . '/' . preg_replace('/\.' . $extension . '$/', '_min.' . $extension, $relative);
        \Toolbox::resizePicture(GLPI_PICTURE_DIR . '/' . $relative, $thumbnail);

        return $relative;
    }
}
