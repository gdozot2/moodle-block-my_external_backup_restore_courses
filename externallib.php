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
 * block_my_external_backup_restore_courses webservice externallib
 *
 * @package
 * @subpackage
 * @copyright  2015 unistra  {@link http://unistra.fr}
 * @author Celine Perves <cperves@unistra.fr>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

class block_my_external_backup_restore_courses_external extends external_api {
    public static function get_courses_zip($username, $courseid, $withuserdatas=false) {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/blocks/my_external_backup_restore_courses/locallib.php');
        require_once('backup_external_courses_helper.class.php');
        $params = self::validate_parameters(self::get_courses_zip_parameters(),
            array('username' => $username, 'courseid' => $courseid, 'withuserdatas' => $withuserdatas));

        require_capability('block/my_external_backup_restore_courses:can_retrieve_courses', context_system::instance());
        if (!empty($username)) {
            // Check some user rights.
            $usercourses = block_my_external_backup_restore_courses_tools::get_all_users_courses($params['username']);

            $usercourseids = array();
            foreach ($usercourses as $usercourse) {
                $usercourseids[] = $usercourse->id;
            }
            // User is not the owner of the course.
            if (!in_array($params['courseid'], $usercourseids)) {
                throw new Exception(get_string('notcourseowner', 'block_my_external_backup_restore_courses'));
            }

            $userrecord = $DB->get_record('user', array('username' => $params['username']));
            if (!$userrecord) {
                throw new invalid_username_exception('user with username not found');
            }
        }
        // Admin will backup course to have the necessary capabilities.
        // Choose this fact to simplify capabilities requirements and to avoid to set to much capabilities to roles.
        $res = backup_external_courses_helper::run_external_backup($params['courseid'], get_admin()->id, $withuserdatas);
        if (empty($res) || $res === false) {
            throw new Exception('Backup course can\'t be created');
        }

        $source = 'block_my_external_backup_restore_courses';

        $DB->execute('UPDATE {files} set source=:source where id=:id',
            array('source' => $source, 'id' => $res['file_record_id']));

        return array('filename' => $res['filename'], 'filerecordid' => $res['file_record_id']);
    }

    public static function get_courses_zip_parameters() {
        return new external_function_parameters(
            array(
                'username'      => new external_value(PARAM_TEXT, 'username'),
                'courseid'      => new external_value(PARAM_INT, 'course id'),
                'withuserdatas' => new external_value(PARAM_BOOL, 'get course archive with user datas included in', VALUE_DEFAULT, false),
            )
        );
    }

    public static function request_backup_parameters() {
        return new external_function_parameters(
            array(
                'username'      => new external_value(PARAM_TEXT, 'username'),
                'courseid'      => new external_value(PARAM_INT, 'course id'),
                'originalid'      => new external_value(PARAM_INT, 'original id'),
                'moodleurl'      => new external_value(PARAM_TEXT, 'moodle url'),
                'withuserdatas' => new external_value(PARAM_BOOL, 'get course archive with user datas included in', VALUE_DEFAULT, false),
            )
        );
    }

    public static function get_courses_zip_returns() {
        return new external_single_structure(
            array(
                'filename'        => new external_value(PARAM_RAW, 'file_name'),
                'filerecordid'    => new external_value(PARAM_INT, 'file_record_id'),
            )
        );
    }

    public static function request_backup_returns() {
        return new external_single_structure(
            array(
                'result'        => new external_value(PARAM_INT, 'result')
            )
        );
    }

    public static function request_backup($username, $courseid, $originalid, $moodleurl, $withuserdatas=false) {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/blocks/my_external_backup_restore_courses/locallib.php');
        $params = self::validate_parameters(self::request_backup_parameters(),
            array('username' => $username, 'courseid' => $courseid, 'originalid' => $originalid, 'moodleurl' => $moodleurl, 'withuserdatas' => $withuserdatas));
        
        if (!empty($username)) {
            // Check some user rights.
           $usercourses = block_my_external_backup_restore_courses_tools::get_all_users_courses($params['username']);

            $usercourseids = array();
            foreach ($usercourses as $usercourse) {
                $usercourseids[] = $usercourse->id;
            }

            // User is not the owner of the course.
            if (!in_array($params['courseid'], $usercourseids)) {
                return false;
            } 

            $userrecord = $DB->get_record('user', array('username' => $params['username']));
            if (!$userrecord) {
                return array('result' => 0);
            }

            $datas = new stdClass();
            $datas->userid = $userrecord->id;
            $datas->originalid = $originalid;
            $datas->courseid = $params['courseid'];
            $datas->externalmoodleurl = $params['moodleurl'];
            $datas->status = 0;
            $datas->withuserdatas = $params['withuserdatas'];
            $DB->insert_record('block_external_backup', $datas);

            return array('result' => 1);
        }
        return array('result' => 0);
    }

    public static function request_restore_parameters() {
        return new external_function_parameters(
            array(
                'id'      => new external_value(PARAM_INT, 'id'),
                'filename'      => new external_value(PARAM_TEXT, 'filename'),
                'status'      => new external_value(PARAM_INT, 'status'),
            )
        );
    }

    public static function request_restore_returns() {
        return new external_single_structure(
            array(
                'result'        => new external_value(PARAM_INT, 'result')
            )
        );
    }

    public static function request_restore($id, $filename, $status) {
        global $DB, $CFG;
        require_once($CFG->dirroot.'/blocks/my_external_backup_restore_courses/locallib.php');
        $params = self::validate_parameters(self::request_restore_parameters(),
            array('id' => $id, 'filename' => $filename, 'status' => $status));

        if (!empty($params['id'])) {
            $record = $DB->get_record('block_external_backuprestore', array('id' => $params['id']));
            if ($params['status'] != 0) {
                $record->status = -1;
            } else {
                $record->status = 3;
                $record->filelocation = $filename;
            }
            $DB->update_record('block_external_backuprestore', $record);
            return array('result' => 0);
        }
        return array('result' => 1);
    }

    public static function get_courses($username, $concernedroles) {
        global $CFG, $DB;
        $roles = explode(",", $concernedroles);
        require_once($CFG->dirroot.'/blocks/my_external_backup_restore_courses/locallib.php');

        $params = self::validate_parameters(self::get_courses_parameters(),
            array('username' => $username, 'concernedroles' => $concernedroles));

        require_capability('block/my_external_backup_restore_courses:can_see_backup_courses', context_system::instance());
        $usercourses = block_my_external_backup_restore_courses_tools::get_all_users_courses($params['username']);

        // Create return value.
        $coursesinfo = array();
        foreach ($usercourses as $usercourse) {
            $roleids = $DB->get_records_list('role', 'shortname', $roles);
            $concernedusers = array();
            foreach ($roleids as $roleid) {
                $usersrecord = get_role_users($roleid->id, context_course::instance($usercourse->id, false));
                foreach ($usersrecord as $userrecord) {
                    $concernedusers[$userrecord->username] = $userrecord->username;
                }
            }
            $courseinfo = array();
            $courseinfo['id'] = $usercourse->id;
            $courseinfo['category'] = $usercourse->category;
            $courseinfo['sortorder'] = $usercourse->sortorder;
            $courseinfo['shortname'] = $usercourse->shortname;
            $courseinfo['fullname'] = $usercourse->fullname;
            $courseinfo['idnumber'] = $usercourse->idnumber;
            $courseinfo['startdate'] = $usercourse->startdate;
            $courseinfo['visible'] = $usercourse->visible;
            $courseinfo['groupmode'] = $usercourse->groupmode;
            $courseinfo['groupmodeforce'] = $usercourse->groupmodeforce;
            $courseinfo['categoryidentifier'] = property_exists($usercourse,'categoryidentifier') ? $usercourse->categoryidentifier : null;
            $courseinfo['concernedusers'] = property_exists($usercourse,'concernedusers') ? implode(",", $concernedusers) : '';
            $coursesinfo[] = $courseinfo;
        }

        return $coursesinfo;
    }

    public static function get_courses_parameters() {
        return new external_function_parameters(
            array(
                'username' => new external_value(PARAM_TEXT, ''),
                'concernedroles' => new external_value(PARAM_TEXT, ''),
            )
        );
    }

    public static function get_courses_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'course id'),
                    'shortname' => new external_value(PARAM_TEXT, 'course short name'),
                    'category' => new external_value(PARAM_INT, 'category id'),
                    'sortorder' => new external_value(PARAM_INT, 'sort order into the category', VALUE_OPTIONAL),
                    'fullname' => new external_value(PARAM_TEXT, 'full name'),
                    'idnumber' => new external_value(PARAM_RAW, 'id number', VALUE_OPTIONAL),
                    'startdate' => new external_value(PARAM_INT, 'timestamp when the course start'),
                    'visible' => new external_value(PARAM_INT, '1: available to student, 0:not available', VALUE_OPTIONAL),
                     'groupmode' => new external_value(PARAM_INT, 'no group, separate, visible', VALUE_OPTIONAL),
                    'groupmodeforce' => new external_value(PARAM_INT, '1: yes, 0: no',  VALUE_OPTIONAL),
                    'categoryidentifier' => new external_value(PARAM_TEXT,
                        'categoryidentifier beetween moodle plateforms',  VALUE_OPTIONAL),
                    'concernedusers' => new external_value(PARAM_TEXT, 'concerned users'),
                ), 'course'
            )
        );
    }
}
