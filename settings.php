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

/**
 * Whether to stamp at all, and what the stamp looks like.
 *
 * The appearance values were hardcoded constants. They are settings because the
 * right balance between "clearly marked" and "still readable" depends on the
 * documents, and nobody should have to edit PHP to retune it.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Not every site wants the stamp. Where the workstations already apply their own
    // watermark, ours lands on top of theirs: two overlapping marks, a document that
    // is measurably harder to read, and no attribution the site did not already have.
    //
    // Turning the stamp off changes only the stamp. The original still has no URL of
    // its own, delivery still runs the login, enrolment and capability checks, the
    // viewer still hides download and print, and the document text is still indexed
    // for Global Search. It also removes FPDI from the path, which means documents
    // FPDI refuses - encrypted ones above all - become readable instead of failing
    // closed, and links, bookmarks and form fields survive intact.
    $settings->add(new admin_setting_configselect(
        'mod_pdfsecure/stampmode',
        get_string('settingstampmode', 'mod_pdfsecure'),
        get_string('settingstampmode_desc', 'mod_pdfsecure'),
        'off',
        [
            'full' => get_string('stampmodefull', 'mod_pdfsecure'),
            'off'  => get_string('stampmodeoff', 'mod_pdfsecure'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_pdfsecure/tone',
        get_string('settingtone', 'mod_pdfsecure'),
        get_string('settingtone_desc', 'mod_pdfsecure'),
        232,
        [
            190 => get_string('tonestrong', 'mod_pdfsecure'),
            210 => get_string('tonemedium', 'mod_pdfsecure'),
            232 => get_string('tonelight', 'mod_pdfsecure'),
            243 => get_string('tonefaint', 'mod_pdfsecure'),
        ]
    ));

    $settings->add(new admin_setting_configselect(
        'mod_pdfsecure/fontsize',
        get_string('settingfontsize', 'mod_pdfsecure'),
        get_string('settingfontsize_desc', 'mod_pdfsecure'),
        11,
        [8 => '8 pt', 10 => '10 pt', 11 => '11 pt', 14 => '14 pt', 18 => '18 pt']
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_pdfsecure/includedate',
        get_string('settingincludedate', 'mod_pdfsecure'),
        get_string('settingincludedate_desc', 'mod_pdfsecure'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'mod_pdfsecure/maxstampbytes',
        get_string('settingmaxsize', 'mod_pdfsecure'),
        get_string('settingmaxsize_desc', 'mod_pdfsecure'),
        41943040,
        [
            10485760  => '10 MB',
            41943040  => '40 MB',
            104857600 => '100 MB',
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'mod_pdfsecure/showfooter',
        get_string('settingshowfooter', 'mod_pdfsecure'),
        get_string('settingshowfooter_desc', 'mod_pdfsecure'),
        1
    ));

    // Every setting above describes the stamp, or the cost of producing one, so all
    // of them are noise once the stamp is off. Hidden rather than removed: the saved
    // values stay put, and switching back restores the previous appearance exactly.
    foreach (['tone', 'fontsize', 'includedate', 'maxstampbytes', 'showfooter'] as $dependent) {
        $settings->hide_if('mod_pdfsecure/' . $dependent, 'mod_pdfsecure/stampmode', 'eq', 'off');
    }
}
