<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_pdfsecure\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Viewer tokens: the protected PDF is delivered only to the PDF.js request that view.php just opened.
 *
 * Login and the watermark already say WHO may read and WHO leaked. This closes the remaining gap: copying the
 * file address from the viewer and opening or saving it elsewhere. A token is random, bound to the user, the
 * session, the module and the file, expires after a short time and is spent on use. On top of that, browsers
 * that send Fetch Metadata must show a script fetch from this site (Sec-Fetch-Dest: empty, same-origin), which
 * is what PDF.js does and what a typed address, a link, a new tab or "save link as" is not.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class view_token {

    /** Seconds a fresh token stays valid. */
    const LIFETIME = 120;

    /**
     * Issues a token for one viewer load.
     *
     * @param int $cmid Course module.
     * @param string $component Owning component.
     * @param string $filename File the viewer will request.
     * @param int $uses How many requests may spend it (1 for a rendered copy; more when byte ranges are served).
     * @return string Token for the `pdfsecureview` URL parameter.
     */
    public static function issue(int $cmid, string $component, string $filename, int $uses = 1): string {
        global $USER;
        $token = bin2hex(random_bytes(16));
        \cache::make('mod_pdfsecure', 'viewtokens')->set($token, [
            'userid' => (int)$USER->id,
            'sid' => session_id(),
            'cmid' => $cmid,
            'component' => $component,
            'filename' => $filename,
            'expires' => time() + self::LIFETIME,
            'uses' => max(1, $uses),
        ]);
        return $token;
    }

    /**
     * Checks the current request and spends one use of its token; anything else ends with "not found".
     *
     * @param int $cmid Course module.
     * @param string $component Owning component.
     * @param string $filename Requested file.
     */
    public static function require_valid(int $cmid, string $component, string $filename): void {
        if (!self::request_is_viewer_fetch($_SERVER)) {
            send_file_not_found();
        }
        $token = optional_param('pdfsecureview', '', PARAM_ALPHANUM);
        if (!self::spend($token, $cmid, $component, $filename)) {
            send_file_not_found();
        }
    }

    /**
     * Fetch Metadata check. Browsers without these headers fall back to the token alone.
     *
     * @param array $server Request headers ($_SERVER).
     * @return bool
     */
    public static function request_is_viewer_fetch(array $server): bool {
        $dest = $server['HTTP_SEC_FETCH_DEST'] ?? null;
        $site = $server['HTTP_SEC_FETCH_SITE'] ?? null;
        $mode = $server['HTTP_SEC_FETCH_MODE'] ?? null;
        if ($dest !== null && $dest !== 'empty') {
            return false;
        }
        if ($site !== null && $site !== 'same-origin') {
            return false;
        }
        if ($mode !== null && $mode === 'navigate') {
            return false;
        }
        return true;
    }

    /**
     * Spends one use of a token if it matches this user, session, module and file.
     *
     * @param string $token Token from the URL.
     * @param int $cmid Course module.
     * @param string $component Owning component.
     * @param string $filename Requested file.
     * @return bool
     */
    public static function spend(string $token, int $cmid, string $component, string $filename): bool {
        global $USER;
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return false;
        }
        $cache = \cache::make('mod_pdfsecure', 'viewtokens');
        $data = $cache->get($token);
        if (!is_array($data)) {
            return false;
        }
        $ok = $data['userid'] === (int)$USER->id && $data['sid'] === session_id() && $data['cmid'] === $cmid
            && $data['component'] === $component && $data['filename'] === $filename && $data['expires'] >= time();
        if (!$ok) {
            return false;
        }
        $data['uses']--;
        if ($data['uses'] <= 0) {
            $cache->delete($token);
        } else {
            $cache->set($token, $data);
        }
        return true;
    }
}
