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

use GlpiPlugin\Gac\Sso\RuleInput;
use PHPUnit\Framework\TestCase;

final class RuleInputTest extends TestCase
{
    public function testGoogleParamsCarryTheAncestorsAndTheWorkspaceKey(): void
    {
        $this->assertSame(
            ['google_ou' => ['/a', '/a/b'], 'google_workspace' => 'principal'],
            RuleInput::googleParams(['/a', '/a/b'], 'principal')
        );
    }

    public function testNoWorkspaceKeyMeansNoWorkspaceParam(): void
    {
        $this->assertSame(['google_ou' => ['/a']], RuleInput::googleParams(['/a'], ''));
    }

    public function testEngineInputMapsTheParamsToTheCriteria(): void
    {
        $input = RuleInput::engineInput(['google_ou' => ['/a'], 'google_workspace' => 'principal', 'other' => 1]);

        $this->assertSame(['GOOGLE_OU' => ['/a'], 'GOOGLE_WORKSPACE' => 'principal'], $input);
    }

    public function testRulesWithoutTheWorkspaceCriterionSeeTheSameOuInputAsBefore(): void
    {
        $this->assertSame(['GOOGLE_OU' => ['/a']], RuleInput::engineInput(['google_ou' => ['/a']]));
    }

    public function testEngineInputIgnoresValuesOfTheWrongType(): void
    {
        $this->assertSame([], RuleInput::engineInput(['google_ou' => '/a', 'google_workspace' => ['x']]));
        $this->assertSame([], RuleInput::engineInput(['google_workspace' => '']));
        $this->assertSame([], RuleInput::engineInput([]));
    }

    public function testCriterionNamesAreTheOnesStoredInTheRules(): void
    {
        $this->assertSame('GOOGLE_OU', RuleInput::OU_CRITERION);
        $this->assertSame('GOOGLE_WORKSPACE', RuleInput::WORKSPACE_CRITERION);
    }
}
