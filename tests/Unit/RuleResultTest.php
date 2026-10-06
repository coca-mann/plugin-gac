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

namespace GlpiPlugin\Gac\Tests\Unit;

use GlpiPlugin\Gac\Sso\RuleResult;
use PHPUnit\Framework\TestCase;

final class RuleResultTest extends TestCase
{
    public function testReadsEntityProfileRecursiveTriples(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [['3', '3', '0'], ['4', '5', '1']]],
        ]);

        $this->assertFalse($result->denied);
        $this->assertTrue($result->hasGrants());
        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 3, 'is_recursive' => 0],
            ['entities_id' => 4, 'profiles_id' => 5, 'is_recursive' => 1],
        ], $result->grants);
    }

    public function testDuplicateTriplesCollapse(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [['3', '3', '0'], ['4', '5', '0'], ['3', '3', '0']]],
        ]);

        $this->assertCount(2, $result->grants);
    }

    public function testAMultiEntityRowExpands(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities_rights' => [[['3', '4'], '2', '0']]],
        ]);

        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 2, 'is_recursive' => 0],
            ['entities_id' => 4, 'profiles_id' => 2, 'is_recursive' => 0],
        ], $result->grants);
    }

    public function testEntityOnlyRulesCombineWithProfileOnlyRules(): void
    {
        $result = RuleResult::fromOutput([
            '_ldap_rules' => ['rules_entities' => [['3', '1']], 'rules_rights' => ['5', '6']],
        ]);

        $this->assertSame([
            ['entities_id' => 3, 'profiles_id' => 5, 'is_recursive' => 1],
            ['entities_id' => 3, 'profiles_id' => 6, 'is_recursive' => 1],
        ], $result->grants);
    }

    public function testEntityOnlyWithoutProfileUsesProfileZeroMeaningGlpiDefault(): void
    {
        $result = RuleResult::fromOutput(['_ldap_rules' => ['rules_entities' => [['3', '0']]]]);

        $this->assertSame([['entities_id' => 3, 'profiles_id' => 0, 'is_recursive' => 0]], $result->grants);
    }

    public function testDenyAndEmptyOutput(): void
    {
        $denied = RuleResult::fromOutput(['_deny_login' => '1']);
        $empty  = RuleResult::fromOutput(['_no_rule_matches' => true]);

        $this->assertTrue($denied->denied);
        $this->assertFalse($denied->hasGrants());
        $this->assertFalse($empty->denied);
        $this->assertFalse($empty->hasGrants());
    }

    public function testDefaultEntityComesFromTheEntitiesIdOutputKey(): void
    {
        $this->assertSame(3, RuleResult::fromOutput(['entities_id' => '3'])->defaultEntityId);
        $this->assertNull(RuleResult::fromOutput([])->defaultEntityId);
    }
}
