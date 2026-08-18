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

namespace mod_pdfsecure\local;

/**
 * Burns a per-user watermark into the PDF bytes.
 *
 * The point is attribution, not prevention: a browser that can display a document
 * can always copy it. What this guarantees is that every copy in circulation
 * carries the identity of the account that fetched it.
 *
 * The original upload is never modified and never served - see
 * pdfsecure_pluginfile(). The stamped copy is produced on FIRST VIEW, per user,
 * and cached. It cannot be produced at upload time, because at upload time there
 * is no reader to name yet; that is also why newly uploaded files are covered
 * automatically, with no batch step.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watermarker {

    /** Filearea holding the generated per-user derivatives. */
    const AREA = 'watermarked';

    /**
     * Bump when the rendering CODE changes.
     *
     * Appearance settings are hashed into the cache key separately, so retuning
     * them in the admin UI invalidates derivatives on its own. This constant
     * exists for changes settings cannot express.
     */
    const RENDER_VERSION = 3;

    /**
     * Effective appearance settings, with defaults for anything never saved.
     *
     * @return array
     */
    protected static function settings(): array {
        $cfg = get_config('mod_pdfsecure');
        return [
            'tone'        => isset($cfg->tone)        ? (int)$cfg->tone     : 232,
            'fontsize'    => isset($cfg->fontsize)    ? (int)$cfg->fontsize : 11,
            'includedate' => isset($cfg->includedate) ? (int)$cfg->includedate : 1,
            'showfooter'  => isset($cfg->showfooter)  ? (int)$cfg->showfooter  : 1,
        ];
    }

    /**
     * Renders a stamped copy for this user, right now, and returns the bytes.
     *
     * Nothing is stored. The previous design cached one derivative per user, which
     * froze the timestamp at the moment of that user's FIRST view: someone opening a
     * document months later carried a stamp from the day they first looked at it.
     * For a mark whose job is tracing a leak, a stale time is worse than no time -
     * it points at the wrong moment with the same confidence as the right one.
     *
     * Generation costs ~0.02 s and ~8 MB for the documents on these sites, so paying
     * it per view is cheaper than the storage, the pruning task and the staleness the
     * cache brought with it.
     *
     * The caller MUST send this with `Accept-Ranges: none`. FPDI output is not
     * byte-identical between runs, so if the browser were allowed to fetch byte
     * ranges it would assemble pieces of separately generated files into one
     * corrupt PDF.
     *
     * @param \stored_file $source the original uploaded PDF
     * @param \stdClass $user the user the copy is being stamped for
     * @param int $cmid course module id, stamped in so a leak identifies the activity
     * @return string raw PDF bytes
     */
    public static function render_for(\stored_file $source, \stdClass $user, int $cmid): string {
        return self::render($source, $user, $cmid, self::settings());
    }

    /**
     * Returns the watermarked derivative for this user, generating it on first access.
     *
     * The cache key carries three things, and each one has to be there: the render
     * version, a hash of the appearance settings, and the SOURCE contenthash.
     * Without the settings hash, changing the tone in the admin UI would silently
     * do nothing for anyone who already has a cached copy.
     *
     * @param \stored_file $source the original uploaded PDF
     * @param \stdClass $user the user the copy is being stamped for
     * @param int $cmid course module id, stamped in so a leak identifies the activity
     * @return \stored_file the watermarked copy
     */
    public static function get_for_user(\stored_file $source, \stdClass $user, int $cmid): \stored_file {
        $fs = get_file_storage();

        $settings = self::settings();
        $skew = substr(md5(json_encode($settings)), 0, 8);
        $targetname = 'v' . self::RENDER_VERSION . '-' . $skew . '-'
            . $source->get_contenthash() . '.pdf';

        $existing = $fs->get_file($source->get_contextid(), 'mod_pdfsecure', self::AREA,
            $user->id, '/', $targetname);
        if ($existing) {
            return $existing;
        }

        self::prune_stale($source, $user->id, $targetname);

        $content = self::render($source, $user, $cmid, $settings);

        return $fs->create_file_from_string([
            'contextid' => $source->get_contextid(),
            'component' => 'mod_pdfsecure',
            'filearea'  => self::AREA,
            'itemid'    => $user->id,
            'filepath'  => '/',
            'filename'  => $targetname,
            'mimetype'  => 'application/pdf',
        ], $content);
    }

    /**
     * Deletes this user's derivatives that no longer match the current key.
     *
     * @param \stored_file $source
     * @param int $userid
     * @param string $keep filename that must survive
     */
    protected static function prune_stale(\stored_file $source, int $userid, string $keep): void {
        $fs = get_file_storage();
        $old = $fs->get_area_files($source->get_contextid(), 'mod_pdfsecure', self::AREA,
            $userid, 'id', false);
        foreach ($old as $f) {
            if ($f->get_filename() !== $keep) {
                $f->delete();
            }
        }
    }

    /**
     * Produces the watermarked PDF bytes.
     *
     * @param \stored_file $source
     * @param \stdClass $user
     * @param int $cmid
     * @param array $settings
     * @return string raw PDF
     */
    protected static function render(\stored_file $source, \stdClass $user, int $cmid,
            array $settings): string {

        // Resolved relative to this file on purpose: in the Moodle 5.x split webroot
        // $CFG->dirroot is the public/ directory, not the project root, so building
        // the path from dirroot yields public/public/... and fails.
        require_once(__DIR__ . '/../../vendor/autoload.php');

        // FPDI cannot read encrypted PDFs and drops annotations, links and form
        // fields on import - acceptable for reading material. Anything it refuses
        // raises, and the caller fails closed rather than serving the original.
        $tmp = make_request_directory() . '/source.pdf';
        $source->copy_content_to($tmp);

        $stamp = self::build_stamp($user, $cmid, $settings);

        $pdf = new watermark_pdf();
        $pdf->SetAutoPageBreak(false);
        $pdf->SetCreator('mod_pdfsecure');
        // Machine-readable attribution, independent of the visible marks.
        $pdf->SetAuthor(self::enc($stamp['identity']));
        $pdf->SetSubject(self::enc($stamp['footer']));

        $pages = $pdf->setSourceFile($tmp);
        for ($p = 1; $p <= $pages; $p++) {
            $tplid = $pdf->importPage($p);
            $size = $pdf->getTemplateSize($tplid);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplid);
            self::stamp_page($pdf, $size['width'], $size['height'], $stamp, $settings);
        }

        return $pdf->Output('S');
    }

    /**
     * Builds the strings stamped onto every page.
     *
     * @param \stdClass $user
     * @param int $cmid
     * @param array $settings
     * @return array{identity:string,tile:string,footer:string}
     */
    protected static function build_stamp(\stdClass $user, int $cmid, array $settings): array {
        $identity = fullname($user);
        if (!empty($user->email)) {
            $identity .= ' <' . $user->email . '>';
        }

        $when = userdate(time(), '%Y-%m-%d %H:%M');

        $tile = $identity;
        if (!empty($settings['includedate'])) {
            $tile .= '  -  ' . $when;
        }

        $footer = $identity . '  |  uid ' . $user->id . '  |  cmid ' . $cmid . '  |  ' . $when;

        return ['identity' => $identity, 'tile' => $tile, 'footer' => $footer];
    }

    /**
     * Draws the tiled diagonal mark plus the legible footer stamp.
     *
     * Two marks with different jobs: the tiled diagonal survives a screenshot or a
     * photo of the screen, the footer stays readable so a copy can be traced by eye.
     *
     * @param watermark_pdf $pdf
     * @param float $w page width in mm
     * @param float $h page height in mm
     * @param array $stamp
     * @param array $settings
     */
    protected static function stamp_page(watermark_pdf $pdf, float $w, float $h,
            array $stamp, array $settings): void {

        $tile = self::enc($stamp['tile']);
        $tone = max(0, min(255, $settings['tone']));

        // FPDF 1.8 has no alpha channel, so the grey level IS the transparency.
        // Not bold: bold at this size closes up the letterforms and fights the
        // document text underneath.
        $pdf->SetFont('Helvetica', '', $settings['fontsize']);
        $pdf->SetTextColor($tone, $tone, $tone);

        // Step derived from the actual rendered width instead of a fixed number:
        // the string length changes with the name and with the date setting, and a
        // fixed step either overlaps or leaves the tiling clipped at the margins.
        $stepx = max(40, $pdf->GetStringWidth($tile) + 22);
        $stepy = max(28, $settings['fontsize'] * 3.2);

        // Stop the tiling short of the footer band. The white band hides the tiles
        // visually, but their text objects still sit there in the content stream, and
        // pdftotext reads by position - so a tile fragment gets spliced into the middle
        // of the footer line ("rifiVerificacao <...>"). That corrupts the one mark a
        // forensic tool would parse, so the band has to be genuinely empty, not covered.
        $tilelimit = !empty($settings['showfooter']) ? $h - 12 : $h + $stepy;

        for ($y = -15; $y < $tilelimit; $y += $stepy) {
            for ($x = -$stepx; $x < $w + $stepx; $x += $stepx) {
                $pdf->rotate_at(35, $x, $y);
                $pdf->Text($x, $y, $tile);
                $pdf->rotate_reset();
            }
        }

        if (empty($settings['showfooter'])) {
            return;
        }

        // The footer needs its own opaque background: these documents carry a dark
        // branded band across the bottom, and grey-on-dark-blue renders the stamp
        // unreadable exactly where it matters most. The band is only as wide as the
        // text, so the rest of the document's own footer stays visible.
        $footer = self::enc($stamp['footer']);
        $pdf->SetFont('Helvetica', '', 7);
        $bandwidth = $pdf->GetStringWidth($footer) + 4;
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(6, $h - 7.5, min($bandwidth, $w - 12), 5.5, 'F');
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Text(8, $h - 3.8, $footer);
    }

    /**
     * Converts UTF-8 to the cp1252 the FPDF core fonts expect.
     *
     * Brazilian names routinely carry accents; without this they render as mojibake,
     * which defeats the point of a forensic mark. //TRANSLIT degrades the few
     * characters cp1252 lacks instead of dropping them.
     *
     * @param string $text
     * @return string
     */
    protected static function enc(string $text): string {
        $converted = @iconv('UTF-8', 'windows-1252//TRANSLIT', $text);
        return $converted === false ? $text : $converted;
    }
}
