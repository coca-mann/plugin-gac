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
 * "Teste a seco" (spec 6.2): runs the same checks as a login for an e-mail, minus the Google
 * sign-in, and reports what would happen. Creates no session, user or login event and changes
 * no authorization.
 */
final class DryRun
{
    /**
     * @return array{email: string, workspace: string, ou: string, ancestors: list<string>, outcome: string, message: string,
     *               grants: list<array{entity: string, profile: string, is_recursive: bool}>, default_entity: ?string}
     */
    public static function run(string $email): array
    {
        $email    = mb_strtolower(trim($email));
        $settings = SsoConfig::load();
        $report   = [
            'email' => $email, 'workspace' => '', 'ou' => '', 'ancestors' => [], 'outcome' => Outcome::OK,
            'message' => '', 'grants' => [], 'default_entity' => null,
        ];

        $finish = static function (string $outcome, string $detail = '') use (&$report): array {
            $report['outcome'] = $outcome;
            $report['message'] = OutcomeLabels::of($outcome) . ($detail !== '' ? ' ' . $detail : '');

            return $report;
        };

        if (!SsoSettings::isConfigured($settings)) {
            return $finish(Outcome::API_ERROR, __('O módulo não está configurado.', 'gac'));
        }

        // The "hd" claim is unknown without a real sign-in; assume a Workspace account.
        $denied = LoginDecision::beforeDirectory(
            $email,
            true,
            'dry-run',
            SsoSettings::allowedDomains($settings),
            SsoSettings::pilotOnly($settings),
            SsoSettings::pilotEmails($settings)
        );
        if ($denied !== null) {
            return $finish($denied);
        }

        $workspace = SsoSettings::workspaces($settings)->forEmail($email);
        if ($workspace === null) {
            return $finish(Outcome::DOMAIN_DENIED);
        }
        $report['workspace'] = $workspace->name;

        try {
            $ou = OuPath::normalize((new DirectoryClient($settings, $workspace->adminSubject))->orgUnitPath($email));
        } catch (SsoException $e) {
            return $finish(Outcome::API_ERROR, '(' . $e->getMessage() . ')');
        }
        $report['ou']        = $ou;
        $report['ancestors'] = OuPath::ancestors($ou);

        $denied = LoginDecision::afterDirectory(
            $ou,
            DomainPolicy::emailDomain($email),
            SsoSettings::blockedOus($settings),
            SsoSettings::domainSegment($settings)
        );
        if ($denied !== null) {
            return $finish($denied);
        }

        $rules = RuleRunner::result($email, $report['ancestors']);
        foreach ($rules->grants as $grant) {
            $report['grants'][] = [
                'entity'       => \Dropdown::getDropdownName('glpi_entities', $grant['entities_id']),
                'profile'      => $grant['profiles_id'] === 0
                    ? __('(perfil padrão do GLPI)', 'gac')
                    : \Dropdown::getDropdownName('glpi_profiles', $grant['profiles_id']),
                'is_recursive' => $grant['is_recursive'] === 1,
            ];
        }
        $report['default_entity'] = $rules->defaultEntityId === null
            ? null
            : \Dropdown::getDropdownName('glpi_entities', $rules->defaultEntityId);

        $denied = LoginDecision::afterRules($rules->denied, $rules->hasGrants());

        return $finish($denied ?? Outcome::OK);
    }
}
