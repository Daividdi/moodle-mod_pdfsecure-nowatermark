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
 * Safely imports top-level PDF files from a drag-and-dropped ZIP archive.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class zip_upload {

    /** Maximum number of archive entries, including directories and rejected types. */
    const MAX_ENTRIES = 50;

    /** Maximum total uncompressed size, checked against ZIP metadata and actual bytes. */
    const MAX_UNCOMPRESSED_BYTES = 524288000;

    /**
     * Extracts valid PDFs, stores them in the new activity and creates its instance.
     *
     * Files which cannot be stamped or exceed the watermark limit are skipped, as
     * loose PDF drag-and-drop follows the same validation. An archive with no valid
     * PDFs fails with a localized error.
     *
     * @param \stdClass $uploadinfo Moodle DND upload details.
     * @param \stored_file $zipfile Uploaded ZIP file in the user's draft area.
     * @return int New activity instance ID.
     */
    public static function handle(\stdClass $uploadinfo, \stored_file $zipfile): int {
        global $USER;

        if (!class_exists('\\ZipArchive')) {
            throw new \moodle_exception('errorzipunsupported', 'mod_pdfsecure');
        }

        $directory = make_request_directory();
        $archivepath = $directory . '/pdfsecure-upload.zip';
        $zipfile->copy_content_to($archivepath);
        $zip = new \ZipArchive();
        if ($zip->open($archivepath) !== true) {
            throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
        }
        $zipopened = true;

        $draftitemid = 0;
        $targetcontextid = 0;
        $targetfilesaved = false;
        $validnames = [];
        $fs = get_file_storage();
        try {
            $entries = self::validate_entries($zip);
            $draftitemid = file_get_unused_draft_itemid();
            $draftcontext = \context_user::instance($USER->id);
            $actualtotal = 0;

            foreach ($entries as $entry) {
                $temppath = tempnam($directory, 'pdfsecure-entry-');
                if ($temppath === false) {
                    throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
                }
                try {
                    $actualsize = self::extract_entry($zip, $entry, $temppath, $actualtotal);
                    $actualtotal += $actualsize;
                    if ($actualtotal > self::MAX_UNCOMPRESSED_BYTES) {
                        throw new \moodle_exception('errorzipsize', 'mod_pdfsecure');
                    }

                    $draftfile = $fs->create_file_from_pathname([
                        'contextid' => $draftcontext->id,
                        'component' => 'user',
                        'filearea' => 'draft',
                        'itemid' => $draftitemid,
                        'filepath' => '/',
                        'filename' => $entry['name'],
                        'mimetype' => 'application/pdf',
                    ], $temppath);

                    if (pdfsecure_stamp_mode() !== 'off'
                            && (watermarker::too_big($draftfile) || !watermarker::can_stamp($draftfile))) {
                        $draftfile->delete();
                        continue;
                    }
                    $validnames[] = $entry['name'];
                } finally {
                    @unlink($temppath);
                }
            }

            $zip->close();
            $zipopened = false;
            if (!$validnames) {
                throw new \moodle_exception('errorzipnovalidpdfs', 'mod_pdfsecure');
            }

            $targetcontext = \context_module::instance((int)$uploadinfo->coursemodule);
            $targetcontextid = $targetcontext->id;
            $targetfilesaved = true;
            file_save_draft_area_files(
                $draftitemid,
                $targetcontextid,
                'mod_pdfsecure',
                'content',
                0,
                ['subdirs' => 0, 'maxfiles' => -1]
            );
            foreach ($validnames as $name) {
                if (!$fs->get_file($targetcontextid, 'mod_pdfsecure', 'content', 0, '/', $name)) {
                    throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
                }
            }

            $activityname = pathinfo($zipfile->get_filename(), PATHINFO_FILENAME);
            if ($activityname === '') {
                $activityname = pathinfo((string)($uploadinfo->displayname ?? ''), PATHINFO_FILENAME);
            }
            $data = (object)[
                'course' => $uploadinfo->course->id,
                'name' => $activityname,
                'intro' => '',
                'introformat' => FORMAT_HTML,
                'coursemodule' => $uploadinfo->coursemodule,
                'completionreadall' => 0,
            ];
            return pdfsecure_add_instance($data, null);
        } catch (\Throwable $e) {
            if ($targetfilesaved && $targetcontextid) {
                $fs->delete_area_files($targetcontextid, 'mod_pdfsecure', 'content', 0);
            }
            throw $e;
        } finally {
            if ($zipopened) {
                $zip->close();
            }
            if ($draftitemid) {
                $fs->delete_area_files(\context_user::instance($USER->id)->id, 'user', 'draft', $draftitemid);
            }
            @unlink($archivepath);
        }
    }

    /**
     * Validates the full archive before any entry is extracted.
     *
     * @param \ZipArchive $zip Open archive.
     * @return array<int, array{name: string, size: int}>
     */
    public static function validate_entries(\ZipArchive $zip): array {
        if ($zip->numFiles < 1) {
            throw new \moodle_exception('errorzipnovalidpdfs', 'mod_pdfsecure');
        }
        if ($zip->numFiles > self::MAX_ENTRIES) {
            throw new \moodle_exception('errorzipsize', 'mod_pdfsecure');
        }

        $entries = [];
        $names = [];
        $declaredtotal = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            if (!is_array($stat) || !isset($stat['name'], $stat['size'])) {
                throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
            }
            $name = (string)$stat['name'];
            if ($name === '' || strpos($name, "\0") !== false || strpos($name, '/') !== false
                    || strpos($name, '\\') !== false || $name === '.' || $name === '..'
                    || preg_match('/^[A-Za-z]:/', $name)) {
                throw new \moodle_exception('errorzipentry', 'mod_pdfsecure');
            }
            if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'pdf') {
                throw new \moodle_exception('errorzipentry', 'mod_pdfsecure');
            }
            $namekey = \core_text::strtolower($name);
            if (isset($names[$namekey])) {
                throw new \moodle_exception('errorzipentry', 'mod_pdfsecure');
            }
            $names[$namekey] = true;

            $flags = (int)($stat['flags'] ?? 0);
            $method = $stat['encryption_method'] ?? null;
            $none = defined('ZipArchive::EM_NONE') ? \ZipArchive::EM_NONE : 0;
            $encryptionname = method_exists($zip, 'getEncryptionName') ? $zip->getEncryptionName($index) : false;
            $unencryptednames = ['no encryption', 'none'];
            if (($flags & 1) || ($method !== null && (int)$method !== $none)
                    || ($encryptionname !== false
                        && !in_array(strtolower((string)$encryptionname), $unencryptednames, true))) {
                throw new \moodle_exception('errorzipencrypted', 'mod_pdfsecure');
            }

            $size = (int)$stat['size'];
            if ($size < 0 || $size > self::MAX_UNCOMPRESSED_BYTES
                    || $declaredtotal > self::MAX_UNCOMPRESSED_BYTES - $size) {
                throw new \moodle_exception('errorzipsize', 'mod_pdfsecure');
            }
            $declaredtotal += $size;
            $entries[] = ['name' => $name, 'size' => $size];
        }
        return $entries;
    }

    /**
     * Streams an entry to a temporary file while enforcing actual expansion limits.
     *
     * @param \ZipArchive $zip Open archive.
     * @param array{name: string, size: int} $entry Validated entry.
     * @param string $targetpath Temporary output path.
     * @param int $prioractual Total bytes already extracted.
     * @return int Actual bytes extracted.
     */
    private static function extract_entry(\ZipArchive $zip, array $entry, string $targetpath, int $prioractual): int {
        $input = $zip->getStream($entry['name']);
        if ($input === false) {
            throw new \moodle_exception('errorzipencrypted', 'mod_pdfsecure');
        }
        $output = fopen($targetpath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
        }

        $actual = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if ($chunk === false) {
                    throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
                }
                if ($chunk === '') {
                    continue;
                }
                $length = strlen($chunk);
                if ($actual > $entry['size'] - $length
                        || $prioractual > self::MAX_UNCOMPRESSED_BYTES - $actual - $length) {
                    throw new \moodle_exception('errorzipsize', 'mod_pdfsecure');
                }
                $written = fwrite($output, $chunk);
                if ($written !== $length) {
                    throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
                }
                $actual += $length;
            }
        } finally {
            fclose($input);
            fclose($output);
        }

        if ($actual !== $entry['size']) {
            throw new \moodle_exception('errorzipinvalid', 'mod_pdfsecure');
        }
        return $actual;
    }
}
