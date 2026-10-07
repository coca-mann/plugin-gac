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
 * One Google Workspace the login accepts (spec S24): its domains and the read-only admin the
 * service account impersonates to read org units there. Pure value object. The key (spec S25) is
 * the stable identifier the authorization rules refer to; the name can be edited.
 */
final class Workspace
{
    /** @param list<string> $domains lowercase */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly array $domains,
        public readonly string $adminSubject,
        public readonly bool $active
    ) {}

    public function hasDomain(string $domain): bool
    {
        return in_array(mb_strtolower($domain), $this->domains, true);
    }

    /** Active and complete: it has at least one domain and an admin to impersonate. */
    public function isUsable(): bool
    {
        return $this->active && $this->domains !== [] && $this->adminSubject !== '';
    }

    /** @return array{key: string, name: string, domains: list<string>, admin_subject: string, is_active: bool} */
    public function toArray(): array
    {
        return [
            'key'           => $this->key,
            'name'          => $this->name,
            'domains'       => $this->domains,
            'admin_subject' => $this->adminSubject,
            'is_active'     => $this->active,
        ];
    }
}
