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

use GlpiPlugin\Gac\Monitor\AlertSoundFile;
use PHPUnit\Framework\TestCase;

final class AlertSoundFileTest extends TestCase
{
    public function testExtensionOfAcceptsOnlyTheAllowedOnesCaseInsensitively(): void
    {
        $this->assertSame('mp3', AlertSoundFile::extensionOf('beep.mp3'));
        $this->assertSame('mp3', AlertSoundFile::extensionOf('BEEP.MP3'));
        $this->assertSame('ogg', AlertSoundFile::extensionOf('a.b.ogg'));
        $this->assertSame('wav', AlertSoundFile::extensionOf('x.wav'));
        $this->assertNull(AlertSoundFile::extensionOf('x.exe'));
        $this->assertNull(AlertSoundFile::extensionOf('x.php.mp3.txt'));
        $this->assertNull(AlertSoundFile::extensionOf('mp3'));
        $this->assertNull(AlertSoundFile::extensionOf(''));
    }

    public function testValidateAcceptsAMatchingFile(): void
    {
        $this->assertNull(AlertSoundFile::validate('beep.mp3', 8000, 'audio/mpeg'));
        $this->assertNull(AlertSoundFile::validate('beep.ogg', 8000, 'application/ogg'));
        $this->assertNull(AlertSoundFile::validate('beep.ogg', 8000, 'audio/ogg'));
        $this->assertNull(AlertSoundFile::validate('beep.wav', 8000, 'audio/x-wav'));
        $this->assertNull(AlertSoundFile::validate('beep.wav', AlertSoundFile::MAX_BYTES, 'audio/wav'));
    }

    public function testValidateRejectsTheWrongExtension(): void
    {
        $this->assertSame(AlertSoundFile::ERROR_EXTENSION, AlertSoundFile::validate('beep.exe', 100, 'audio/mpeg'));
        $this->assertSame(AlertSoundFile::ERROR_EXTENSION, AlertSoundFile::validate('beep', 100, 'audio/mpeg'));
    }

    public function testValidateRejectsEmptyAndOversizedFiles(): void
    {
        $this->assertSame(AlertSoundFile::ERROR_EMPTY, AlertSoundFile::validate('beep.mp3', 0, 'audio/mpeg'));
        $this->assertSame(AlertSoundFile::ERROR_SIZE, AlertSoundFile::validate('beep.mp3', AlertSoundFile::MAX_BYTES + 1, 'audio/mpeg'));
    }

    public function testValidateRejectsContentThatIsNotTheAnnouncedAudio(): void
    {
        // A script renamed to .mp3, or an mp3 renamed to .wav.
        $this->assertSame(AlertSoundFile::ERROR_CONTENT, AlertSoundFile::validate('beep.mp3', 100, 'text/x-php'));
        $this->assertSame(AlertSoundFile::ERROR_CONTENT, AlertSoundFile::validate('beep.mp3', 100, 'application/octet-stream'));
        $this->assertSame(AlertSoundFile::ERROR_CONTENT, AlertSoundFile::validate('beep.wav', 100, 'audio/mpeg'));
    }

    public function testStoredNameIsPredictableAndSafe(): void
    {
        $name = AlertSoundFile::storedName('mp3', 'ABCDEF0123456789abcdef');
        $this->assertSame('alert-abcdef012345.mp3', $name);
        $this->assertTrue(AlertSoundFile::isValidStoredName($name));
        $this->assertSame('alert-000000000000.ogg', AlertSoundFile::storedName('ogg', '../../xyz'));
    }

    public function testIsValidStoredName(): void
    {
        $this->assertTrue(AlertSoundFile::isValidStoredName('alert-0123456789ab.wav'));
        $this->assertFalse(AlertSoundFile::isValidStoredName(''));
        $this->assertFalse(AlertSoundFile::isValidStoredName('../alert-0123456789ab.wav'));
        $this->assertFalse(AlertSoundFile::isValidStoredName('alert-0123456789ab.php'));
        $this->assertFalse(AlertSoundFile::isValidStoredName('alert-xyz.mp3'));
    }

    public function testMimeFor(): void
    {
        $this->assertSame('audio/mpeg', AlertSoundFile::mimeFor('alert-0123456789ab.mp3'));
        $this->assertSame('audio/ogg', AlertSoundFile::mimeFor('alert-0123456789ab.ogg'));
        $this->assertSame('audio/wav', AlertSoundFile::mimeFor('alert-0123456789ab.wav'));
    }

    public function testDisplayNameKeepsOnlyTheBaseNameWithoutControlCharacters(): void
    {
        $this->assertSame('beep.mp3', AlertSoundFile::displayName('C:\\sons\\beep.mp3'));
        $this->assertSame('beep.mp3', AlertSoundFile::displayName('/tmp/x/beep.mp3'));
        $this->assertSame('bee p.mp3', AlertSoundFile::displayName("bee\x00\x07 p.mp3"));
        $this->assertSame(120, strlen(AlertSoundFile::displayName(str_repeat('a', 300) . '.mp3')));
    }
}
