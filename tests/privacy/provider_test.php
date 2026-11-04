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
 * Tests privacy API behaviour for PDF Secure.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\privacy;

use core_privacy\local\request\writer;


#[\PHPUnit\Framework\Attributes\CoversClass(\mod_pdfsecure\privacy\provider::class)]
/**
 * Tests export and deletion of PDF Secure reading progress and derivatives.
 * @covers     \mod_pdfsecure\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Resets the Moodle fixture database for each privacy test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Creates a module context and user progress row for privacy tests.
     *
     * @return array Fixture records and context.
     */
    private function create_progress_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('pdfsecure', $activity->id, $course->id);
        $context = \context_module::instance($cm->id);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $activity->id,
            'userid' => $user->id,
            'pages' => 20,
            'seen' => '1,2,20',
            'timemodified' => time(),
            'timecompleted' => null,
        ]);

        return ['activity' => $activity, 'context' => $context, 'user' => $user];
    }

    /**
     * Creates a per-user file in the legacy watermarked derivative area.
     *
     * @param \context_module $context Module context.
     * @param int $userid User ID used as the file item ID.
     */
    private function create_watermarked_file(\context_module $context, int $userid): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_pdfsecure',
            'filearea' => 'watermarked',
            'itemid' => $userid,
            'filepath' => '/',
            'filename' => 'copy.pdf',
            'mimetype' => 'application/pdf',
        ], 'test derivative');
    }

    /**
     * Exports progress by context, then deletes the user's row and derivative.
     */
    public function test_export_and_delete_user_data(): void {
        global $DB;

        $fixture = $this->create_progress_fixture();
        $context = $fixture['context'];
        $user = $fixture['user'];
        $this->create_watermarked_file($context, $user->id);

        $contexts = provider::get_contexts_for_userid($user->id);
        $this->assertContains($context->id, array_map('intval', $contexts->get_contextids()));
        $this->export_context_data_for_user($user->id, $context, 'mod_pdfsecure');
        $writer = writer::with_context($context);
        $exported = $writer->get_data([get_string('privacy:exportpath', 'mod_pdfsecure')]);
        $this->assertSame('1,2,20', $exported->seen);
        $files = $writer->get_files([get_string('privacy:watermarkedpath', 'mod_pdfsecure')]);
        $this->assertCount(1, $files);

        $approvedcontextlist = new \core_privacy\tests\request\approved_contextlist(
            $user,
            'mod_pdfsecure',
            [$context->id]
        );
        provider::delete_data_for_user($approvedcontextlist);

        $this->assertFalse($DB->record_exists('pdfsecure_progress', [
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $user->id,
        ]));
        $this->assertFalse(get_file_storage()->get_file(
            $context->id,
            'mod_pdfsecure',
            'watermarked',
            $user->id,
            '/',
            'copy.pdf'
        ));
    }

    /**
     * Finds and deletes selected users from a module user list.
     */
    public function test_userlist_deletion_is_limited_to_approved_users(): void {
        global $DB;

        $fixture = $this->create_progress_fixture();
        $otheruser = $this->getDataGenerator()->create_user();
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $otheruser->id,
            'pages' => 10,
            'seen' => '1',
            'timemodified' => time(),
            'timecompleted' => null,
        ]);
        $this->create_watermarked_file($fixture['context'], $fixture['user']->id);
        $this->create_watermarked_file($fixture['context'], $otheruser->id);

        $userlist = new \core_privacy\local\request\userlist($fixture['context'], 'mod_pdfsecure');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $expected = [(int)$fixture['user']->id, (int)$otheruser->id];
        sort($expected);
        $this->assertSame($expected, $userids);

        $approveduserlist = new \core_privacy\local\request\approved_userlist(
            $fixture['context'],
            'mod_pdfsecure',
            [$fixture['user']->id]
        );
        provider::delete_data_for_users($approveduserlist);

        $this->assertFalse($DB->record_exists('pdfsecure_progress', [
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $fixture['user']->id,
        ]));
        $this->assertTrue($DB->record_exists('pdfsecure_progress', [
            'pdfsecureid' => $fixture['activity']->id,
            'userid' => $otheruser->id,
        ]));
        $this->assertFalse(get_file_storage()->get_file(
            $fixture['context']->id,
            'mod_pdfsecure',
            'watermarked',
            $fixture['user']->id,
            '/',
            'copy.pdf'
        ));
        $this->assertNotFalse(get_file_storage()->get_file(
            $fixture['context']->id,
            'mod_pdfsecure',
            'watermarked',
            $otheruser->id,
            '/',
            'copy.pdf'
        ));
    }

    /**
     * Deletes all users' progress and derivatives from a module context.
     */
    public function test_context_deletion_removes_all_data(): void {
        global $DB;

        $fixture = $this->create_progress_fixture();
        $this->create_watermarked_file($fixture['context'], $fixture['user']->id);

        provider::delete_data_for_all_users_in_context($fixture['context']);

        $this->assertFalse($DB->record_exists('pdfsecure_progress', [
            'pdfsecureid' => $fixture['activity']->id,
        ]));
        $this->assertCount(0, get_file_storage()->get_area_files(
            $fixture['context']->id,
            'mod_pdfsecure',
            'watermarked'
        ));
    }
}
