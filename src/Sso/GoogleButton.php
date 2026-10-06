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
 * The "Entrar com Google" button, drawn as Google's branding guidelines ask for the sign-in
 * button: white, grey border, the unaltered four-colour "G" and a medium weight dark label. The
 * logo is inline SVG and the style a small scoped block, so the button needs no static file and
 * looks the same in the light and dark GLPI themes.
 */
final class GoogleButton
{
    private const LOGO = "<svg class='gac-sso-google-logo' xmlns='http://www.w3.org/2000/svg' viewBox='0 0 48 48' width='20' height='20' aria-hidden='true' focusable='false'>"
        . "<path fill='#EA4335' d='M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z'/>"
        . "<path fill='#4285F4' d='M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z'/>"
        . "<path fill='#FBBC05' d='M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z'/>"
        . "<path fill='#34A853' d='M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z'/>"
        . '</svg>';

    private const STYLE = '<style>'
        . '.gac-sso-login{width:min(340px,calc(100vw - 4rem));margin-inline:auto;text-align:center}'
        . '.gac-sso-google{display:flex;align-items:center;justify-content:center;gap:12px;width:100%;min-height:44px;padding:0 16px;'
        . 'background:#fff;color:#3c4043;border:1px solid #dadce0;border-radius:6px;font-weight:500;font-size:15px;text-decoration:none;'
        . 'transition:background-color .15s,box-shadow .15s,border-color .15s}'
        . '.gac-sso-google:hover{background:#f8f9fa;color:#3c4043;border-color:#d2e3fc;box-shadow:0 1px 3px rgba(60,64,67,.3)}'
        . '.gac-sso-google:focus-visible{outline:3px solid #4285f4;outline-offset:2px;color:#3c4043}'
        . '.gac-sso-google:active{background:#eef1f4}'
        . '.gac-sso-google-logo{flex:none}'
        . '</style>';

    public static function render(string $url, string $label): string
    {
        return self::STYLE
            . "<a class='gac-sso-google' href='" . htmlspecialchars($url, ENT_QUOTES) . "'>"
            . self::LOGO
            . '<span>' . htmlspecialchars($label, ENT_QUOTES) . '</span></a>';
    }
}
