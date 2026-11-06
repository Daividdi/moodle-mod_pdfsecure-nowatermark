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
 * Tests migration of legacy single-file progress to filename keys.
 *
 * @package mod_pdfsecure
 * @covers \mod_pdfsecure\local\progress_migration
 */
final class progress_migration_test extends \advanced_testcase {

    /**
     * Upgrade migration assigns legacy progress to the existing content filename.
     */
    public function test_upgrade_fills_legacy_filekey_from_activity_content(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('pdfsecure_progress', (object)[
            'pdfsecureid' => $activity->id,
            'userid' => $user->id,
            'pages' => 3,
            'seen' => '1,2,3',
            'timemodified' => time(),
            'timecompleted' => time(),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($activity->cmid)->id,
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'legacy.pdf',
        ], "%PDF-1.4\nlegacy fixture");

        progress_migration::fill_filekeys();

        $progress = $DB->get_record('pdfsecure_progress', [
            'pdfsecureid' => $activity->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);
        $this->assertSame('legacy.pdf', $progress->filekey);
        $this->assertSame('1,2,3', $progress->seen);
    }
}
