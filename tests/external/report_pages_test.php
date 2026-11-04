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
 * Tests page reporting for the PDF Secure activity module.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\external;


#[\PHPUnit\Framework\Attributes\CoversClass(\mod_pdfsecure\external\report_pages::class)]
/**
 * Tests page reporting and read-to-end completion.
 * @covers     \mod_pdfsecure\external\report_pages
 */
final class report_pages_test extends \advanced_testcase {
    /**
     * Creates a course, an automatically completing PDF activity, and its reader.
     *
     * @return array{course: \stdClass, activity: \stdClass, cmid: int, user: \stdClass}
     */
    private function create_activity_and_reader(): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $activity = $this->getDataGenerator()->create_module('pdfsecure', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionreadall' => 1,
        ]);
        $cm = get_coursemodule_from_instance('pdfsecure', $activity->id, $course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);

        return ['course' => $course, 'activity' => $activity, 'cmid' => $cm->id, 'user' => $user];
    }

    /**
     * Reports a batch of pages as the specified user.
     *
     * @param int $cmid Course module ID.
     * @param \stdClass $user Reader account.
     * @param int $pages Total page count.
     * @param array $seen Newly seen page numbers.
     * @return array Result from the external function.
     */
    private function report(int $cmid, \stdClass $user, int $pages, array $seen): array {
        $this->setUser($user);
        return report_pages::execute($cmid, $pages, $seen);
    }

    /**
     * Completes after 90 percent of pages and the last page have been seen.
     */
    public function test_ninety_percent_and_last_page_complete_rule(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader();
        $firstbatch = $this->report($fixture['cmid'], $fixture['user'], 11, range(1, 5));
        $this->assertFalse($firstbatch['complete']);
        $result = $this->report(
            $fixture['cmid'],
            $fixture['user'],
            11,
            array_merge(range(6, 9), [11])
        );

        $this->assertSame(10, $result['seen']);
        $this->assertSame(11, $result['pages']);
        $this->assertTrue($result['complete']);
        $this->assertTrue($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $fixture['cmid'],
            'userid' => $fixture['user']->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }

    /**
     * Confirms that going directly to the final page does not complete a long PDF.
     */
    public function test_skipping_to_last_page_does_not_complete(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader();
        $result = $this->report($fixture['cmid'], $fixture['user'], 20, [20]);

        $this->assertSame(1, $result['seen']);
        $this->assertFalse($result['complete']);
    }

    /**
     * Clamps the page total and filters page numbers outside the accepted range.
     */
    public function test_page_total_and_page_numbers_are_clamped(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader();
        $result = $this->report($fixture['cmid'], $fixture['user'], 9000, [0, 1, 4999, 5000, 5001]);

        $this->assertSame(5000, $result['pages']);
        $this->assertSame(3, $result['seen']);
        $this->assertFalse($result['complete']);
    }

    /**
     * Confirms that reporting progress never changes a different user's row.
     */
    public function test_other_users_progress_is_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader();
        $otheruser = $this->getDataGenerator()->create_user();
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $otheruser->id,
            'pages' => 20,
            'seen' => '4,8',
            'timemodified' => time(),
            'timecompleted' => null,
        ]);

        $this->report($fixture['cmid'], $fixture['user'], 20, [1]);

        $otherprogress = $DB->get_record('pdfsecure_progress', [
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $otheruser->id,
        ], '*', MUST_EXIST);
        $this->assertSame('4,8', $otherprogress->seen);
        $this->assertNull($otherprogress->timecompleted);
    }
}
