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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_pdfsecure\task;

/**
 * Removes the cached stamped copies left behind by earlier versions.
 *
 * Up to v1.3.0 one stamped copy was stored per user per document. That cache is gone:
 * documents are now rendered per request, so nothing accumulates and there is nothing
 * to retain. What remains is other people's disk still holding the old copies, which
 * are dead weight and carry frozen timestamps that no longer match anything.
 *
 * This task exists to clear them and can be removed once every install has run it.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prune_derivatives extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprune', 'mod_pdfsecure');
    }

    /**
     * Deletes every file in the legacy `watermarked` area.
     */
    public function execute(): void {
        global $DB;

        $fs = get_file_storage();
        $rs = $DB->get_recordset_select('files',
            "component = :component AND filearea = :filearea AND filename <> '.'",
            ['component' => 'mod_pdfsecure', 'filearea' => 'watermarked'],
            '', 'id');

        $count = 0;
        $bytes = 0;
        foreach ($rs as $record) {
            if ($file = $fs->get_file_by_id($record->id)) {
                $bytes += $file->get_filesize();
                $file->delete();
                $count++;
            }
        }
        $rs->close();

        if ($count) {
            mtrace("pdfsecure: removed {$count} legacy cached copy(ies), "
                . display_size($bytes) . " reclaimed");
        }
    }
}
