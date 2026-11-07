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
 * Small internal API for activity modules which reuse PDF Secure protection.
 *
 * Callers must perform their own login, module-context, capability and stored-file
 * allow-list checks before calling send(). Their endpoint must never expose the
 * source or this class's `normalized` file area. PDF Secure's own pluginfile route
 * remains tied to mod_pdfsecure; another component should use a thin pluginfile
 * callback which checks its own component and capability, then calls send().
 *
 * The progress table contract for record_page_progress() is: an integer primary
 * key, the supplied activity foreign-key column, userid, pagesviewed, total, seen
 * (comma-separated page numbers), and timemodified. The activity/user pair must
 * have a unique index. Page IDs are merged and deduplicated by this API.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protection_api {

    /**
     * Normalizes a PDF into the caller component's private `normalized` area.
     *
     * @param \stored_file $source PDF stored file in the module context.
     * @param string $component Owning Moodle component, for example mod_slidesecure.
     * @return \stored_file|null Rewritten file, or null if Ghostscript cannot rewrite it.
     */
    public static function normalize(\stored_file $source, string $component): ?\stored_file {
        return watermarker::normalize_for($source, $component);
    }

    /**
     * Counts pages using the caller's private normalization area when persistence is wanted.
     *
     * Temporary validation files should pass false so Ghostscript rewrites stay in the
     * request directory instead of leaving an inaccessible normalized file behind.
     *
     * @param \stored_file $source PDF stored file.
     * @param string $component Owning Moodle component.
     * @param bool $persistnormalized Keep a reusable normalized copy for later rendering.
     * @return int|null Page count, or null when the PDF cannot be read.
     */
    public static function page_count(\stored_file $source, string $component,
            bool $persistnormalized = true): ?int {
        return watermarker::page_count($source, $component, $persistnormalized);
    }

    /**
     * Renders a per-user stamped PDF using PDF Secure's normal settings and rules.
     *
     * @param \stored_file $source Original or converted PDF.
     * @param \stdClass $user Current reader.
     * @param int $cmid Course module ID embedded in the watermark.
     * @param string $component Owning Moodle component for private normalization.
     * @return string Stamped PDF bytes. Send with Accept-Ranges: none and no-store.
     */
    public static function render(\stored_file $source, \stdClass $user, int $cmid, string $component): string {
        return watermarker::render_for($source, $user, $cmid, $component);
    }

    /**
     * Sends one resolved source file through the protected PDF response path.
     *
     * The caller must already have authenticated the request, checked its module
     * context and capability, and resolved the file from a strict allow-list. This
     * method intentionally does not accept an activity ID or resolve any URL input.
     *
     * @param \stored_file $source Resolved private PDF source.
     * @param \stdClass $user Current reader.
     * @param int $cmid Course module ID.
     * @param string $filename Safe display filename.
     * @param string $component Owning Moodle component.
     * @return never Ends the request by streaming the protected PDF.
     */
    public static function send(\stored_file $source, \stdClass $user, int $cmid,
            string $filename, string $component): never {
        if ($filename === '' || strpos($filename, '/') !== false || strpos($filename, '\\') !== false
                || preg_match('/[\r\n\"]/', $filename)) {
            send_file_not_found();
        }

        if (watermarker::too_big($source)) {
            debugging('pdfsecure: refusing oversized file ' . $source->get_id() . ' from ' . $component,
                DEBUG_DEVELOPER);
            send_file_not_found();
        }

        try {
            $content = self::render($source, $user, $cmid, $component);
        } catch (\Throwable $e) {
            debugging('pdfsecure: shared protection failed for file ' . $source->get_id()
                . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            send_file_not_found();
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filename . '"; filename*=UTF-8\'\''
            . rawurlencode($filename));
        header('Content-Length: ' . strlen($content));
        header('Accept-Ranges: none');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        echo $content;
        die;
    }

    /**
     * Records a batch of newly viewed pages in another component's progress table.
     *
     * @param string $table Moodle table name without braces or prefix.
     * @param string $activityfield Activity foreign-key column in that table.
     * @param int $activityid Activity instance ID.
     * @param int $userid Reader user ID.
     * @param int $totalpages Server-verified page count.
     * @param int[] $newpages Page numbers newly viewed.
     * @return array{seen:int[],pagesviewed:int,total:int,complete:bool}
     */
    public static function record_page_progress(string $table, string $activityfield, int $activityid,
            int $userid, int $totalpages, array $newpages): array {
        global $DB;

        foreach ([$table, $activityfield] as $identifier) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,27}$/D', $identifier)) {
                throw new \invalid_parameter_exception('Invalid progress table identifier.');
            }
        }
        if ($activityid < 1 || $userid < 1 || $totalpages < 1 || $totalpages > 5000) {
            throw new \invalid_parameter_exception('Invalid progress dimensions.');
        }

        $existing = $DB->get_record($table, [$activityfield => $activityid, 'userid' => $userid]);
        $seen = [];
        if ($existing && (int)$existing->total === $totalpages) {
            foreach (explode(',', (string)$existing->seen) as $page) {
                $page = (int)$page;
                if ($page >= 1 && $page <= $totalpages) {
                    $seen[$page] = $page;
                }
            }
        }
        foreach ($newpages as $page) {
            $page = (int)$page;
            if ($page >= 1 && $page <= $totalpages) {
                $seen[$page] = $page;
            }
        }
        ksort($seen, SORT_NUMERIC);

        $record = (object)[
            $activityfield => $activityid,
            'userid' => $userid,
            'pagesviewed' => count($seen),
            'total' => $totalpages,
            'seen' => implode(',', $seen),
            'timemodified' => time(),
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record($table, $record);
        } else {
            $DB->insert_record($table, $record);
        }

        return [
            'seen' => array_values($seen),
            'pagesviewed' => count($seen),
            'total' => $totalpages,
            'complete' => count($seen) === $totalpages,
        ];
    }
}
