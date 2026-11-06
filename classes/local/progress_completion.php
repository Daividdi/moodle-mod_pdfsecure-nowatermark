<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_pdfsecure\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates per-file reading progress for an activity completion rule.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_completion {

    /**
     * Checks whether a reader meets the configured all-files or any-file rule.
     *
     * @param \context_module $context Activity context.
     * @param int $activityid Activity instance ID.
     * @param int $userid Reader user ID.
     * @param int $mode 0 disabled, 1 all files, 2 any file.
     * @return bool
     */
    public static function is_complete(\context_module $context, int $activityid, int $userid, int $mode): bool {
        global $DB;

        if (!in_array($mode, [1, 2], true)) {
            return false;
        }
        $pdfs = content_files::get_pdfs($context);
        if (!$pdfs) {
            return false;
        }

        $records = $DB->get_records('pdfsecure_progress', [
            'pdfsecureid' => $activityid,
            'userid' => $userid,
        ], '', 'id, filekey, timecompleted');
        $completekeys = [];
        foreach ($records as $record) {
            if (!empty($record->timecompleted)) {
                $completekeys[$record->filekey] = true;
            }
        }

        if ($mode === 2) {
            return (bool)array_intersect_key($pdfs, $completekeys);
        }
        foreach (array_keys($pdfs) as $filekey) {
            if (empty($completekeys[$filekey])) {
                return false;
            }
        }
        return true;
    }
}
