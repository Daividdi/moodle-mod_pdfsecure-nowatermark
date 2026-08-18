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

namespace mod_pdfsecure\search;

defined('MOODLE_INTERNAL') || die();

/**
 * Makes the activity and the text inside its PDF findable by Global Search.
 *
 * Without this class the documents are invisible to search no matter how the
 * search engine is configured - Moodle simply never offers them for indexing.
 *
 * Indexing does not conflict with the delivery restriction in
 * pdfsecure_pluginfile(). That check refuses the `content` area over HTTP; the
 * indexer reads through the file storage API instead (base_activity::attach_files
 * calls get_area_files, and the Solr engine posts $storedfile->get_content()), so
 * no URL is involved and nothing is loosened to make search work.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class activity extends \core_search\base_activity {

    /**
     * The point of this plugin is the text inside the PDF, so file indexing is
     * the feature rather than an extra.
     *
     * @return bool
     */
    public function uses_file_indexing() {
        return true;
    }

    /**
     * Areas whose files are sent to the search engine for text extraction.
     *
     * `watermarked` is deliberately absent. It holds one stamped copy per user of
     * the same document: indexing it would multiply every PDF by the number of
     * readers, filling the index with duplicates that differ only by the name
     * burned into them. The original in `content` carries the same text and exists
     * exactly once.
     *
     * @return string[]
     */
    public function get_search_fileareas() {
        return ['intro', 'content'];
    }
}
