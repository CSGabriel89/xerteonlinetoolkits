<?php
/**
 * Licensed to The Apereo Foundation under one or more contributor license
 * agreements. See the NOTICE file distributed with this work for
 * additional information regarding copyright ownership.

 * The Apereo Foundation licenses this file to you under the Apache License,
 * Version 2.0 (the "License"); you may not use this file except in
 * compliance with the License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.

 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Created by PhpStorm.
 * User: tom
 * Date: 10-10-2015
 * Time: 12:58
 */
require_once(dirname(__FILE__) . "/../config.php");
require_once(dirname(__FILE__) . "/config.php");
require_once(dirname(__FILE__) . "/../functions.php");

_load_language_file("/automation/automation.class.inc");

class Automate
{

    private $mesg;
    private $status;
    private $teacher_name;
    private $teacher_username;
    private $teacher_id;
    private $teacher_mdl_id;
    private $owner_id;
    private $owner_username;
    private $teacher_root_folder_id;
    private $owner_group_folder_id;
    private $practice_prefix;
    private $group_name;
    private $folder_name;
    private $org_template_id;
    private $readonly;
    private $practice;
    private $attempt;
    private $course_roles;
    private $allowed = null;
    private $db_connection = null;

    private function _moodleConnect()
    {
        global $automation_config;
        if ($this->db_connection != null)
        {
            return $this->db_connection;
        }
        $dsn = "mysql:dbname={$automation_config->moodleDB};host={$automation_config->moodleServer}";
        $options = array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION);

