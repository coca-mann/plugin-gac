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

/** Portuguese explanations of the outcome codes, for the dry run and the event list. */
final class OutcomeLabels
{
    public static function of(string $outcome): string
    {
        return match ($outcome) {
            Outcome::OK               => __('Login permitido.', 'gac'),
            Outcome::DOMAIN_DENIED    => __('O domínio do e-mail não está na lista de domínios permitidos (ou a conta não é do Workspace).', 'gac'),
            Outcome::EMAIL_UNVERIFIED => __('O Google não confirmou o e-mail.', 'gac'),
            Outcome::OU_BLOCKED       => __('A OU está na lista de OUs sempre bloqueadas.', 'gac'),
            Outcome::OU_DENIED        => __('Uma regra de autorização nega o login para esta OU.', 'gac'),
            Outcome::OU_UNMAPPED      => __('Nenhuma regra de autorização concede acesso a esta OU.', 'gac'),
            Outcome::DOMAIN_MISMATCH  => __('O domínio do e-mail não corresponde ao domínio no caminho da OU.', 'gac'),
            Outcome::EMAIL_AMBIGUOUS  => __('Mais de um usuário do GLPI tem este e-mail.', 'gac'),
            Outcome::PILOT_BLOCKED    => __('Modo piloto: o e-mail não está na lista do piloto.', 'gac'),
            Outcome::CREATE_DISABLED  => __('O usuário não existe e a criação automática está desligada.', 'gac'),
            Outcome::USER_INACTIVE    => __('O usuário do GLPI está inativo ou excluído.', 'gac'),
            Outcome::LOCAL_ACCOUNT    => __('O e-mail pertence a uma conta local do GLPI, que não é convertida automaticamente.', 'gac'),
            Outcome::STATE_INVALID    => __('O estado do login é inválido ou expirou.', 'gac'),
            Outcome::TOKEN_INVALID    => __('O Google não devolveu um token válido.', 'gac'),
            Outcome::API_ERROR        => __('Falha ao consultar o Google ou erro interno.', 'gac'),
            Outcome::REVOKED          => __('Autorizações dinâmicas removidas.', 'gac'),
            Outcome::UNDONE           => __('Conversão desfeita.', 'gac'),
            default                   => $outcome,
        };
    }
}
