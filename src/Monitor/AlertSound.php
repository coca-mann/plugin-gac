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

namespace GlpiPlugin\Gac\Monitor;

/**
 * The alert sounds the administrator uploads (spec M17, M19): one for the whole plugin (the
 * monitor configuration) and one per Tela. Files live in GLPI's plugin documents folder and are
 * served by a session-less endpoint (ajax/monitor/alert_sound.php), because the public board that
 * plays them has no login. The pure rules (types, size, names, which source wins) live in
 * AlertSoundFile and AlertSoundChoice.
 *
 * A stored file is named after the hash of its content, so the same sound uploaded in two places
 * is one file on disk: it is only deleted when nothing references it any more.
 */
final class AlertSound
{
    public static function directory(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/gac/monitor';
    }

    /** Full path of a stored sound; null when the name is not a valid one or the file is gone. */
    public static function pathOf(string $storedName): ?string
    {
        if (!AlertSoundFile::isValidStoredName($storedName)) {
            return null;
        }
        $path = self::directory() . '/' . $storedName;
        return is_file($path) ? $path : null;
    }

    /**
     * Full path of the plugin-wide uploaded sound; null when none is set or the file is gone.
     *
     * @param array<string, string> $settings
     */
    public static function path(array $settings): ?string
    {
        return self::pathOf(MonitorSettings::alertSoundFile($settings));
    }

    /** The URL that serves one stored sound; the modification time busts the browser cache. */
    public static function fileUrl(string $storedName): string
    {
        global $CFG_GLPI;
        $path = self::pathOf($storedName);
        return $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/monitor/alert_sound.php?f=' . rawurlencode($storedName)
            . '&v=' . ($path !== null ? (int) filemtime($path) : 0);
    }

    /**
     * Which source a Tela's sound comes from (AlertSoundChoice), looking at what really exists on disk.
     *
     * @param array<string, string> $settings
     */
    public static function sourceFor(array $settings, ?MonitorScreen $screen): string
    {
        return AlertSoundChoice::choose(
            self::usableName((string) ($screen?->fields['alert_sound_file'] ?? '')),
            self::usableName(MonitorSettings::alertSoundFile($settings)),
            MonitorSettings::alertSoundUrl($settings)
        );
    }

    /**
     * What a board's <audio> loads: the Tela's own sound, else the plugin's file, else the plugin's
     * URL (empty by default: then the alert is only visual).
     *
     * @param array<string, string> $settings
     */
    public static function urlFor(array $settings, ?MonitorScreen $screen): string
    {
        return match (self::sourceFor($settings, $screen)) {
            AlertSoundChoice::SCREEN_FILE => self::fileUrl((string) $screen?->fields['alert_sound_file']),
            AlertSoundChoice::PLUGIN_FILE => self::fileUrl(MonitorSettings::alertSoundFile($settings)),
            AlertSoundChoice::PLUGIN_URL  => MonitorSettings::alertSoundUrl($settings),
            default                       => '',
        };
    }

    /** @param array<string, string> $settings */
    public static function url(array $settings): string
    {
        return self::urlFor($settings, null);
    }

    /** The stored name when its file exists, '' otherwise. */
    private static function usableName(string $storedName): string
    {
        return self::pathOf($storedName) !== null ? $storedName : '';
    }

    /**
     * Validates one uploaded file and moves it into the sounds folder. It does not touch any
     * setting: the caller decides who owns the file.
     *
     * @param array<string, mixed> $upload one entry of $_FILES
     * @return array{error: string|null, stored: string, name: string}
     */
    public static function storeUpload(array $upload): array
    {
        $fail = static fn(string $message): array => ['error' => $message, 'stored' => '', 'name' => ''];

        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return $fail(self::uploadErrorMessage($error));
        }

        $tmp  = (string) ($upload['tmp_name'] ?? '');
        $name = (string) ($upload['name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return $fail(__('O envio do arquivo de som falhou.', 'gac'));
        }

        // The type is read from the file's own content: the one the browser announces is
        // user-controlled.
        $mime    = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $problem = AlertSoundFile::validate($name, (int) filesize($tmp), $mime);
        if ($problem !== null) {
            return $fail(self::validationMessage($problem));
        }

        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $fail(__('Não foi possível criar a pasta do som de alerta.', 'gac'));
        }

        $stored = AlertSoundFile::storedName((string) AlertSoundFile::extensionOf($name), (string) sha1_file($tmp));
        if (!is_file($dir . '/' . $stored) && !move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return $fail(__('Não foi possível gravar o arquivo de som.', 'gac'));
        }

