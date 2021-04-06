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

use block_login\output\content;
use block_login\output\renderer;

/**
 * Login block
 *
 * @package   block_login
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class block_login extends block_base {
    function init() {
        $this->title = get_string("pluginname", "block_login");
    }

    function applicable_formats() {
        return array("site" => true);
    }

    function get_content () {
        global $CFG;
        require_once($CFG->libdir."/authlib.php");

        if ($this->content !== NULL) {
            return $this->content;
        }

        $content = new content();
        /** @var renderer $renderer */
        $renderer = $this->page->get_renderer("block_login");

        $this->content = new stdClass();
        $this->content->footer = "";
        if (!isloggedin() or isguestuser()) {
            $this->content->text = $renderer->render($content);
        }

        return $this->content;
    }
}
