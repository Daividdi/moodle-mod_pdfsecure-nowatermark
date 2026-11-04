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
 * Course module view event for the PDF Secure activity module.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure\event;


/**
 * Event triggered when a user views a PDF Secure activity.
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Initializes the base view event with the PDF Secure activity table.
     */
    protected function init() {
        parent::init();
        $this->data['objecttable'] = 'pdfsecure';
    }

    /**
     * Gets the localized name for this event.
     *
     * @return string Event name.
     */
    public static function get_name() {
        return get_string('eventcoursemoduleviewed', 'mod_pdfsecure');
    }
}
