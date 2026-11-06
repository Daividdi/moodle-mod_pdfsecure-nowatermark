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
 * Assigns legacy single-file progress rows to the activity's stored filename.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class progress_migration {

    /**
     * Fills empty progress keys from the existing single content file.
     *
     * @param int|null $activityid Restrict migration to one activity when restoring an older backup.
     * @return void
     */
    public static function fill_filekeys(?int $activityid = null): void {
        global $DB;

        $conditions = 'p.filekey = :emptykey';
        $params = ['modname' => 'pdfsecure', 'emptykey' => ''];
        if ($activityid !== null) {
            $conditions .= ' AND p.pdfsecureid = :activityid';
            $params['activityid'] = $activityid;
        }
        $rows = $DB->get_records_sql(
            "SELECT p.id, p.pdfsecureid, cm.id AS cmid
               FROM {pdfsecure_progress} p
               JOIN {course_modules} cm ON cm.instance = p.pdfsecureid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
              WHERE $conditions
              ORDER BY p.id",
            $params
        );
        foreach ($rows as $row) {
            $context = \context_module::instance((int)$row->cmid);
            $pdfs = content_files::get_pdfs($context);
            $file = reset($pdfs);
            if ($file) {
                $DB->set_field('pdfsecure_progress', 'filekey', $file->get_filename(), ['id' => $row->id]);
            }
        }
    }
}