        try
        {
            $db_connection = new PDO($dsn, $automation_config->moodleDBUser, $automation_config->moodleDBPassword, $options);
        }
        catch(PDOException $e) {
            _debug("Failed to connect to db: {$e->getMessage()}");
            return false;
        }
        $db_connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $this->db_connection = $db_connection;
        return $db_connection;
    }

    private function _moodleDisconnect()
    {
        $this->db_connection = null;
    }

    private function _moodleQuery($sql, $params = array())
    {
        $connection = $this->_moodleConnect();

        _debug("Running : $sql", 1);

        $statement = $connection->prepare($sql);

        $ok = $statement->execute($params);
        if ($ok === false) {
            _debug("Failed to execute query : $sql : " . print_r($connection->errorInfo(), true));
            $statement = null;
            $connection = null;
            return false;
        }

        if(preg_match('/^select/i', $sql)) {
            $rows = array();
            while($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = $row;
            }
            $statement = null;
            $connection = null;
            return $rows;
        }
        else if(preg_match('/^(update|delete)/i', $sql)) {
            return $statement->rowCount();  /* number of rows affected */
        }
        else if(preg_match('/^insert/i', $sql)) {
            $lastid = $connection->lastInsertId();;
            $statement = null;
            $connection = null;
            return $lastid;
        }
        else if(preg_match('/^(show)/i', $sql))
        {
            // Just fetch all and return result
            $r = $statement->fetchAll();
            $statement = null;
            $connection = null;
            return $r;
        }
        $statement = null;
        $connection = null;
        return $ok;

    }

    private function _getMoodleUserId($username)
    {
        $sql = "select id from mdl_user where username=?";
        $params = array($username);

        $rows = $this->_moodleQuery($sql, $params);
        if ($rows !== false)
        {
            if (count($rows) > 0) {
                return $rows[0]['id'];
            }
            else
            {
                return false;
            }
        }
        else
        {
            return false;
        }
    }

    private function _getGroups($courseid)
    {
        $result = array();
        $sql = "select g.* from mdl_groups g join mdl_groups_members gm on g.id = gm.groupid where gm.userid = ? and g.courseid=?";
        $params = array($this->teacher_mdl_id, $courseid);

        $rows = $this->_moodleQuery($sql, $params);
        if ($rows !== false)
        {
            $result = $rows;
        }
        return $result;
    }

    private function _getUserRoles($username)
    {
        $sql = "SELECT c.id AS courseid, ra.roleid, c.fullname, c.shortname, u.username, u.firstname, u.lastname, u.email
                    FROM mdl_role_assignments ra
                    JOIN mdl_user u ON u.id = ra.userid
                    JOIN mdl_role r ON r.id = ra.roleid
                    JOIN mdl_context cxt ON cxt.id = ra.contextid
                    JOIN mdl_course c ON c.id = cxt.instanceid
                    WHERE ra.userid = u.id AND ra.contextid = cxt.id AND cxt.contextlevel =50 AND cxt.instanceid = c.id AND  u.username=? ORDER BY c.fullname";

        $params = array($username);

        $rows = $this->_moodleQuery($sql, $params);

        $result = array();

        if ($rows !== false)
        {
            foreach ($rows as $row)
            {
                $groups = $this->_getGroups($row['courseid']);
                $nrgroups = count($groups);
                $result[] = ['courseid' => $row['courseid'], 'coursename' => $row['shortname'], 'roleid' => $row['roleid'], "nrgroups" => $nrgroups, "groups" => $groups];
            }
        }
        return $result;
    }

    public function allowed()
    {
        global $automation_config;
        if ($this->allowed === null && $this->status) {
            if (isset($_SESSION['toolkits_logon_username'])) {
                foreach ($this->course_roles as $course) {
                    if (in_array($course['roleid'], $automation_config->groupMemberSharingRole) or in_array($course['roleid'], $automation_config->sharingRole)) {
                        $this->allowed = true;
                        return true;
                    }
                }
            }
        }
        return $this->allowed;
    }

    public function allowedCourses()
    {
        global $automation_config;

        $result = array();
        if ($this->allowed())
        {
            $result = array();
            foreach ($this->course_roles as $course) {
                if (in_array($course['roleid'], $automation_config->groupMemberSharingRole) or in_array($course['roleid'], $automation_config->sharingRole)) {
                    if ($course['nrgroups'] > 0) {
                        $result[] = $course;
                    }
                }
            }
        }
        return $result;
    }


    public function allowedGroups()
    {
        global $automation_config;

        $result = array();
        if ($this->allowed())
        {
            $result = array();
            foreach ($this->course_roles as $course) {
                if (in_array($course['roleid'], $automation_config->groupMemberSharingRole) or in_array($course['roleid'], $automation_config->sharingRole)) {
                    if ($course['nrgroups'] > 0) {
                        foreach($course['groups'] as $group) {
                            $result[$group['id']] = $group;
                        }
                    }
                }
            }
        }
        return $result;
    }

    public function availableTemplates()
    {
        global $automation_config;
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        $result = array();
        foreach($automation_config->availableTemplates as $templateId) {
            $query_for_template_name = "select template_name from {$prefix}templatedetails where template_id= ?";
            $params = array($templateId);
            $row = db_query_one($query_for_template_name, $params);
            if ($row !== false) {
                $result[] = ['id' => $templateId, 'name' => str_replace("_", " ", $row['template_name'])];
            }
        }
        return $result;
    }

    /**
     *
     * Function create folder loop
     * This function creates folders needed when duplicating a template
     * @param string $foldername - the path to this folder
     * @param number $looplevel - a number to make sure that we enter and leave each folder correctly
     * @version 1.0
     * @author Patrick Lockley
     */

    private function createFolderLoop($dir_path, $new_path)
    {

        $folder_name = opendir($dir_path);

        while ($f = readdir($folder_name)) {
            $full = $dir_path . $f;
            if (is_dir($full)) {
                if (($f != ".") && ($f != "..")) {
                    $temp_new_path = $new_path . $f . "/";
                    if (@mkdir($temp_new_path)) {
                        if (@chmod($temp_new_path, 0777)) {
                            $this->createFolderLoop($full . "/", $temp_new_path);
                        } else {
                            $this->mesg .= AUTOMATION_FOLDERPERMS_FAILED . $temp_new_path . ".\n";
                            return false;
                        }
                    } else {
                        $this->mesg .= AUTOMATION_FOLDER_CREATE_FAILED . $temp_new_path . ".\n";
                        return false;
                    }
                }
            } else {
                $file_dest_path = $new_path . $f;
                if (@copy($full, $file_dest_path)) {
                    if (!@chmod($file_dest_path, 0777)) {
                        $this->mesg .= AUTOMATION_COPY_PERMS_FAILED . $full . " " . $file_dest_path . ".\n";
                        return false;
                    }
                } else {
                    $this->mesg .= AUTOMATION_COPY_FAILED . $full . " " . $file_dest_path . ".\n";
                    return false;
                }
            }
        }
        closedir($folder_name);

        /*
         * loop level is used to check for the recusion to make sure it has worked ok. A failure in this is not critical but is used in error reporting
         */

        return true;
    }

    /**
     *
     * Function create folder loop
     * This function creates folders needed when duplicating a template
     * @param string $folder_name_id - the id of the new template
     * @param number $id_to_copy - the id of the old template
     * @param string $tutorial_id_from_post - The name of this tutorial type i.e Nottingham
     * @version 1.0
     * @author Patrick Lockley
     */

    private function duplicateTemplate($user_name, $new_id, $id_to_copy, $template_type)
    {

        global $dir_path, $new_path, $xerte_toolkits_site;

        // Get creator of template $id_to_copy
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        $this->mesg .= AUTOMATION_DUPLICATE_MESG;
        $q = "select ld.username from {$prefix}templatedetails td, {$prefix}logindetails ld where td.template_id=? and td.creator_id=ld.login_id";
        $row = db_query_one($q, array($id_to_copy));

        if ($row == null) {
            $this->mesg .= AUTOMATION_DUPLICATE_USER_NOT_FOUND . $id_to_copy . ".\n";
            return false;
        }
        $org_user_name = $row['username'];

        $dir_path = $xerte_toolkits_site->users_file_area_full . $id_to_copy . "-" . $org_user_name . "-" . $template_type . "/";

        /*
         * Get the id of the folder we are looking to copy into
         */

        $new_path = $xerte_toolkits_site->users_file_area_full . $new_id . "-" . $user_name . "-" . $template_type . "/";

        if (mkdir($new_path)) {
            if (@chmod($new_path, 0777)) {
                if ($this->createFolderLoop($dir_path, $new_path)) {
                    if (file_exists($new_path = $xerte_toolkits_site->users_file_area_full . $new_id . "-" . $user_name . "-" . $template_type . "/lockfile.txt")) {
                        unlink($new_path = $xerte_toolkits_site->users_file_area_full . $new_id . "-" . $user_name . "-" . $template_type . "/lockfile.txt");
                    }
                    return true;
                } else {
                    return false;
                }
            } else {
                $this->mesg .= AUTOMATION_DUPLCATE_CREATE_FOLDER_PERMS_FAILED . $new_path . ".\n";
                return false;
            }
        } else {
            $this->mesg .= AUTOMATION_DUPLICATE_CREATE_FOLDER_FAILED . $new_path . ".\n";
            return false;
        }
    }

    /*
     * Look if login exists, and if not create it.
     * This will not create an account with password.
     *
     * It is assumed the account is already created (depending on the authentication method)
     *
     * returns array($login_id, $root_folder_id)
     */
    private function checkCreateLogin($username, $firstname, $surname)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        //if (!$this->Allowed())
        //    return false;

        $this->mesg .= AUTOMATION_CHECK_USER_MESG1 . $username . "\n";
        $mesg = AUTOMATION_CHECK_USER_MESG2;
        $mesg = str_replace("%s", $username, $mesg);
        $this->mesg .= $mesg;
        // search for user in logindetails table
        $q = "select * from {$prefix}logindetails where username=?";
        $row = db_query_one($q, array($username));

        if ($row !== false && $row != null) {
            $login_id = $row['login_id'];
        } else {

            $this->mesg .= AUTOMATION_CREATE_USER_LOGIN . $username . ".\n";

            // Create logindetails
            $query = "insert into {$prefix}logindetails (username, lastlogin, firstname, surname) values (?,?,?,?)";
            $login_id = db_query($query, array($username, date('Y-m-d'), $firstname, $surname));

            // Create recycle bin
            $query = "insert into {$prefix}folderdetails (login_id,folder_parent,folder_name) VALUES (?,?,?)";
            $res = db_query($query, array($login_id, "0", 'recyclebin'));

            if ($res === false) {
                $this->mesg .= AUTOMATION_CREATE_USER_LOGIN_RECYCLEBIN_FAILED . $username . "\n";
                return false;
            }
        }

        // Check root folder
        $root_folder_id = $this->checkCreateRootFolder($login_id, $username);
        if ($root_folder_id == null || $root_folder_id === false) {
            $this->mesg .= AUTOMATION_CREATE_USER_LOGIN_ROOTFOLDER_FAILED . $username . ".\n";
            return false;
        }
        return array('login_id' => $login_id, 'root_folder_id' => $root_folder_id);

    }

    /*
     * Look if root folder for user exists, and if not create it.
     *
     * returns folder_id
     */
    private function checkCreateRootFolder($login_id, $username)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        //if (!$this->Allowed())
        //    return false;

        // Check root folder
        $this->mesg .= AUTMATION_CHECK_ROOTFOLDER_MESG . $username . ".\n";

        $query = "select folder_id from {$prefix}folderdetails where login_id= ? AND folder_name = ?";
        $params = array($login_id, $username);

        $response = db_query_one($query, $params);
        if ($response == null) {

            $this->mesg .= AUTOMATION_CHECK_ROOTFOLDER_CREATE_MESG . $username . ".\n";

            $query = "insert into {$prefix}folderdetails (login_id,folder_parent,folder_name) VALUES (?,?,?)";
            $params = array($login_id, "0", $username);

            $folder_id = db_query($query, $params);
        }
        else
        {
            $folder_id = $response['folder_id'];
        }
        return $folder_id;

    }

    /*
     * Look if a folder for the owner exists, and if not create it.
     *
     * returns folder_id
     */

    private function checkCreateOwnerFolder($login_id, $user_name, $parent_folder_id, $foldername)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!$this->allowed())
            return false;

        $q = "select * from {$prefix}auto_template_group_folders where template_id=? and group_name=?";
        $params = array($this->org_template_id, $this->group_name);
        $rows = db_query($q, $params);

        if ($rows !== false) {
            if (count($rows) > 0) {
                $this->owner_id = $rows[0]['user_id'];
                $this->owner_username = $rows[0]['user_name'];
                $this->owner_group_folder_id = $rows[0]['folder_id'];

                // Check whether folder exists
                $q = "select * from {$prefix}folderdetails where login_id=? and folder_id=?";
                $folder = db_query_one($q, array($this->owner_id , $this->owner_group_folder_id));
                if ($folder !== false)
                {
                    if ($folder['folder_name'] == $foldername) {
                        $mesg = AUTOMATION_CHECK_OWNERFOLDER_MESG;
                        str_replace("%f", $foldername, $mesg);
                        $this->mesg .= $mesg . $rows[0]['user_name'] . ".\n";
                        $this->status = true;
                        return $this->owner_group_folder_id;
                    }
                    else
                    {
                        $this->mesg .= AUTOMATION_CHECK_OWNERFOLDER_INCONISTENT;
                        $this->status = false;
                        return false;
                    }

                }
                else
                {
                    $this->mesg .= AUTOMATION_CHECK_OWNERFOLDER_QUERY_FAILED;
                    $this->status = false;
                    return false;
                }

            } else {
                $this->owner_id = $login_id;
                $this->owner_username = $user_name;

                // search for folder in logindetails table
                $q = "select * from {$prefix}folderdetails where login_id=? and folder_parent=? and folder_name=?";
                $row = db_query_one($q, array($login_id, $parent_folder_id, $foldername));


                if ($row == null) {
                    // Create folder
                    $query = "insert into {$prefix}folderdetails (login_id,folder_parent,folder_name,date_created) values  (?,?,?,?)";
                    $params = array($login_id, $parent_folder_id, $foldername, date('Y-m-d'));

                    $folder_id = db_query($query, $params);
                    if ($folder_id !== false) {
                        // Create record in auto_template_group_folders
                        $query = "insert into {$prefix}auto_template_group_folders set date_created=?, template_id=?, group_name=?, user_id=?, user_name=?, folder_id=?";
                        $params = array(date('Y-m-d H:i:s'), $this->org_template_id, $this->group_name, $login_id, $user_name, $folder_id);
                        $record = db_query($query, $params);

                        if ($record !== false) {
                            $mesg = AUTOMATION_CHECK_OWNERFOLDER_CREATE;
                            $mesg = str_replace("%f", $foldername, $mesg);
                            $this->mesg .= $mesg . $this->org_template_id . "\n";
                            return $folder_id;  // Might be false
                        }
                        else{
                            $this->mesg .= AUTOMATION_CHECK_OWNERFOLDER_CREATE_RECORD_FAILED;
                            $this->status = false;
                            return false;
                        }
                    }
                    else{
                        $this->mesg .= AUTOMATION_CHECK_OWNERFOLDER_CREATE_FAILED;
                        $this->status = false;
                        return false;
                    }
                } else {
                    // The folder exists (for a different template), create a new auto_template_group_folders record
                    $query = "insert into {$prefix}auto_template_group_folders set date_created=?, template_id=?, group_name=?, user_id=?, user_name=?, folder_id=?";
                    $params = array(date('Y-m-d H:i:s'), $this->org_template_id, $this->group_name, $login_id, $user_name, $row['folder_id']);
                    $record = db_query($query, $params);
                    if ($record !== false) {
                        $mesg = AUTOMATION_CHECK_OWNERFOLDER_CREATE_RECORD_MESG;
                        $mesg = str_replace("%f", $foldername, $mesg);
                        $this->mesg .= $mesg . $this->org_template_id . "\n";
                        return $row['folder_id'];  // Might be false
                    }
                    else{
                        $this->mesg .= AUTOMATION_CHECK_OWNERFOLDER_CREATE_RECORD_FAILED;
                        $this->status = false;
                        return false;
                    }
                }
            }
        }
        return false;
    }

    /*
     * Look if a folder for user exists, and if not create it.
     *
     * returns folder_id
     */
    private function checkCreateFolder($login_id, $parent_folder_id, $foldername)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!$this->Allowed())
            return false;

        // search for user in logindetails table
        $q = "select * from {$prefix}folderdetails where login_id=? and folder_parent=? and folder_name=?";
        $row = db_query_one($q, array($login_id, $parent_folder_id, $foldername));

        if ($row == null) {
            // Create folder
            $query = "insert into {$prefix}folderdetails (login_id,folder_parent,folder_name,date_created) values  (?,?,?,?)";
            $params = array($login_id, $parent_folder_id, $foldername, date('Y-m-d'));

            $folder_id = db_query($query, $params);

            return $folder_id;  // Might be false
        } else {
            return $row['folder_id'];
        }
    }
    /*
     * Copy template to userfolder and return id of template
     */
    private function copyTemplateToUserFolder($template_id, $login_id, $user_name, $folder_id, $for_user_id, $for_username,  $for_user)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!$this->allowed())
            return false;

        // Check if already has this template
        $sql = "select * from {$prefix}auto_copied_templates where org_template_id=? and owner_id=? and owner_user_name=? and owner_folder_id=? and for_user_id=? and for_user_name=? and for_user=?";
        $params = array($template_id, $login_id, $user_name, $folder_id, $for_user_id, $for_username, $for_user);

        $rows = db_query($sql, $params);
        if ($rows === false || count($rows)>0)
        {
            // Error or already added, do not add again
            if ($rows === false) {
                $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_QUERY_FAILED;
                return false;
            }
            else
            {
                // It was already copied. Check if the LO still actuall exists by checking if there is a template in the correct folder
                // Step 1. Check if template actually exists
                $q = "select * from {$prefix}templatedetails where template_id=?";
                $params = array($rows[0]['copied_template_id']);
                $res = db_query_one($q, $params);
                if ($res !== false && $res['template_id'] == $rows[0]['copied_template_id']) {
                    // Step 2. Template exists. Check whther it's in the correct folder
                    $q = "select * from {$prefix}templaterights where template_id=? and user_id=? and folder=?";
                    $params = array($rows[0]['copied_template_id'], $rows[0]['owner_id'], $rows[0]['owner_folder_id']);
                    $res = db_query_one($q, $params);
                    if ($res !== false && $res!=null) {
                        $mesg = AUTOMATION_COPY_TEMPLATE_TO_USER_EXISTS;
                        $mesg = str_replace("%t", $template_id, $mesg);
                        $mesg = str_replace("%l", $login_id, $mesg);
                        $this->mesg .= $mesg;
                        return $rows[0]['copied_template_id'];
                    }
                    else
                    {
                        $mesg = AUTOMATION_COPY_TEMPLATE_TO_USER_SHARED_WRONG_FOLDER;
                        $mesg = str_replace("%t", $template_id, $mesg);
                        $this->mesg .= $mesg;

                        // Remove all sharing to moved LO
                        $q = "delete from {$prefix}templaterights where template_id=? and role != 'creator'";
                        $params = array($rows[0]['copied_template_id']);
                        $res = db_query($q, $params);

                        // Remove record from auto_copied_templates
                        $q = "delete from {$prefix}auto_copied_templates where org_template_id=? and owner_id=? and owner_user_name=? and owner_folder_id=? and for_user_id=? and for_user_name=? and for_user=?";
                        $params = array($template_id, $login_id, $user_name, $folder_id, $for_user_id, $for_username, $for_user);
                        $res = db_query($q, $params);
                    }
                }
                else
                {
                    $mesg = AUTOMATION_COPY_TEMPLATE_TO_USER_SHARED_DELETED;
                    $mesg = str_replace("%t", $template_id, $mesg);
                    $this->mesg .= $mesg;

                    // Remove all sharing to moved LO
                    $q = "delete from {$prefix}templaterights where template_id=? and role != 'creator'";
                    $params = array($rows[0]['copied_template_id']);
                    $res = db_query($q, $params);

                    // Remove record from auto_copied_templates
                    $q = "delete from {$prefix}auto_copied_templates where org_template_id=? and owner_id=? and owner_user_name=? and owner_folder_id=? and for_user_id=? and for_user_name=? and for_user=?";
                    $params = array($template_id, $login_id, $user_name, $folder_id, $for_user_id, $for_username, $for_user);
                    $res = db_query($q, $params);
                }
            }
        }

        /*
         * get the maximum id number from templates, as the id for this template
         */
        $row = db_query_one("SELECT max(template_id) as count FROM {$prefix}templatedetails");

        if ($row === false) {
            $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_GETID_FAILED;
            return false;
        }
        $new_template_id = $row['count'] + 1;

        $query_for_template_type_id = "select otd.template_type_id, otd.template_name as org_template_name, otd.template_framework, td.extra_flags, td.template_name from "
            . "{$prefix}originaltemplatesdetails otd, {$prefix}templatedetails td where "
            . "otd.template_type_id = td.template_type_id  AND "
            . "td.template_id = ? ";

        $params = array($template_id);

        $row_template_type = db_query_one($query_for_template_type_id, $params);

        /*
         * create the new template record in the database
         */

        $query_for_new_template = "insert into {$prefix}templatedetails "
            . "(template_id, creator_id, template_type_id, date_created, date_modified, access_to_whom, template_name, extra_flags)"
            . " VALUES (?,?,?,?,?,?,?,?)";
        $params = array(
            $new_template_id,
            $login_id,
            $row_template_type['template_type_id'],
            date('Y-m-d'),
            date('Y-m-d'),
            "Private",
            $this->practice_prefix . $row_template_type['template_name'] . "_-_" . $for_user,
            $row_template_type['extra_flags']);

        if (db_query($query_for_new_template, $params) !== FALSE) {

            $query_for_template_rights = "insert into {$prefix}templaterights (template_id,user_id,role, folder) VALUES (?,?,?,?)";
            $params = array($new_template_id, $login_id, "creator", $folder_id);

            if (db_query($query_for_template_rights, $params) !== FALSE) {

                $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_RECORD_CREATED;

                if ($this->duplicateTemplate($user_name, $new_template_id, $template_id, $row_template_type['org_template_name']))
                {

                    $sql = "insert into {$prefix}auto_copied_templates set org_template_id=?, owner_id=?, owner_user_name=?, owner_folder_id=?, for_user_id=?, for_user_name=?, for_user=?, copied_template_id=?";
                    $params = array($template_id, $login_id, $user_name, $folder_id, $for_user_id, $for_username, $for_user, $new_template_id);
                    $row = db_query($sql, $params);
                    if ($row === false)
                    {
                        $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_CREATE_REGISTRATIONRECORD_FAILED;
                    }
                    return $new_template_id;
                }
                else
                {
                    $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_DUPLICATION_FAILED;
                    return false;
                }

            } else {

                $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_CREATE_RECORD_FAILED;
                return false;
            }

        } else {

            $this->mesg .= AUTOMATION_COPY_TEMPLATE_TO_USER_CREATE_RECORD_FAILED;

            return false;

        }
    }


    /*
     * Copy template to userfolder and return id of template
     */
    private function shareTemplateWithUserInFolder($template_id, $new_login_id, $new_folder_id, $role)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!$this->allowed())
            return false;

        // Check if the sharing is already there, and needs to be changed
        $sql = "select * from {$prefix}templaterights where template_id=? and user_id=? and folder=?";
        $params = array($template_id, $new_login_id, $new_folder_id);
        $row = db_query($sql, $params);

        if ($row !== false && count($row) > 0)
        {
            // Ok already present, update sharing role
            $sql = "update {$prefix}templaterights set role=? where template_id=? and user_id=? and folder=?";
            $params = array($role, $template_id, $new_login_id, $new_folder_id);
            if(db_query($sql, $params) !== false)
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else
        {
            // Create sharing record

            $query_to_insert_share = "insert into {$prefix}templaterights (template_id, user_id, role, folder) VALUES (?,?,?,?)";
            $params = array($template_id, $new_login_id, $role, $new_folder_id);

            if (db_query($query_to_insert_share, $params) !== false) {

                return true;

            } else {

                return false;
            }
        }
    }

    /*
     * Copy template to userfolder and return id of template
     */
    private function unShareTemplateWithUserInFolder($template_id, $username)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!$this->allowed())
            return false;

        // Get the rights of the templates of owner_id in owner_group_folder of user $login_id
        $q = "select * from {$prefix}auto_copied_templates where org_template_id=? and owner_folder_id=? and for_user_name=?";
        $params = array($template_id, $this->owner_group_folder_id, $username);
        $row = db_query_one($q, $params);

        if ($row !== false) {
            if ($row != null) {

                //$this->mesg .= "Share template\n;";

                $query_to_delete_share = "delete from {$prefix}templaterights where template_id=? and user_id=?";
                $params = array($row['copied_template_id'], $row['for_user_id']);

                if (db_query($query_to_delete_share, $params) !== false) {

                    return true;

                } else {

                    return false;
                }
            }
            else{
                // Not found, noting to unshare
                return true;
            }
        }
        else
        {
            return false;
        }
    }

    private function setTeacher($username, $firstname, $surname)
    {
        $teacher = $this->checkCreateLogin($username, $firstname, $surname);

        if ($teacher !== false)
        {
            $this->teacher_id = $teacher['login_id'];
            $this->teacher_root_folder_id = $teacher['root_folder_id'];
            $this->teacher_username = $username;
            $this->teacher_name = $firstname . " " . $surname;
            $this->teacher_mdl_id = $this->_getMoodleUserId($username);
            $this->status = true;
        }
        else
        {
            $this->status = false;
        }
    }

    private function constructGroupname($groupname)
    {
        if ($this->practice) {
            // Construct Practice prefix
            $this->practice_prefix = AUTOMATION_PRACTICE_PREFIX . AUTOMATION_ATTEMPT_PREFIX . $this->attempt . " - ";
            $this->group_name = $this->practice_prefix . $groupname;
        }
        else
        {
            $this->practice_prefix = "";
            $this->group_name = $groupname;
        }
    }

    function __construct()
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;

        if (!isset($_SESSION['toolkits_logon_username']))
        {
            die(AUTOMATION_NOT_LOGGED_IN);
        }
        // Add tables to xot database
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}auto_copied_templates (
                    org_template_id int(11) NOT NULL AUTO_INCREMENT,
                    owner_id int(11) NOT NULL,
                    owner_user_name varchar(64) NOT NULL,
                    owner_folder_id int(11) NOT NULL,
                    for_user_id int(11) NOT NULL,
                    for_user_name varchar(64) NOT NULL,
                    for_user varchar(64) NOT NULL,
                    copied_template_id int(11),
                    UNIQUE KEY copied_idx (org_template_id, owner_id, owner_user_name, owner_folder_id, for_user_name)
                  )";

        db_query($sql) or die(AUTOMATION_CREATE_TABLE_AUTO_COPIED_TEMPLATES_FAILED);
        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}auto_sharing_log (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    date_created datetime NOT NULL,
                    action varchar(20) NOT NULL,
                    template_id int(11) NOT NULL,
                    group_id int(11) NOT NULL,
                    group_name varchar(64) NOT NULL,
                    user_id int(11) NOT NULL,
                    user_name varchar(64) NOT NULL,
                    readonly tinyint(1) NOT NULL,
                    practice tinyint(1) NOT NULL,
                    attempt int(11) NOT NULL,
                    logmessage text,
                    PRIMARY KEY (`id`)
                  )";
        db_query($sql) or die(AUTOMATION_CREATE_TABLE_AUTO_SHARING_LOG_FAILED);

        $sql = "CREATE TABLE IF NOT EXISTS {$prefix}auto_template_group_folders (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    date_created datetime NOT NULL,
                    template_id int(11) NOT NULL,
                    group_name varchar(64) NOT NULL,
                    user_id int(11) NOT NULL,
                    user_name varchar(64) NOT NULL,
                    folder_id int(11) NOT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY folder_idx (template_id, group_name)
                  )";
        db_query($sql) or die(AUTOMATION_CERATE_TABLE_TEMPATE_GROUP_FOLDERS_FAILED);

        $this->setTeacher($_SESSION['toolkits_logon_username'], $_SESSION['toolkits_firstname'], $_SESSION['toolkits_surname']);
        $this->course_roles = $this->_getUserRoles($this->teacher_username);
    }

    public function setGroupFolder($group_name)
    {
        $this->constructGroupname($group_name);

        $folderid = $this->checkCreateOwnerFolder($this->teacher_id, $this->teacher_username, $this->teacher_root_folder_id, $this->group_name);
        
        if ($folderid !== false)
        {
            $this->owner_group_folder_id  = $folderid;
            $this->folder_name = $this->group_name;

            $this->status = true;
        }
        else
        {
            $this->status = false;    
        }
    }
    
    public function setOriginalTemplateId($template_id)
    {
        $this->org_template_id = $template_id;
        $this->mesg .= AUTOMATION_SET_ORGINAL_TEMPLATE_MESG . $template_id . ".\n";
    }

    public function setReadonly($readonly)
    {
        $this->readonly = $readonly;
        $this->mesg .= AUTOMATION_SET_READONLY_MESG . $readonly . ".\n";
    }

    public function setPractice($practice)
    {
        $this->practice = $practice;
        $this->mesg .= AUTOMATION_SET_PRACTICE_MESG . $practice . ".\n";
    }

    public function setAttempt($attempt)
    {
        $this->attempt = $attempt;
        $this->mesg .= AUTOMATION_SET_ATTEMPT_MESG . $attempt . ".\n";
    }

    public function recordSharing($action, $templateId, $group, $readonly, $practice, $attempt, $logmesg)
    {
        global $xerte_toolkits_site;

        $prefix = $xerte_toolkits_site->database_table_prefix;
        $sql = "select * from mdl_groups where id=?";
        $params = array($group);
        $rows = $this->_moodleQuery($sql, $params);
        if ($rows !== false) {

            $sql = "insert into {$prefix}auto_sharing_log set date_created=?, action=?, template_id=?, group_id=?, group_name=?, user_id=?, user_name=?, readonly=?, practice=?, attempt=?, logmessage=?";
            $params = array(date('Y-m-d H:i:s'), $action, $templateId, $group, $rows[0]['name'], $this->teacher_id, $this->teacher_username, $readonly, $practice, $attempt, $logmesg);
            $row = db_query($sql, $params);

            if ($row !== false)
            {
                $this->status = true;
            }
            else
            {
                $this->mesg .= AUTOMATION_LOG_SHARING_FAILED;
                $this->status = false;
            }
        }
        else{
            $this->mesg .= AUTOMATION_LOG_SHARING_FAILED;
            $this->status = false;
        }
    }

    public function getGroupMembersAndRoles($group)
    {
        $sql = "SELECT
                c.id AS courseid,
                u.username,
                u.firstname,
                u.lastname,
                u.email,
                ra.roleid

                FROM
                mdl_role_assignments ra
                JOIN mdl_user u ON u.id = ra.userid
                JOIN mdl_role r ON r.id = ra.roleid
                JOIN mdl_context cxt ON cxt.id = ra.contextid
                JOIN mdl_course c ON c.id = cxt.instanceid
                JOIN mdl_groups_members gm ON u.id=gm.userid
                JOIN mdl_groups g ON g.id=gm.groupid

                WHERE
                cxt.contextlevel =50
                AND  g.id =?";
        $params = array($group);
        $rows = $this->_moodleQuery($sql, $params);

        if ($rows !== false)
        {
            $this->status = true;
            return $rows;
        }
        else
        {
            $this->mesg .= AUTOMATION_GET_GROUPMEMBERS_FAILED . $group;
            $this->status = false;
            return array();
        }
    }

    public function isGroupStudentAccessRole($roleid)
    {
        global $automation_config;

        return (in_array($roleid, $automation_config->groupMemberStudentAccessRole));
    }

    public function isGroupTeacherAccessRole($roleid)
    {
        global $automation_config;

        return (in_array($roleid, $automation_config->groupMemberTeacherAccessRole));
    }

    public function addAccessToLO($username, $firstname, $surname, $role, $teachers)
    {
        // Create login for student
        $mesg = AUTOMATION_ADD_ACCESS_TO_MESG;
        $mesg = str_replace("%n", $firstname . " " . $surname, $mesg);
        $mesg = str_replace("%r", $role, $mesg);
        $this->mesg .= $mesg;
        $login = $this->checkCreateLogin($username, $firstname, $surname);

        if ($login !== false)
        {
            // Place template in teachers folder for this student
            $this->mesg .= AUTOMATION_ADD_ACCESS_TO_PLACE_IN_TEACHER_FOLDER;
            $template_id = $this->copyTemplateToUserFolder($this->org_template_id, $this->owner_id, $this->owner_username, $this->owner_group_folder_id, $login['login_id'], $username, $firstname . " " . $surname);

            if ($this->status)
            {
                // Share template with student
                $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_SHARE_MESG;

                $folderid = $this->checkCreateFolder($login['login_id'], $username, $login['root_folder_id'], $this->group_name);

                if ($folderid !== false) {
                    if ($this->shareTemplateWithUserInFolder($template_id, $login['login_id'], $folderid, $role) !== false) {
                        // Share template with other teachers
                        foreach ($teachers as $teacher) {
                            $mesg = AUTOMATION_ADD_ACCESS_TO_LO_TEACHER_MESG;
                            $mesg = str_replace("%t", $teacher['firstname'] . " " . $teacher['lastname'], $mesg);
                            $this->mesg .= $mesg;
                            $teacher_login = $this->checkCreateLogin($teacher['username'], $teacher['firstname'], $teacher['lastname']);
                            if ($teacher_login !== false) {
                                $folderid = $this->checkCreateFolder($teacher_login['login_id'], $teacher['username'], $teacher_login['root_folder_id'], $this->group_name);

                                if ($folderid !== false) {
                                    if ($this->shareTemplateWithUserInFolder($template_id, $teacher_login['login_id'], $folderid, 'read-only') === false) {
                                        $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_TEACHER_FAILED . $teacher['username'] . "\n";
                                        $this->status = false;
                                        return false;
                                    }
                                }
                                else
                                {
                                    $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_CREATE_TEACHER_GROUPFOLDER_FAILED;
                                    $this->status = false;
                                    return false;
                                }
                            } else {
                                $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_CHECK_TEACHER_LOGIN_FAILED . $teacher['username'];
                                $this->status = false;
                                return false;
                            }
                        }
                    }
                    else
                    {
                        $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_STUDENT_FAILED;
                        $this->status = false;
                        return false;
                    }
                }
                else
                {
                    $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_CREATE_STUDENT_GROUPFOLDER_FAILED;
                    $this->status = false;
                    return false;
                }
            }
            else
            {
                $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_COPY_TO_OWNER_GROUPFOLDER_FAILED;
                $this->status = false;
                return false;
            }
        }
        else
        {
            $this->mesg .= AUTOMATION_ADD_ACCESS_TO_LO_CHECK_LOGIN_FAILED . $username;
            $this->status = false;
            return false;
        }

    }

    public function removeAccessFromLO($username, $firstname, $surname, $template_id)
    {
        // Create login for student
        $this->mesg .= AUTOMATION_UNSHARE_MESG . $firstname . " " . $surname . "\n";
        // Share template with student
        $this->mesg .= AUTOMATION_UNSHARE_MESG2;
        if ($this->unShareTemplateWithUserInFolder($template_id, $username) !== false) {
            $this->mesg .= "\n";
            return true;
        }
    }

    public function getTeacherId()
    {
        if ($this->status) {
            return $this->teacher_id;
        }
    }

    public function getTeacherUsername()
    {
        if ($this->status) {
            return $this->teacher_username;
        }
    }

    public function getTeacherName()
    {
        if ($this->status) {
            return $this->teacher_name;
        }
    }

    public function getOwnerId()
    {
        if ($this->status) {
            return $this->owner_id;
        }
    }

    public function getOwnerUsername()
    {
        if ($this->status) {
            return $this->owner_username;
        }
    }

    public function getMesgHTML()
    {
        return str_replace("\n", "<br>",  $this->mesg);
    }

    public function getMesg()
    {
        return $this->mesg;
    }

    public function getStatus()
    {
        return $this->status;
    }

}