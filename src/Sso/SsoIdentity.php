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

use CommonDBTM;
use GlpiPlugin\Gac\Features;

/**
 * Link between a GLPI user and a Google "sub" (spec S10, S11). It is also the item the
 * "Login com Google" profile right (plugin_gac_sso) hangs on; lists are drawn by SsoPages.
 */
class SsoIdentity extends CommonDBTM
{
    public static $rightname = 'plugin_gac_sso';

    public const RIGHT_CONFIG = Features::RIGHT_CONFIG;

    public $dohistory = false;

    /** Explicit: the class sits in a sub-namespace, GLPI's derived name would be wrong. */
    public static function getTable($classname = null)
    {
        if ($classname !== null && $classname !== static::class) {
            return parent::getTable($classname);
        }

        return 'glpi_plugin_gac_ssoidentities';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Identidade Google', 'Identidades Google', $nb, 'gac');
    }

    public static function getIcon()
    {
        return 'ti ti-brand-google';
    }

    /** Read-only data: the only rights that mean anything are "Ler" and "Configurar". */
    public function getRights($interface = 'central')
    {
        return [
            READ               => __('Read'),
            self::RIGHT_CONFIG => __('Configurar', 'gac'),
        ];
    }

    /** @return ?array<string, mixed> */
    public static function findBySub(string $sub): ?array
    {
        return self::findOne(['google_sub' => $sub]);
    }

    /** @return ?array<string, mixed> */
    public static function findByUserId(int $usersId): ?array
    {
        return self::findOne(['users_id' => $usersId]);
    }

    /** @return ?array<string, mixed> */
    public static function findById(int $id): ?array
    {
        return self::findOne(['id' => $id]);
    }

    /**
     * @param array<string, scalar> $where
     * @return ?array<string, mixed>
     */
    private static function findOne(array $where): ?array
    {
        global $DB;

        $rows = $DB->request(['FROM' => self::getTable(), 'WHERE' => $where, 'LIMIT' => 1]);
        foreach ($rows as $row) {
            return $row;
        }

        return null;
    }

    /**
     * @param list<array{entities_id: int, profiles_id: int, is_recursive: int}> $removed dynamic authorizations the user had before
     * @param int $prevEntitiesId the user's default entity before the conversion, restored by "Desfazer conversão"
     * @return int the new identity id
     */
    public static function link(int $usersId, string $sub, string $email, int $prevAuthtype, int $prevAuthsId, array $removed, int $prevEntitiesId = 0): int
    {
        global $DB;

        $DB->insert(self::getTable(), [
            'users_id'               => $usersId,
            'google_sub'             => $sub,
            'email_at_link'          => mb_substr($email, 0, 255),
            'prev_authtype'          => $prevAuthtype,
            'prev_auths_id'          => $prevAuthsId,
            'prev_entities_id'       => $prevEntitiesId,
            'removed_authorizations' => json_encode($removed, JSON_THROW_ON_ERROR),
            'linked_at'              => date('Y-m-d H:i:s'),
            'last_login_at'          => date('Y-m-d H:i:s'),
        ]);

        return (int) $DB->insertId();
    }

    /** The etag and the file of the last Google photo copied to the user (spec S33). */
    public static function setPhoto(int $identityId, string $etag, string $path): void
    {
        global $DB;

        $DB->update(self::getTable(), ['photo_etag' => mb_substr($etag, 0, 255), 'photo_path' => mb_substr($path, 0, 255)], ['id' => $identityId]);
    }

    /** The e-mail the identity remembers, kept in step with the Google account (spec S32). */
    public static function updateEmail(int $identityId, string $email): void
    {
        global $DB;

        $DB->update(self::getTable(), ['email_at_link' => mb_substr($email, 0, 255)], ['id' => $identityId]);
    }

    public static function touch(string $sub, string $ouPath): void
    {
        global $DB;

        $DB->update(
            self::getTable(),
            ['last_login_at' => date('Y-m-d H:i:s'), 'last_ou_path' => mb_substr($ouPath, 0, 500)],
            ['google_sub' => $sub]
        );
    }

    public static function unlink(int $id): void
    {
        global $DB;

        $DB->delete(self::getTable(), ['id' => $id]);
    }

    /**
     * Hook item_purge on User: a user purged from GLPI (not just sent to the trash) leaves the
     * identities table, so the person is a new user at the next Google login.
     */
    public static function onUserPurged(CommonDBTM $item): void
    {
        self::forgetUser((int) $item->getID(), 'user purged in GLPI');
    }

    /**
     * Removes the identities of a user that no longer exists and leaves an "undone" event for each
     * one, so the audit trail explains why the row disappeared.
     *
     * @return int how many identities were removed
     */
    public static function forgetUser(int $usersId, string $reason): int
    {
        global $DB;

        if ($usersId <= 0) {
            return 0;
        }

        $removed = 0;
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['users_id' => $usersId]]) as $row) {
            self::unlink((int) $row['id']);
            SsoEvent::record(Outcome::UNDONE, (string) $row['email_at_link'], $usersId, (string) $row['last_ou_path'], $reason);
            ++$removed;
        }

        return $removed;
    }

    /**
     * The GLPI user an identity points to, or null when there is no identity or its user no longer
     * exists (purged by a path that did not run the hook, or before the hook existed). A stale
     * identity is removed on the spot, so the login goes on as for an unlinked person. A user in the
     * trash still exists, so the login keeps denying it as inactive.
     *
     * @param ?array<string, mixed> $identity a row from findBySub()
     */
    public static function linkedUserId(?array $identity): ?int
    {
        if ($identity === null) {
            return null;
        }

        $usersId = (int) $identity['users_id'];
        if (countElementsInTable('glpi_users', ['id' => $usersId]) > 0) {
            return $usersId;
        }

        self::forgetUser($usersId, 'stale identity: the user no longer exists');

        return null;
    }

    /**
     * Removes every identity whose user does not exist anymore (cleanup of rows left behind before
     * the purge hook existed).
     *
     * @return int how many identities were removed
     */
    public static function purgeOrphans(): int
    {
        global $DB;

        $removed = 0;
        foreach ($DB->request([
            'SELECT'    => ['glpi_plugin_gac_ssoidentities.users_id'],
            'DISTINCT'  => true,
            'FROM'      => self::getTable(),
            'LEFT JOIN' => ['glpi_users' => ['ON' => [self::getTable() => 'users_id', 'glpi_users' => 'id']]],
            'WHERE'     => ['glpi_users.id' => null],
        ]) as $row) {
            $removed += self::forgetUser((int) $row['users_id'], 'orphan identity removed: the user no longer exists');
        }

        return $removed;
    }
}
