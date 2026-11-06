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
 * Resolves PDF Secure documents against the activity's own content area.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class content_files {

    /**
     * Returns this activity's top-level PDFs, keyed by their stored filename.
     *
     * @param \context_module $context Activity context.
     * @return \stored_file[]
     */
    public static function get_pdfs(\context_module $context): array {
        $files = get_file_storage()->get_area_files(
            $context->id,
            'mod_pdfsecure',
            'content',
            0,
            'filename',
            false
        );
        $pdfs = [];
        foreach ($files as $file) {
            if ($file->is_directory() || !self::is_pdf($file)) {
                continue;
            }
            $pdfs[$file->get_filename()] = $file;
        }
        return $pdfs;
    }

    /**
     * Resolves a file key only if it names a PDF in this activity.
     *
     * An empty key remains valid for old single-document clients, but is
     * intentionally ambiguous and rejected when an activity has several PDFs.
     *
     * @param \context_module $context Activity context.
     * @param string $filekey Stored filename supplied by the viewer.
     * @return \stored_file
     */
    public static function resolve(\context_module $context, string $filekey): \stored_file {
        $pdfs = self::get_pdfs($context);
        if (!$pdfs) {
            throw new \moodle_exception('errorpagesnodocument', 'mod_pdfsecure');
        }
        if ($filekey === '' && count($pdfs) === 1) {
            return reset($pdfs);
        }
        if (isset($pdfs[$filekey])) {
            return $pdfs[$filekey];
        }
        throw new \moodle_exception('errorfileinvalid', 'mod_pdfsecure');
    }

    /**
     * Whether a stored file represents a PDF.
     *
     * @param \stored_file $file File to inspect.
     * @return bool
     */
    public static function is_pdf(\stored_file $file): bool {
        return $file->get_mimetype() === 'application/pdf'
            || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf';
    }
}
