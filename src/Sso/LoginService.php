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

use GlpiPlugin\Gac\Shared\ServiceResult;

/**
 * Orchestrates the return from Google (spec section 6.1, steps 2 to 8). Every failure is recorded
 * in glpi_plugin_gac_ssoevents; the id of that event is the code the user is shown (S20).
 */
final class LoginService
{
    /**
     * @param array<string, mixed>  $query the callback's GET parameters
     * @param ?array<string, mixed> $state what start.php stored in $_SESSION['gac_sso']
     */
    public static function handleCallback(array $query, ?array $state): ServiceResult
    {
        global $CFG_GLPI;

        $settings = SsoConfig::load();
        $email    = '';
        $ou       = '';
        $usersId  = null;

        $fail = static function (string $outcome, string $detail = '') use (&$email, &$ou, &$usersId): ServiceResult {
            $eventId = SsoEvent::record($outcome, $email, $usersId, $ou, $detail);

            return ServiceResult::fail($outcome, ['event_id' => $eventId]);
        };

        try {
            if (!SsoSettings::isConfigured($settings)) {
                return $fail(Outcome::API_ERROR, 'module not configured');
            }

            // The state is single use and short lived.
            if (
                $state === null
                || time() - (int) ($state['t'] ?? 0) > 600
                || !isset($query['state'])
                || !hash_equals((string) ($state['state'] ?? ''), (string) $query['state'])
            ) {
                return $fail(Outcome::STATE_INVALID);
            }
            if (isset($query['error'])) {
                return $fail(Outcome::TOKEN_INVALID, 'provider error: ' . substr((string) $query['error'], 0, 80));
            }
            if (!isset($query['code']) || !is_string($query['code']) || $query['code'] === '') {
                return $fail(Outcome::TOKEN_INVALID, 'missing code');
            }

            // 2. Token and claims.
            try {
                $google = new GoogleClient($settings, SsoSettings::redirectUri($settings, (string) $CFG_GLPI['url_base']));
                $claims = $google->claimsFromCode($query['code'], (string) $state['pkce'], (string) $state['nonce']);
            } catch (SsoException $e) {
                return $fail(Outcome::TOKEN_INVALID, $e->getMessage());
            }
            $email = mb_strtolower(trim((string) $claims['email']));
            $sub   = (string) $claims['sub'];

            // 3. Domain, verified e-mail, pilot mode.
            $denied = LoginDecision::beforeDirectory(
                $email,
                IdToken::emailVerified($claims),
                (string) ($claims['hd'] ?? ''),
                SsoSettings::allowedDomains($settings),
                SsoSettings::pilotOnly($settings),
                SsoSettings::pilotEmails($settings)
            );
            if ($denied !== null) {
                return $fail($denied);
            }

            // 4. The user's OU, then the hard block and the optional domain x OU check.
            try {
                $ou = OuPath::normalize((new DirectoryClient($settings))->orgUnitPath($email));
            } catch (SsoException $e) {
                return $fail(Outcome::API_ERROR, $e->getMessage());
            }

            $identity = SsoIdentity::findBySub($sub);
            $linkedId = $identity === null ? null : (int) $identity['users_id'];

            $denied = LoginDecision::afterDirectory(
                $ou,
                DomainPolicy::emailDomain($email),
                SsoSettings::blockedOus($settings),
                SsoSettings::domainSegment($settings)
            );
            if ($denied !== null) {
                self::revokeIfLinked($linkedId, $settings, $email, $ou);

                return $fail($denied);
            }

            // 5. The authorization rules.
            $ruleOutput = RuleRunner::run($email, OuPath::ancestors($ou));
            $rules      = RuleResult::fromOutput($ruleOutput);
            $denied     = LoginDecision::afterRules($rules->denied, $rules->hasGrants());
            if ($denied !== null) {
                self::revokeIfLinked($linkedId, $settings, $email, $ou);

                return $fail($denied);
            }

            // 6. Which GLPI user is this?
            $match = IdentityMatcher::decide(
                $linkedId,
                UserProvisioner::candidateIdsByEmail($email),
                SsoSettings::autoCreate($settings)
            );
            if ($match->action === IdentityMatch::DENY) {
                return $fail((string) $match->outcome);
            }

            // 7. Provision.
            $detail = 'login';
            if ($match->action === IdentityMatch::CREATE) {
                $user = UserProvisioner::create($claims, $rules->defaultEntityId);
                if ($user === null) {
                    return $fail(Outcome::API_ERROR, 'user creation failed');
                }
                SsoIdentity::link($user->getID(), $sub, $email, \Auth::EXTERNAL, 0, []);
                $detail = 'created';
            } else {
                $user = UserProvisioner::load((int) $match->userId);
                if ($user === null || !$user->fields['is_active'] || $user->fields['is_deleted']) {
                    $usersId = $match->userId;

                    return $fail(Outcome::USER_INACTIVE);
                }
                if ($match->action === IdentityMatch::LINK_EXISTING) {
                    if (!IdentityMatcher::isConvertible((int) $user->fields['authtype'])) {
                        $usersId = $user->getID();

                        return $fail(Outcome::LOCAL_ACCOUNT);
                    }
                    UserProvisioner::convertExisting($user, $sub, $email);
                    $detail = 'linked';
                }
            }
            $usersId = $user->getID();

            UserProvisioner::applyRules($user, $ruleOutput);
            SsoIdentity::touch($sub, $ou);

            // 8. Session.
            if (!SessionStarter::start($user)) {
                return $fail(Outcome::OU_UNMAPPED, 'no usable authorization after applying the rules');
            }

            SsoEvent::record(Outcome::OK, $email, $usersId, $ou, $detail);

            return ServiceResult::ok('', [
                'redirect' => (string) ($state['redirect'] ?? ''),
                'user_id'  => $usersId,
            ]);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('gac', 'sso callback: ' . $e::class . ': ' . $e->getMessage() . "\n");

            return $fail(Outcome::API_ERROR, 'unexpected ' . $e::class);
        }
    }

    /**
     * S15: a user already bound to Google who now falls under a block or a deny loses the dynamic
     * authorizations.
     *
     * @param array<string, string> $settings
     */
    private static function revokeIfLinked(?int $linkedId, array $settings, string $email, string $ou): void
    {
        if ($linkedId === null || !SsoSettings::revokeOnDeny($settings)) {
            return;
        }

        $removed = UserProvisioner::revokeDynamic($linkedId);
        if ($removed > 0) {
            SsoEvent::record(Outcome::REVOKED, $email, $linkedId, $ou, $removed . ' dynamic authorization(s) removed');
        }
    }
}
