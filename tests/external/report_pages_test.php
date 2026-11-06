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
     * Builds a PDF of the given length that FPDI reads without Ghostscript.
     *
     * @param int $pages Number of pages.
     * @return string PDF bytes.
     */
    private function pdf(int $pages): string {
        $kids = implode(' ', array_map(fn($page) => (2 + $page) . ' 0 R', range(1, $pages)));
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => "<< /Type /Pages /Kids [$kids] /Count $pages >>",
        ];
        foreach (range(1, $pages) as $page) {
            $objects[2 + $page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>';
        }
        $pdf = "%PDF-1.4\n";
        $offsets = '';
        foreach ($objects as $number => $body) {
            $offsets .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $start = strlen($pdf);
        $size = count($objects) + 1;
        return $pdf . "xref\n0 $size\n0000000000 65535 f \n$offsets"
            . "trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    }

    /**
     * Stores content as the activity's document, replacing the one there.
     *
     * @param int $cmid Course module ID.
     * @param string $content File content.
     */
    private function store_document(int $cmid, string $content, string $filename = 'doc.pdf', bool $replace = true): void {
        $contextid = \context_module::instance($cmid)->id;
        $fs = get_file_storage();
        if ($replace) {
            $fs->delete_area_files($contextid, 'mod_pdfsecure', 'content');
        }
        $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Creates a course, an automatically completing PDF activity, and its reader.
     *
     * @param int $pages Pages in the activity's document; 0 leaves it without one.
     * @return array{course: \stdClass, activity: \stdClass, cmid: int, user: \stdClass}
     */
    private function create_activity_and_reader(int $pages, int $completionmode = 1): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $activity = $this->getDataGenerator()->create_module('pdfsecure', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionreadall' => $completionmode,
        ]);
        $cm = get_coursemodule_from_instance('pdfsecure', $activity->id, $course->id);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id);
        if ($pages) {
            $this->store_document($cm->id, $this->pdf($pages));
        }

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
    private function report(int $cmid, \stdClass $user, int $pages, array $seen, string $file = 'doc.pdf'): array {
        $this->setUser($user);
        return report_pages::execute($cmid, $pages, $seen, $file);
    }

    /**
     * Completes after 90 percent of pages and the last page have been seen.
     */
    public function test_ninety_percent_and_last_page_complete_rule(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(11);
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
        $fixture = $this->create_activity_and_reader(20);
        $result = $this->report($fixture['cmid'], $fixture['user'], 20, [20]);

        $this->assertSame(1, $result['seen']);
        $this->assertFalse($result['complete']);
    }

    /**
     * Filters page numbers outside the document.
     */
    public function test_page_numbers_outside_the_document_are_filtered(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(20);
        $result = $this->report($fixture['cmid'], $fixture['user'], 20, [0, 1, 20, 21, 5000]);

        $this->assertSame(20, $result['pages']);
        $this->assertSame(2, $result['seen']);
        $this->assertFalse($result['complete']);
    }

    /**
     * A page total the stored PDF contradicts changes nothing, however it is forged.
     */
    public function test_forged_page_total_is_refused(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(10);
        foreach ([[1, [1]], [9, range(1, 9)], [9000, [1]]] as [$total, $seen]) {
            try {
                $this->report($fixture['cmid'], $fixture['user'], $total, $seen);
                $this->fail("A total of $total was accepted for a 10-page PDF");
            } catch (\moodle_exception $e) {
                $this->assertSame('errorpagesmismatch', $e->errorcode);
            }
        }

        $this->assertFalse($DB->record_exists('pdfsecure_progress', ['pdfsecureid' => $fixture['activity']->id]));
        $this->assertFalse($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $fixture['cmid'],
            'userid' => $fixture['user']->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }

    /**
     * Documents the limit of the check: a client reporting every real page still completes without having read anything.
     */
    public function test_signal_remains_client_attested(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(10);

        $result = $this->report($fixture['cmid'], $fixture['user'], 10, range(1, 10));

        $this->assertTrue($result['complete']);
    }

    /**
     * A reader part-way through keeps their pages, and one already complete stays complete.
     */
    public function test_existing_progress_keeps_working(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(10);
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $fixture['user']->id,
            'filekey' => 'doc.pdf',
            'pages' => 10,
            'seen' => implode(',', range(1, 9)),
            'timemodified' => time(),
            'timecompleted' => null,
        ]);
        $result = $this->report($fixture['cmid'], $fixture['user'], 10, [10]);
        $this->assertSame(10, $result['seen']);
        $this->assertTrue($result['complete']);

        // The file is replaced by a shorter one: pages beyond it drop out, the completion stands.
        $this->store_document($fixture['cmid'], $this->pdf(5));
        $result = $this->report($fixture['cmid'], $fixture['user'], 5, [1]);
        $this->assertSame(5, $result['pages']);
        $this->assertSame(5, $result['seen']);
        $this->assertTrue($result['complete']);
    }

    /**
     * An activity with no document has no pages to report.
     */
    public function test_activity_without_document_is_refused(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(0);

        $this->expectExceptionMessage(get_string('errorpagesnodocument', 'mod_pdfsecure'));
        $this->report($fixture['cmid'], $fixture['user'], 1, [1]);
    }

    /**
     * A PDF the server cannot read falls back to the viewer's total, so its readers can still complete it.
     */
    public function test_unreadable_document_falls_back_to_the_viewer(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->pathtogs = '';
        $fixture = $this->create_activity_and_reader(0);
        $this->store_document($fixture['cmid'], 'not a pdf');

        $result = $this->report($fixture['cmid'], $fixture['user'], 2, [1, 2]);
        $this->assertDebuggingCalled();

        $this->assertSame(2, $result['pages']);
        $this->assertTrue($result['complete']);
    }

    /**
     * Confirms that reporting progress never changes a different user's row.
     */
    public function test_other_users_progress_is_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(20);
        $otheruser = $this->getDataGenerator()->create_user();
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $otheruser->id,
            'filekey' => 'doc.pdf',
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

    /**
     * A viewer cannot submit progress for a PDF outside this activity.
     */
    public function test_report_pages_rejects_file_outside_activity_allowlist(): void {
        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(1);

        try {
            $this->report($fixture['cmid'], $fixture['user'], 1, [1], 'another-course.pdf');
            $this->fail('Progress for a PDF outside this activity was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfileinvalid', $e->errorcode);
        }
    }

    /**
     * Completion mode one requires a completed row for every PDF in the activity.
     */
    public function test_completion_mode_one_requires_both_files(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(1, 1);
        $this->store_document($fixture['cmid'], $this->pdf(1), 'second.pdf', false);

        $first = $this->report($fixture['cmid'], $fixture['user'], 1, [1], 'doc.pdf');
        $this->assertTrue($first['complete']);
        $this->assertFalse($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $fixture['cmid'],
            'userid' => $fixture['user']->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));

        $second = $this->report($fixture['cmid'], $fixture['user'], 1, [1], 'second.pdf');
        $this->assertTrue($second['complete']);
        $this->assertTrue($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $fixture['cmid'],
            'userid' => $fixture['user']->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }

    /**
     * Completion mode two is met by reading either one of two PDFs.
     */
    public function test_completion_mode_two_accepts_either_file(): void {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $this->resetAfterTest();
        $fixture = $this->create_activity_and_reader(1, 2);
        $this->store_document($fixture['cmid'], $this->pdf(1), 'second.pdf', false);

        $result = $this->report($fixture['cmid'], $fixture['user'], 1, [1], 'second.pdf');
        $this->assertTrue($result['complete']);
        $this->assertTrue($DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $fixture['cmid'],
            'userid' => $fixture['user']->id,
            'completionstate' => COMPLETION_COMPLETE,
        ]));
    }
}
