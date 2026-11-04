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
 * Upgrade steps for the PDF Secure activity module.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Upgrade the PDF Secure activity module database schema.
 *
 * @param int $oldversion The previously installed plugin version.
 * @return bool Whether the upgrade succeeded.
 */
function xmldb_pdfsecure_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026102400) {
        $activitytable = new xmldb_table('pdfsecure');
        $completionfield = new xmldb_field(
            'completionreadall',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );
        if (!$dbman->field_exists($activitytable, $completionfield)) {
            $dbman->add_field($activitytable, $completionfield);
        }

        $progresstable = new xmldb_table('pdfsecure_progress');
        if (!$dbman->table_exists($progresstable)) {
            $progresstable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
            $progresstable->add_field('pdfsecureid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $progresstable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL);
            $progresstable->add_field('pages', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $progresstable->add_field('seen', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL);
            $progresstable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $progresstable->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null);
            $progresstable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $progresstable->add_key('pdfsecure_fk', XMLDB_KEY_FOREIGN, ['pdfsecureid'], 'pdfsecure', ['id']);
            $progresstable->add_key('user_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $progresstable->add_index('pdfsecure_user_uix', XMLDB_INDEX_UNIQUE, ['pdfsecureid', 'userid']);
            $dbman->create_table($progresstable);
        }

        upgrade_mod_savepoint(true, 2026102400, 'pdfsecure');
    }

    return true;
}
