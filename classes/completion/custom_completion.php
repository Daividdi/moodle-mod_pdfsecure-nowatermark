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
 * Custom completion implementation for the PDF Secure activity module.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\completion;


/**
 * Custom completion rules for PDF Secure activities.
 */
class custom_completion extends \core_completion\activity_custom_completion {
    /**
     * Gets the completion state for a custom rule and the current user.
     *
     * @param string $rule Completion rule name.
     * @return int Completion state.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);
        $timecompleted = $DB->get_field('pdfsecure_progress', 'timecompleted', [
            'pdfsecureid' => $this->cm->instance,
            'userid' => $this->userid,
        ]);

        return !empty($timecompleted) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    /**
     * Lists the custom completion rules provided by this activity.
     *
     * @return array Custom rule names.
     */
    public static function get_defined_custom_rules(): array {
        return ['completionreadall'];
    }

    /**
     * Gets the descriptions of the custom completion rules.
     *
     * @return array Rule descriptions indexed by rule name.
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionreadall' => get_string('completiondetail:readall', 'mod_pdfsecure'),
        ];
    }

    /**
     * Returns the order in which completion rules are displayed.
     *
     * @return array Rule names in display order.
     */
    public function get_sort_order(): array {
        return ['completionview', 'completionreadall'];
    }
}
