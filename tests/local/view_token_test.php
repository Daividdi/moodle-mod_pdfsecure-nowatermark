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
 * Viewer tokens: one use, bound to user, module and file; navigations and cross-site requests refused.
 *
 * @package mod_pdfsecure
 * @copyright 2026 Angel Aligner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_pdfsecure\local\view_token
 */
final class view_token_test extends \advanced_testcase {

    public function test_token_is_spent_once_and_bound(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $token = view_token::issue(10, 'mod_pdfsecure', 'a.pdf');
        $this->assertFalse(view_token::spend($token, 11, 'mod_pdfsecure', 'a.pdf'));
        $this->assertFalse(view_token::spend($token, 10, 'mod_slidesecure', 'a.pdf'));
        $this->assertFalse(view_token::spend($token, 10, 'mod_pdfsecure', 'b.pdf'));
        $this->setUser($other);
        $this->assertFalse(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
        $this->setUser($user);
        $this->assertTrue(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
        $this->assertFalse(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
        $this->assertFalse(view_token::spend('not-a-token', 10, 'mod_pdfsecure', 'a.pdf'));
    }

    public function test_multi_use_token_for_byte_ranges(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $token = view_token::issue(10, 'mod_pdfsecure', 'a.pdf', 2);
        $this->assertTrue(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
        $this->assertTrue(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
        $this->assertFalse(view_token::spend($token, 10, 'mod_pdfsecure', 'a.pdf'));
    }

    public function test_fetch_metadata(): void {
        $this->assertTrue(view_token::request_is_viewer_fetch([]));
        $this->assertTrue(view_token::request_is_viewer_fetch(['HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_MODE' => 'cors']));
        $this->assertFalse(view_token::request_is_viewer_fetch(['HTTP_SEC_FETCH_DEST' => 'document']));
        $this->assertFalse(view_token::request_is_viewer_fetch(['HTTP_SEC_FETCH_DEST' => 'empty',
            'HTTP_SEC_FETCH_SITE' => 'cross-site']));
        $this->assertFalse(view_token::request_is_viewer_fetch(['HTTP_SEC_FETCH_MODE' => 'navigate']));
    }
}
