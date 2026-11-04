<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\\core\\event\\course_module_created',
        'callback' => '\\mod_pdfsecure\\local\\observer::course_module_created',
    ],
    [
        'eventname' => '\\core\\event\\course_module_updated',
        'callback' => '\\mod_pdfsecure\\local\\observer::course_module_updated',
    ],
];
