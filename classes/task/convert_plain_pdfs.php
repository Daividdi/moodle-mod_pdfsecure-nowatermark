<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_pdfsecure\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Converts a newly created or updated supported source activity.
 *
 * @package    mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class convert_plain_pdfs extends \core\task\adhoc_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('taskconvertplainpdfs', 'mod_pdfsecure');
    }

    /**
     * Convert one module if enforcement remains enabled.
     *
     * @return void
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        if (empty($data->cmid) || !\mod_pdfsecure\local\converter::is_enabled()) {
            return;
        }
        \mod_pdfsecure\local\converter::convert_coursemodule((int)$data->cmid);
    }
}
