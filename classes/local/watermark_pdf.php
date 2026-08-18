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
 * FPDI with text rotation.
 *
 * FPDF 1.8 draws text horizontally only, so the diagonal watermark has to be done
 * with raw PDF transformation operators. Every rotate_at() opens a graphics state
 * (`q`) that rotate_reset() must close (`Q`) - an unbalanced pair corrupts the
 * content stream, so _endpage() closes any state left open by mistake.
 *
 * @package    mod_pdfsecure
 * @copyright  2026 Aditek / Angel Aligner
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class watermark_pdf extends \setasign\Fpdi\Fpdi {

    /** @var float degrees currently applied, 0 when no state is open */
    protected $rotationangle = 0;

    /**
     * Rotates the coordinate system around a point, in degrees counter-clockwise.
     *
     * @param float $angle
     * @param float $x pivot, user units
     * @param float $y pivot, user units
     */
    public function rotate_at(float $angle, float $x, float $y): void {
        $this->rotate_reset();

        if ($angle == 0) {
            return;
        }
        $this->rotationangle = $angle;

        $rad = $angle * M_PI / 180;
        $c = cos($rad);
        $s = sin($rad);
        // PDF user space has its origin bottom-left; FPDF counts y from the top.
        $cx = $x * $this->k;
        $cy = ($this->h - $y) * $this->k;

        $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm',
            $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
    }

    /**
     * Closes the graphics state opened by rotate_at(), if any.
     */
    public function rotate_reset(): void {
        if ($this->rotationangle != 0) {
            $this->_out('Q');
            $this->rotationangle = 0;
        }
    }

    /**
     * Safety net: never let a page end with an unbalanced graphics state.
     */
    protected function _endpage() {
        $this->rotate_reset();
        parent::_endpage();
    }
}
