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
 * Queues conversion after Moodle creates or updates a supported source activity.
 *
 * @package    mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Handle a created course module.
     *
     * @param \core\event\course_module_created $event Event.
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        self::queue_from_event($event);
    }

    /**
     * Handle an updated course module.
     *
     * @param \core\event\course_module_updated $event Event.
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        self::queue_from_event($event);
    }

    /**
     * Queue conversion for module-context events only.
     *
     * @param \core\event\base $event Event.
     * @return void
     */
    private static function queue_from_event(\core\event\base $event): void {
        if (!converter::is_enabled()) {
            return;
        }
        $context = $event->get_context();
        if ($context->contextlevel !== CONTEXT_MODULE) {
            return;
        }
        converter::queue_coursemodule((int)$context->instanceid);
    }
}
