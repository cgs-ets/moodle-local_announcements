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
 * The base class for audience types.
 *
 * @package   local_announcements
 * @copyright 2020 Michael Vangelovski <michael.vangelovski@hotmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_announcements\providers;

defined('MOODLE_INTERNAL') || die();


abstract class audience_provider {

    /* The following properties must be overridden in child classes. */
	/** The enrolment type for this audience in moodle. */
    const PROVIDER = null;
    /** The roles that the audience is aware of and can handle. */ 
    /* The label for this role can overridden in the audiencesettings. */
    const ROLES = array('Staff', 'Students', 'Mentors');

    /**
    * Returns an array including the code and any other related codes. E.g. the course plus the meta course.
    *
    * @param string $code
    * @return array Array of audience codes
    */
    abstract public static function get_related_audience_codes($code);


    /**
    * Gets the list of audiences types to display as buttons on the selector. Child class must override this.
    *
    * @return array.
    */
    abstract public static function get_audience_types();


     /**
    * Gets a list of audience types for the given provider.
    *
    * @param string $providername. 
    * @param array $providerroles. 
    * @return array. List of audience types with roles based on audiencesettings 
    * and providerroles definition.
    */
    protected static function get_provider_audience_types($providername, $providerroles) {
        global $DB;

        $audiencetypes = $DB->get_records('ann_audience_types', array(
            'active' => 1, 
            'provider' => $providername
        ), 'uisort' );

        //get role types from db.
        foreach ($audiencetypes as $audtype) {
            $roles = explode(',', $audtype->roletypes);
            $audtype->roletypes = array();

            // Check role (or handler if alias) against provider roles.
            // E.g. Students[Participants],Mentors,Staff.
            foreach ($roles as $role) {
                // Remove possible alias for checking.
                $cleanrole = preg_replace('/[\[].*[\]]/U' , '', $role);
                if (in_array($cleanrole, $providerroles)) {
                    // If there is an alias use it instead.
                    if (preg_match('#\[(.*?)\]#', $role, $match)) { 
                        $audtype->roletypes[] = array(
                            'key' => $cleanrole,
                            'name' => $match[1],
                            'ticked' => ($cleanrole == 'Staff'),
                        );
                    } else {
                        $audtype->roletypes[] = array(
                            'key' => $cleanrole,
                            'name' => $cleanrole,
                            'ticked' => ($cleanrole == 'Staff'),
                        );
                    }
                }
            }
        }

        return $audiencetypes;
    }

    /**
    * Gets a list of audience associations for a given user for the audience selector.
    *
    * @param array $selectedaudience. The selected audience type.
    * @return array. List of audiences to display.
    */
    abstract public static function get_selector_user_audience_associations($selectedaudience);

    /**
    * Checks whether current user can post to the audience type
    *
    * @param array $type. The selected audience type.
    * @return boolean.
    */
    abstract public static function can_user_post_to_audiencetype($type);

    /**
    * Checks whether current user can post to the specific audience
    *
    * @param array $type. The selected audience type.
    * @param array $code. The selected audience code.
    * @return boolean.
    */
    abstract public static function can_user_post_to_audience($type, $code);

    /**
    * Check the privilege for a given code.
    *
    * @param string $checktype. The check type.
    * @param string $checkvalue. The check value.
    * @param string $code. The audience code.
    * @return boolean.
    */
    abstract public static function check_privilege_for_code($checktype, $checkvalue, $code = null);

    /**
    * Gets the audience name by code.
    *
    * @param string $code. The audience code.
    * @return string. The name of the audience.
    */
    abstract public static function get_audience_name($code);

    /**
    * Gets the audience url by code.
    *
    * @param string $code. The audience code.
    * @return string. The url of the audience.
    */
    abstract public static function get_audience_url($code);

    /**
    * Gets the users for the audience
    *
    * @param string $parcodeam. The audience code.
    * @return array. The users of the audience.
    */
    //abstract public static function get_audience_users($code, $type = '', $roles = array());

    /**
    * Gets the users for the audience
    *
    * @param string $code. 
    * @param string $type. The audience type.
    * @param string $roles. Array of roles.
    * @return array. The users of the audience.
    */
    abstract public static function get_audience_usernames($code, $type = '', $roles = array());

    /**
    * Converts a flat array of audiences to a tree. Audiences must contain a groupby param.
    *
    * @param array audiences as a flat array.
    * @return array. audiences as a tree.
    */
    public static function list_to_tree($audiences) {
        $out = array();
        foreach ($audiences as $audience) {
            $groupbykey = $audience['groupbykey'];
            $groupbyname = $audience['groupbyname'];
            unset($audience['groupbykey']);
            unset($audience['groupbyname']);
            if (isset($out[$groupbykey])) {
                $out[$groupbykey]['items'][] = $audience;
            } else {
                $out[$groupbykey] = array(
                    'groupbykey' => $groupbykey,
                    'groupbyname' => $groupbyname,
                    'items' => array($audience)
                );
            }
        }

        // Drop the named keys for mustache templating and return.
        return array_values($out);
    }

    /**
    * Determines whether the provider has roles. Default false.
    *
    * @return boolean.
    */
    public static function has_roles() {
        return false;
    }

    /* Getter methods */
    public static function get_provider() {
        return static::PROVIDER;
    }

    /**
    * Implementation. Takes a code and returns the true code.
    *
    * @return string.
    */
    public static function true_code($code) {
        return $code;
    }

}
