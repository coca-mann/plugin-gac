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
 * Everything that changes a GLPI user because of a Google login: finding candidates, creating,
 * converting an AD user (with a snapshot to undo it), applying the rules through GLPI's own
 * User::applyRightRules(), and revoking dynamic authorizations (spec S11 to S15).
 */
final class UserProvisioner
{
    /**
     * Non-deleted users whose e-mail or login equals the address.
     *
     * @return list<int>
     */
    public static function candidateIdsByEmail(string $email): array
    {
        global $DB;

        $ids = [];

        $byMail = $DB->request([
            'SELECT'     => 'glpi_useremails.users_id',
            'FROM'       => 'glpi_useremails',
            'INNER JOIN' => [
                'glpi_users' => ['ON' => ['glpi_useremails' => 'users_id', 'glpi_users' => 'id']],
            ],
            'WHERE'      => ['glpi_useremails.email' => $email, 'glpi_users.is_deleted' => 0],
        ]);
        foreach ($byMail as $row) {
            $ids[(int) $row['users_id']] = true;
        }

        $byName = $DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => ['name' => $email, 'is_deleted' => 0],
        ]);
        foreach ($byName as $row) {
            $ids[(int) $row['id']] = true;
        }

        return array_keys($ids);
    }

    public static function load(int $id): ?\User
    {
        $user = new \User();

        return $user->getFromDB($id) ? $user : null;
    }

    /**
     * Brings a linked user in step with an e-mail that changed in Google (spec S32): the user's
     * e-mail, the identity and, only for a user whose login was the old e-mail, the login too. A
     * login that is not the old e-mail (an AD login) stays, and so does one another user already has.
     *
     * @param array<string, mixed> $identity the user's identity row (id, email_at_link)
     * @return string the text for the login event, empty when nothing changed
     */
    public static function syncEmail(\User $user, array $identity, string $newEmail): string
    {
        global $DB;

        $id    = $user->getID();
        $old   = (string) $identity['email_at_link'];
        $new   = mb_strtolower(trim($newEmail));
        $taken = $new !== '' && countElementsInTable('glpi_users', ['name' => $new, 'id' => ['<>', $id]]) > 0;
        $plan  = EmailSync::plan((string) $user->fields['name'], $old, $new, $taken);
        if (!$plan['changed']) {
            return '';
        }

        $oldRow     = null;
        $newRow     = null;
        $hasDefault = false;
        foreach ($DB->request(['FROM' => 'glpi_useremails', 'WHERE' => ['users_id' => $id]]) as $row) {
            $address    = mb_strtolower(trim((string) $row['email']));
            $hasDefault = $hasDefault || (int) $row['is_default'] === 1;
            if ($address === mb_strtolower(trim($old))) {
                $oldRow ??= $row;
            } elseif ($address === $new) {
                $newRow ??= $row;
            }
        }

        if ($newRow !== null) {
            // The new address is already one of the user's: drop the old one, keeping a default.
            if ($oldRow !== null) {
                if ((int) $oldRow['is_default'] === 1) {
                    $DB->update('glpi_useremails', ['is_default' => 1], ['id' => $newRow['id']]);
                }
                $DB->delete('glpi_useremails', ['id' => $oldRow['id']]);
            }
        } elseif ($oldRow !== null) {
            $DB->update('glpi_useremails', ['email' => $new], ['id' => $oldRow['id']]);
        } else {
            $DB->insert('glpi_useremails', [
                'users_id'   => $id,
                'email'      => $new,
                'is_default' => $hasDefault ? 0 : 1,
                'is_dynamic' => 0,
            ]);
        }

        if ($plan['rename']) {
            $DB->update('glpi_users', ['name' => $new], ['id' => $id]);
        }
        SsoIdentity::updateEmail((int) $identity['id'], $new);

        // The session is opened from this object next, so it must carry the new login.
        $user->getFromDB($id);

        return EmailSync::detail($old, $new, $plan);
    }

    /** @return list<array{entities_id: int, profiles_id: int, is_recursive: int}> */
    private static function dynamicSnapshot(int $usersId): array
    {
        global $DB;

        $rows     = [];
        $iterator = $DB->request([
            'SELECT' => ['entities_id', 'profiles_id', 'is_recursive'],
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => ['users_id' => $usersId, 'is_dynamic' => 1],
        ]);
        foreach ($iterator as $row) {
            $rows[] = [
                'entities_id'  => (int) $row['entities_id'],
                'profiles_id'  => (int) $row['profiles_id'],
                'is_recursive' => (int) $row['is_recursive'],
            ];
        }

        return $rows;
    }

    /**
     * Binds an existing user (typically from the AD) to the Google "sub" and switches its
     * authentication to external. The previous authtype and dynamic authorizations are stored so
     * "Desfazer conversão" can restore them (S11).
     *
     * @return int the new identity id
     */
    public static function convertExisting(\User $user, string $sub, string $email): int
    {
        global $DB;

        $id       = $user->getID();
        $snapshot = self::dynamicSnapshot($id);
        $previous = [(int) $user->fields['authtype'], (int) $user->fields['auths_id']];

        $DB->update('glpi_users', ['authtype' => \Auth::EXTERNAL, 'auths_id' => 0], ['id' => $id]);

        return SsoIdentity::link($id, $sub, $email, $previous[0], $previous[1], $snapshot, (int) $user->fields['entities_id']);
    }

    /**
     * Creates a user for a Google account (S12). The login (name) is the full e-mail.
     *
     * @param array<string, mixed> $claims verified ID token claims
     */
    public static function create(array $claims, ?int $defaultEntityId): ?\User
    {
        $email = mb_strtolower((string) $claims['email']);
        $user  = new \User();

        $id = $user->add([
            'name'        => $email,
            'realname'    => (string) ($claims['family_name'] ?? ''),
            'firstname'   => (string) ($claims['given_name'] ?? ''),
            'authtype'    => \Auth::EXTERNAL,
            'auths_id'    => 0,
            'is_active'   => 1,
            'entities_id' => $defaultEntityId ?? 0,
            '_extauth'    => 1,
            '_useremails' => [$email],
        ]);

        return $id ? self::load((int) $id) : null;
    }

    /**
     * Applies the engine's result with GLPI's own pipeline (the same one the LDAP login uses):
     * it creates, updates and deletes only the dynamic authorizations and leaves the manual ones.
     * The default entity (the _entities_id_default action) is stored on the user.
     *
     * @param array<string, mixed> $ruleOutput RuleRunner::run() output
     */
    public static function applyRules(\User $user, array $ruleOutput): void
    {
        global $DB;

        $user->input = $ruleOutput;
        $user->willProcessRuleRight();
        $user->applyRightRules();

        if (isset($ruleOutput['entities_id']) && is_numeric($ruleOutput['entities_id'])) {
            $DB->update('glpi_users', ['entities_id' => (int) $ruleOutput['entities_id']], ['id' => $user->getID()]);
        }
    }

    /** Removes the user's dynamic authorizations (S15). @return int how many were removed */
    public static function revokeDynamic(int $usersId): int
    {
        $count = countElementsInTable('glpi_profiles_users', ['users_id' => $usersId, 'is_dynamic' => 1]);
        if ($count > 0) {
            (new \Profile_User())->deleteByCriteria(['users_id' => $usersId, 'is_dynamic' => 1], true);
        }

        return $count;
    }

    /**
     * "Desfazer conversão": puts back the previous authtype and the dynamic authorizations stored
     * when the account was linked, and drops the identity.
     */
    public static function undo(int $identityId): bool
    {
        global $DB;

        $identity = SsoIdentity::findById($identityId);
        if ($identity === null) {
            return false;
        }

        $usersId = (int) $identity['users_id'];
        $DB->update('glpi_users', [
            'authtype' => (int) $identity['prev_authtype'],
            'auths_id' => (int) $identity['prev_auths_id'],
            // The rules set the default entity on login; put back the one the user had before.
            'entities_id' => (int) ($identity['prev_entities_id'] ?? 0),
        ], ['id' => $usersId]);

        self::revokeDynamic($usersId);
        $snapshot = json_decode((string) $identity['removed_authorizations'], true);
        foreach (is_array($snapshot) ? $snapshot : [] as $row) {
            (new \Profile_User())->add([
                'users_id'     => $usersId,
                'entities_id'  => (int) $row['entities_id'],
                'profiles_id'  => (int) $row['profiles_id'],
                'is_recursive' => (int) $row['is_recursive'],
                'is_dynamic'   => 1,
            ]);
        }

        SsoIdentity::unlink($identityId);
        SsoEvent::record(Outcome::UNDONE, (string) $identity['email_at_link'], $usersId, (string) $identity['last_ou_path'], 'conversion undone');

        return true;
    }
}
