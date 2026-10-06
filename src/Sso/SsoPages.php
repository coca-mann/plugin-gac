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

use GlpiPlugin\Gac\Features;
use Html;

/** HTML of the SSO administration lists (identities, events, pending OUs). Spec section 6 and S19. */
final class SsoPages
{
    private static function base(): string
    {
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/gac/front/sso';
    }

    public static function nav(string $active): string
    {
        $items = [
            'identities' => [__('Identidades', 'gac'), 'identities.php', 'ti-users'],
            'events'     => [__('Eventos', 'gac'), 'events.php', 'ti-list-details'],
            'pending'    => [__('OUs pendentes', 'gac'), 'pending.php', 'ti-hourglass'],
        ];

        $html = "<ul class='nav nav-pills mb-3'>";
        foreach ($items as $key => [$label, $page, $icon]) {
            $html .= "<li class='nav-item'><a class='nav-link" . ($key === $active ? ' active' : '') . "' href='"
                . htmlescape(self::base() . '/' . $page) . "'><i class='ti " . $icon . " me-1'></i>" . htmlescape($label) . '</a></li>';
        }

        return $html . '</ul>';
    }

    public static function identities(): string
    {
        global $DB, $CFG_GLPI;

        $canUndo = Features::canConfigure(SsoIdentity::$rightname);
        $rows    = iterator_to_array($DB->request([
            'SELECT'    => [
                'glpi_plugin_gac_ssoidentities.id', 'glpi_plugin_gac_ssoidentities.users_id',
                'glpi_plugin_gac_ssoidentities.email_at_link', 'glpi_plugin_gac_ssoidentities.linked_at',
                'glpi_plugin_gac_ssoidentities.last_login_at', 'glpi_plugin_gac_ssoidentities.last_ou_path',
                'glpi_plugin_gac_ssoidentities.prev_authtype', 'glpi_users.name AS login',
                'glpi_users.is_active', 'glpi_users.is_deleted',
            ],
            'FROM'      => 'glpi_plugin_gac_ssoidentities',
            'LEFT JOIN' => ['glpi_users' => ['ON' => ['glpi_plugin_gac_ssoidentities' => 'users_id', 'glpi_users' => 'id']]],
            'ORDER'     => ['glpi_plugin_gac_ssoidentities.last_login_at DESC'],
            'LIMIT'     => 300,
        ]), false);

        $head = self::cardHead(
            'ti-users',
            __('Identidades Google', 'gac'),
            (string) count($rows),
            __('Usuários do GLPI vinculados a uma conta Google. Desfazer devolve o usuário ao método de login que ele tinha antes.', 'gac')
        );

        if ($rows === []) {
            return "<div class='card'>" . $head . self::emptyState('ti-users', __('Nenhuma identidade vinculada ainda.', 'gac')) . '</div>';
        }

        $html = "<div class='card'>" . $head . "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr>"
            . '<th>' . htmlescape(__('Usuário', 'gac')) . '</th><th>' . htmlescape(__('Origem', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Vinculado em', 'gac')) . '</th><th>' . htmlescape(__('Último login', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Última OU', 'gac')) . '</th>' . ($canUndo ? '<th></th>' : '') . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $login   = (string) $row['login'];
            $email   = (string) $row['email_at_link'];
            $origin  = IdentityOrigin::of((int) $row['prev_authtype']);
            $userUrl = $CFG_GLPI['root_doc'] . '/front/user.form.php?id=' . (int) $row['users_id'];

            $user = '<a class="fw-bold" href="' . htmlescape($userUrl) . '">' . htmlescape($login) . '</a>';
            if (mb_strtolower($email) !== mb_strtolower($login)) {
                $user .= "<div class='small text-muted'>" . htmlescape($email) . '</div>';
            }

            $badge = match ($origin) {
                IdentityOrigin::CREATED => "<span class='badge bg-green-lt'>" . htmlescape(__('Criado pelo Google', 'gac')) . '</span>',
                IdentityOrigin::LDAP    => "<span class='badge bg-azure-lt'>" . htmlescape(__('Convertido do AD', 'gac')) . '</span>',
                default                 => "<span class='badge bg-azure-lt'>" . htmlescape(__('Convertido', 'gac')) . '</span>',
            };
            if (!$row['is_active'] || $row['is_deleted']) {
                $badge .= " <span class='badge bg-red-lt'>" . htmlescape(__('Inativo', 'gac')) . '</span>';
            }

            $html .= '<tr><td>' . $user . '</td><td>' . $badge . '</td>'
                . '<td>' . self::dateCell((string) $row['linked_at']) . '</td>'
                . '<td>' . self::dateCell((string) $row['last_login_at']) . '</td>'
                . '<td>' . ($row['last_ou_path'] === '' || $row['last_ou_path'] === null
                    ? "<span class='text-muted'>-</span>"
                    : "<span class='badge bg-secondary-lt font-monospace'>" . htmlescape((string) $row['last_ou_path']) . '</span>') . '</td>';
            if ($canUndo) {
                $html .= "<td class='text-end'>" . self::undoForm((int) $row['id'], IdentityOrigin::isConversion($origin)) . '</td>';
            }
            $html .= '</tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    /** The date on top and the time, muted, below. */
    private static function dateCell(string $dbValue): string
    {
        [$date, $time] = IdentityOrigin::splitDateTime((string) Html::convDateTime($dbValue));
        if ($date === '') {
            return "<span class='text-muted'>-</span>";
        }

        return "<div class='text-nowrap'>" . htmlescape($date) . ($time !== '' ? "<div class='small text-muted'>" . htmlescape($time) . '</div>' : '') . '</div>';
    }

    private static function undoForm(int $id, bool $conversion): string
    {
        $confirm = htmlescape($conversion
            ? __('Desfazer a conversão? O usuário volta ao método de autenticação anterior e às autorizações dinâmicas que tinha.', 'gac')
            : __('Desfazer o vínculo? O usuário continua existindo no GLPI, mas perde as autorizações dinâmicas e o vínculo com a conta Google; no próximo login pelo Google ele é vinculado de novo.', 'gac'));
        $label = $conversion ? __('Desfazer conversão', 'gac') : __('Desfazer vínculo', 'gac');

        return "<form method='post' action='" . htmlescape(self::base() . '/undo.php') . "' class='d-inline'"
            . " onsubmit=\"return confirm('" . $confirm . "');\">"
            . "<input type='hidden' name='id' value='" . $id . "'>"
            . "<button type='submit' class='btn btn-sm btn-ghost-danger' name='undo' value='1'>"
            . "<i class='ti ti-arrow-back-up me-1'></i>" . htmlescape($label) . '</button>'
            . Html::closeForm(false);
    }

    /** @param array{outcome?: string, email?: string} $filters */
    public static function events(array $filters): string
    {
        global $DB;

        $limit   = 200;
        $where   = [];
        $outcome = (string) ($filters['outcome'] ?? '');
        if ($outcome !== '' && in_array($outcome, Outcome::all(), true)) {
            $where['outcome'] = $outcome;
        }
        $email = trim((string) ($filters['email'] ?? ''));
        if ($email !== '') {
            $where['email'] = ['LIKE', '%' . $email . '%'];
        }

        $options = '<option value="">' . htmlescape(__('Todos os resultados', 'gac')) . '</option>';
        foreach (Outcome::all() as $code) {
            $options .= '<option value="' . htmlescape($code) . '"' . ($code === $outcome ? ' selected' : '') . '>'
                . htmlescape($code) . '</option>';
        }
        $filter = "<div class='card-body border-bottom py-3'><form method='get' class='row g-2 align-items-center'>"
            . "<div class='col-md-3'><select class='form-select' name='outcome'>" . $options . '</select></div>'
            . "<div class='col-md-4'><input class='form-control' name='email' placeholder='" . htmlescape(__('E-mail contém...', 'gac')) . "' value='" . htmlescape($email) . "'></div>"
            . "<div class='col-auto'><button class='btn btn-primary' type='submit'><i class='ti ti-filter me-1'></i>" . htmlescape(__('Filtrar', 'gac')) . '</button></div>'
            . ($where !== []
                ? "<div class='col-auto'><a class='btn btn-ghost-secondary' href='" . htmlescape(self::base() . '/events.php') . "'>" . htmlescape(__('Limpar filtro', 'gac')) . '</a></div>'
                : '')
            . '</form></div>';

        $rows = iterator_to_array($DB->request([
            'FROM'  => SsoEvent::getTable(),
            'WHERE' => $where,
            'ORDER' => ['id DESC'],
            'LIMIT' => $limit,
        ]), false);

        $head = self::cardHead(
            'ti-list-details',
            __('Eventos do login com Google', 'gac'),
            count($rows) >= $limit ? $limit . '+' : (string) count($rows),
            __('Cada tentativa de login pelo Google. O número do evento é o código que o usuário vê na tela de erro. Mostra os 200 mais recentes.', 'gac')
        );

        if ($rows === []) {
            return "<div class='card'>" . $head . $filter . self::emptyState('ti-list-details', __('Nenhum evento.', 'gac')) . '</div>';
        }

        $html = "<div class='card'>" . $head . $filter . "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr>"
            . '<th>#</th><th>' . htmlescape(__('Data', 'gac')) . '</th><th>' . htmlescape(__('E-mail', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('OU', 'gac')) . '</th><th>' . htmlescape(__('Resultado', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Detalhe', 'gac')) . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $code = (string) $row['outcome'];
            $html .= "<tr><td class='text-muted'>" . (int) $row['id'] . '</td>'
                . '<td>' . self::dateCell((string) $row['date']) . '</td>'
                . '<td>' . htmlescape((string) $row['email']) . '</td>'
                . '<td>' . self::ouBadge((string) $row['ou_path']) . '</td>'
                . "<td><span class='badge bg-" . OutcomeTone::of($code) . "-lt'>" . htmlescape($code) . '</span>'
                . "<div class='small text-muted'>" . htmlescape(OutcomeLabels::of($code)) . '</div></td>'
                . "<td class='text-muted'>" . htmlescape((string) $row['detail']) . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    public static function pending(): string
    {
        global $CFG_GLPI;

        $ruleUrl = $CFG_GLPI['root_doc'] . '/front/ruleright.php';
        $pending = SsoEvent::pendingOus();

        $head = self::cardHead(
            'ti-hourglass',
            __('OUs pendentes', 'gac'),
            (string) count($pending),
            __('OUs em que alguém tentou entrar e nenhuma regra de autorização concedeu acesso. Para liberar, crie uma regra com o critério "OU do Google Workspace" igual ao caminho abaixo (condição "é").', 'gac'),
            "<a class='btn btn-sm btn-outline-primary ms-auto' href='" . htmlescape($ruleUrl) . "'><i class='ti ti-external-link me-1'></i>"
                . htmlescape(__('Abrir as regras de autorização', 'gac')) . '</a>'
        );

        if ($pending === []) {
            return "<div class='card'>" . $head . self::emptyState('ti-hourglass', __('Nenhuma OU pendente.', 'gac')) . '</div>';
        }

        $html = "<div class='card'>" . $head . "<div class='table-responsive'><table class='table table-vcenter card-table table-hover'><thead><tr>"
            . '<th>' . htmlescape(__('OU', 'gac')) . '</th><th>' . htmlescape(__('Tentativas', 'gac')) . '</th>'
            . '<th>' . htmlescape(__('Última tentativa', 'gac')) . '</th></tr></thead><tbody>';
        foreach ($pending as $row) {
            $html .= '<tr><td>' . self::ouBadge((string) $row['ou_path']) . '</td>'
                . "<td><span class='badge bg-orange-lt'>" . (int) $row['attempts'] . '</span></td>'
                . '<td>' . self::dateCell((string) $row['last_at']) . '</td></tr>';
        }

        return $html . '</tbody></table></div></div>';
    }

    /** The header of every list card: icon, title, counter and a short explanation, plus an optional action. */
    private static function cardHead(string $icon, string $title, string $count, string $intro, string $action = ''): string
    {
        return "<div class='card-header'><h3 class='card-title'><i class='ti " . htmlescape($icon) . " me-2'></i>"
            . htmlescape($title) . " <span class='badge bg-blue-lt ms-2'>" . htmlescape($count) . '</span></h3>' . $action . '</div>'
            . "<div class='card-body border-bottom py-3 text-muted'>" . htmlescape($intro) . '</div>';
    }

    private static function emptyState(string $icon, string $text): string
    {
        return "<div class='card-body text-center text-muted py-5'><i class='ti " . htmlescape($icon) . " fs-1 d-block mb-2'></i>"
            . htmlescape($text) . '</div>';
    }

    private static function ouBadge(string $ou): string
    {
        return $ou === ''
            ? "<span class='text-muted'>-</span>"
            : "<span class='badge bg-secondary-lt font-monospace'>" . htmlescape($ou) . '</span>';
    }
}
