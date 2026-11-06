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
 * Privacy API provider for PDF Secure reading progress and derivatives.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as plugin_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\metadata\provider as metadata_provider;


/**
 * Privacy API implementation for PDF Secure reading progress and derivatives.
 */
class provider implements core_userlist_provider, metadata_provider, plugin_provider {
    /**
     * Describes the personal data stored by this plugin.
     *
     * @param collection $items Metadata collection to populate.
     * @return collection Updated collection.
     */
    public static function get_metadata(collection $items): collection {
        $items->add_database_table('pdfsecure_progress', [
            'pdfsecureid' => 'privacy:metadata:pdfsecure_progress:pdfsecureid',
            'userid' => 'privacy:metadata:pdfsecure_progress:userid',
            'filekey' => 'privacy:metadata:pdfsecure_progress:filekey',
            'pages' => 'privacy:metadata:pdfsecure_progress:pages',
            'seen' => 'privacy:metadata:pdfsecure_progress:seen',
            'timemodified' => 'privacy:metadata:pdfsecure_progress:timemodified',
            'timecompleted' => 'privacy:metadata:pdfsecure_progress:timecompleted',
        ], 'privacy:metadata:pdfsecure_progress');
        $items->add_subsystem_link('core_files', [], 'privacy:metadata:watermarked');

        return $items;
    }

    /**
     * Gets module contexts containing progress for a user.
     *
     * @param int $userid User ID.
     * @return contextlist Contexts containing this user's data.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {course_modules} cm ON cm.id = ctx.instanceid
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {pdfsecure_progress} progress ON progress.pdfsecureid = cm.instance
              WHERE ctx.contextlevel = :contextlevel AND progress.userid = :userid",
            [
                'modname' => 'pdfsecure',
                'contextlevel' => CONTEXT_MODULE,
                'userid' => $userid,
            ]
        );
        $contextlist->add_from_sql(
            "SELECT ctx.id
               FROM {context} ctx
               JOIN {files} f ON f.contextid = ctx.id
              WHERE ctx.contextlevel = :contextlevel
                AND f.component = :component
                AND f.filearea = :filearea
                AND f.itemid = :userid
                AND f.filename <> :directoryname",
            [
                'contextlevel' => CONTEXT_MODULE,
                'component' => 'mod_pdfsecure',
                'filearea' => 'watermarked',
                'userid' => $userid,
                'directoryname' => '.',
            ]
        );

        return $contextlist;
    }

    /**
     * Exports a user's progress from each approved module context.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            $activityid = self::get_activity_id($context);
            if (!$activityid) {
                continue;
            }

            $progresses = $DB->get_records('pdfsecure_progress', [
                'pdfsecureid' => $activityid,
                'userid' => $userid,
            ], 'filekey');
            if ($progresses) {
                $exported = [];
                foreach ($progresses as $progress) {
                    $progress->timemodified = transform::datetime($progress->timemodified);
                    if (!empty($progress->timecompleted)) {
                        $progress->timecompleted = transform::datetime($progress->timecompleted);
                    }
                    $exported[] = $progress;
                }
                $progress = clone reset($exported);
                $progress->files = $exported;
                $writer = writer::with_context($context);
                $writer->export_data([get_string('privacy:exportpath', 'mod_pdfsecure')], $progress);
            }
            writer::with_context($context)->export_area_files(
                [get_string('privacy:watermarkedpath', 'mod_pdfsecure')],
                'mod_pdfsecure',
                'watermarked',
                $userid
            );
        }
    }

    /**
     * Deletes all progress and watermarked derivatives in a module context.
     *
     * @param \context $context Context to clean.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        $activityid = self::get_activity_id($context);
        if (!$activityid) {
            return;
        }

        $DB->delete_records('pdfsecure_progress', ['pdfsecureid' => $activityid]);
        get_file_storage()->delete_area_files($context->id, 'mod_pdfsecure', 'watermarked');
    }

    /**
     * Deletes the user's progress and derivative from approved module contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts and user.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            self::delete_user_data_in_context($context, $userid);
        }
    }

    /**
     * Adds users with reading progress to a module context user list.
     *
     * @param userlist $userlist User list to populate.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if (!($context instanceof \context_module)) {
            return;
        }

        $userlist->add_from_sql(
            'userid',
            "SELECT progress.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {pdfsecure_progress} progress ON progress.pdfsecureid = cm.instance
              WHERE cm.id = :cmid",
            ['modname' => 'pdfsecure', 'cmid' => $context->instanceid]
        );
        $userlist->add_from_sql(
            'itemid',
            "SELECT f.itemid
               FROM {files} f
              WHERE f.contextid = :contextid
                AND f.component = :component
                AND f.filearea = :filearea
                AND f.filename <> :directoryname",
            [
                'contextid' => $context->id,
                'component' => 'mod_pdfsecure',
                'filearea' => 'watermarked',
                'directoryname' => '.',
            ]
        );
    }

    /**
     * Deletes progress and derivatives for users in an approved module user list.
     *
     * @param approved_userlist $userlist Approved context and users to clean.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data_in_context($context, (int)$userid);
        }
    }

    /**
     * Resolves the PDF Secure activity instance for a module context.
     *
     * @param \context $context Context to resolve.
     * @return int|null Activity instance ID, or null for another context type.
     */
    private static function get_activity_id(\context $context): ?int {
        global $DB;

        if (!($context instanceof \context_module)) {
            return null;
        }

        $activityid = $DB->get_field_sql(
            "SELECT cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid AND m.name = :modname",
            ['cmid' => $context->instanceid, 'modname' => 'pdfsecure']
        );

        return $activityid ? (int)$activityid : null;
    }

    /**
     * Deletes one user's progress row and per-user derivative in a module context.
     *
     * @param \context $context Context to clean.
     * @param int $userid User ID to delete.
     */
    private static function delete_user_data_in_context(\context $context, int $userid): void {
        global $DB;

        $activityid = self::get_activity_id($context);
        if (!$activityid) {
            return;
        }

        $DB->delete_records('pdfsecure_progress', [
            'pdfsecureid' => $activityid,
            'userid' => $userid,
        ]);
        get_file_storage()->delete_area_files($context->id, 'mod_pdfsecure', 'watermarked', $userid);
    }
}
