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
 * Guards the local changes to the bundled PDF.js viewer.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pdfsecure;

/**
 * The viewer is plain JavaScript with no test harness here, so this catches a replaced viewer losing its changes.
 * @coversNothing
 */
final class viewer_hardening_test extends \basic_testcase {
    /**
     * Eval stays off, the sample document is gone and file is checked against the plugin's area.
     */
    public function test_viewer_keeps_local_hardening(): void {
        $viewer = file_get_contents(__DIR__ . '/../pdfjs-drm/web/viewer.js');

        $this->assertMatchesRegularExpression('/isEvalSupported: \{\s+value: false,/', $viewer);
        $this->assertMatchesRegularExpression('/defaultUrl = \{\s+value: "",/', $viewer);
        $this->assertStringContainsString('mod_(?:pdfsecure|slidesecure)\\/stamped\\/0', $viewer);
        $this->assertMatchesRegularExpression('/!isPdfSecureDocument\(new URL\(file,/', $viewer);
        $this->assertDoesNotMatchRegularExpression('/case 83:\s+eventBus\.dispatch\("download"/', $viewer);
        $this->assertDoesNotMatchRegularExpression('/case 79:\s+eventBus\.dispatch\("openfile"/', $viewer);
        $this->assertDoesNotMatchRegularExpression('/case 80:\s+PDFViewerApplication\.requestPresentationMode\(\)/', $viewer);
        $this->assertDoesNotMatchRegularExpression('/event\.keyCode === 80 && \(event\.ctrlKey \|\| event\.metaKey\)/', $viewer);
    }

    /**
     * The activity viewer resolves the requested key against its own PDF allow-list.
     */
    public function test_view_resolves_file_parameter_against_activity_allowlist(): void {
        $view = file_get_contents(__DIR__ . '/../view.php');
        $this->assertStringContainsString(
            'content_files::resolve($context, (string)$filekey)',
            $view
        );
    }
}
