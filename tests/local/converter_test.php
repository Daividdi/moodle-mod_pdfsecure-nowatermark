<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_pdfsecure\local;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../lib.php');

/**
 * Tests enforcement and automatic conversion of PDFs from supported activities.
 *
 * @package    mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_pdfsecure\local\converter
 */
final class converter_test extends \advanced_testcase {

    /**
     * Enable onlysecure and use an upload-capable account for each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('onlysecure', 1, 'mod_pdfsecure');
        set_config('enableavailability', 1);
    }

    /**
     * Validation rejects PDFs from File and Folder forms, but permits other files.
     */
    public function test_coursemodule_validation_rejects_file_and_folder_pdfs(): void {
        global $USER;

        $fs = get_file_storage();
        $draftcontext = \context_user::instance($USER->id);
        foreach (['resource', 'folder'] as $modulename) {
            $draftitemid = file_get_unused_draft_itemid();
            $fs->create_file_from_string([
                'contextid' => $draftcontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $modulename . '-document.pdf',
            ], "%PDF-1.4\nPDF test content");

            $errors = \pdfsecure_coursemodule_validation(null, [
                'modulename' => $modulename,
                'files' => $draftitemid,
            ]);
            $this->assertArrayHasKey('files', $errors);
            $this->assertSame(get_string('onlysecurevalidation', 'mod_pdfsecure'), $errors['files']);
        }

        $draftitemid = file_get_unused_draft_itemid();
        $fs->create_file_from_string([
            'contextid' => $draftcontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'notes.txt',
        ], 'plain text');
        $this->assertSame([], \pdfsecure_coursemodule_validation(null, [
            'modulename' => 'resource',
            'files' => $draftitemid,
        ]));

        $pdfdraftid = file_get_unused_draft_itemid();
        $fs->create_file_from_string([
            'contextid' => $draftcontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $pdfdraftid,
            'filepath' => '/',
            'filename' => 'allowed-when-disabled.pdf',
        ], "%PDF-1.4\nPDF test content");
        set_config('onlysecure', 0, 'mod_pdfsecure');
        $this->assertSame([], \pdfsecure_coursemodule_validation(null, [
            'modulename' => 'resource',
            'files' => $pdfdraftid,
        ]));
    }

