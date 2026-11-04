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
 * Tests the PDF Secure custom completion rule.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\completion;


#[\PHPUnit\Framework\Attributes\CoversClass(\mod_pdfsecure\completion\custom_completion::class)]
/**
 * Tests the read-to-end custom completion rule.
 * @covers     \mod_pdfsecure\completion\custom_completion
 */
final class custom_completion_test extends \advanced_testcase {
    /**
     * Confirms that completion state follows the user's progress row.
     */
    public function test_completion_state_reflects_progress(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $activity = $this->getDataGenerator()->create_module('pdfsecure', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionreadall' => 1,
        ]);
        $cmrecord = get_coursemodule_from_instance('pdfsecure', $activity->id, $course->id);
        $cm = get_fast_modinfo($course)->get_cm($cmrecord->id);
        $user = $this->getDataGenerator()->create_user();
        $completion = new custom_completion($cm, $user->id);

        $this->assertSame(COMPLETION_INCOMPLETE, $completion->get_state('completionreadall'));

        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $activity->id,
            'userid' => $user->id,
            'pages' => 20,
            'seen' => '1,2,3',
            'timemodified' => time(),
            'timecompleted' => time(),
        ]);

        $this->assertSame(COMPLETION_COMPLETE, $completion->get_state('completionreadall'));
    }
}
