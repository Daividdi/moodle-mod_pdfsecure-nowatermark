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

namespace mod_pdfsecure;

#[\PHPUnit\Framework\Attributes\CoversFunction('pdfsecure_reset_userdata')]
/**
 * Course reset removes reading progress, so a reset course does not complete everyone again at once.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     ::pdfsecure_reset_userdata
 */
final class reset_test extends \advanced_testcase {
    /**
     * Progress goes with the reading-progress option and with the completion reset; other courses keep theirs.
     *
     * @return void
     */
    public function test_reset_removes_progress(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/pdfsecure/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $mine = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        $theirs = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $other->id]);
        $progress = function (int $pdfsecureid) use ($DB, $user): void {
            $DB->insert_record('pdfsecure_progress', (object)['pdfsecureid' => $pdfsecureid, 'userid' => $user->id,
                'pages' => 4, 'seen' => '1,2,3,4', 'timemodified' => time(), 'timecompleted' => time()]);
        };
        $progress((int)$mine->id);
        $progress((int)$theirs->id);

        $status = pdfsecure_reset_userdata((object)['courseid' => $course->id, 'reset_pdfsecure_progress' => 1]);
        $this->assertCount(1, $status);
        $this->assertFalse($DB->record_exists('pdfsecure_progress', ['pdfsecureid' => $mine->id]));
        $this->assertTrue($DB->record_exists('pdfsecure_progress', ['pdfsecureid' => $theirs->id]));

        $progress((int)$mine->id);
        pdfsecure_reset_userdata((object)['courseid' => $course->id, 'reset_completion' => 1]);
        $this->assertFalse($DB->record_exists('pdfsecure_progress', ['pdfsecureid' => $mine->id]));

        $progress((int)$mine->id);
        $this->assertSame([], pdfsecure_reset_userdata((object)['courseid' => $course->id]));
        $this->assertTrue($DB->record_exists('pdfsecure_progress', ['pdfsecureid' => $mine->id]));
    }
}
