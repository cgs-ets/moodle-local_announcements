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
/**
 * Audience provider that allows for a combination of audiences of other types.
 * Items override must be filled with the following format for each row: 
 * [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers\audiences;

defined('MOODLE_INTERNAL') || die();

use local_announcements\providers\privileges;
use context_user;
use context_course;

class audience_combination extends \local_announcements\providers\audience_provider {

    const PROVIDER = 'combination';

    /**
    * Implementation. For profile fields there are no related audience codes.
    *
    * @param string $code
    * @return array Array of audience codes
    */
    public static function get_related_audience_codes($code) {
        // Return the code itself.
        return array('provider' => 'combination', 'code' => $code);
    }

    /**
    * Gets the list of audiences types to display as buttons on the selector.
    *
    * @return array.
    */
    public static function get_audience_types() {
        return parent::get_provider_audience_types(static::PROVIDER, static::ROLES);
    }

    /**
    * Gets the list of audience targets for the audience selector.
    *
    * @param array $type. The selected audience type.
    * @return array. List of audiences to display.
    */
    public static function get_selector_user_audience_associations($type) {
        global $USER, $DB;

        $audiences = array();

        // Load the audience type.
        $audiencetype = get_audiencetype($type);

        // Items must be pre defined through audiencesettings.php.
        $items = preg_split('/\r\n|\r|\n/', $audiencetype->itemsoverride);
        if (empty($items)) {
            return [];
        }

        // Prepare the items for the selector.
        foreach ($items as $combocode) {
            if (empty($combocode)) {
                continue;
            }

            // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
            list($providerstr, $scope, $code, $name, $groupby, $roles) = static::get_combocode_parts($combocode);

            $provider = get_provider($providerstr);
            if (empty($provider)) {
                continue;
            }

            // Check if user is allowed to post to this code.
            if (!$provider::can_user_post_to_audience($type, $code)) {
                continue;
            }
            
            $name = static::get_audience_name($combocode, false);

            $audienceout = array(
                'id' => $combocode,
                'code' => $combocode,
                'name' => $name,
            );
            if ($audiencetype->grouped && $groupby) {
                $audienceout['groupbyname'] = $groupby;
                $audienceout['groupbykey'] = $groupby;
                $audienceout['groupitemname'] = $name;
                $audienceout['name'] = $groupby . ' ' . $name;
            }
            $audiences[] = $audienceout;
        }

        if ($audiencetype->grouped) {
            $audiences = parent::list_to_tree($audiences);
        }

        return $audiences;
    }


    private static function get_combocode_parts($combocode) {
        $out = array('', '', '', '', '', array());
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        $delim = '|';
        $codearr = explode($delim, $combocode);
        if (count($codearr) < 3) {
            return $out;
        }

        $scopearr = explode('=', $codearr[1]);
        $code = array_pop($scopearr);
        $scope = '';
        if (count($scopearr)) {
            $scope = array_pop($scopearr);
        }

        $groupby = isset($codearr[3]) ? $codearr[3] : '';

        $roles = isset($codearr[4]) ? $codearr[4] : array();

        $out = array($codearr[0], $scope, $code, $codearr[2], $groupby, $roles);

        return $out;
    }

    /**
    * Implementation. Checks whether current user can post to the audience type
    *
    * @param array $type. The selected audience type.
    * @return boolean.
    */
    public static function can_user_post_to_audiencetype($type) {
        global $DB, $USER;

        // Announcement admins can always post
        if (is_user_admin()) { 
            return true;
        }

        $checks = privileges::get_checks($type);
        foreach ($checks as $checktype => $checkvalues) {
            foreach ($checkvalues as $checkvalue) {
                switch ($checktype) {
                    case "usercapability":
                        if(privileges::check_usercapability($checkvalue)) { 
                            return true;
                        }
                        break;
                    case "coursecapability":
                        if (privileges::check_coursecapability($checkvalue)) {
                            return true;
                        }
                        break;
                    case "username":
                        if (privileges::check_username($checkvalue)) {
                            return true;
                        }
                        break;
                    case "profilefield":
                        if (privileges::check_profilefield($checkvalue)) {
                            return true;
                        }
                        break;
                }
            }
        }

        return false;
    }

    /**
    * Implementation. Checks whether current user can post to a specific profile field.
    *
    * @param array $type. The selected audience type.
    * @param array $code. The selected audience code.
    * @return boolean.
    */
    public static function can_user_post_to_audience($type, $code) {
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $roles) = static::get_combocode_parts($code);

        $provider = get_provider($providerstr);
        if (empty($provider)) {
            return false;
        }

        // Defer the check to the provider.
        return $provider::can_user_post_to_audience($type, $code);
    }

    /**
    * Implementation. Check the privilege for a given code.
    *
    * @param string $checktype. The check type.
    * @param string $checkvalue. The check value.
    * @param string $code. The audience code.
    * @return boolean.
    */
    public static function check_privilege_for_code($checktype, $checkvalue, $code = null) {
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $roles) = static::get_combocode_parts($code);

        $provider = get_provider($providerstr);
        if (empty($provider)) {
            return false;
        }

        // Defer the check to the provider.
        return $provider::check_privilege_for_code($checktype, $checkvalue, $code);
    }

    /**
    * Implementation. Gets the audience name by code.
    *
    * @param string $code. The audience code.
    * @param bool $includegroupby. Whether to include the groupby in name.
    * @return string. The name of the audience.
    */
    public static function get_audience_name($code, $includegroupby = true) {
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $roles) = static::get_combocode_parts($code);

        if ($includegroupby && $groupby) {
            $name = $groupby . ' ' . $name;
        }

        if (empty($name)) {

            $provider = get_provider($providerstr);
            if (empty($provider)) {
                return '';
            }

            $name = $provider::get_audience_name($code);
        }

        return $name;
    }

    /**
    * Implementation. Gets the audience url by code.
    *
    * @param string $code. The audience code.
    * @return string. The url of the audience.
    */
    public static function get_audience_url($code) {
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $roles) = static::get_combocode_parts($code);

        $provider = get_provider($providerstr);
        if (empty($provider)) {
            return '';
        }

        return $provider::get_audience_url($code);
    }

    /**
    * Implementation. Gets the usernames for the audience
    *
    * @param string $code. The audience code. E.g. mdlcourse|MA10A|Maths Year 10|Courses|students,parents
    * @param string $type. The audience type. E.g. role
    * @param array $roles. The selected roles to extract. E.g. students,parents
    * @return array. The usernames of the users in the audience.
    */
    public static function get_audience_usernames($code, $type = '', $roles = array()) {
        // Load the audience type.
        $audiencetype = get_audiencetype($type);

        // Check that the code truly exists for security.
        if (strpos($audiencetype->itemsoverride, $code) === false) {
            return array();
        }

        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $coderoles) = static::get_combocode_parts($code);
        
        $provider = get_provider($providerstr);
        if (empty($provider)) {
            return array();
        }

        if (empty($roles)) {
            // Select all roles.
            $roles = $provider::ROLES;
        }

        if ($coderoles) {
            // Limit to roles that are allowed by the item override.
            $roles = array_intersect($roles, explode(',', $coderoles));
        }
        
        // $scope is required by mdlprofile.
        return $provider::get_audience_usernames($code, $scope, $roles, true);
    }

    /**
    * Implementation. Determines whether the provider has roles. 
    *
    * @return boolean.
    */
    public static function has_roles() {
        return true;
    }

    /**
    * Implementation. Takes a code and returns the true code.
    *
    * @return boolean.
    */
    public static function true_code($code) {
        // Split the combocode, format is [provider]|[(optional)scope=][code]|[name]|[(optional)groupby]|[(optional)roles].
        list($providerstr, $scope, $code, $name, $groupby, $coderoles) = static::get_combocode_parts($code);
        return $code;
    }


}