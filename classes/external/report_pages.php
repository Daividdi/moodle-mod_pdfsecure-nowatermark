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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * External API for reporting PDF Secure reading progress.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * External API for recording pages seen in a PDF Secure activity.
 */
class report_pages extends external_api {
    /**
     * Describes the parameters for reporting visible pages.
     *
     * @return external_function_parameters Parameter definition.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'pages' => new external_value(PARAM_INT, 'Total number of pages'),
            'seen' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A page number reported as seen')
            ),
        ]);
    }

    /**
     * Records the reported pages and updates activity completion when applicable.
     *
     * @param int $cmid Course module ID.
     * @param int $pages Total number of pages in the PDF.
     * @param array $seen Page numbers newly reported by the viewer.
     * @return array Progress summary.
     */
    public static function execute($cmid, $pages, $seen) {
        global $CFG, $DB, $USER;

        require_once($CFG->libdir . '/completionlib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'pages' => $pages,
            'seen' => $seen,
        ]);
        $cm = get_coursemodule_from_id('pdfsecure', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/pdfsecure:view', $context);

        $activity = $DB->get_record('pdfsecure', ['id' => $cm->instance], '*', MUST_EXIST);
        $totalpages = max(1, min(5000, (int)$params['pages']));
        $seenpages = [];
        foreach ($params['seen'] as $pagenumber) {
            $pagenumber = (int)$pagenumber;
            if ($pagenumber >= 1 && $pagenumber <= $totalpages) {
                $seenpages[$pagenumber] = $pagenumber;
            }
        }

        $existing = $DB->get_record('pdfsecure_progress', [
            'pdfsecureid' => $activity->id,
            'userid' => $USER->id,
        ]);
        if ($existing) {
            foreach (explode(',', $existing->seen) as $pagenumber) {
                $pagenumber = (int)$pagenumber;
                if ($pagenumber >= 1 && $pagenumber <= $totalpages) {
                    $seenpages[$pagenumber] = $pagenumber;
                }
            }
        }

        ksort($seenpages, SORT_NUMERIC);
        $seenlist = array_values($seenpages);
        $meetsrule = count($seenlist) >= (int)ceil($totalpages * 0.9)
            && in_array($totalpages, $seenlist, true);
        $now = time();
        $timecompleted = $existing && !empty($existing->timecompleted) ? $existing->timecompleted : null;
        if ($meetsrule && $timecompleted === null) {
            $timecompleted = $now;
        }

        $record = (object)[
            'pdfsecureid' => $activity->id,
            'userid' => $USER->id,
            'pages' => $totalpages,
            'seen' => implode(',', $seenlist),
            'timemodified' => $now,
            'timecompleted' => $timecompleted,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('pdfsecure_progress', $record);
        } else {
            $DB->insert_record('pdfsecure_progress', $record);
        }

        $complete = $timecompleted !== null;
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $cm = get_fast_modinfo($course)->get_cm($cm->id);
        $completion = new \completion_info($course);
        if (
            $complete && !empty($activity->completionreadall)
                && $cm->completion == COMPLETION_TRACKING_AUTOMATIC && $completion->is_enabled($cm)
        ) {
            $completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);
        }

        return [
            'seen' => count($seenlist),
            'pages' => $totalpages,
            'complete' => $complete,
        ];
    }

    /**
     * Describes the result returned to the viewer.
     *
     * @return external_single_structure Return value definition.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'seen' => new external_value(PARAM_INT, 'Number of distinct pages seen'),
            'pages' => new external_value(PARAM_INT, 'Clamped total number of pages'),
            'complete' => new external_value(PARAM_BOOL, 'Whether the read-to-end rule is complete'),
        ]);
    }
}
