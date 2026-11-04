<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License, either version 3 of the License, or
// (at your option) any later version.

namespace mod_pdfsecure\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Hourly fallback sweep for native File and Folder PDFs missed by an event.
 *
 * @package    mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sweep_plain_pdfs extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksweepplainpdfs', 'mod_pdfsecure');
    }

    /**
     * Sweep native File and Folder activities for PDFs.
     *
     * @return void
     */
    public function execute(): void {
        \mod_pdfsecure\local\converter::sweep();
    }
}
