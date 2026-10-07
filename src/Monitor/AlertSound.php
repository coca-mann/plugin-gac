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
 * The alert sound the administrator uploads (spec M17): stored on disk inside GLPI's plugin
 * documents folder and served by a session-less endpoint (ajax/monitor/alert_sound.php), because
 * the public board that plays it has no login. The pure rules (types, size, names) live in
 * AlertSoundFile.
 */
final class AlertSound
{
    public static function directory(): string
    {
        return GLPI_PLUGIN_DOC_DIR . '/gac/monitor';
    }

    /**
     * Full path of the uploaded sound; null when none is set or the file is gone from the disk.
     *
     * @param array<string, string> $settings
     */
    public static function path(array $settings): ?string
    {
        $name = MonitorSettings::alertSoundFile($settings);
        if ($name === '') {
            return null;
        }
        $path = self::directory() . '/' . $name;
        return is_file($path) ? $path : null;
    }

    /**
     * What the board's <audio> loads: the uploaded file when there is one, otherwise the URL
     * setting (which is empty by default: then the alert is only visual).
     *
     * @param array<string, string> $settings
     */
    public static function url(array $settings): string
    {
        global $CFG_GLPI;
        $path = self::path($settings);
        if ($path !== null) {
            // The modification time busts the browser cache when the file is replaced.
            return $CFG_GLPI['root_doc'] . '/plugins/gac/ajax/monitor/alert_sound.php?v=' . (int) filemtime($path);
        }
        return MonitorSettings::alertSoundUrl($settings);
    }

    /**
     * Validates and stores an uploaded file, replacing the previous one.
     *
     * @param array<string, mixed>  $upload   one entry of $_FILES
     * @param array<string, string> $settings updated in place on success
     * @return string|null an error message, null on success
     */
    public static function store(array $upload, array &$settings): ?string
    {
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return self::uploadErrorMessage($error);
        }

        $tmp  = (string) ($upload['tmp_name'] ?? '');
        $name = (string) ($upload['name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            return __('O envio do arquivo de som falhou.', 'gac');
        }

        // The type is read from the file's own content: the one the browser announces is
        // user-controlled.
        $mime    = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $problem = AlertSoundFile::validate($name, (int) filesize($tmp), $mime);
        if ($problem !== null) {
            return self::validationMessage($problem);
        }

        $dir = self::directory();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return __('Não foi possível criar a pasta do som de alerta.', 'gac');
        }

        $stored = AlertSoundFile::storedName((string) AlertSoundFile::extensionOf($name), (string) sha1_file($tmp));
        if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
            return __('Não foi possível gravar o arquivo de som.', 'gac');
        }

        $previous = MonitorSettings::alertSoundFile($settings);
        if ($previous !== '' && $previous !== $stored) {
            self::deleteStored($previous);
        }
        $settings['monitor_alert_sound_file'] = $stored;
        $settings['monitor_alert_sound_name'] = AlertSoundFile::displayName($name);
        return null;
    }

    /** @param array<string, string> $settings updated in place */
    public static function remove(array &$settings): void
    {
        $previous = MonitorSettings::alertSoundFile($settings);
        if ($previous !== '') {
            self::deleteStored($previous);
        }
        $settings['monitor_alert_sound_file'] = '';
        $settings['monitor_alert_sound_name'] = '';
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

    private static function deleteStored(string $storedName): void
    {
        if (!AlertSoundFile::isValidStoredName($storedName)) {
            return;
        }
        $path = self::directory() . '/' . $storedName;
        if (is_file($path)) {
            unlink($path);
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

    private static function validationMessage(string $problem): string
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
