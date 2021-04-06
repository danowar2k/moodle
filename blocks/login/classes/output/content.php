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

namespace block_login\output;

use core\session\manager;

use \renderable;
use \templatable;
use \renderer_base;

/**
 * Class containing data for the login block.
 *
 * @package    block_login
 * @copyright  2021 Daniel Poggenpohl
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class content implements renderable, templatable {

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output
     * @return array
     */
    public function export_for_template(renderer_base $output) {
        global $CFG, $PAGE;
        require_once($CFG->libdir."/authlib.php");

        $username = get_moodle_cookie();

        $authsequence = get_enabled_auth_plugins(true); // Get all auths, in sequence.
        $potentialidps = array();
        foreach ($authsequence as $authname) {
            $authplugin = get_auth_plugin($authname);
            $potentialidps = array_merge($potentialidps, $authplugin->loginpage_idp_list($PAGE->url->out(false)));
        }

        $idps = [];
        foreach ($potentialidps as $potentialidp) {
            $idp = [
                "url" => $potentialidp["url"]->out(),
                "name" => s($potentialidp["name"]),
                "iconurl" => $potentialidp["iconurl"] ? s($potentialidp["iconurl"]) : ""
            ];
            $idps[] = $idp;
        }

        $context = [];
        $context["loginUrl"] = get_login_url();
        $context["sUsername"] = s($username);
        $context["loginViaEmail"] = (!empty($CFG->authloginviaemail));
        $context["rememberUsername"] = (isset($CFG->rememberusername) and $CFG->rememberusername == 2);
        $context["usernameSet"] = (!empty($username));
        $context["loginToken"] = s(manager::get_login_token());
        $context["signup"] = signup_is_enabled() ? $CFG->wwwroot."/login/signup.php" : "";
        // TODO: Now that we have multiauth it is hard to find out if there is a way to change password.
        $context["forgot"] = $CFG->wwwroot . "/login/forgot_password.php";
        $context["potentialIdps"] = $idps;
        return $context;
    }
}
