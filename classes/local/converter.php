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
 * Converts PDFs from supported Moodle activities to PDF Secure.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class converter {

    /**
     * Whether automatic PDF Secure enforcement is enabled.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return !empty(get_config('mod_pdfsecure', 'onlysecure'));
    }

    /**
     * Queue conversion of a supported course module if it currently contains a PDF.
     *
     * @param int $cmid Course module id.
     * @return void
     */
    public static function queue_coursemodule(int $cmid): void {
        if (!self::is_enabled() || !self::has_pdf($cmid)) {
            return;
        }

        $task = new \mod_pdfsecure\task\convert_plain_pdfs();
        $task->set_component('mod_pdfsecure');
        $task->set_custom_data(['cmid' => $cmid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Check whether a course module is a supported source activity with a PDF.
     *
     * @param int $cmid Course module id.
     * @return bool
     */
    public static function has_pdf(int $cmid): bool {
        global $DB;

        $cm = $DB->get_record_sql(
            'SELECT cm.id, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND cm.deletioninprogress = 0',
            ['cmid' => $cmid]
        );
        if (!$cm || !in_array($cm->modulename, ['resource', 'folder', 'pdfprotect'], true)) {
            return false;
        }

        return !empty(self::source_files($cmid, $cm->modulename));
    }

    /**
     * Convert one supported source activity, or preview without writing.
     *
     * $force is reserved for an explicit administrator invocation of the migration
     * tool. Events, adhoc tasks and the scheduled sweep use the default setting gate.
     *
     * @param int $cmid Course module id.
     * @param bool $force Run even when onlysecure is disabled.
     * @param bool $dryrun Report the work without changing the course.
     * @return array Result summary.
     */
    public static function convert_coursemodule(int $cmid, bool $force = false, bool $dryrun = false): array {
        global $CFG, $DB;

        if (!$force && !self::is_enabled()) {
            return ['status' => 'disabled', 'created' => 0];
        }

        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/lib/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria.php');

        $cm = $DB->get_record_sql(
            'SELECT cm.*, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid',
            ['cmid' => $cmid]
        );
        if (!$cm || !in_array($cm->modulename, ['resource', 'folder', 'pdfprotect'], true)) {
            return ['status' => 'not-found', 'created' => 0];
        }
        if (!empty($cm->deletioninprogress)) {
            return ['status' => 'deleting', 'created' => 0];
        }
        if ($cm->modulename === 'pdfprotect' && !$DB->get_manager()->table_exists('pdfprotect')) {
            return ['status' => 'source-unavailable', 'created' => 0];
        }

        $instance = $DB->get_record($cm->modulename, ['id' => $cm->instance], 'id, name, intro, introformat');
        if (!$instance) {
            return ['status' => 'not-found', 'created' => 0];
        }
        $cm->name = $instance->name;
        $cm->intro = $instance->intro;
        $cm->introformat = $instance->introformat;

        $files = self::source_files($cmid, $cm->modulename);
        if (!$files) {
            return ['status' => 'no-pdf', 'created' => 0];
        }

        if ($cm->modulename === 'resource') {
            return self::convert_resource($cm, reset($files), $dryrun);
        } else if ($cm->modulename === 'pdfprotect') {
            return self::convert_single_file($cm, reset($files), 'migrado-pdfprotect-' . $cm->id, $dryrun);
        }
        return self::convert_folder($cm, $files, $dryrun);
    }

    /**
     * Return candidate source activities that contain PDF-like files.
     *
     * @param string|null $type Optional resource, folder or pdfprotect filter.
     * @param int $courseid Optional course filter.
     * @return int[] Course module ids.
     */
    public static function candidate_coursemodule_ids(?string $type = null, int $courseid = 0): array {
        global $DB;

        if ($type !== null && !in_array($type, ['resource', 'folder', 'pdfprotect'], true)) {
            return [];
        }

        $conditions = [];
        if ($type === null || $type === 'resource') {
            $conditions[] = "(m.name = 'resource' AND f.component = 'mod_resource' AND f.filearea = 'content'"
                . " AND f.filename <> '.' AND (f.mimetype = :pdfmime_resource OR LOWER(f.filename) LIKE :pdfsuffix_resource))";
        }
        if ($type === null || $type === 'folder') {
            $conditions[] = "(m.name = 'folder' AND f.component = 'mod_folder' AND f.filearea = 'content'"
                . " AND f.filename <> '.' AND (f.mimetype = :pdfmime_folder OR LOWER(f.filename) LIKE :pdfsuffix_folder))";
        }
        if ($type === null || $type === 'pdfprotect') {
            $conditions[] = "(m.name = 'pdfprotect' AND f.component = 'mod_pdfprotect' AND f.filesize > 0"
                . " AND LOWER(f.filename) LIKE :pdfsuffix_pdfprotect)";
        }
        if (!$conditions) {
            return [];
        }
        $coursecondition = $courseid ? ' AND cm.course = :courseid' : '';
        $params = ['contextlevel' => CONTEXT_MODULE];
        if ($type === null || $type === 'resource') {
            $params['pdfmime_resource'] = 'application/pdf';
            $params['pdfsuffix_resource'] = '%.pdf';
        }
        if ($type === null || $type === 'folder') {
            $params['pdfmime_folder'] = 'application/pdf';
            $params['pdfsuffix_folder'] = '%.pdf';
        }
        if ($type === null || $type === 'pdfprotect') {
            $params['pdfsuffix_pdfprotect'] = '%.pdf';
        }
        if ($courseid) {
            $params['courseid'] = $courseid;
        }

        $ids = $DB->get_fieldset_sql(
            "SELECT DISTINCT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
               JOIN {files} f ON f.contextid = ctx.id
              WHERE cm.deletioninprogress = 0
                AND (" . implode(' OR ', $conditions) . "){$coursecondition}",
            $params
        );

        return array_map('intval', $ids);
    }

    /**
     * Sweep for supported source PDFs missed by event observers.
     *
     * @return int Number of course modules processed.
     */
    public static function sweep(): int {
        if (!self::is_enabled()) {
            return 0;
        }

        $processed = 0;
        foreach (self::candidate_coursemodule_ids() as $cmid) {
            self::convert_coursemodule($cmid);
            $processed++;
        }
        return $processed;
    }

    /**
     * Convert the first PDF in a File activity.
     *
     * @param \stdClass $cm Source course module.
     * @param \stored_file $source PDF.
     * @param bool $dryrun Whether to preview only.
     * @return array Result summary.
     */
    private static function convert_resource(\stdClass $cm, \stored_file $source, bool $dryrun): array {
        return self::convert_single_file($cm, $source, 'migrado-resource-' . $cm->id, $dryrun);
    }

    /**
     * Convert a single-file source using the File activity's completion and reference behavior.
     *
     * @param \stdClass $cm Source course module.
     * @param \stored_file $source PDF.
     * @param string $marker Idempotency marker.
     * @param bool $dryrun Whether to preview only.
     * @return array Result summary.
     */
    private static function convert_single_file(\stdClass $cm, \stored_file $source, string $marker,
            bool $dryrun): array {
        global $DB;

        $created = 0;
        $target = self::find_marked_activity($marker, (int)$cm->course);
        $adopted = false;
        if (!$target) {
            $target = self::find_activity_with_file((int)$cm->course, $source);
            $adopted = (bool)$target;
        }
        if ($dryrun) {
            return ['status' => $target ? 'finalize-or-preserve' : 'create', 'created' => $target ? 0 : 1];
        }

        if (!$target) {
            $target = self::create_activity($cm, $marker, $cm->name, true);
            $created = 1;
        } else {
            self::set_activity_marker((int)$target->id, $marker);
        }

        if (!$adopted && !(int)$cm->visible && self::target_has_file((int)$target->id)) {
            return ['status' => 'already-converted', 'created' => 0, 'newcmid' => (int)$target->id];
        }

        self::copy_and_confirm($source, (int)$target->id);
        self::position_after((int)$cm->id, (int)$target->id, (int)$cm->section);
        self::repoint_references((int)$cm->course, (int)$cm->id, (int)$target->id);
        $copiedusers = self::copy_completion((int)$cm->id, (int)$target->id);
        self::clear_completion_cache((int)$cm->course, $copiedusers);
        self::touch_search_index((int)$target->instance);
        set_coursemodule_visible((int)$cm->id, 0);
        rebuild_course_cache((int)$cm->course, true);

        return ['status' => 'converted', 'created' => $created, 'newcmid' => (int)$target->id];
    }

    /**
     * Convert PDFs in a Folder, keeping the Folder visible if anything remains.
     *
     * @param \stdClass $cm Source course module.
     * @param \stored_file[] $pdfs PDFs in display order.
     * @param bool $dryrun Whether to preview only.
     * @return array Result summary.
     */
    private static function convert_folder(\stdClass $cm, array $pdfs, bool $dryrun): array {
        $files = self::source_files((int)$cm->id, 'folder', false);
        $onlypdfs = count($files) === count($pdfs);
        $targets = [];
        $markers = [];
        $usedtargets = [];
        $hasadoptedtarget = false;
        $creates = 0;
        foreach (array_values($pdfs) as $index => $source) {
            $marker = 'migrado-folder-' . $cm->id . ($index ? '-' . ($index + 1) : '');
            $target = self::find_marked_activity($marker, (int)$cm->course);
            if (!$target) {
                $target = self::find_activity_with_file((int)$cm->course, $source, array_keys($usedtargets));
                $hasadoptedtarget = $hasadoptedtarget || (bool)$target;
            }
            if (!$target) {
                $creates++;
            } else {
                $usedtargets[(int)$target->id] = true;
            }

            // Keep an edited target intact, but protect the still-plain source PDF separately.
            if (!$onlypdfs && $target && self::target_has_file((int)$target->id)
                    && !self::target_matches_source($source, (int)$target->id)) {
                $fallbackmarker = $marker . '-source';
                $fallback = self::find_marked_activity($fallbackmarker, (int)$cm->course);
                $suffix = 2;
                while ($fallback && self::target_has_file((int)$fallback->id)
                        && !self::target_matches_source($source, (int)$fallback->id)) {
                    $fallbackmarker = $marker . '-source-' . $suffix++;
                    $fallback = self::find_marked_activity($fallbackmarker, (int)$cm->course);
                }
                if (!$fallback) {
                    $fallback = self::find_activity_with_file((int)$cm->course, $source, array_keys($usedtargets));
                    $hasadoptedtarget = $hasadoptedtarget || (bool)$fallback;
                }
                if (!$fallback) {
                    $creates++;
                } else {
                    $usedtargets[(int)$fallback->id] = true;
                }
                $target = $fallback;
                $marker = $fallbackmarker;
            }

            $markers[$index] = $marker;
            $targets[$index] = $target;
        }

        if ($dryrun) {
            return ['status' => 'create-or-preserve', 'created' => $creates, 'hide' => $onlypdfs];
        }

        $alreadycomplete = true;
        foreach ($targets as $target) {
            if (!$target || !self::target_has_file((int)$target->id)) {
                $alreadycomplete = false;
                break;
            }
        }
        if ($alreadycomplete && !$hasadoptedtarget) {
            foreach ($targets as $index => $target) {
                $marker = $markers[$index];
                self::set_activity_marker((int)$target->id, $marker);
            }
            if (!$onlypdfs) {
                $deleted = self::delete_confirmed_folder_pdfs($pdfs, $targets);
                $visibilitychanged = self::restore_folder_visibility($cm, $targets);
                if ($deleted || $visibilitychanged) {
                    rebuild_course_cache((int)$cm->course, true);
                }
                return ['status' => 'already-converted', 'created' => 0, 'count' => count($pdfs), 'hidden' => false];
            }
            if (!(int)$cm->visible) {
                return ['status' => 'already-converted', 'created' => 0, 'count' => count($pdfs), 'hidden' => true];
            }
        }

        $created = 0;
        $previous = (int)$cm->id;
        foreach (array_values($pdfs) as $index => $source) {
            $marker = $markers[$index];
            $target = $targets[$index];
            if (!$target) {
                $name = count($pdfs) > 1
                    ? $cm->name . ' — ' . pathinfo($source->get_filename(), PATHINFO_FILENAME)
                    : $cm->name;
                $target = self::create_activity($cm, $marker, $name, false);
                if (strpos($marker, '-source') !== false) {
                    // The Folder still held an older copy of a PDF a teacher has since replaced: keep it, hidden, so
                    // learners do not see two versions.
                    set_coursemodule_visible((int)$target->id, 0);
                }
                $created++;
            } else {
                self::set_activity_marker((int)$target->id, $marker);
            }

            self::copy_and_confirm($source, (int)$target->id);
            self::position_after($previous, (int)$target->id, (int)$cm->section);
            self::touch_search_index((int)$target->instance);
            $previous = (int)$target->id;
            $targets[$index] = $target;
        }

        if ($onlypdfs) {
            set_coursemodule_visible((int)$cm->id, 0);
        } else {
            self::delete_confirmed_folder_pdfs($pdfs, $targets);
            self::restore_folder_visibility($cm, $targets);
        }
        rebuild_course_cache((int)$cm->course, true);

        return [
            'status' => $onlypdfs ? 'converted' : 'converted-folder-retained',
            'created' => $created,
            'count' => count($pdfs),
            'hidden' => $onlypdfs,
        ];
    }

    /**
     * Create or return a PDF Secure activity matching the source's course settings.
     *
     * @param \stdClass $sourcecm Source course module.
     * @param string $marker Idempotency marker.
     * @param string $name New activity name.
     * @param bool $copycompletion Whether to carry the source completion behavior.
     * @return \stdClass New course module.
     */
    private static function create_activity(\stdClass $sourcecm, string $marker, string $name,
            bool $copycompletion): \stdClass {
        global $DB;

        $course = get_course((int)$sourcecm->course);
        $sectionnumber = $DB->get_field('course_sections', 'section', ['id' => $sourcecm->section], MUST_EXIST);
        $completionenabled = (new \completion_info($course))->is_enabled();
        $trackscompletion = $copycompletion && $completionenabled
            && (int)$sourcecm->completion !== COMPLETION_TRACKING_NONE;

        $module = $DB->get_field('modules', 'id', ['name' => 'pdfsecure'], MUST_EXIST);
        $moduleinfo = (object)[
            'modulename' => 'pdfsecure',
            'module' => $module,
            'course' => (int)$sourcecm->course,
            'section' => (int)$sectionnumber,
            'name' => $name,
            'intro' => $sourcecm->intro,
            'introformat' => $sourcecm->introformat,
            'visible' => (int)$sourcecm->visible,
            'visibleoncoursepage' => property_exists($sourcecm, 'visibleoncoursepage')
                ? (int)$sourcecm->visibleoncoursepage : 1,
            'showdescription' => (int)($sourcecm->showdescription ?? 0),
            'availability' => $sourcecm->availability,
            'groupmode' => 0,
            'groupingid' => 0,
            'cmidnumber' => $marker,
            'completion' => $trackscompletion ? COMPLETION_TRACKING_AUTOMATIC : COMPLETION_TRACKING_NONE,
            'completionview' => 0,
            'completionexpected' => $trackscompletion ? (int)$sourcecm->completionexpected : 0,
            'completionreadall' => $trackscompletion ? 1 : 0,
        ];

        $created = add_moduleinfo($moduleinfo, $course);
        $newcm = $DB->get_record('course_modules', ['id' => $created->coursemodule], '*', MUST_EXIST);
        return $newcm;
    }

    /**
     * Look up a generated activity by marker in the source course.
     *
     * @param string $marker Idempotency marker.
     * @param int $courseid Source course id.
     * @return \stdClass|null
     */
    private static function find_marked_activity(string $marker, int $courseid): ?\stdClass {
        global $DB;

        $cm = $DB->get_record('course_modules', [
            'idnumber' => $marker,
            'course' => $courseid,
            'deletioninprogress' => 0,
        ]);
        if (!$cm) {
            return null;
        }
        $name = $DB->get_field('modules', 'name', ['id' => $cm->module]);
        return $name === 'pdfsecure' ? $cm : null;
    }

    /**
     * Find a PDF Secure activity in the same course that already stores this file.
     *
     * @param int $courseid Course id.
     * @param \stored_file $source Source file.
     * @param int[] $exclude Course module ids already assigned in this conversion.
     * @return \stdClass|null
     */
    private static function find_activity_with_file(int $courseid, \stored_file $source, array $exclude = []): ?\stdClass {
        global $DB;

        $candidates = $DB->get_records_sql(
            "SELECT DISTINCT cm.*
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = 'pdfsecure'
               JOIN {context} ctx ON ctx.instanceid = cm.id AND ctx.contextlevel = :contextlevel
               JOIN {files} f ON f.contextid = ctx.id
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
                AND f.component = 'mod_pdfsecure' AND f.filearea = 'content' AND f.itemid = 0
                AND f.filename = :filename AND f.contenthash = :contenthash
           ORDER BY cm.id",
            [
                'contextlevel' => CONTEXT_MODULE,
                'courseid' => $courseid,
                'filename' => $source->get_filename(),
                'contenthash' => $source->get_contenthash(),
            ]
        );
        foreach ($candidates as $candidate) {
            if (!in_array((int)$candidate->id, $exclude, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Set the migration marker on an existing or adopted PDF Secure activity.
     *
     * @param int $cmid Course module id.
     * @param string $marker Idempotency marker.
     * @return void
     */
    private static function set_activity_marker(int $cmid, string $marker): void {
        global $DB;

        $DB->set_field('course_modules', 'idnumber', $marker, ['id' => $cmid]);
    }

    /**
     * Copy a source file into PDF Secure and confirm its content hash.
     *
     * @param \stored_file $source Source PDF.
     * @param int $targetcmid Target course module id.
     * @return \stored_file Confirmed copy.
     */
    private static function copy_and_confirm(\stored_file $source, int $targetcmid): \stored_file {
        $context = \context_module::instance($targetcmid);
        $fs = get_file_storage();
        $existing = $fs->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
        if ($existing) {
            // A teacher may have replaced the protected file after migration. Keep it.
            return reset($existing);
        }

        // An empty marked target is the repair path for a run interrupted before file storage.
        $copy = $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $source->get_filename(),
        ], $source);

        $confirmed = $fs->get_file($context->id, 'mod_pdfsecure', 'content', 0, '/', $source->get_filename());
        if (!$confirmed || $confirmed->get_contenthash() !== $source->get_contenthash()) {
            throw new \moodle_exception('errorcreatingactivity', 'moodle', '', 'pdfsecure');
        }
        return $copy;
    }

    /**
     * Whether an existing PDF Secure activity contains the exact source file.
     *
     * @param \stored_file $source Source PDF.
     * @param int $targetcmid Target course module id.
     * @return bool
     */
    private static function target_matches_source(\stored_file $source, int $targetcmid): bool {
        $context = \context_module::instance($targetcmid);
        $files = get_file_storage()->get_area_files($context->id, 'mod_pdfsecure', 'content', 0, 'sortorder', false);
        foreach ($files as $file) {
            if ($file->get_contenthash() === $source->get_contenthash()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a PDF Secure activity contains any stored file.
     *
     * @param int $targetcmid Course module id.
     * @return bool
     */
    private static function target_has_file(int $targetcmid): bool {
        $context = \context_module::instance($targetcmid);
        return (bool)get_file_storage()->get_area_files(
            $context->id,
            'mod_pdfsecure',
            'content',
            0,
            'sortorder',
            false
        );
    }

    /**
     * Delete the source PDFs only after every mixed-Folder PDF is present by hash in its target.
     *
     * @param \stored_file[] $pdfs Folder PDFs in display order.
     * @param \stdClass[] $targets Matching target course modules.
     * @return bool Whether all source PDFs were confirmed and removed.
     */
    private static function delete_confirmed_folder_pdfs(array $pdfs, array $targets): bool {
        foreach (array_values($pdfs) as $index => $source) {
            if (empty($targets[$index]) || !self::target_matches_source($source, (int)$targets[$index]->id)) {
                return false;
            }
        }
        foreach ($pdfs as $source) {
            $source->delete();
        }
        return true;
    }

    /**
     * Restore the Folder's original visibility if a previous PDF-only conversion hid it.
     *
     * @param \stdClass $cm Source Folder course module.
     * @param \stdClass[] $targets Converted PDF activities.
     * @return bool Whether visibility changed.
     */
    private static function restore_folder_visibility(\stdClass $cm, array $targets): bool {
        $visibilitysource = self::find_marked_activity(
            'migrado-folder-' . $cm->id,
            (int)$cm->course
        );
        if (!$visibilitysource) {
            $visibilitysource = $targets[0] ?? null;
        }
        if (!$visibilitysource) {
            return false;
        }
        $visible = (int)$visibilitysource->visible;
        if ((int)$cm->visible === $visible) {
            return false;
        }
        set_coursemodule_visible((int)$cm->id, $visible);
        return true;
    }

    /**
     * Move an activity immediately after another activity in the same section.
     *
     * @param int $predecessorcmid Existing course module.
     * @param int $targetcmid New PDF Secure course module.
     * @param int $sectionid Course section id.
     * @return void
     */
    private static function position_after(int $predecessorcmid, int $targetcmid, int $sectionid): void {
        global $DB;

        $section = $DB->get_record('course_sections', ['id' => $sectionid], '*', MUST_EXIST);
        $sequence = array_values(array_filter(explode(',', (string)$section->sequence), 'strlen'));
        $position = array_search((string)$predecessorcmid, $sequence, true);
        $nextcmid = ($position !== false && isset($sequence[$position + 1]))
            ? (int)$sequence[$position + 1] : 0;
        if (!$nextcmid || $nextcmid === $targetcmid) {
            return;
        }

        // Moodle 5.2 deprecates moveto_module() for the course format's actions; 4.5 has only the function.
        if (method_exists(\core_courseformat\local\cmactions::class, 'move_before')) {
            \core_courseformat\formatactions::cm((int)$section->course)->move_before($targetcmid, $nextcmid);
            return;
        }
        $target = get_coursemodule_from_id('pdfsecure', $targetcmid, 0, false, MUST_EXIST);
        $before = $DB->get_record('course_modules', ['id' => $nextcmid], '*', MUST_EXIST);
        moveto_module($target, $section, $before);
    }

    /**
     * Repoint course completion and availability references from a File to PDF Secure.
     *
     * @param int $courseid Course id.
     * @param int $oldcmid Original course module id.
     * @param int $newcmid Replacement course module id.
     * @return void
     */
    private static function repoint_references(int $courseid, int $oldcmid, int $newcmid): void {
        global $DB;

        $DB->execute(
            'UPDATE {course_completion_criteria}
                SET module = :modulename, moduleinstance = :newcmid
              WHERE course = :courseid AND criteriatype = :criteriatype AND moduleinstance = :oldcmid',
            [
                'modulename' => 'pdfsecure',
                'newcmid' => $newcmid,
                'courseid' => $courseid,
                'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
                'oldcmid' => $oldcmid,
            ]
        );

        foreach ($DB->get_records('course_modules', ['course' => $courseid]) as $cm) {
            if (in_array((int)$cm->id, [$oldcmid, $newcmid], true) || empty($cm->availability)) {
                continue;
            }
            $availability = json_decode($cm->availability);
            if (json_last_error() !== JSON_ERROR_NONE || !self::replace_availability_cmid($availability, $oldcmid, $newcmid)) {
                continue;
            }
            $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $cm->id]);
        }

        foreach ($DB->get_records('course_sections', ['course' => $courseid]) as $section) {
            if (empty($section->availability)) {
                continue;
            }
            $availability = json_decode($section->availability);
            if (json_last_error() !== JSON_ERROR_NONE || !self::replace_availability_cmid($availability, $oldcmid, $newcmid)) {
                continue;
            }
            $DB->set_field('course_sections', 'availability', json_encode($availability), ['id' => $section->id]);
        }
    }

    /**
     * Replace cm ids recursively in an availability structure.
     *
     * @param mixed $value Decoded availability data.
     * @param int $oldcmid Original course module id.
     * @param int $newcmid Replacement course module id.
     * @return bool Whether anything changed.
     */
    private static function replace_availability_cmid(&$value, int $oldcmid, int $newcmid): bool {
        $changed = false;
        if (is_object($value)) {
            foreach ($value as $key => &$child) {
                if ($key === 'cm' && is_numeric($child) && (int)$child === $oldcmid) {
                    $child = $newcmid;
                    $changed = true;
                } else if (self::replace_availability_cmid($child, $oldcmid, $newcmid)) {
                    $changed = true;
                }
            }
            unset($child);
        } else if (is_array($value)) {
            foreach ($value as &$child) {
                if (self::replace_availability_cmid($child, $oldcmid, $newcmid)) {
                    $changed = true;
                }
            }
            unset($child);
        }
        return $changed;
    }

    /**
     * Copy existing successful completion states to a replacement activity.
     *
     * @param int $oldcmid Original course module id.
     * @param int $newcmid Replacement course module id.
     * @return int[] User ids whose completion was copied.
     */
    private static function copy_completion(int $oldcmid, int $newcmid): array {
        global $DB;

        $copiedusers = [];
        foreach ($DB->get_records('course_modules_completion', ['coursemoduleid' => $oldcmid]) as $completion) {
            if (!in_array((int)$completion->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                continue;
            }
            $copy = clone $completion;
            $copy->coursemoduleid = $newcmid;
            $existing = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $newcmid,
                'userid' => $completion->userid,
            ]);
            if ($existing) {
                $copy->id = $existing->id;
                $DB->update_record('course_modules_completion', $copy);
            } else {
                unset($copy->id);
                $DB->insert_record('course_modules_completion', $copy);
            }
            $copiedusers[(int)$completion->userid] = (int)$completion->userid;
        }
        return array_values($copiedusers);
    }

    /**
     * Clear cached course completion for learners whose module completion was copied.
     *
     * Moodle caches get_data() under the key "userid_courseid" in core/completion.
     *
     * @param int $courseid Course id.
     * @param int[] $userids Users whose completion was copied.
     * @return void
     */
    private static function clear_completion_cache(int $courseid, array $userids): void {
        if (!$userids) {
            return;
        }
        $cache = \cache::make('core', 'completion');
        foreach ($userids as $userid) {
            $cache->delete((int)$userid . '_' . $courseid);
        }
    }

    /**
     * Set the instance timestamp so Global Search sees the copied old file.
     *
     * @param int $instanceid PDF Secure instance id.
     * @return void
     */
    private static function touch_search_index(int $instanceid): void {
        global $DB;

        $DB->set_field('pdfsecure', 'timemodified', time(), ['id' => $instanceid]);
    }

    /**
     * Get PDF files in a native activity's content area.
     *
     * @param int $cmid Course module id.
     * @param string $type Module name.
     * @param bool $pdfonly Return only PDFs.
     * @return \stored_file[]
     */
    private static function source_files(int $cmid, string $type, bool $pdfonly = true): array {
        global $DB;

        $context = \context_module::instance($cmid);
        if ($type === 'pdfprotect') {
            $records = $DB->get_records_select(
                'files',
                'contextid = :contextid AND component = :component AND filename <> :directory AND filesize > 0',
                [
                    'contextid' => $context->id,
                    'component' => 'mod_pdfprotect',
                    'directory' => '.',
                ],
                'filearea, itemid, sortorder, id'
            );
            $fs = get_file_storage();
            foreach ($records as $record) {
                if (strtolower(pathinfo($record->filename, PATHINFO_EXTENSION)) !== 'pdf') {
                    continue;
                }
                $file = $fs->get_file_by_id($record->id);
                if ($file) {
                    return [$file];
                }
            }
            return [];
        }

        $records = $DB->get_records_select(
            'files',
            'contextid = :contextid AND component = :component AND filearea = :filearea'
                . ' AND filename <> :directory',
            [
                'contextid' => $context->id,
                'component' => 'mod_' . $type,
                'filearea' => 'content',
                'directory' => '.',
            ],
            'filepath, filename, sortorder, id'
        );

        $files = [];
        $fs = get_file_storage();
        foreach ($records as $record) {
            $file = $fs->get_file_by_id($record->id);
            if (!$file || ($pdfonly && !self::is_pdf($file))) {
                continue;
            }
            $files[] = $file;
        }
        return $files;
    }

    /**
     * Determine PDF status using Moodle's stored MIME type and filename extension.
     *
     * @param \stored_file $file Stored file.
     * @return bool
     */
    private static function is_pdf(\stored_file $file): bool {
        return $file->get_mimetype() === 'application/pdf'
            || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf';
    }
}