        return ['error' => null, 'stored' => $stored, 'name' => AlertSoundFile::displayName($name)];
    }

    /**
     * The plugin-wide sound: stores an upload and makes it the configured one, replacing the
     * previous one.
     *
     * @param array<string, mixed>  $upload   one entry of $_FILES
     * @param array<string, string> $settings updated in place on success
     * @return string|null an error message, null on success
     */
    public static function store(array $upload, array &$settings): ?string
    {
        $result = self::storeUpload($upload);
        if ($result['error'] !== null) {
            return $result['error'];
        }

        $previous = MonitorSettings::alertSoundFile($settings);
        $settings['monitor_alert_sound_file'] = $result['stored'];
        $settings['monitor_alert_sound_name'] = $result['name'];
        if ($previous !== '' && $previous !== $result['stored']) {
            // The configuration still points at the old file until it is saved, so ignore that reference.
            self::deleteIfUnused($previous, null, true);
        }
        return null;
    }

    /** @param array<string, string> $settings updated in place */
    public static function remove(array &$settings): void
    {
        $previous = MonitorSettings::alertSoundFile($settings);
        $settings['monitor_alert_sound_file'] = '';
        $settings['monitor_alert_sound_name'] = '';
        if ($previous !== '') {
            self::deleteIfUnused($previous, null, true);
        }
    }

    /**
     * Whether anything still points at this stored sound: the plugin configuration or a Tela.
     *
     * @param int|null $exceptScreenId a Tela to leave out (the one being changed or deleted)
     * @param bool     $exceptPlugin   leave out the plugin configuration (it is being changed)
     */
    public static function isReferenced(string $storedName, ?int $exceptScreenId = null, bool $exceptPlugin = false): bool
    {
        if (!$exceptPlugin && MonitorSettings::alertSoundFile(MonitorConfig::load()) === $storedName) {
            return true;
        }
        $where = ['alert_sound_file' => $storedName];
        if ($exceptScreenId !== null) {
            $where['NOT'] = ['id' => $exceptScreenId];
        }
        return countElementsInTable(MonitorScreen::getTable(), $where) > 0;
    }

    /** Deletes a stored sound from the disk, unless something else still uses it. */
    public static function deleteIfUnused(string $storedName, ?int $exceptScreenId = null, bool $exceptPlugin = false): void
    {
        if (!AlertSoundFile::isValidStoredName($storedName)) {
            return;
        }
        if (self::isReferenced($storedName, $exceptScreenId, $exceptPlugin)) {
            return;
        }
        $path = self::directory() . '/' . $storedName;
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** Uninstall: leaves no sound file behind. */
    public static function removeAll(): void
    {
        foreach (glob(self::directory() . '/alert-*') ?: [] as $file) {
            if (AlertSoundFile::isValidStoredName(basename($file))) {
                unlink($file);
            }
        }
    }

    /**
     * Streams the file, honouring a Range request (Safari will not play audio served without
     * it), and ends the script. The file is at most AlertSoundFile::MAX_BYTES, so it is read whole.
     */
    public static function send(string $path): never
    {
        $size = (int) filesize($path);
        $last = $size - 1;
        $from = 0;
        $to   = $last;
        $status = 200;

        header('Content-Type: ' . AlertSoundFile::mimeFor(basename($path)));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('Accept-Ranges: bytes');

        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim((string) ($_SERVER['HTTP_RANGE'] ?? '')), $m) === 1 && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $from = max(0, $size - (int) $m[2]);
            } else {
                $from = (int) $m[1];
                if ($m[2] !== '') {
                    $to = min($last, (int) $m[2]);
                }
            }
            if ($from > $to || $from >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $status = 206;
            header('Content-Range: bytes ' . $from . '-' . $to . '/' . $size);
        }

        http_response_code($status);
        header('Content-Length: ' . ($to - $from + 1));
        $handle = fopen($path, 'rb');
        fseek($handle, $from);
        echo fread($handle, $to - $from + 1);
        fclose($handle);
        exit;
    }

    public static function validationMessage(string $problem): string
    {
        return match ($problem) {
            AlertSoundFile::ERROR_EXTENSION => __('Use um arquivo de som mp3, ogg ou wav.', 'gac'),
            AlertSoundFile::ERROR_EMPTY     => __('O arquivo de som está vazio.', 'gac'),
            AlertSoundFile::ERROR_SIZE      => sprintf(__('O arquivo de som passa de %d KB.', 'gac'), intdiv(AlertSoundFile::MAX_BYTES, 1024)),
            default                         => __('O conteúdo do arquivo não é um áudio mp3, ogg ou wav válido.', 'gac'),
        };
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('O arquivo de som é maior que o limite de envio do servidor.', 'gac'),
            default                                   => __('O envio do arquivo de som falhou.', 'gac'),
        };
    }
}