    /**
     * File conversion preserves restrictions and completion dependencies.
     */
    public function test_resource_conversion_preserves_dependencies_and_completion(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $trigger = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $resource = $this->getDataGenerator()->create_module('resource', [
            'course' => $course->id,
            'name' => 'Restricted PDF',
            'intro' => 'Original introduction',
            'introformat' => FORMAT_HTML,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $resourcecm = $DB->get_record('course_modules', ['id' => $resource->cmid], '*', MUST_EXIST);
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'completion', 'cm' => $trigger->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $resource->cmid]);
        $DB->set_field('course_modules', 'groupmode', 1, ['id' => $resource->cmid]);
        $DB->set_field('course_modules', 'groupingid', 0, ['id' => $resource->cmid]);
        $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $resource->cmid]);

        $sourcefile = $this->create_module_file($resource->cmid, 'mod_resource', 'content', 'restricted.pdf', 0);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $resource->cmid,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE_PASS,
            'viewed' => 1,
            'timemodified' => time(),
        ]);
        $criterionid = $DB->insert_record('course_completion_criteria', (object)[
            'course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'resource',
            'moduleinstance' => $resource->cmid,
        ]);

        $dependent = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $dependentavailability = json_encode([
            'op' => '&',
            'c' => [['type' => 'completion', 'cm' => $resource->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $dependentavailability, ['id' => $dependent->cmid]);

        $this->setUser($user);
        $completioninfo = new \completion_info($course);
        $beforeconversion = $completioninfo->get_data($resourcecm, false, $user->id);
        $this->assertSame(COMPLETION_COMPLETE_PASS, (int)$beforeconversion->completionstate);
        $this->setAdminUser();

        $result = converter::convert_coursemodule($resource->cmid, true);
        $this->assertSame('converted', $result['status']);
        $newcm = $DB->get_record('course_modules', ['idnumber' => 'migrado-resource-' . $resource->cmid], '*', MUST_EXIST);
        $newinstance = $DB->get_record('pdfsecure', ['id' => $newcm->instance], '*', MUST_EXIST);
        $newcontext = \context_module::instance($newcm->id);
        $copy = get_file_storage()->get_file($newcontext->id, 'mod_pdfsecure', 'content', 0, '/', 'restricted.pdf');

        $this->assertNotFalse($copy);
        $this->assertSame($sourcefile->get_contenthash(), $copy->get_contenthash());
        $this->assertSame('Restricted PDF', $newinstance->name);
        $this->assertSame('Original introduction', $newinstance->intro);
        $this->assertSame($availability, $newcm->availability);
        $this->assertSame(0, (int)$newcm->groupmode);
        $this->assertSame(0, (int)$newcm->groupingid);
        $this->assertSame(0, (int)$newcm->visibleoncoursepage);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int)$newcm->completion);
        $this->assertSame(1, (int)$newinstance->completionreadall);
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $resource->cmid]));

        $section = $DB->get_record('course_sections', ['id' => $resourcecm->section], '*', MUST_EXIST);
        $sequence = explode(',', (string)$section->sequence);
        $originalposition = array_search((string)$resource->cmid, $sequence, true);
        $this->assertSame((string)$newcm->id, $sequence[$originalposition + 1]);

        $criterion = $DB->get_record('course_completion_criteria', ['id' => $criterionid], '*', MUST_EXIST);
        $this->assertSame('pdfsecure', $criterion->module);
        $this->assertSame((int)$newcm->id, (int)$criterion->moduleinstance);
        $completion = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $newcm->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);
        $this->assertSame(COMPLETION_COMPLETE_PASS, (int)$completion->completionstate);

        $this->setUser($user);
        $afterconversion = (new \completion_info($course))->get_data($newcm, false, $user->id);
        $this->assertSame(COMPLETION_COMPLETE_PASS, (int)$afterconversion->completionstate);

        $updatedavailability = json_decode($DB->get_field('course_modules', 'availability', ['id' => $dependent->cmid]));
        $this->assertSame((int)$newcm->id, (int)$updatedavailability->c[0]->cm);
        // the original keeps its own completion setting: it is hidden, never changed
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int)$DB->get_field('course_modules', 'completion', ['id' => $resource->cmid]));
    }

    /**
     * A mixed Folder produces one activity per PDF, remains visible, and is idempotent.
     */
    public function test_folder_conversion_keeps_mixed_folder_visible_and_second_run_is_noop(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', [
            'course' => $course->id,
            'name' => 'Course pack',
        ]);
        $first = $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'first.pdf', 0);
        $second = $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'second.pdf', 0);
        $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'video.mp4', 0);

        $result = converter::convert_coursemodule($folder->cmid, true);
        $this->assertSame('converted-folder-retained', $result['status']);
        $this->assertSame(2, (int)$result['count']);
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $folder->cmid]));

        $firstcm = $DB->get_record('course_modules', ['idnumber' => 'migrado-folder-' . $folder->cmid], '*', MUST_EXIST);
        $secondcm = $DB->get_record('course_modules', ['idnumber' => 'migrado-folder-' . $folder->cmid . '-2'], '*', MUST_EXIST);
        $firstcopy = get_file_storage()->get_file(\context_module::instance($firstcm->id)->id,
            'mod_pdfsecure', 'content', 0, '/', 'first.pdf');
        $secondcopy = get_file_storage()->get_file(\context_module::instance($secondcm->id)->id,
            'mod_pdfsecure', 'content', 0, '/', 'second.pdf');
        $this->assertSame($first->get_contenthash(), $firstcopy->get_contenthash());
        $this->assertSame($second->get_contenthash(), $secondcopy->get_contenthash());

        $folderfiles = get_file_storage()->get_area_files(
            \context_module::instance($folder->cmid)->id,
            'mod_folder',
            'content',
            0,
            'sortorder',
            false
        );
        $this->assertSame(['video.mp4'], array_values(array_map(function($file) {
            return $file->get_filename();
        }, $folderfiles)));

        $section = $DB->get_record('course_sections', ['id' => $DB->get_field('course_modules', 'section', ['id' => $folder->cmid])], '*', MUST_EXIST);
        $sequence = explode(',', (string)$section->sequence);
        $folderposition = array_search((string)$folder->cmid, $sequence, true);
        $this->assertSame((string)$firstcm->id, $sequence[$folderposition + 1]);
        $this->assertSame((string)$secondcm->id, $sequence[$folderposition + 2]);

        $before = $DB->count_records('course_modules', ['course' => $course->id]);
        $again = converter::convert_coursemodule($folder->cmid, true);
        $after = $DB->count_records('course_modules', ['course' => $course->id]);
        $this->assertSame('no-pdf', $again['status']);
        $this->assertSame(0, (int)$again['created']);
        $this->assertSame($before, $after);
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $folder->cmid]));
    }

    /**
     * A later teacher upload in PDF Secure is never replaced by the hidden File's older PDF.
     */
    public function test_resource_conversion_preserves_teacher_replacement_on_second_run(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->create_module_file($resource->cmid, 'mod_resource', 'content', 'original.pdf', 0);
        converter::convert_coursemodule($resource->cmid, true);

        $target = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'migrado-resource-' . $resource->cmid,
        ], '*', MUST_EXIST);
        $context = \context_module::instance($target->id);
        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'mod_pdfsecure', 'content', 0);
        $teacherfile = $this->create_module_file($target->id, 'mod_pdfsecure', 'content', 'teacher-update.pdf', 0);
        $before = $DB->count_records('course_modules', ['course' => $course->id]);

        $result = converter::convert_coursemodule($resource->cmid, true);

        $stored = $fs->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
        $this->assertSame('already-converted', $result['status']);
        $this->assertCount(1, $stored);
        $this->assertSame($teacherfile->get_contenthash(), reset($stored)->get_contenthash());
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * Course-module deletion excludes the activity from validation, queueing and conversion.
     */
    public function test_deletion_in_progress_source_is_skipped(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $this->create_module_file($resource->cmid, 'mod_resource', 'content', 'deleting.pdf', 0);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $resource->cmid]);

        $this->assertFalse(converter::has_pdf($resource->cmid));
        $this->assertNotContains($resource->cmid, converter::candidate_coursemodule_ids('resource'));
        $this->assertSame('deleting', converter::convert_coursemodule($resource->cmid, true)['status']);
    }

    /**
     * Reuse the matching activity in the same course and ignore matching markers in other courses.
     */
    public function test_resource_conversion_adopts_same_course_file_without_duplication(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $resource = $this->getDataGenerator()->create_module('resource', ['course' => $course->id]);
        $sourcefile = $this->create_module_file($resource->cmid, 'mod_resource', 'content', 'adopt.pdf', 0);
        $marker = 'migrado-resource-' . $resource->cmid;

        $othercourse = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $othercourse->id]);
        $DB->set_field('course_modules', 'idnumber', $marker, ['id' => $foreign->cmid]);
        $this->create_module_file($foreign->cmid, 'mod_pdfsecure', 'content', 'adopt.pdf', 0);

        $matching = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        $this->create_module_file($matching->cmid, 'mod_pdfsecure', 'content', 'adopt.pdf', 0);
        $before = $DB->count_records('course_modules', ['course' => $course->id]);

        $result = converter::convert_coursemodule($resource->cmid, true);

        $this->assertSame(0, (int)$result['created']);
        $this->assertSame((int)$matching->cmid, (int)$result['newcmid']);
        $this->assertSame($marker, $DB->get_field('course_modules', 'idnumber', ['id' => $matching->cmid]));
        $this->assertSame($sourcefile->get_contenthash(), get_file_storage()->get_file(
            \context_module::instance($matching->cmid)->id,
            'mod_pdfsecure',
            'content',
            0,
            '/',
            'adopt.pdf'
        )->get_contenthash());
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $course->id]));
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $resource->cmid]));
    }

    /**
     * A mixed Folder keeps a teacher-edited target and moves any remaining source PDF to a protected copy.
     */
    public function test_mixed_folder_preserves_edited_target_and_removes_plain_source_pdf(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id, 'name' => 'Materials']);
        $source = $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'handout.pdf', 0);
        $sourcehash = $source->get_contenthash();
        $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'clip.mp4', 0);
        converter::convert_coursemodule($folder->cmid, true);

        $primary = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'migrado-folder-' . $folder->cmid,
        ], '*', MUST_EXIST);
        $primarycontext = \context_module::instance($primary->id);
        $fs = get_file_storage();
        $fs->delete_area_files($primarycontext->id, 'mod_pdfsecure', 'content', 0);
        $teacherfile = $this->create_module_file($primary->id, 'mod_pdfsecure', 'content', 'revised.pdf', 0);

        // Recreate the legacy mixed-Folder state where the plain source remains after an earlier conversion.
        $this->create_module_file($folder->cmid, 'mod_folder', 'content', 'handout.pdf', 0);
        $result = converter::convert_coursemodule($folder->cmid, true);

        $fallback = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'migrado-folder-' . $folder->cmid . '-source',
        ], '*', MUST_EXIST);
        $teacherfiles = $fs->get_area_files($primarycontext->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
        $fallbackfiles = $fs->get_area_files(
            \context_module::instance($fallback->id)->id,
            'mod_pdfsecure',
            'content',
            0,
            'sortorder',
            false
        );
        $folderfiles = $fs->get_area_files(
            \context_module::instance($folder->cmid)->id,
            'mod_folder',
            'content',
            0,
            'sortorder',
            false
        );

        $this->assertSame('converted-folder-retained', $result['status']);
        $this->assertSame($teacherfile->get_contenthash(), reset($teacherfiles)->get_contenthash());
        $this->assertSame($sourcehash, reset($fallbackfiles)->get_contenthash());
        $this->assertSame(['clip.mp4'], array_values(array_map(function($file) {
            return $file->get_filename();
        }, $folderfiles)));
        $this->assertSame(1, (int)$DB->get_field('course_modules', 'visible', ['id' => $folder->cmid]));
    }

    /**
     * Convert pdfprotect with the File rules when that third-party module is installed.
     */
    public function test_pdfprotect_conversion_when_module_is_installed(): void {
        global $DB;

        if (!$DB->record_exists('modules', ['name' => 'pdfprotect'])
                || !$DB->get_manager()->table_exists('pdfprotect')) {
            $this->markTestSkipped('mod_pdfprotect is not installed.');
        }

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $source = $this->getDataGenerator()->create_module('pdfprotect', [
            'course' => $course->id,
            'name' => 'Protected source',
            'intro' => 'Source description',
        ]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_AUTOMATIC, ['id' => $source->cmid]);
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'completion', 'cm' => $source->cmid, 'e' => COMPLETION_COMPLETE]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $source->cmid]);
        $sourcefile = $this->create_module_file(
            $source->cmid,
            'mod_pdfprotect',
            'documents',
            'protected-source.pdf',
            $source->id
        );
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('course_modules_completion', (object)[
            'coursemoduleid' => $source->cmid,
            'userid' => $user->id,
            'completionstate' => COMPLETION_COMPLETE,
            'viewed' => 1,
            'timemodified' => time(),
        ]);

        $result = converter::convert_coursemodule($source->cmid, true);

        $target = $DB->get_record('course_modules', [
            'course' => $course->id,
            'idnumber' => 'migrado-pdfprotect-' . $source->cmid,
        ], '*', MUST_EXIST);
        $targetinstance = $DB->get_record('pdfsecure', ['id' => $target->instance], '*', MUST_EXIST);
        $targetfile = get_file_storage()->get_area_files(
            \context_module::instance($target->id)->id,
            'mod_pdfsecure',
            'content',
            0,
            'sortorder',
            false
        );
        $completion = $DB->get_record('course_modules_completion', [
            'coursemoduleid' => $target->id,
            'userid' => $user->id,
        ], '*', MUST_EXIST);

        $this->assertSame('converted', $result['status']);
        $this->assertSame('Protected source', $targetinstance->name);
        $this->assertSame('Source description', $targetinstance->intro);
        $this->assertSame($availability, $target->availability);
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, (int)$target->completion);
        $this->assertSame(1, (int)$targetinstance->completionreadall);
        $this->assertSame($sourcefile->get_contenthash(), reset($targetfile)->get_contenthash());
        $this->assertSame(COMPLETION_COMPLETE, (int)$completion->completionstate);
        $this->assertSame(0, (int)$DB->get_field('course_modules', 'visible', ['id' => $source->cmid]));
    }

    /**
     * Drag-and-drop stores a PDF in the protected file area.
     */
    public function test_dndupload_handler_stores_the_dropped_pdf(): void {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $placeholder = $this->getDataGenerator()->create_module('pdfsecure', ['course' => $course->id]);
        pdfsecure_delete_instance($placeholder->id);
        $DB->set_field('course_modules', 'instance', 0, ['id' => $placeholder->cmid]);

        $draftitemid = file_get_unused_draft_itemid();
        $draftcontext = \context_user::instance($USER->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $draftcontext->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'dropped.pdf',
        ], "%PDF-1.4\nDrag and drop PDF");

        $instanceid = \pdfsecure_dndupload_handle((object)[
            'course' => $course,
            'displayname' => 'Dropped document',
            'coursemodule' => $placeholder->cmid,
            'draftitemid' => $draftitemid,
        ]);
        $DB->set_field('course_modules', 'instance', $instanceid, ['id' => $placeholder->cmid]);

        $file = get_file_storage()->get_file(\context_module::instance($placeholder->cmid)->id,
            'mod_pdfsecure', 'content', 0, '/', 'dropped.pdf');
        $this->assertNotFalse($file);
        $this->assertSame($instanceid, (int)$DB->get_field('course_modules', 'instance', ['id' => $placeholder->cmid]));
    }

    /**
     * Store a file in a module's content file area.
     *
     * @param int $cmid Course module id.
     * @param string $component File component.
     * @param string $filearea File area.
     * @param string $filename Filename.
     * @param int $itemid File area item id.
     * @return \stored_file
     */
    private function create_module_file(int $cmid, string $component, string $filearea, string $filename,
            int $itemid): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cmid)->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => $itemid,
            'filepath' => '/',
            'filename' => $filename,
        ], 'fixture content for ' . $filename);
    }
}
