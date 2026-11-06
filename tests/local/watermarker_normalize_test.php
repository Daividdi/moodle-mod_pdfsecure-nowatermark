<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace mod_pdfsecure\local;

/**
 * Tests stamping of PDFs the free FPDI parser cannot read as uploaded.
 *
 * @package    mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers    \mod_pdfsecure\local\watermarker
 */
final class watermarker_normalize_test extends \advanced_testcase {

    /**
     * Builds a one-page PDF 1.5 whose cross-reference is a stream, as current exporters write.
     *
     * @param string $label Optional unique comment in the header.
     * @return string
     */
    private function xref_stream_pdf(string $label = ''): string {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];
        $pdf = "%PDF-1.5\n" . ($label === '' ? '' : "% $label\n");
        $rows = "\x00\x00\x00\xff";
        foreach ($objects as $number => $body) {
            $rows .= "\x01" . pack('n', strlen($pdf)) . "\x00";
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $start = strlen($pdf);
        $rows .= "\x01" . pack('n', $start) . "\x00";
        $pdf .= "4 0 obj\n<< /Type /XRef /Size 5 /W [1 2 1] /Root 1 0 R /Length " . strlen($rows)
            . " >>\nstream\n$rows\nendstream\nendobj\nstartxref\n$start\n%%EOF\n";
        return $pdf;
    }

    /**
     * Builds a simple one-page PDF with a classic cross-reference table.
     *
     * @param string $label Unique page content label.
     * @return string
     */
    private function classic_pdf(string $label): string {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 200 200] >>',
        ];
        $pdf = "%PDF-1.4\n% $label\n";
        $offsets = '';
        foreach ($objects as $number => $body) {
            $offsets .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $start = strlen($pdf);
        return $pdf . "xref\n0 4\n0000000000 65535 f \n$offsets"
            . "trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    }

    /**
     * Stores content in an activity's content area.
     *
     * @param string $content
     * @return array{0:\stored_file,1:\stdClass}
     */
    private function stored_source(string $content): array {
        $course = $this->getDataGenerator()->create_course();
        $cm = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $source = get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->cmid)->id,
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'doc.pdf',
        ], $content);
        return [$source, $cm];
    }

    /**
     * A cross-reference stream PDF is stamped through one stored rewritten copy.
     */
    public function test_xref_stream_pdf_is_stamped(): void {
        global $CFG;
        $this->resetAfterTest();
        if (empty($CFG->pathtogs) || !is_executable($CFG->pathtogs)) {
            $CFG->pathtogs = '/usr/bin/gs';
        }
        if (!is_executable($CFG->pathtogs)) {
            $this->markTestSkipped('Ghostscript not available');
        }
        [$source, $cm] = $this->stored_source($this->xref_stream_pdf());
        $user = $this->getDataGenerator()->create_user();

        $this->assertTrue(watermarker::can_stamp($source));
        $first = watermarker::render_for($source, $user, (int)$cm->cmid);
        $this->assertStringStartsWith('%PDF-', $first);
        watermarker::render_for($source, $user, (int)$cm->cmid);

        $second = get_file_storage()->create_file_from_string([
            'contextid' => $source->get_contextid(),
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'second.pdf',
        ], $this->xref_stream_pdf('second source'));
        watermarker::render_for($second, $user, (int)$cm->cmid);

        $stored = get_file_storage()->get_area_files($source->get_contextid(), 'mod_pdfsecure',
            watermarker::NORMALIZED_AREA, 0, 'id', false);
        $this->assertCount(2, $stored);
    }

    /**
     * The page count of a cross-reference stream PDF comes through the rewritten copy, and is null without Ghostscript.
     */
    public function test_page_count_reads_xref_stream_pdf(): void {
        global $CFG;
        $this->resetAfterTest();
        $pathtogs = $CFG->pathtogs ?? '';
        $CFG->pathtogs = '';
        [$unreadable] = $this->stored_source($this->xref_stream_pdf() . '% no rewrite');
        $this->assertNull(watermarker::page_count($unreadable));

        $CFG->pathtogs = is_executable($pathtogs) ? $pathtogs : '/usr/bin/gs';
        if (!is_executable($CFG->pathtogs)) {
            $this->markTestSkipped('Ghostscript not available');
        }
        [$source] = $this->stored_source($this->xref_stream_pdf());
        $this->assertSame(1, watermarker::page_count($source));
    }

    /**
     * Rebuilding one user's derivative for a changed source key preserves other PDFs.
     */
    public function test_cached_derivative_pruning_is_scoped_to_source_file(): void {
        $this->resetAfterTest();
        [$source, $cm] = $this->stored_source($this->classic_pdf('first'));
        $second = get_file_storage()->create_file_from_string([
            'contextid' => $source->get_contextid(),
            'component' => 'mod_pdfsecure',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'second.pdf',
        ], $this->classic_pdf('second'));
        $user = $this->getDataGenerator()->create_user();

        watermarker::get_for_user($source, $user, (int)$cm->cmid);
        watermarker::get_for_user($second, $user, (int)$cm->cmid);
        set_config('tone', 200, 'mod_pdfsecure');
        watermarker::get_for_user($source, $user, (int)$cm->cmid);

        $stored = get_file_storage()->get_area_files($source->get_contextid(), 'mod_pdfsecure',
            watermarker::AREA, $user->id, 'id', false);
        $this->assertCount(2, $stored);
    }

    /**
     * Without Ghostscript the render still fails closed and the form check says so.
     */
    public function test_without_ghostscript_fails_closed(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->pathtogs = '';
        [$source, $cm] = $this->stored_source($this->xref_stream_pdf());

        $this->assertFalse(watermarker::can_stamp($source));
        $this->expectException(\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException::class);
        watermarker::render_for($source, $this->getDataGenerator()->create_user(), (int)$cm->cmid);
    }

    /**
     * Something that is not a PDF is refused by the form check.
     */
    public function test_garbage_is_refused(): void {
        $this->resetAfterTest();
        [$source] = $this->stored_source('not a pdf');
        $this->assertFalse(watermarker::can_stamp($source));
    }
}
