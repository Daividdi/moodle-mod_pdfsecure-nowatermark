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
 * Tests resolution of user-supplied document keys against activity content.
 *
 * @package mod_pdfsecure
 * @covers \mod_pdfsecure\local\content_files
 */
final class content_files_test extends \advanced_testcase {

    /**
     * A file query from the view cannot resolve another activity's document name.
     */
    public function test_view_file_allowlist_rejects_unknown_file(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        $context = \context_module::instance($activity->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'owned.pdf',
        ], '%PDF');

        try {
            content_files::resolve($context, 'not-owned.pdf');
            $this->fail('A filename outside this activity was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorfileinvalid', $e->errorcode);
        }
    }
}
