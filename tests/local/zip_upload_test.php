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
 * Tests strict ZIP entry validation before extraction.
 *
 * @package mod_pdfsecure
 * @covers \mod_pdfsecure\local\zip_upload
 */
final class zip_upload_test extends \advanced_testcase {
    /** @var string[] Temporary archives to remove after each test. */
    private array $archives = [];

    /**
     * Creates a ZIP archive from entry-name to content pairs and opens it for testing.
     *
     * @param array<string, string> $entries Archive entries.
     * @return \ZipArchive
     */
    private function archive(array $entries): \ZipArchive {
        if (!class_exists('\\ZipArchive')) {
            $this->markTestSkipped('PHP ZipArchive extension not available');
        }
        $path = tempnam(sys_get_temp_dir(), 'pdfsecure-zip-test-');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $this->archives[] = $path;

        $opened = new \ZipArchive();
        $opened->open($path);
        return $opened;
    }

    /**
     * @return void
     */
    protected function tearDown(): void {
        foreach ($this->archives as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /**
     * Rejects traversal entries before any filesystem extraction.
     */
    public function test_zip_handler_rejects_path_traversal(): void {
        $this->resetAfterTest();
        $zip = $this->archive(['../escape.pdf' => '%PDF']);
        try {
            zip_upload::validate_entries($zip);
            $this->fail('A traversal entry was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorzipentry', $e->errorcode);
        } finally {
            $zip->close();
        }
    }

    /**
     * Rejects non-PDF archive entries.
     */
    public function test_zip_handler_rejects_non_pdf_entries(): void {
        $this->resetAfterTest();
        $zip = $this->archive(['readme.txt' => 'not a PDF']);
        try {
            zip_upload::validate_entries($zip);
            $this->fail('A non-PDF entry was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorzipentry', $e->errorcode);
        } finally {
            $zip->close();
        }
    }

    /**
     * Rejects archives with more than 50 entries.
     */
    public function test_zip_handler_enforces_entry_cap(): void {
        $this->resetAfterTest();
        $entries = [];
        for ($index = 0; $index <= zip_upload::MAX_ENTRIES; $index++) {
            $entries['file-' . $index . '.pdf'] = '';
        }
        $zip = $this->archive($entries);
        try {
            zip_upload::validate_entries($zip);
            $this->fail('An archive with more than 50 entries was accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorzipsize', $e->errorcode);
        } finally {
            $zip->close();
        }
    }
}
