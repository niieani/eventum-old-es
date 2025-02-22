<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 encoding=utf-8: */
// +----------------------------------------------------------------------+
// | Eventum - Issue Tracking System                                      |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003 - 2008 MySQL AB                                   |
// | Copyright (c) 2008 - 2010 Sun Microsystem Inc.                       |
// | Copyright (c) 2011 - 2013 Eventum Team.                              |
// |                                                                      |
// | This program is free software; you can redistribute it and/or modify |
// | it under the terms of the GNU General Public License as published by |
// | the Free Software Foundation; either version 2 of the License, or    |
// | (at your option) any later version.                                  |
// |                                                                      |
// | This program is distributed in the hope that it will be useful,      |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of       |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the        |
// | GNU General Public License for more details.                         |
// |                                                                      |
// | You should have received a copy of the GNU General Public License    |
// | along with this program; if not, write to:                           |
// |                                                                      |
// | Free Software Foundation, Inc.                                       |
// | 59 Temple Place - Suite 330                                          |
// | Boston, MA 02111-1307, USA.                                          |
// +----------------------------------------------------------------------+
// | Authors: João Prado Maia <jpm@mysql.com>                             |
// +----------------------------------------------------------------------+
//


/**
 * Class designed to handle all business logic related to the issues in the
 * system, such as adding or updating them or listing them in the grid mode.
 *
 * @author  João Prado Maia <jpm@mysql.com>
 */

class Issue
{
    /**
     * Method used to check whether a given issue ID exists or not.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   boolean $check_project If we should check that this issue is in the current project
     * @return  boolean
     */
    function exists($issue_id, $check_project = true)
    {
        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        if ($check_project) {
            $stmt .= " AND
                    iss_prj_id = " . Auth::getCurrentProject();
        }
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        return $res != 0;
    }


    /**
     * Method used to get the list of column heading titles for the
     * CSV export functionality of the issue listing screen.
     *
     * @param   integer $prj_id The project ID
     * @return  array The list of column heading titles
     */
    public static function getColumnHeadings($prj_id)
    {
        $headings = array(
            'Priority',
            'Issue ID',
            'Reporter',
        );
        // hide the group column from the output if no
        // groups are available in the database
        $groups = Group::getAssocList($prj_id);
        if (count($groups) > 0) {
            $headings[] = 'Group';
        }
        $headings[] = 'Assigned';
        $headings[] = 'Time Spent';
        // hide the category column from the output if no
        // categories are available in the database
        $categories = Category::getAssocList($prj_id);
        if (count($categories) > 0) {
            $headings[] = 'Category';
        }
        if (Customer::hasCustomerIntegration($prj_id)) {
            $headings[] = 'Customer';
        }
        $headings[] = 'Status';
        $headings[] = 'Status Change Date';
        $headings[] = 'Last Action Date';
        $headings[] = 'Est. Dev. TIme';
        $headings[] = 'Summary';
        $headings[] = 'Expected Resolution Date';
        return $headings;
    }


    /**
     * Method used to get the full list of date fields available to issues, to
     * be used when customizing the issue listing screen in the 'last status
     * change date' column.
     *
     * @access  public
     * @param   boolean $display_customer_fields Whether to include any customer related fields or not
     * @return  array The list of available date fields
     */
    function getDateFieldsAssocList($display_customer_fields = FALSE)
    {
        $fields = array(
            'iss_created_date'              => 'Created Date',
            'iss_updated_date'              => 'Last Updated Date',
            'iss_last_response_date'        => 'Last Response Date',
            'iss_closed_date'               => 'Closed Date'
        );
        if ($display_customer_fields) {
            $fields['iss_last_customer_action_date'] = 'Customer Action Date';
        }
        asort($fields);
        return $fields;
    }


    /**
     * Method used to get the full list of issue IDs and their respective
     * titles associated to a given project.
     *
     * @access  public
     * @param   integer $prj_id The project ID
     * @return  array The list of issues
     */
    function getAssocListByProject($prj_id)
    {
        $stmt = "SELECT
                    iss_id,
                    iss_summary
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_prj_id=" . Misc::escapeInteger($prj_id) . "
                 ORDER BY
                    iss_id ASC";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        }

        return $res;
    }


    /**
     * Method used to get the status of a given issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer The status ID
     */
    function getStatusID($issue_id)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_sta_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Records the last customer action date for a given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function recordLastCustomerAction($issue_id)
    {
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_last_customer_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_type='customer action'
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        return 1;
    }


    /**
     * Returns the customer ID associated with the given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer The customer ID associated with the issue
     */
    public static function getCustomerID($issue_id)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_customer_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Returns the contract ID associated with the given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer The customer ID associated with the issue
     */
    function getContractID($issue_id)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_customer_contract_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Sets the contract ID for a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer The contract ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setContractID($issue_id, $contract_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);

        $old_contract_id = self::getContractID($issue_id);

        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                SET
                    iss_customer_contract_id = " . Misc::escapeInteger($contract_id) . "
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // log this
        History::add($issue_id, Auth::getUserID(), History::getTypeID("contract_changed"), "Contract changed from $old_contract_id to $contract_id by " . User::getFullName(Auth::getUserID()));
        return 1;
    }


    /**
     * Returns the customer ID associated with the given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer The customer ID associated with the issue
     */
    function getContactID($issue_id)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_customer_contact_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Method used to get the project associated to a given issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   boolean $force_refresh If the cache should not be used.
     * @return  integer The project ID
     */
    function getProjectID($issue_id, $force_refresh = false)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if ((!empty($returns[$issue_id])) && ($force_refresh != true)) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_prj_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Method used to remotely assign a given issue to an user.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $usr_id The user ID of the person performing the change
     * @param   boolean $assignee The user ID of the assignee
     * @return  integer The status ID
     */
    function remoteAssign($issue_id, $usr_id, $assignee)
    {
        Workflow::handleAssignmentChange(self::getProjectID($issue_id), $issue_id, $usr_id, self::getDetails($issue_id), array($assignee), true);
        // clear up the assignments for this issue, and then assign it to the current user
        self::deleteUserAssociations($issue_id, $usr_id);
        $res = self::addUserAssociation($usr_id, $issue_id, $assignee, false);
        if ($res != -1) {
            // save a history entry about this...
            History::add($issue_id, $usr_id, History::getTypeID('remote_assigned'), "Issue remotely assigned to " . User::getFullName($assignee) . " by " . User::getFullName($usr_id));
            Notification::subscribeUser($usr_id, $issue_id, $assignee, Notification::getDefaultActions($issue_id, User::getEmail($assignee), 'remote_assign'), false);
            if ($assignee != $usr_id) {
                Notification::notifyNewAssignment(array($assignee), $issue_id);
            }
        }
        return $res;
    }


    /**
     * Method used to set the status of a given issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $status_id The new status ID
     * @param   boolean $notify If a notification should be sent about this change.
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setStatus($issue_id, $status_id, $notify = false)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $status_id = Misc::escapeInteger($status_id);

        $workflow = Workflow::preStatusChange(self::getProjectID($issue_id), $issue_id, $status_id, $notify);
        if ($workflow !== true) {
            return $workflow;
        }

        // check if the status is already set to the 'new' one
        if (self::getStatusID($issue_id) == $status_id) {
            return -1;
        }

        $old_status = self::getStatusID($issue_id);
        $old_details = Status::getDetails($old_status);

        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_sta_id=$status_id,
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_type='update'
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // clear out the last-triggered-reminder flag when changing the status of an issue
        Reminder_Action::clearLastTriggered($issue_id);

        // if old status was closed and new status is not, clear closed data from issue.
        if (@$old_details['sta_is_closed'] == 1) {
            $new_details = Status::getDetails($status_id);
            if ($new_details['sta_is_closed'] != 1) {
                self::clearClosed($issue_id);
            }
        }

        if ($notify) {
            Notification::notifyStatusChange($issue_id, $old_status, $status_id);
        }

        return 1;
    }


    /**
     * Method used to remotely set the status of a given issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $usr_id The user ID of the person performing this change
     * @param   integer $new_status The new status ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setRemoteStatus($issue_id, $usr_id, $new_status)
    {
        $sta_id = Status::getStatusID($new_status);

        $res = self::setStatus($issue_id, $sta_id);
        if ($res == 1) {
            // record history entry
            History::add($issue_id, $usr_id, History::getTypeID('remote_status_change'), "Status remotely changed to '$new_status' by " . User::getFullName($usr_id));
        }
        return $res;
    }


    /**
     * Method used to set the release of an issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @param   integer $pre_id The ID of the release to set this issue too
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setRelease($issue_id, $pre_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $pre_id = Misc::escapeInteger($pre_id);

        if ($pre_id != self::getRelease($issue_id)) {
            $sql = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                    SET
                        iss_pre_id = $pre_id
                    WHERE
                        iss_id = $issue_id";
            $res = DB_Helper::getInstance()->query($sql);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            }

            return 1;
        }
    }


    /**
     * Returns the current release of an issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @return  integer The release
     */
    function getRelease($issue_id)
    {
        $sql = "SELECT
                    iss_pre_id
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id = " . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return $res;
    }


    /**
     * Method used to set the priority of an issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @param   integer $pri_id The ID of the priority to set this issue too
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setPriority($issue_id, $pri_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $pri_id = Misc::escapeInteger($pri_id);

        if ($pri_id != self::getPriority($issue_id)) {
            $sql = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                    SET
                        iss_pri_id = $pri_id
                    WHERE
                        iss_id = $issue_id";
            $res = DB_Helper::getInstance()->query($sql);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            }

            return 1;
        }
    }


    /**
     * Returns the current issue priority
     *
     * @param   integer $issue_id The ID of the issue
     * @return  integer The priority
     */
    public static function getPriority($issue_id)
    {
        $sql = "SELECT
                    iss_pri_id
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id = " . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return $res;
    }

    /**
     * Method used to set the expected resolution date of an issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @param   string $expected_resolution_date The Expected Resolution Date to set this issue too
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setExpectedResolutionDate($issue_id, $expected_resolution_date)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $expected_resolution_date = Misc::escapeString($expected_resolution_date);
        $current = self::getExpectedResolutionDate($issue_id);
        if ($expected_resolution_date != $current) {
            $sql = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                    SET
                        iss_expected_resolution_date = " . (empty($expected_resolution_date) ? "null" : " '$expected_resolution_date'") . "
                    WHERE
                        iss_id = $issue_id";
            $res = DB_Helper::getInstance()->query($sql);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            }

            $usr_id = Auth::getUserID();
            Notification::notifyIssueUpdated($issue_id, array('iss_expected_resolution_date' => $current), array('expected_resolution_date' => $expected_resolution_date));
            History::add($issue_id, $usr_id, History::getTypeID('issue_updated'), "Issue updated (Expected Resolution Date: " . History::formatChanges($current, $expected_resolution_date) . ") by " . User::getFullName($usr_id));
            return 1;
        }
    }

    /**
     * Returns the current issue expected resolution date
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @return  string The Expected Resolution Date
     */
    function getExpectedResolutionDate($issue_id)
    {
        $sql = "SELECT
                    iss_expected_resolution_date
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id = " . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return $res;
    }

    /**
     * Method used to set the category of an issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @param   integer $prc_id The ID of the category to set this issue too
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setCategory($issue_id, $prc_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $prc_id = Misc::escapeInteger($prc_id);

        if ($prc_id != self::getPriority($issue_id)) {
            $sql = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                    SET
                        iss_prc_id = $prc_id
                    WHERE
                        iss_id = $issue_id";
            $res = DB_Helper::getInstance()->query($sql);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            }

            return 1;
        }
    }


    /**
     * Returns the current issue category
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @return  integer The category
     */
    function getCategory($issue_id)
    {
        $sql = "SELECT
                    iss_prc_id
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id = " . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return $res;
    }


    /**
     * Method used to get all issues associated with a status that doesn't have
     * the 'closed' context.
     *
     * @access  public
     * @param   integer $prj_id The project ID to list issues from
     * @param   integer $usr_id The user ID of the user requesting this information
     * @param   boolean $show_all_issues Whether to show all open issues, or just the ones assigned to the given email address
     * @param   integer $status_id The status ID to be used to restrict results
     * @return  array The list of open issues
     */
    function getOpenIssues($prj_id, $usr_id, $show_all_issues, $status_id)
    {
        $prj_id = Misc::escapeInteger($prj_id);
        $status_id = Misc::escapeInteger($status_id);
        $projects = Project::getRemoteAssocListByUser($usr_id);
        if (@count($projects) == 0) {
            return '';
        }

        $stmt = "SELECT
                    iss_id,
                    iss_summary,
                    sta_title
                 FROM
                    (
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                    )
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                 ON
                    isu_iss_id=iss_id
                 WHERE ";
        if (!empty($status_id)) {
            $stmt .= " sta_id=$status_id AND ";
        }
        $stmt .= "
                    iss_prj_id=$prj_id AND
                    sta_id=iss_sta_id AND
                    sta_is_closed=0";
        if ($show_all_issues == false) {
            $stmt .= " AND
                    isu_usr_id=$usr_id";
        }
        $stmt .= "\nGROUP BY
                        iss_id";
        $res = DB_Helper::getInstance()->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        if (count($res) > 0) {
            self::getAssignedUsersByIssues($res);
        }
        return $res;
    }


    /**
     * Method used to build the required parameters to simulate an email reply
     * to the user who reported the issue, using the issue details like summary
     * and description as email fields.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The email parameters
     */
    function getReplyDetails($issue_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);

        $stmt = "SELECT
                    iss_created_date,
                    usr_full_name AS reporter,
                    usr_email AS reporter_email,
                    iss_description AS description,
                    iss_summary AS sup_subject
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    iss_usr_id=usr_id AND
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return '';
        }

        // TRANSLATORS: %1 = issue_id, %2 = issue summary
        $res['reply_subject'] = ev_gettext('Re: [#%1$s] %2$s', $issue_id, $res["sup_subject"]);
        $res['created_date_ts'] = Date_Helper::getUnixTimestamp($res['iss_created_date'], 'GMT');
        return $res;
    }


    /**
     * Method used to record the last updated timestamp for a given
     * issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   string $type The type of update that was made (optional)
     * @return  boolean
     */
    function markAsUpdated($issue_id, $type = false)
    {
        $public = array("staff response", "customer action", "file uploaded", "user response");
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "'\n";
        if ($type != false) {
            if (in_array($type, $public)) {
                $field = "iss_last_public_action_";
            } else {
                $field = "iss_last_internal_action_";
            }
            $stmt .= ",\n " . $field . "date = '" . Date_Helper::getCurrentDateGMT() . "',\n" .
                $field . "type  ='" . Misc::escapeString($type) . "'\n";
        }
        $stmt .= "WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        // update last response dates if this is a staff response
        if ($type == "staff response") {
            $stmt = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                     SET
                        iss_last_response_date='" . Date_Helper::getCurrentDateGMT() . "'
                     WHERE
                        iss_id = " . Misc::escapeInteger($issue_id);
            DB_Helper::getInstance()->query($stmt);
            $stmt = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                     SET
                        iss_first_response_date='" . Date_Helper::getCurrentDateGMT() . "'
                     WHERE
                        iss_first_response_date IS NULL AND
                        iss_id = " . Misc::escapeInteger($issue_id);
            DB_Helper::getInstance()->query($stmt);
        }

        return true;
    }


    /**
     * Method used to check whether a given issue has duplicates
     * or not.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  boolean
     */
    function hasDuplicates($issue_id)
    {
        $stmt = "SELECT
                    COUNT(iss_id)
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_duplicated_iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        return $res != 0;
    }


    /**
     * Method used to update the duplicated issues for a given
     * issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function updateDuplicates($issue_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);

        $ids = self::getDuplicateList($issue_id);
        if ($ids == '') {
            return -1;
        }
        $ids = @array_keys($ids);
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_type='updated',
                    iss_prc_id=" . Misc::escapeInteger($_POST["category"]) . ",";
        if (@$_POST["keep"] == "no") {
            $stmt .= "iss_pre_id=" . Misc::escapeInteger($_POST["release"]) . ",";
        }
        $stmt .= "
                    iss_pri_id=" . Misc::escapeInteger($_POST["priority"]) . ",
                    iss_sta_id=" . Misc::escapeInteger($_POST["status"]) . ",
                    iss_res_id=" . Misc::escapeInteger($_POST["resolution"]) . "
                 WHERE
                    iss_id IN (" . implode(", ", $ids) . ")";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // record the change
        for ($i = 0; $i < count($ids); $i++) {
            History::add($ids[$i], Auth::getUserID(), History::getTypeID('duplicate_update'),
                "The details for issue #$issue_id were updated by " . User::getFullName(Auth::getUserID()) . " and the changes propagated to the duplicated issues.");
        }
        return 1;
    }


    /**
     * Method used to get a list of the duplicate issues for a given
     * issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of duplicates
     */
    function getDuplicateList($issue_id)
    {
        $res = self::getDuplicateDetailsList($issue_id);
        if (@count($res) == 0) {
            return '';
        }

        $list = array();
        for ($i = 0; $i < count($res); $i++) {
            $list[$res[$i]['issue_id']] = $res[$i]['title'];
        }
        return $list;
    }


    /**
     * Method used to get a list of the duplicate issues (and their details)
     * for a given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of duplicates
     */
    function getDuplicateDetailsList($issue_id)
    {
        static $returns;

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    iss_id issue_id,
                    iss_summary title,
                    sta_title current_status,
                    sta_is_closed is_closed
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 WHERE
                    iss_sta_id=sta_id AND
                    iss_duplicated_iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Method used to clear the duplicate status of an issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function clearDuplicateStatus($issue_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_type='updated',
                    iss_duplicated_iss_id=NULL
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // record the change
        History::add($issue_id, Auth::getUserID(), History::getTypeID('duplicate_removed'), "Duplicate flag was reset by " . User::getFullName(Auth::getUserID()));
        return 1;
    }


    /**
     * Method used to mark an issue as a duplicate of an existing one.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function markAsDuplicate($issue_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        if (!self::exists($issue_id)) {
            return -1;
        }

        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_type='updated',
                    iss_duplicated_iss_id=" . Misc::escapeInteger($_POST["duplicated_issue"]) . "
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        if (!empty($_POST["comments"])) {
            // add note with the comments of marking an issue as a duplicate of another one
            $_POST['title'] = 'Issue duplication comments';
            $_POST["note"] = $_POST["comments"];
            Note::insert(Auth::getUserID(), $issue_id);
        }
        // record the change
        History::add($issue_id, Auth::getUserID(), History::getTypeID('duplicate_added'),
                "Issue marked as a duplicate of issue #" . $_POST["duplicated_issue"] . " by " . User::getFullName(Auth::getUserID()));
        return 1;
    }


    function isDuplicate($issue_id)
    {
        $sql = "SELECT
                    count(iss_id)
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id = " . Misc::escapeInteger($issue_id) . " AND
                    iss_duplicated_iss_id IS NULL";
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        return !($res > 0);
    }


    /**
     * Method used to get an associative array of user ID => user
     * status associated with a given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of users
     */
    function getAssignedUsersStatus($issue_id)
    {
        $stmt = "SELECT
                    usr_id,
                    usr_status
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    isu_iss_id=" . Misc::escapeInteger($issue_id) . " AND
                    isu_usr_id=usr_id";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        return $res;
    }


    /**
     * Method used to get the summary associated with a given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  string The issue summary
     */
    function getTitle($issue_id)
    {
        $stmt = "SELECT
                    iss_summary
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        }

        return $res;
    }


    /**
     * Method used to get the issue ID associated with a specific summary.
     *
     * @access  public
     * @param   string $summary The summary to look for
     * @return  integer The issue ID
     */
    function getIssueID($summary)
    {
        $stmt = "SELECT
                    iss_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_summary='" . Misc::escapeString($summary) . "'";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return !empty($res) ? $res : 0;
    }


    /**
     * Method used to add a new anonymous based issue in the system.
     *
     * @access  public
     * @return  integer The new issue ID
     */
    function addAnonymousReport()
    {
        $options = Project::getAnonymousPostOptions($_POST["project"]);
        $initial_status = Project::getInitialStatus($_POST["project"]);
        $stmt = "INSERT INTO
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 (
                    iss_prj_id,
                    iss_prc_id,
                    iss_pre_id,
                    iss_pri_id,
                    iss_usr_id,";
        if (!empty($initial_status)) {
            $stmt .= "iss_sta_id,";
        }
        $stmt .= "
                    iss_created_date,
                    iss_last_public_action_date,
                    iss_last_public_action_type,
                    iss_summary,
                    iss_description,
                    iss_root_message_id
                 ) VALUES (
                    " . Misc::escapeInteger($_POST["project"]) . ",
                    " . $options["category"] . ",
                    0,
                    " . $options["priority"] . ",
                    " . $options["reporter"] . ",";
        if (!empty($initial_status)) {
            $stmt .= "$initial_status,";
        }
        $stmt .= "
                    '" . Date_Helper::getCurrentDateGMT() . "',
                    '" . Date_Helper::getCurrentDateGMT() . "',
                    'created',
                    '" . Misc::escapeString($_POST["summary"]) . "',
                    '" . Misc::escapeString($_POST["description"]) . "',
                    '" . Misc::escapeString(Mail_Helper::generateMessageID()) . "'
                 )";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return $res;
        }

        $new_issue_id = DB_Helper::get_last_insert_id();
        // log the creation of the issue
        History::add($new_issue_id, APP_SYSTEM_USER_ID, History::getTypeID('issue_opened_anon'), 'Issue opened anonymously');

        // now process any files being uploaded
        $found = 0;
        for ($i = 0; $i < count(@$_FILES["file"]["name"]); $i++) {
            if (!@empty($_FILES["file"]["name"][$i])) {
                $found = 1;
                break;
            }
        }
        if ($found) {
            $attachment_id = Attachment::add($new_issue_id, $options["reporter"], 'files uploaded anonymously');
            for ($i = 0; $i < count(@$_FILES["file"]["name"]); $i++) {
                $filename = @$_FILES["file"]["name"][$i];
                if (empty($filename)) {
                    continue;
                }
                $blob = file_get_contents($_FILES["file"]["tmp_name"][$i]);
                if (!empty($blob)) {
                    Attachment::addFile($attachment_id, $filename, $_FILES["file"]["type"][$i], $blob);
                }
            }
        }
        // need to process any custom fields ?
        if (@count($_POST["custom_fields"]) > 0) {
            foreach ($_POST["custom_fields"] as $fld_id => $value) {
                Custom_Field::associateIssue($new_issue_id, $fld_id, $value);
            }
        }

        // now add the user/issue association
        $assign = array();
        $users = @$options["users"];
        $actions = Notification::getDefaultActions($new_issue_id, false, 'anon_issue');
        for ($i = 0; $i < count($users); $i++) {
            Notification::subscribeUser(APP_SYSTEM_USER_ID, $new_issue_id, $users[$i], $actions);
            self::addUserAssociation(APP_SYSTEM_USER_ID, $new_issue_id, $users[$i]);
            $assign[] = $users[$i];
        }

        Workflow::handleNewIssue(Misc::escapeInteger($_POST["project"]),  $new_issue_id, false, false);

        // also notify any users that want to receive emails anytime a new issue is created
        Notification::notifyNewIssue($_POST['project'], $new_issue_id);

        return $new_issue_id;
    }


    /**
     * Method used to remove all issues associated with a specific list of
     * projects.
     *
     * @access  public
     * @param   array $ids The list of projects to look for
     * @return  boolean
     */
    function removeByProjects($ids)
    {
        $items = @implode(", ", Misc::escapeInteger($ids));
        $stmt = "SELECT
                    iss_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_prj_id IN ($items)";
        $res = DB_Helper::getInstance()->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        if (count($res) > 0) {
            self::deleteAssociations($res);
            Attachment::removeByIssues($res);
            SCM::removeByIssues($res);
            Impact_Analysis::removeByIssues($res);
            self::deleteUserAssociations($res);
            Note::removeByIssues($res);
            Time_Tracking::removeByIssues($res);
            Notification::removeByIssues($res);
            Custom_Field::removeByIssues($res);
            Phone_Support::removeByIssues($res);
            History::removeByIssues($res);
            // now really delete the issues
            $items = implode(", ", $res);
            $stmt = "DELETE FROM
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                     WHERE
                        iss_id IN ($items)";
            DB_Helper::getInstance()->query($stmt);
        }

        return true;
    }


    /**
     * Method used to close off an issue.
     *
     * @access  public
     * @param   integer $usr_id The user ID
     * @param   integer $issue_id The issue ID
     * @param   bool $send_notification Whether to send a notification about this action or not
     * @param   integer $resolution_id The resolution ID
     * @param   integer $status_id The status ID
     * @param   string $reason The reason for closing this issue
     * @param   string  $send_notification_to Who this notification should be sent too
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function close($usr_id, $issue_id, $send_notification, $resolution_id, $status_id, $reason, $send_notification_to = 'internal')
    {
        $usr_id = Misc::escapeInteger($usr_id);
        $issue_id = Misc::escapeInteger($issue_id);
        $resolution_id = Misc::escapeInteger($resolution_id);
        $status_id = Misc::escapeInteger($status_id);

        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_type='closed',
                    iss_closed_date='" . Date_Helper::getCurrentDateGMT() . "',\n";
        if (!empty($resolution_id)) {
            $stmt .= "iss_res_id=$resolution_id,\n";
        }
        $stmt .= "iss_sta_id=$status_id
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        $prj_id = self::getProjectID($issue_id);

        // record the change
        History::add($issue_id, $usr_id, History::getTypeID('issue_closed'), "Issue updated to status '" . Status::getStatusTitle($status_id) . "' by " . User::getFullName($usr_id));

        if ($send_notification_to == 'all') {

            $from = User::getFromHeader($usr_id);
            $message_id = User::getFromHeader($usr_id);
            $full_email = Support::buildFullHeaders($issue_id, $message_id, $from,
                '', '', 'Issue closed comments', $reason, '');

            $structure = Mime_Helper::decode($full_email, true, false);

            $email = array(
                'ema_id'        =>  Email_Account::getEmailAccount(self::getProjectID($issue_id)),
                'issue_id'      =>  $issue_id,
                'message_id'    =>  $message_id,
                'date'          =>  Date_Helper::getCurrentDateGMT(),
                'subject'       =>  'Issue closed comments',
                'from'          =>  $from,
                'has_attachment'=>  0,
                'body'          =>  $reason,
                'full_email'    =>  $full_email,
                'headers'       =>  $structure->headers
            );
            Support::insertEmail($email, $structure, $sup_id, true);
            $ids = $sup_id;
        } else {
            // add note with the reason to close the issue
            $_POST['title'] = 'Issue closed comments';
            $_POST["note"] = $reason;
            Note::insert($usr_id, $issue_id, false, true, true, $send_notification);
            $ids = false;
        }

        if ($send_notification) {
            if (Customer::hasCustomerIntegration($prj_id)) {
                // send a special confirmation email when customer issues are closed
                $stmt = "SELECT
                            iss_customer_contact_id
                         FROM
                            " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                         WHERE
                            iss_id=$issue_id";
                $customer_contact_id = DB_Helper::getInstance()->getOne($stmt);
                if (!empty($customer_contact_id)) {
                    Customer::notifyIssueClosed($prj_id, $issue_id, $customer_contact_id, $send_notification, $resolution_id, $status_id, $reason);
                }
            }
            // send notifications for the issue being closed
            Notification::notify($issue_id, 'closed', $ids);
        }
        Workflow::handleIssueClosed($prj_id, $issue_id, $send_notification, $resolution_id, $status_id, $reason);
        return 1;
    }


    /**
     * Method used to update the details of a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 or -2 otherwise
     */
    function update($issue_id)
    {
        global $errors;
        $errors = array();

        $issue_id = Misc::escapeInteger($issue_id);

        $usr_id = Auth::getUserID();
        $prj_id = self::getProjectID($issue_id);

        $workflow = Workflow::preIssueUpdated($prj_id, $issue_id, $usr_id, $_POST);
        if ($workflow !== true) {
            return $workflow;
        }

        // get all of the 'current' information of this issue
        $current = self::getDetails($issue_id);
        // update the issue associations
        if (empty($_POST['associated_issues'])) {
            $associated_issues = array();
        } else {
            $associated_issues = explode(',', @$_POST['associated_issues']);
            // make sure all associated issues are valid (and in this project)
            for ($i = 0; $i < count($associated_issues); $i++) {
                if (!self::exists(trim($associated_issues[$i]), false)) {
                    $errors['Associated Issues'][] = 'Issue #' . $associated_issues[$i] . ' does not exist and was removed from the list of associated issues.';
                    unset($associated_issues[$i]);
                }
            }
        }
        $association_diff = Misc::arrayDiff($current['associated_issues'], $associated_issues);
        if (count($association_diff) > 0) {
            // go through the new assocations, if association already exists, skip it
            $associations_to_remove = $current['associated_issues'];
            if (count($associated_issues) > 0) {
                foreach ($associated_issues as $index => $associated_id) {
                    if (!in_array($associated_id, $current['associated_issues'])) {
                        self::addAssociation($issue_id, $associated_id, $usr_id);
                    } else {
                        // already assigned, remove this user from list of users to remove
                        unset($associations_to_remove[array_search($associated_id, $associations_to_remove)]);
                    }
                }
            }
            if (count($associations_to_remove) > 0) {
                foreach ($associations_to_remove as $associated_id) {
                    self::deleteAssociation($issue_id, $associated_id);
                }
            }
        }
        $assignments_changed = false;
        if (@$_POST["keep_assignments"] == "no") {
            // only change the issue-user associations if there really were any changes
            $old_assignees = array_merge($current['assigned_users'], $current['assigned_inactive_users']);
            if (!empty($_POST['assignments'])) {
                $new_assignees = @$_POST['assignments'];
            } else {
                $new_assignees = array();
            }
            $assignment_notifications = array();

            // remove people from the assignment list, if appropriate
            foreach ($old_assignees as $assignee) {
                if (!in_array($assignee, $new_assignees)) {
                    self::deleteUserAssociation($issue_id, $assignee);
                    $assignments_changed = true;
                }
            }
            // add people to the assignment list, if appropriate
            foreach ($new_assignees as $assignee) {
                if (!in_array($assignee, $old_assignees)) {
                    self::addUserAssociation($usr_id, $issue_id, $assignee);
                    Notification::subscribeUser($usr_id, $issue_id, $assignee, Notification::getDefaultActions($issue_id, User::getEmail($assignee), 'issue_update'), TRUE);
                    $assignment_notifications[] = $assignee;
                    $assignments_changed = true;
                }
            }
            if (count($assignment_notifications) > 0) {
                Notification::notifyNewAssignment($assignment_notifications, $issue_id);
            }
        }
        if (empty($_POST["estimated_dev_time"])) {
            $_POST["estimated_dev_time"] = 0;
        }
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_type='updated',";
        if (!empty($_POST["category"])) {
            $stmt .= "iss_prc_id=" . Misc::escapeInteger($_POST["category"]) . ",";
        }
        if (@$_POST["keep"] == "no") {
            $stmt .= "iss_pre_id=" . Misc::escapeInteger($_POST["release"]) . ",";
        }
        if (!empty($_POST['expected_resolution_date'])) {
            $stmt .= "iss_expected_resolution_date='" . Misc::escapeString($_POST['expected_resolution_date']) . "',";
        } else {
            $stmt .= "iss_expected_resolution_date=null,";
        }
        $stmt .= "
                    iss_pre_id=" . Misc::escapeInteger($_POST["release"]) . ",
                    iss_pri_id=" . Misc::escapeInteger($_POST["priority"]) . ",
                    iss_sta_id=" . Misc::escapeInteger($_POST["status"]) . ",
                    iss_res_id=" . Misc::escapeInteger($_POST["resolution"]) . ",
                    iss_summary='" . Misc::escapeString($_POST["summary"]) . "',
                    iss_description='" . Misc::escapeString($_POST["description"]) . "',
                    iss_dev_time='" . Misc::escapeString($_POST["estimated_dev_time"]) . "',
                    iss_percent_complete= '" . Misc::escapeString($_POST["percent_complete"]) . "',
                    iss_trigger_reminders=" . Misc::escapeInteger($_POST["trigger_reminders"]) . ",
                    iss_grp_id ='" . Misc::escapeInteger($_POST["group"]) . "'";
        if (isset($_POST['private'])) {
            $stmt .= ",
                    iss_private = " . Misc::escapeInteger($_POST['private']);
        }
        $stmt .= "
                 WHERE
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // add change to the history (only for changes on specific fields?)
        $updated_fields = array();
        if ($current["iss_expected_resolution_date"] != $_POST['expected_resolution_date']) {
            $updated_fields["Expected Resolution Date"] = History::formatChanges($current["iss_expected_resolution_date"], $_POST['expected_resolution_date']);
        }
        if ($current["iss_prc_id"] != $_POST["category"]) {
            $updated_fields["Category"] = History::formatChanges(Category::getTitle($current["iss_prc_id"]), Category::getTitle($_POST["category"]));
        }
        if ($current["iss_pre_id"] != $_POST["release"]) {
            $updated_fields["Release"] = History::formatChanges(Release::getTitle($current["iss_pre_id"]), Release::getTitle($_POST["release"]));
        }
        if ($current["iss_pri_id"] != $_POST["priority"]) {
            $updated_fields["Priority"] = History::formatChanges(Priority::getTitle($current["iss_pri_id"]), Priority::getTitle($_POST["priority"]));
            Workflow::handlePriorityChange($prj_id, $issue_id, $usr_id, $current, $_POST);
        }
        if ($current["iss_sta_id"] != $_POST["status"]) {
            // clear out the last-triggered-reminder flag when changing the status of an issue
            Reminder_Action::clearLastTriggered($issue_id);

            // if old status was closed and new status is not, clear closed data from issue.
            $old_status_details = Status::getDetails($current['iss_sta_id']);
            if ($old_status_details['sta_is_closed'] == 1) {
                $new_status_details = Status::getDetails($_POST["status"]);
                if ($new_status_details['sta_is_closed'] != 1) {
                    self::clearClosed($issue_id);
                }
            }
            $updated_fields["Status"] = History::formatChanges(Status::getStatusTitle($current["iss_sta_id"]), Status::getStatusTitle($_POST["status"]));
        }
        if ($current["iss_res_id"] != $_POST["resolution"]) {
            $updated_fields["Resolution"] = History::formatChanges(Resolution::getTitle($current["iss_res_id"]), Resolution::getTitle($_POST["resolution"]));
        }
        if ($current["iss_dev_time"] != $_POST["estimated_dev_time"]) {
            $updated_fields["Estimated Dev. Time"] = History::formatChanges(Misc::getFormattedTime(($current["iss_dev_time"]*60)), Misc::getFormattedTime(($_POST["estimated_dev_time"]*60)));
        }
        if ($current["iss_summary"] != $_POST["summary"]) {
            $updated_fields["Summary"] = '';
        }

        if ($current["iss_original_percent_complete"] != $_POST["percent_complete"]) {
            $updated_fields["Percent complete"] = History::formatChanges($current["iss_original_percent_complete"],$_POST["percent_complete"]);
        }

        if ($current["iss_description"] != $_POST["description"]) {
            $updated_fields["Description"] = '';
        }
        if ((isset($_POST['private'])) && ($_POST['private'] != $current['iss_private'])) {
            $updated_fields["Private"] = History::formatChanges(Misc::getBooleanDisplayValue($current['iss_private']), Misc::getBooleanDisplayValue($_POST['private']));
        }
        if (count($updated_fields) > 0) {
            // log the changes
            $changes = '';
            $i = 0;
            foreach ($updated_fields as $key => $value) {
                if ($i > 0) {
                    $changes .= "; ";
                }
                if (($key != "Summary") && ($key != "Description")) {
                    $changes .= "$key: $value";
                } else {
                    $changes .= "$key";
                }
                $i++;
            }
            History::add($issue_id, $usr_id, History::getTypeID('issue_updated'), "Issue updated ($changes) by " . User::getFullName($usr_id));
            // send notifications for the issue being updated
            Notification::notifyIssueUpdated($issue_id, $current, $_POST);
        }

        // record group change as a seperate change
        if ($current["iss_grp_id"] != (int)$_POST["group"]) {
            History::add($issue_id, $usr_id, History::getTypeID('group_changed'),
                "Group changed (" . History::formatChanges(Group::getName($current["iss_grp_id"]), Group::getName($_POST["group"])) . ") by " . User::getFullName($usr_id));
        }

        // now update any duplicates, if any
        $update_dupe = array(
            'Category',
            'Release',
            'Priority',
            'Release',
            'Resolution'
        );
        // COMPAT: the following line requires PHP > 4.0.4
        $intersect = array_intersect($update_dupe, array_keys($updated_fields));
        if (($current["duplicates"] != '') && (count($intersect) > 0)) {
            self::updateDuplicates($issue_id);
        }

        // if there is customer integration, mark last customer action
        if ((Customer::hasCustomerIntegration($prj_id)) && (User::getRoleByUser($usr_id, $prj_id) == User::getRoleID('Customer'))) {
            self::recordLastCustomerAction($issue_id);
        }

        if ($assignments_changed) {
            // XXX: we may want to also send the email notification for those "new" assignees
            Workflow::handleAssignmentChange(self::getProjectID($issue_id), $issue_id, $usr_id, self::getDetails($issue_id), @$_POST['assignments'], false);
        }

        Workflow::handleIssueUpdated($prj_id, $issue_id, $usr_id, $current, $_POST);
        // Move issue to another project
        if (isset($_POST['move_issue']) and (User::getRoleByUser($usr_id, $prj_id) >= User::getRoleID("Developer"))) {
            $new_prj_id = (int)@$_POST['new_prj'];
            if (($prj_id != $new_prj_id) && (array_key_exists($new_prj_id, Project::getAssocList($usr_id)))) {
                if(User::getRoleByUser($usr_id, $new_prj_id) >= User::getRoleID("Reporter")) {
                    $res = self::moveIssue($issue_id, $new_prj_id);
                    if ($res == -1) {
                        return $res;
                    }
                } else {
                    return -1;
                }
            }
        }
        return 1;
    }


    /**
     * Move the issue to a new project
     *
     * @param integer $issue_id
     * @param integer $new_prj_id
     * @return integer 1 on success, -1 otherwise
     */
    function moveIssue($issue_id, $new_prj_id)
    {
        $stmt = "UPDATE
              " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
          SET
              iss_prj_id = " . Misc::escapeInteger($new_prj_id) . "
          WHERE
              iss_id = " . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        $currentDetails = self::getDetails($issue_id);

        // set new category
        $new_iss_prc_list = Category::getAssocList($new_prj_id);
        $iss_prc_title = Category::getTitle($currentDetails['iss_prc_id']);
        $new_prc_id = array_search($iss_prc_title, $new_iss_prc_list);
        if ($new_prc_id === false) {
          // use the first category listed in the new project
          $new_prc_id = key($new_iss_prc_list);
        }

        // set new priority
        $new_iss_pri_list = Priority::getAssocList($new_prj_id);
        $iss_pri_title = Priority::getTitle($currentDetails['iss_pri_id']);
        $new_pri_id = array_search($iss_pri_title, $new_iss_pri_list);
        if ($new_pri_id === false) {
          // use the first category listed in the new project
          $new_pri_id = key($new_iss_pri_list);
        }

        // XXX: Set status if needed when moving issue
        $stmt = "UPDATE
              " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
          SET
              iss_prc_id=" . Misc::escapeInteger($new_prc_id) . ",
              iss_pri_id=" . Misc::escapeInteger($new_pri_id) . "
          WHERE
              iss_id=$issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
        }

        // clear project cache
        self::getProjectID($issue_id, true);

        Notification::notifyNewIssue($new_prj_id, $issue_id);
        return 1;
    }


    /**
     * Method used to associate an existing issue with another one.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $issue_id The other issue ID
     * @return  void
     */
    function addAssociation($issue_id, $associated_id, $usr_id, $link_issues = TRUE)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $associated_id = Misc::escapeInteger($associated_id);

        $stmt = "INSERT INTO
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_association
                 (
                    isa_issue_id,
                    isa_associated_id
                 ) VALUES (
                    $issue_id,
                    $associated_id
                 )";
        DB_Helper::getInstance()->query($stmt);
        History::add($issue_id, $usr_id, History::getTypeID('issue_associated'), "Issue associated to Issue #$associated_id by " . User::getFullName($usr_id));
        // link the associated issue back to this one
        if ($link_issues) {
            self::addAssociation($associated_id, $issue_id, $usr_id, FALSE);
        }
    }


    /**
     * Method used to remove the issue associations related to a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  void
     */
    function deleteAssociations($issue_id, $usr_id = FALSE)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        if (is_array($issue_id)) {
            $issue_id = implode(", ", $issue_id);
        }
        $stmt = "DELETE FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_association
                 WHERE
                    isa_issue_id IN ($issue_id) OR
                    isa_associated_id IN ($issue_id)";
        DB_Helper::getInstance()->query($stmt);
        if ($usr_id) {
            History::add($issue_id, $usr_id, History::getTypeID('issue_all_unassociated'), 'Issue associations removed by ' . User::getFullName($usr_id));
        }
    }


    /**
     * Method used to remove a issue association from an issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $associated_id The associated issue ID to remove.
     * @return  void
     */
    function deleteAssociation($issue_id, $associated_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $associated_id = Misc::escapeInteger($associated_id);
        $stmt = "DELETE FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_association
                 WHERE
                    (
                        isa_issue_id = $issue_id AND
                        isa_associated_id = $associated_id
                    ) OR
                    (
                        isa_issue_id = $associated_id AND
                        isa_associated_id = $issue_id
                    )";
        DB_Helper::getInstance()->query($stmt);
        History::add($issue_id, Auth::getUserID(), History::getTypeID('issue_unassociated'),
                "Issue association to Issue #$associated_id removed by " . User::getFullName(Auth::getUserID()));
        History::add($associated_id, Auth::getUserID(), History::getTypeID('issue_unassociated'),
                "Issue association to Issue #$issue_id removed by " . User::getFullName(Auth::getUserID()));
    }


    /**
     * Method used to assign an issue with an user.
     *
     * @access  public
     * @param   integer $usr_id The user ID of the person performing this change
     * @param   integer $issue_id The issue ID
     * @param   integer $assignee_usr_id The user ID of the assignee
     * @param   boolean $add_history Whether to add a history entry about this or not
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function addUserAssociation($usr_id, $issue_id, $assignee_usr_id, $add_history = TRUE)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $assignee_usr_id = Misc::escapeInteger($assignee_usr_id);
        $stmt = "INSERT INTO
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                 (
                    isu_iss_id,
                    isu_usr_id,
                    isu_assigned_date
                 ) VALUES (
                    $issue_id,
                    $assignee_usr_id,
                    '" . Date_Helper::getCurrentDateGMT() . "'
                 )";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        if ($add_history) {
            History::add($issue_id, $usr_id, History::getTypeID('user_associated'),
                'Issue assigned to ' . User::getFullName($assignee_usr_id) . ' by ' . User::getFullName($usr_id));
        }
        return 1;
    }


    /**
     * Method used to delete all user assignments for a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $usr_id The user ID of the person performing the change
     * @return  void
     */
    function deleteUserAssociations($issue_id, $usr_id = FALSE)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        if (is_array($issue_id)) {
            $issue_id = implode(", ", $issue_id);
        }
        $stmt = "DELETE FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                 WHERE
                    isu_iss_id IN ($issue_id)";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        if ($usr_id) {
            History::add($issue_id, $usr_id, History::getTypeID('user_all_unassociated'), 'Issue assignments removed by ' . User::getFullName($usr_id));
        }
        return 1;
    }


    /**
     * Method used to delete a single user assignments for a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $usr_id The user to remove.
     * @param   boolean $add_history Whether to add a history entry about this or not
     * @return  void
     */
    function deleteUserAssociation($issue_id, $usr_id, $add_history = true)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $usr_id = Misc::escapeInteger($usr_id);
        $stmt = "DELETE FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                 WHERE
                    isu_iss_id = $issue_id AND
                    isu_usr_id = $usr_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        if ($add_history) {
            History::add($issue_id, Auth::getUserID(), History::getTypeID('user_unassociated'),
                User::getFullName($usr_id) . ' removed from issue by ' . User::getFullName(Auth::getUserID()));
        }
        return 1;
    }


    /**
     * Creates an issue with the given email information.
     *
     * @access  public
     * @param   integer $prj_id The project ID
     * @param   integer $usr_id The user responsible for this action
     * @param   string $sender The original sender of this email
     * @param   string $summary The issue summary
     * @param   string $description The issue description
     * @param   integer $category The category ID
     * @param   integer $priority The priority ID
     * @param   array $assignment The list of users to assign this issue to
     * @param   string $date The date the email was originally sent.
     * @param   string $msg_id The message ID of the email we are creating this issue from.
     * @return  void
     */
    function createFromEmail($prj_id, $usr_id, $sender, $summary, $description, $category, $priority, $assignment, $date, $msg_id)
    {
        $data = array();
        $exclude_list = array();

        $sender_email = Mail_Helper::getEmailAddress($sender);
        $sender_usr_id = User::getUserIDByEmail($sender_email, true);
        if (!empty($sender_usr_id)) {
            $reporter = $sender_usr_id;
            $exclude_list[] = $sender_usr_id;
        }

        $data = array(
            'category' => $category,
            'priority' => $priority,
            'description' => $description,
            'summary' => $summary,
            'msg_id' => $msg_id,
        );

        if (Customer::hasCustomerIntegration($prj_id)) {
            list($customer_id, $customer_contact_id) = Customer::getCustomerIDByEmails($prj_id, array($sender_email));
            if (!empty($customer_id)) {
                $contact = Customer::getContactDetails($prj_id, $customer_contact_id);
                // overwrite the reporter with the customer contact
                $reporter = User::getUserIDByContactID($customer_contact_id);
                $contact_timezone = Date_Helper::getPreferredTimezone($reporter);

                $data['customer'] = $customer_id;
                $data['contact'] = $customer_contact_id;
#                $data['contract'] =  // XXX missing
                $data['contact_person_lname'] = $contact['last_name'];
                $data['contact_person_fname'] = $contact['first_name'];
                $data['contact_email'] = $sender_email;
                $data['contact_phone'] = $contact['phone'];
                $data['contact_timezone'] = $contact_timezone;
            }
        } else {
            $customer_id = false;
        }
        if (empty($reporter)) {
            $reporter = APP_SYSTEM_USER_ID;
        }

        $data['reporter'] = $reporter;

        $issue_id = self::insertIssue($prj_id, $usr_id, $data);
        if ($issue_id == -1) {
            return -1;
        }

        $has_TAM = false;
        $has_RR = false;
        // log the creation of the issue
        History::add($issue_id, $usr_id, History::getTypeID('issue_opened'), 'Issue opened by ' . $sender);

        $emails = array();
        $manager_usr_ids = array();
        if ((Customer::hasCustomerIntegration($prj_id)) && (!empty($customer_id))) {
            // if there are any technical account managers associated with this customer, add these users to the notification list
            $managers = Customer::getAccountManagers($prj_id, $customer_id);
            $manager_usr_ids = array_keys($managers);
            $manager_emails = array_values($managers);
            $emails = array_merge($emails, $manager_emails);
        }
        // add the reporter to the notification list
        $emails[] = $sender;
        $emails = array_unique($emails);
        $actions = Notification::getDefaultActions($issue_id, false, 'issue_from_email');
        foreach ($emails as $address) {
            Notification::subscribeEmail($reporter, $issue_id, $address, $actions);
        }

        // only assign the issue to an user if the associated customer has any technical account managers
        $users = array();
        if ((Customer::hasCustomerIntegration($prj_id)) && (count($manager_usr_ids) > 0)) {
            foreach ($manager_usr_ids as $manager_usr_id) {
                $users[] = $manager_usr_id;
                self::addUserAssociation(APP_SYSTEM_USER_ID, $issue_id, $manager_usr_id, false);
                History::add($issue_id, $usr_id, History::getTypeID('issue_auto_assigned'), 'Issue auto-assigned to ' . User::getFullName($manager_usr_id) . ' (TAM)');
            }
            $has_TAM = true;
        }
        // now add the user/issue association
        if (@count($assignment) > 0) {
            for ($i = 0; $i < count($assignment); $i++) {
                Notification::subscribeUser($reporter, $issue_id, $assignment[$i], $actions);
                self::addUserAssociation(APP_SYSTEM_USER_ID, $issue_id, $assignment[$i]);
                if ($assignment[$i] != $usr_id) {
                    $users[] = $assignment[$i];
                }
            }
        } else {
            // only use the round-robin feature if this new issue was not
            // already assigned to a customer account manager
            if (@count($manager_usr_ids) < 1) {
                $assignee = Round_Robin::getNextAssignee($prj_id);
                // assign the issue to the round robin person
                if (!empty($assignee)) {
                    self::addUserAssociation(APP_SYSTEM_USER_ID, $issue_id, $assignee, false);
                    History::add($issue_id, APP_SYSTEM_USER_ID, History::getTypeID('rr_issue_assigned'), 'Issue auto-assigned to ' . User::getFullName($assignee) . ' (RR)');
                    $users[] = $assignee;
                    $has_RR = true;
                }
            }
        }
        if (count($users) > 0) {
            $has_assignee = true;
        }

        Workflow::handleNewIssue($prj_id, $issue_id, $has_TAM, $has_RR);
	if(defined('APP_NOTIFY_AUTO_CREATED_ISSUE') && APP_NOTIFY_AUTO_CREATED_ISSUE == true)
	{
        // send special 'an issue was auto-created for you' notification back to the sender
        Notification::notifyAutoCreatedIssue($prj_id, $issue_id, $sender, $date, $summary);
	}
        // also notify any users that want to receive emails anytime a new issue is created
        Notification::notifyNewIssue($prj_id, $issue_id, $exclude_list);

        return $issue_id;
    }


    /**
     * Return errors that happened when creating new issue from POST method.
     *
     * @return  array
     */
    private static $insert_errors = array();
    static function getInsertErrors() {
        return self::$insert_errors;
    }

    /**
     * Method used to add a new issue using the normal report form.
     *
     * @return  integer The new issue ID
     */
    public static function createFromPost()
    {
        $keys = array(
            'add_primary_contact', 'attached_emails', 'category', 'contact', 'contact_email', 'contact_extra_emails', 'contact_person_fname',
            'contact_person_lname', 'contact_phone', 'contact_timezone', 'contract', 'customer', 'custom_fields', 'description',
            'estimated_dev_time', 'group', 'notify_customer', 'notify_senders', 'priority', 'private', 'release', 'summary', 'users',
        );
        $data = array();
        foreach ($keys as $key) {
            if (isset($_POST[$key])) {
                $data[$key] = $_POST[$key];
            }
        }

        $prj_id = Auth::getCurrentProject();
        $usr_id = Auth::getUserID();

        // if we are creating an issue for a customer, put the
        // main customer contact as the reporter for it
        if (Customer::hasCustomerIntegration($prj_id)) {
            $contact_usr_id = User::getUserIDByContactID($data['contact']);
            if (empty($contact_usr_id)) {
                $contact_usr_id = $usr_id;
            }
            $data['reporter'] = $contact_usr_id;
        } else {
            $data['reporter'] = $usr_id;
        }

        $data['msg_id'] = Mail_Helper::generateMessageID();

        $issue_id = self::insertIssue($prj_id, $usr_id, $data);
        if ($issue_id == -1) {
            return -1;
        }

        $has_TAM = false;
        $has_RR = false;
        $info = User::getNameEmail($usr_id);
        // log the creation of the issue
        History::add($issue_id, Auth::getUserID(), History::getTypeID('issue_opened'), 'Issue opened by ' . User::getFullName(Auth::getUserID()));

        $emails = array();
        if (Customer::hasCustomerIntegration($prj_id)) {
            if (!empty($data['contact_extra_emails']) && count($data['contact_extra_emails']) > 0) {
                $emails = $data['contact_extra_emails'];
            }
            // add the primary contact to the notification list
            if ($data['add_primary_contact'] == 'yes') {
                $contact_email = User::getEmailByContactID($data['contact']);
                if (!empty($contact_email)) {
                    $emails[] = $contact_email;
                }
            }
            // if there are any technical account managers associated with this customer, add these users to the notification list
            $managers = Customer::getAccountManagers($prj_id, $data['customer']);
            $manager_usr_ids = array_keys($managers);
            $manager_emails = array_values($managers);
            $emails = array_merge($emails, $manager_emails);
        }
        // add the reporter to the notification list
        $emails[] = $info['usr_email'];
        $emails = array_unique($emails);
        foreach ($emails as $address) {
            Notification::subscribeEmail($usr_id, $issue_id, $address, Notification::getDefaultActions($issue_id, $address, 'new_issue'));
        }

        // only assign the issue to an user if the associated customer has any technical account managers
        $users = array();
        $has_TAM = false;
        if ((Customer::hasCustomerIntegration($prj_id)) && (count($manager_usr_ids) > 0)) {
            foreach ($manager_usr_ids as $manager_usr_id) {
                $users[] = $manager_usr_id;
                self::addUserAssociation($usr_id, $issue_id, $manager_usr_id, false);
                History::add($issue_id, $usr_id, History::getTypeID('issue_auto_assigned'), 'Issue auto-assigned to ' . User::getFullName($manager_usr_id) . ' (TAM)');
            }
            $has_TAM = true;
        }
        // now add the user/issue association (aka assignments)
        if (!empty($data['users']) && count($data['users']) > 0) {
            for ($i = 0; $i < count($data['users']); $i++) {
                Notification::subscribeUser($usr_id, $issue_id, $data['users'][$i],
                                Notification::getDefaultActions($issue_id, User::getEmail($data['users'][$i]), 'new_issue'));
                self::addUserAssociation($usr_id, $issue_id, $data['users'][$i]);
                if ($data['users'][$i] != $usr_id) {
                    $users[] = $data['users'][$i];
                }
            }
        } else {
            // only use the round-robin feature if this new issue was not
            // already assigned to a customer account manager
            if (@count($manager_usr_ids) < 1) {
                $assignee = Round_Robin::getNextAssignee($prj_id);
                // assign the issue to the round robin person
                if (!empty($assignee)) {
                    $users[] = $assignee;
                    self::addUserAssociation($usr_id, $issue_id, $assignee, false);
                    History::add($issue_id, APP_SYSTEM_USER_ID, History::getTypeID('rr_issue_assigned'), 'Issue auto-assigned to ' . User::getFullName($assignee) . ' (RR)');
                    $has_RR = true;
                }
            }
        }

        // now process any files being uploaded
        $found = 0;
        for ($i = 0; $i < count(@$_FILES["file"]["name"]); $i++) {
            if (!@empty($_FILES["file"]["name"][$i])) {
                $found = 1;
                break;
            }
        }
        if ($found) {
            $files = array();
            for ($i = 0; $i < count($_FILES["file"]["name"]); $i++) {
                $filename = @$_FILES["file"]["name"][$i];
                if (empty($filename)) {
                    continue;
                }
                $blob = file_get_contents($_FILES["file"]["tmp_name"][$i]);
                if (empty($blob)) {
                    // error reading a file
                    self::$insert_errors["file[$i]"] = "There was an error uploading the file '$filename'.";
                    continue;
                }
                $files[] = array(
                    "filename" => $filename,
                    "type"     => $_FILES['file']['type'][$i],
                    "blob"     => $blob
                );
            }
            if (count($files) > 0) {
                $attachment_id = Attachment::add($issue_id, $usr_id, 'Files uploaded at issue creation time');
                foreach ($files as $file) {
                    Attachment::addFile($attachment_id, $file["filename"], $file["type"], $file["blob"]);
                }
            }
        }
        // need to associate any emails ?
        if (!empty($data['attached_emails'])) {
            $items = explode(",", $data['attached_emails']);
            Support::associate($usr_id, $issue_id, $items);
        }
        // need to notify any emails being converted into issues ?
        if (@count($data['notify_senders']) > 0) {
            $recipients = Notification::notifyEmailConvertedIntoIssue($prj_id, $issue_id, $data['notify_senders'], @$data['customer']);
        } else {
            $recipients = array();
        }
        // need to process any custom fields ?
        if (@count($data['custom_fields']) > 0) {
            foreach ($data['custom_fields'] as $fld_id => $value) {
                Custom_Field::associateIssue($issue_id, $fld_id, $value);
            }
        }
        // also send a special confirmation email to the customer contact
        if ((@$data['notify_customer'] == 'yes') && (!empty($data['contact']))) {
            // also need to pass the list of sender emails already notified,
            // so we can avoid notifying the same person again
            $contact_email = User::getEmailByContactID($data['contact']);
            if (@!in_array($contact_email, $recipients)) {
                Customer::notifyCustomerIssue($prj_id, $issue_id, $data['contact']);
            }
        }

        Workflow::handleNewIssue($prj_id, $issue_id, $has_TAM, $has_RR);

        // also notify any users that want to receive emails anytime a new issue is created
        Notification::notifyNewIssue($prj_id, $issue_id);

        return $issue_id;
    }

    /**
     * Insert issue to database.
     *
     * @param   integer $prj_id The project ID
     * @param   integer $usr_id The user responsible for this action
     * @param   array $data of issue to be inserted
     * @return  integer The new issue ID
     */
    private function insertIssue($prj_id, $usr_id, $data)
    {

        // XXX missing_fields never used
        $missing_fields = array();
        if ($data['category'] == -1) {
            $missing_fields[] = 'Category';
        }
        if ($data['priority'] == -1) {
            $missing_fields[] = 'Priority';
        }

        // if there is no reporter set, use the system user
        if (empty($data['reporter'])) {
            $data['reporter'] = APP_SYSTEM_USER_ID;
        }

        if ((!isset($data['estimated_dev_time'])) || ($data['estimated_dev_time'] == '')) {
            $data['estimated_dev_time'] = 0;
        }

        // add new issue
        $stmt = "INSERT INTO " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue ".
                "SET ".
                    "iss_prj_id=" . $prj_id . ",";
        if (!empty($data['group'])) {
            $stmt .= "iss_grp_id=" . Misc::escapeInteger($data['group']) . ",\n";
        }
        if (!empty($data['category'])) {
            $stmt .= "iss_prc_id=". Misc::escapeInteger($data['category']) . ",\n";
        }
        if (!empty($data['release'])) {
            $stmt .= "iss_pre_id=". Misc::escapeInteger($data['release']) . ",\n";
        }
        if (!empty($data['priority'])) {
            $stmt .= "iss_pri_id=". Misc::escapeInteger($data['priority']) . ",";
        }

        $stmt .= "iss_usr_id=". Misc::escapeInteger($data['reporter']) .",";

        $initial_status = Project::getInitialStatus($prj_id);
        if (!empty($initial_status)) {
            $stmt .= "iss_sta_id=" . Misc::escapeInteger($initial_status) . ",";
        }

        if (Customer::hasCustomerIntegration($prj_id)) {
            $stmt .= "
                    iss_customer_id='". Misc::escapeString($data['customer']) . "',";
            if (!empty($data['contract'])) {
            $stmt .= "
                    iss_customer_contract_id='". Misc::escapeString($data['contract']) . "',";
            }
            $stmt .= "
                    iss_customer_contact_id='". Misc::escapeString($data['contact']) . "',
                    iss_contact_person_lname='". Misc::escapeString($data['contact_person_lname']) . "',
                    iss_contact_person_fname='". Misc::escapeString($data['contact_person_fname']) . "',
                    iss_contact_email='". Misc::escapeString($data['contact_email']) . "',
                    iss_contact_phone='". Misc::escapeString($data['contact_phone']) . "',
                    iss_contact_timezone='". Misc::escapeString($data['contact_timezone']) . "',";
        }

        $stmt .= "
                    iss_created_date='". Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_public_action_type='created',
                    iss_summary='" . Misc::escapeString($data['summary']) . "',
                    iss_description='" . Misc::escapeString($data['description']) . "',
                    iss_dev_time='" . Misc::escapeString($data['estimated_dev_time']) . "',";
            if (!empty($data['contact'])) {
                $stmt .= "
                    iss_private=" . Misc::escapeInteger($data['private']) . " ,";
            }
        $stmt .= "
                    iss_root_message_id='". Misc::escapeString($data['msg_id']) ."'
        ";

        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        $issue_id = DB_Helper::get_last_insert_id();
        return $issue_id;
    }

    /**
     * Returns the list of action date fields appropriate for the
     * current user ID.
     *
     * @return  array The list of action date fields
     */
    public static function getLastActionFields()
    {
        $last_action_fields = array(
            "iss_last_public_action_date"
        );
        if (Auth::getCurrentRole() > User::getRoleID('Customer')) {
            $last_action_fields[] = "iss_last_internal_action_date";
        }
        if (count($last_action_fields) > 1) {
            return "GREATEST(" . implode(', IFNULL(', $last_action_fields) . ", '0000-00-00')) AS last_action_date";
        } else {
            return $last_action_fields[0] . " AS last_action_date";
        }
    }


    /**
     * Processes a result set to format the "Last Action Date" column.
     *
     * @param   array $result The result set
     */
    public static function formatLastActionDates(&$result)
    {
        for ($i = 0; $i < count($result); $i++) {
            if (($result[$i]['action_type'] == "internal") &&
                    (Auth::getCurrentRole() > User::getRoleID('Customer'))) {
                $label = $result[$i]["iss_last_internal_action_type"];
                $last_date = $result[$i]["iss_last_internal_action_date"];
            } else {
                $label = $result[$i]["iss_last_public_action_type"];
                $last_date = $result[$i]["iss_last_public_action_date"];
            }
            $date = new Date($last_date);
            $current = new Date(Date_Helper::getCurrentDateGMT());
            $result[$i]['last_action_date_diff'] = Date_Helper::getFormattedDateDiff($current->getDate(DATE_FORMAT_UNIXTIME), $date->getDate(DATE_FORMAT_UNIXTIME));
            $result[$i]['last_action_date_label'] = ucwords($label);
        }
    }


    /**
     * Retrieves the last status change date for the given issue.
     *
     * @param   integer $prj_id The project ID
     * @param   array $result The associative array of data
     * @see     Search::getListing()
     */
    public static function getLastStatusChangeDates($prj_id, &$result)
    {
        $ids = array();
        for ($i = 0; $i < count($result); $i++) {
            $ids[] = $result[$i]["iss_sta_id"];
        }
        if (count($ids) == 0) {
            return false;
        }
        $customizations = Status::getProjectStatusCustomization($prj_id, $ids);
        for ($i = 0; $i < count($result); $i++) {
            if (empty($result[$i]['iss_sta_id'])) {
                $result[$i]['status_change_date'] = '';
            } else {
                list($label, $date_field_name) = @$customizations[$result[$i]['iss_sta_id']];
                if ((empty($label)) || (empty($date_field_name))) {
                    $result[$i]['status_change_date'] = '';
                    continue;
                }
                $current = new Date(Date_Helper::getCurrentDateGMT());
                $desc = "$label: %s ago";
                $target_date = $result[$i][$date_field_name];
                if (empty($target_date)) {
                    $result[$i]['status_change_date'] = '';
                    continue;
                }
                $date = new Date($target_date);
                $result[$i]['status_change_date'] = sprintf($desc, Date_Helper::getFormattedDateDiff($current->getDate(DATE_FORMAT_UNIXTIME), $date->getDate(DATE_FORMAT_UNIXTIME)));
            }
        }
    }


    /**
     * Method used to get the previous and next issues that are available
     * according to the current search parameters.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   array $options The search parameters
     * @return  array The list of issues
     */
    function getSides($issue_id, $options)
    {
        $usr_id = Auth::getUserID();
        $role_id = Auth::getCurrentRole();

        $stmt = "SELECT
                    iss_id,
                    " . self::getLastActionFields() . "
                 FROM
                    (
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user";
        // join custom fields if we are searching by custom fields
        if ((is_array($options['custom_field'])) && (count($options['custom_field']) > 0)) {
            foreach ($options['custom_field'] as $fld_id => $search_value) {
                if (empty($search_value)) {
                    continue;
                }
                $field = Custom_Field::getDetails($fld_id);
                if (($field['fld_type'] == 'date') &&
                        ((empty($search_value['Year'])) || (empty($search_value['Month'])) || (empty($search_value['Day'])))) {
                    continue;
                }
                if (($field['fld_type'] == 'integer') && empty($search_value['value'])) {
                    continue;
                }

                if ($field['fld_type'] == 'multiple') {
                    $search_value = Misc::escapeString($search_value);
                    foreach ($search_value as $cfo_id) {
                        $stmt .= ",\n" . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_custom_field as cf" . $fld_id . '_' . $cfo_id . "\n";
                    }
                } else {
                    $stmt .= ",\n" . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_custom_field as cf" . $fld_id . "\n";
                }
            }
        }
        $stmt .= ")";
        // check for the custom fields we want to sort by
        if (strstr($options['sort_by'], 'custom_field') !== false) {
            $fld_id = str_replace("custom_field_", '', $options['sort_by']);
            $stmt .= "\n LEFT JOIN \n" .
                APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_custom_field as cf_sort
                ON
                    (icf_iss_id = iss_id AND icf_fld_id = $fld_id) \n";
        }
        if (!empty($options["users"]) || @$options["sort_by"] == "isu_usr_id") {
            $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                 ON
                    isu_iss_id=iss_id";
        }
        if ((!empty($options["show_authorized_issues"])) || (($role_id == User::getRoleID("Reporter")) && (Project::getSegregateReporters(Auth::getCurrentProject())))) {
             $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user_replier
                 ON
                    iur_iss_id=iss_id";
        }
        if (!empty($options["show_notification_list_issues"])) {
            $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "subscription
                 ON
                    sub_iss_id=iss_id";
        }
        if (@$options["sort_by"] == "pre_scheduled_date") {
            $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_release
                 ON
                    iss_pre_id = pre_id";
        }
        if (@$options['sort_by'] == 'prc_title') {
            $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_category
                 ON
                    iss_prc_id = prc_id";
        }
        $stmt .= "
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 ON
                    iss_sta_id=sta_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_priority
                 ON
                    iss_pri_id=pri_id
                 WHERE
                    iss_prj_id=" . Auth::getCurrentProject();
        $stmt .= Search::buildWhereClause($options);
        if (strstr($options["sort_by"], 'custom_field') !== false) {
            $fld_details = Custom_Field::getDetails($fld_id);
            $sort_by = 'cf_sort.' . Custom_Field::getDBValueFieldNameByType($fld_details['fld_type']);
        } else {
            $sort_by = Misc::escapeString($options["sort_by"]);
        }
        $stmt .= "
                 GROUP BY
                    iss_id
                 ORDER BY
                    " . $sort_by . " " . Misc::escapeString($options["sort_order"]) . ",
                    iss_id DESC";
        $res = DB_Helper::getInstance()->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            // COMPAT: the next line requires PHP >= 4.0.5
            $index = array_search($issue_id, $res);
            if (!empty($res[$index+1])) {
                $next = $res[$index+1];
            }
            if (!empty($res[$index-1])) {
                $previous = $res[$index-1];
            }
            return array(
                "next"     => @$next,
                "previous" => @$previous
            );
        }
    }


    /**
     * Method used to get the full list of user IDs assigned to a specific
     * issue.
     *
     * @param   integer $issue_id The issue ID
     * @return  array The list of user IDs
     */
    public static function getAssignedUserIDs($issue_id)
    {
        $stmt = "SELECT
                    usr_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    isu_iss_id=" . Misc::escapeInteger($issue_id) . " AND
                    isu_usr_id=usr_id";
        $res = DB_Helper::getInstance()->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        return $res;
    }


    /**
     * Method used to see if a user is assigned to an issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $usr_id An integer containg the ID of the user.
     * @return  boolean true if the user(s) are assigned to the issue.
     */
    function isAssignedToUser($issue_id, $usr_id)
    {
        $assigned_users = self::getAssignedUserIDs($issue_id);
        if (in_array($usr_id, $assigned_users)) {
            return true;
        } else {
            return false;
        }
    }


    /**
     * Method used to get the full list of reporters associated with a given
     * list of issues.
     *
     * @access  public
     * @param   array $result The result set
     * @return  void
     */
    function getReportersByIssues(&$result)
    {
        $ids = array();
        for ($i = 0; $i < count($result); $i++) {
            $ids[] = $result[$i]["iss_id"];
        }
        $ids = implode(", ", $ids);
        $stmt = "SELECT
                    iss_id,
                    CONCAT(usr_full_name, ' <', usr_email, '>') AS usr_full_name
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    iss_usr_id=usr_id AND
                    iss_id IN ($ids)";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
        } else {
            // now populate the $result variable again
            for ($i = 0; $i < count($result); $i++) {
                @$result[$i]['reporter'] = $res[$result[$i]['iss_id']];
            }
        }
    }


    /**
     * Method used to get the full list of assigned users by a list
     * of issues. This was originally created to optimize the issue
     * listing page.
     *
     * @access  public
     * @param   array $result The result set
     * @return  void
     */
    public static function getAssignedUsersByIssues(&$result)
    {
        $ids = array();
        for ($i = 0; $i < count($result); $i++) {
            $ids[] = $result[$i]["iss_id"];
        }
        if (count($ids) < 1) {
            return;
        }
        $ids = implode(", ", $ids);
        $stmt = "SELECT
                    isu_iss_id,
                    usr_full_name
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    isu_usr_id=usr_id AND
                    isu_iss_id IN ($ids)";
        $res = DB_Helper::getInstance()->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
        } else {
            $t = array();
            for ($i = 0; $i < count($res); $i++) {
                if (!empty($t[$res[$i]['isu_iss_id']])) {
                    $t[$res[$i]['isu_iss_id']] .= ', ' . $res[$i]['usr_full_name'];
                } else {
                    $t[$res[$i]['isu_iss_id']] = $res[$i]['usr_full_name'];
                }
            }
            // now populate the $result variable again
            for ($i = 0; $i < count($result); $i++) {
                @$result[$i]['assigned_users'] = $t[$result[$i]['iss_id']];
            }
        }
    }


    /**
     * Method used to add the issue description to a list of issues.
     *
     * @access  public
     * @param   array $result The result set
     * @return  void
     */
    function getDescriptionByIssues(&$result)
    {
        if (count($result) == 0) {
            return;
        }

        $ids = array();
        for ($i = 0; $i < count($result); $i++) {
            $ids[] = $result[$i]["iss_id"];
        }
        $ids = implode(", ", $ids);

        $stmt = "SELECT
                    iss_id,
                    iss_description
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id in ($ids)";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return;
        }

        for ($i = 0; $i < count($result); $i++) {
            @$result[$i]['iss_description'] = $res[$result[$i]['iss_id']];
        }
    }


    /**
     * Method used to get the full list of users (the full names) assigned to a
     * specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of users
     */
    function getAssignedUsers($issue_id)
    {
        $stmt = "SELECT
                    usr_full_name
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    isu_iss_id=" . Misc::escapeInteger($issue_id) . " AND
                    isu_usr_id=usr_id";
        $res = DB_Helper::getInstance()->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        return $res;
    }


    /**
     * Method used to get the full list of users (the email usernames) assigned to a
     * specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of users
     */
    function getAssignedUserEmailHandles($issue_id)
    {
        $stmt = "SELECT
                    usr_id,
                    SUBSTRING(usr_email, 1, INSTR(usr_email, '@')-1) AS handle
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                 WHERE
                    isu_iss_id=" . Misc::escapeInteger($issue_id) . " AND
                    isu_usr_id=usr_id";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        return array_values($res);
    }


    /**
     * Method used to get the details for a specific issue.
     *
     * @param   integer $issue_id The issue ID
     * @param   boolean $force_refresh If the cache should not be used.
     * @return  array The details for the specified issue
     */
    public static function getDetails($issue_id, $force_refresh = false)
    {
        static $returns;

        $issue_id = Misc::escapeInteger($issue_id);

        if (empty($issue_id)) {
            return '';
        }

        if ((!empty($returns[$issue_id])) && ($force_refresh != true)) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue.*,
                    prj_title,
                    prc_title,
                    pre_title,
                    pri_title,
                    sta_title,
                    sta_abbreviation,
                    sta_color status_color,
                    sta_is_closed
                 FROM
                    (
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project
                    )
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_priority
                 ON
                    iss_pri_id=pri_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 ON
                    iss_sta_id=sta_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_category
                 ON
                    iss_prc_id=prc_id
                 LEFT JOIN
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "project_release
                 ON
                    iss_pre_id=pre_id
                 WHERE
                    iss_id=$issue_id AND
                    iss_prj_id=prj_id";
        $res = DB_Helper::getInstance()->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        } else {
            if (empty($res)) {
                return "";
            } else {
                $created_date_ts = Date_Helper::getUnixTimestamp($res['iss_created_date'], Date_Helper::getDefaultTimezone());
                // get customer information, if any
                if ((!empty($res['iss_customer_id'])) && (Customer::hasCustomerIntegration($res['iss_prj_id']))) {
                    $res['customer_business_hours'] = Customer::getBusinessHours($res['iss_prj_id'], $res['iss_customer_id']);
                    $res['contact_local_time'] = Date_Helper::getFormattedDate(Date_Helper::getCurrentDateGMT(), $res['iss_contact_timezone']);
                    $res['customer_info'] = Customer::getDetails($res['iss_prj_id'], $res['iss_customer_id'], false, $res['iss_customer_contract_id'], $res['iss_customer_contact_id']);
                    $res['redeemed_incidents'] = Customer::getRedeemedIncidentDetails($res['iss_prj_id'], $res['iss_id']);
                    $max_first_response_time = Customer::getMaximumFirstResponseTime($res['iss_prj_id'], $res['iss_customer_id'], $res['iss_customer_contract_id']);
                    $res['max_first_response_time'] = Misc::getFormattedTime($max_first_response_time / 60);
                    if (empty($res['iss_first_response_date'])) {
                        $first_response_deadline = $created_date_ts + $max_first_response_time;
                        if (Date_Helper::getCurrentUnixTimestampGMT() <= $first_response_deadline) {
                            $res['max_first_response_time_left'] = Date_Helper::getFormattedDateDiff($first_response_deadline, Date_Helper::getCurrentUnixTimestampGMT());
                        } else {
                            $res['overdue_first_response_time'] = Date_Helper::getFormattedDateDiff(Date_Helper::getCurrentUnixTimestampGMT(), $first_response_deadline);
                        }
                    }
                }
                $res['iss_original_description'] = $res["iss_description"];
                $res['iss_original_percent_complete'] = $res["iss_percent_complete"];
                if (!strstr($_SERVER["PHP_SELF"], 'update.php')) {
                    $res["iss_description"] = nl2br(htmlspecialchars($res["iss_description"]));
                    $res["iss_resolution"] = Resolution::getTitle($res["iss_res_id"]);
                }
                $res["iss_impact_analysis"] = nl2br(htmlspecialchars($res["iss_impact_analysis"]));
                $res["iss_created_date"] = Date_Helper::getFormattedDate($res["iss_created_date"]);
                $res['iss_created_date_ts'] = $created_date_ts;
                $res["assignments"] = @implode(", ", array_values(self::getAssignedUsers($res["iss_id"])));
                list($res['authorized_names'], $res['authorized_repliers']) = Authorized_Replier::getAuthorizedRepliers($res["iss_id"]);
                $temp = self::getAssignedUsersStatus($res["iss_id"]);
                $res["has_inactive_users"] = 0;
                $res["assigned_users"] = array();
                $res["assigned_inactive_users"] = array();
                foreach ($temp as $usr_id => $usr_status) {
                    if (!User::isActiveStatus($usr_status)) {
                        $res["assigned_inactive_users"][] = $usr_id;
                        $res["has_inactive_users"] = 1;
                    } else {
                        $res["assigned_users"][] = $usr_id;
                    }
                }
                if (@in_array(Auth::getUserID(), $res["assigned_users"])) {
                    $res["is_current_user_assigned"] = 1;
                } else {
                    $res["is_current_user_assigned"] = 0;
                }
                $res["associated_issues_details"] = self::getAssociatedIssuesDetails($res["iss_id"]);
                $res["associated_issues"] = self::getAssociatedIssues($res["iss_id"]);
                $res["reporter"] = User::getFullName($res["iss_usr_id"]);
                if (empty($res["iss_updated_date"])) {
                    $res["iss_updated_date"] = 'not updated yet';
                } else {
                    $res["iss_updated_date"] = Date_Helper::getFormattedDate($res["iss_updated_date"]);
                }
                $res["estimated_formatted_time"] = Misc::getFormattedTime($res["iss_dev_time"]);
                if (Release::isAssignable($res["iss_pre_id"])) {
                    $release = Release::getDetails($res["iss_pre_id"]);
                    $res["pre_title"] = $release["pre_title"];
                    $res["pre_status"] = $release["pre_status"];
                }
                // need to return the list of issues that are duplicates of this one
                $res["duplicates"] = self::getDuplicateList($res["iss_id"]);
                $res["duplicates_details"] = self::getDuplicateDetailsList($res["iss_id"]);
                // also get the issue title of the duplicated issue
                if (!empty($res['iss_duplicated_iss_id'])) {
                    $res['duplicated_issue'] = self::getDuplicatedDetails($res['iss_duplicated_iss_id']);
                }

                // get group information
                if (!empty($res["iss_grp_id"])) {
                    $res["group"] = Group::getDetails($res["iss_grp_id"]);
                }

                // get quarantine issue
                $res["quarantine"] = self::getQuarantineInfo($res["iss_id"]);

                $returns[$issue_id] = $res;
                return $res;
            }
        }
    }


    /**
     * Method used to get some simple details about the given duplicated issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The duplicated issue details
     */
    function getDuplicatedDetails($issue_id)
    {
        $stmt = "SELECT
                    iss_summary title,
                    sta_title current_status,
                    sta_is_closed is_closed
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 WHERE
                    iss_sta_id=sta_id AND
                    iss_id=$issue_id";
        $res = DB_Helper::getInstance()->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        return $res;
    }


    /**
     * Method used to bulk update a list of issues
     *
     * @access  public
     * @return  boolean
     */
    function bulkUpdate()
    {
        // check if user performing this chance has the proper role
        if (Auth::getCurrentRole() < User::getRoleID('Manager')) {
            return -1;
        }

        $items = Misc::escapeInteger($_POST['item']);
        $new_status_id = Misc::escapeInteger($_POST['status']);
        $new_release_id = Misc::escapeInteger(@$_POST['release']);
        $new_priority_id = Misc::escapeInteger($_POST['priority']);
        $new_category_id = Misc::escapeInteger($_POST['category']);

        for ($i = 0; $i < count($items); $i++) {
            if (!self::canAccess($items[$i], Auth::getUserID())) {
                continue;
            } elseif (self::getProjectID($_POST['item'][$i]) != Auth::getCurrentProject()) {
                // make sure issue is not in another project
                continue;
            }

            $updated_fields = array();

            // update assignment
            if (count(@$_POST['users']) > 0) {
                $users = Misc::escapeInteger($_POST['users']);
                // get who this issue is currently assigned too
                $stmt = "SELECT
                            isu_usr_id,
                            usr_full_name
                         FROM
                            " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user,
                            " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "user
                         WHERE
                            isu_usr_id = usr_id AND
                            isu_iss_id = " . $items[$i];
                $current_assignees = DB_Helper::getInstance()->getAssoc($stmt);
                if (PEAR::isError($current_assignees)) {
                    Error_Handler::logError(array($current_assignees->getMessage(), $current_assignees->getDebugInfo()), __FILE__, __LINE__);
                    return -1;
                }
                foreach ($current_assignees as $usr_id => $usr_name) {
                    if (!in_array($usr_id, $users)) {
                        self::deleteUserAssociation($items[$i], $usr_id, false);
                    }
                }
                $new_user_names = array();
                $new_assignees = array();
                foreach ($users as $usr_id) {
                    $new_user_names[$usr_id] = User::getFullName($usr_id);

                    // check if the issue is already assigned to this person
                    $stmt = "SELECT
                                COUNT(*) AS total
                             FROM
                                " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_user
                             WHERE
                                isu_iss_id=" . $items[$i] . " AND
                                isu_usr_id=" . $usr_id;
                    $total = DB_Helper::getInstance()->getOne($stmt);
                    if ($total > 0) {
                        continue;
                    } else {
                        $new_assignees[] = $usr_id;
                        // add the assignment
                        self::addUserAssociation(Auth::getUserID(), $items[$i], $usr_id, false);
                        Notification::subscribeUser(Auth::getUserID(), $items[$i], $usr_id, Notification::getAllActions());
                        Workflow::handleAssignment(Auth::getCurrentProject(), $items[$i], Auth::getUserID());
                    }
                }
                Notification::notifyNewAssignment($new_assignees, $items[$i]);
                $updated_fields['Assignment'] = History::formatChanges(join(', ', $current_assignees), join(', ', $new_user_names));
            }

            // update status
            if (!empty($new_status_id)) {
                $old_status_id = self::getStatusID($items[$i]);
                $res = self::setStatus($items[$i], $new_status_id, false);
                if ($res == 1) {
                    $updated_fields['Status'] = History::formatChanges(Status::getStatusTitle($old_status_id), Status::getStatusTitle($new_status_id));
                }
            }

            // update release
            if (!empty($new_release_id)) {
                $old_release_id = self::getRelease($items[$i]);
                $res = self::setRelease($items[$i], $new_release_id);
                if ($res == 1) {
                    $updated_fields['Release'] = History::formatChanges(Release::getTitle($old_release_id), Release::getTitle($new_release_id));
                }
            }

            // update priority
            if (!empty($new_priority_id)) {
                $old_priority_id = self::getPriority($items[$i]);
                $res = self::setPriority($items[$i], $new_priority_id);
                if ($res == 1) {
                    $updated_fields['Priority'] = History::formatChanges(Priority::getTitle($old_priority_id), Priority::getTitle($new_priority_id));
                }
            }

            // update category
            if (!empty($new_category_id)) {
                $old_category_id = self::getCategory($items[$i]);
                $res = self::setCategory($items[$i], $new_category_id);
                if ($res == 1) {
                    $updated_fields['Category'] = History::formatChanges(Category::getTitle($old_category_id), Category::getTitle($new_category_id));
                }
            }

            if (count($updated_fields) > 0) {
                // log the changes
                $changes = '';
                $k = 0;
                foreach ($updated_fields as $key => $value) {
                    if ($k > 0) {
                        $changes .= "; ";
                    }
                    $changes .= "$key: $value";
                    $k++;
                }
                History::add($items[$i], Auth::getUserID(), History::getTypeID('issue_bulk_updated'), "Issue updated ($changes) by " . User::getFullName(Auth::getUserID()));
            }

            // close if request
            if ((isset($_REQUEST['closed_status'])) && (!empty($_REQUEST['closed_status']))) {
                self::close(Auth::getUserID(), $items[$i], true, 0, $_REQUEST['closed_status'], $_REQUEST['closed_message'], $_REQUEST['notification_list']);
            }
        }
        return true;
    }


    /**
     * Method used to set the initial impact analysis for a specific issue
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer 1 if the update worked, -1 otherwise
     */
    function setImpactAnalysis($issue_id)
    {
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_updated_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_date='" . Date_Helper::getCurrentDateGMT() . "',
                    iss_last_internal_action_type='update',
                    iss_developer_est_time=" . Misc::escapeInteger($_POST["dev_time"]) . ",
                    iss_impact_analysis='" . Misc::escapeString($_POST["impact_analysis"]) . "'
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }

        // add the impact analysis to the history of the issue
        $summary = 'Initial Impact Analysis for issue set by ' . User::getFullName(Auth::getUserID());
        History::add($issue_id, Auth::getUserID(), History::getTypeID('impact_analysis_added'), $summary);
        return 1;
    }


    /**
     * Method used to get the full list of issue IDs that area available in the
     * system.
     *
     * @access  public
     * @param   string $extra_condition An extra condition in the WHERE clause
     * @return  array The list of issue IDs
     */
    function getColList($extra_condition = NULL)
    {
        $stmt = "SELECT
                    iss_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_prj_id=" . Auth::getCurrentProject();
        if (!empty($extra_condition)) {
            $stmt .= " AND $extra_condition ";
        }
        $stmt .= "
                 ORDER BY
                    iss_id DESC";
        $res = DB_Helper::getInstance()->getCol($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        }

        return $res;
    }


    /**
     * Method used to get the full list of issue IDs and their respective
     * titles.
     *
     * @access  public
     * @param   string $extra_condition An extra condition in the WHERE clause
     * @return  array The list of issues
     */
    function getAssocList($extra_condition = NULL)
    {
        $stmt = "SELECT
                    iss_id,
                    iss_summary
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_prj_id=" . Auth::getCurrentProject();
        if (!empty($extra_condition)) {
            $stmt .= " AND $extra_condition ";
        }
        $stmt .= "
                 ORDER BY
                    iss_id ASC";
        $res = DB_Helper::getInstance()->getAssoc($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return "";
        }

        return $res;
    }


    /**
     * Method used to get the list of issues associated to a specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of associated issues
     */
    function getAssociatedIssues($issue_id)
    {
        $issues = self::getAssociatedIssuesDetails($issue_id);
        $associated = array();
        for ($i = 0; $i < count($issues); $i++) {
            $associated[] = $issues[$i]['associated_issue'];
        }
        return $associated;
    }


    /**
     * Method used to get the list of issues associated details to a
     * specific issue.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  array The list of associated issues
     */
    function getAssociatedIssuesDetails($issue_id)
    {
        static $returns;

        if (!empty($returns[$issue_id])) {
            return $returns[$issue_id];
        }

        $stmt = "SELECT
                    isa_associated_id associated_issue,
                    iss_summary associated_title,
                    sta_title current_status,
                    sta_is_closed is_closed
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_association,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 WHERE
                    isa_associated_id=iss_id AND
                    iss_sta_id=sta_id AND
                    isa_issue_id=$issue_id";
        $res = DB_Helper::getInstance()->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        $returns[$issue_id] = $res;
        return $res;
    }


    /**
     * Method used to check whether an issue was already closed or not.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  boolean
     */
    function isClosed($issue_id)
    {
        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "status
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id) . " AND
                    iss_sta_id=sta_id AND
                    sta_is_closed=1";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        return $res != 0;
    }


    /**
     * Returns a simple list of issues that are currently set to some
     * form of quarantine. This is mainly used by the IRC interface.
     *
     * @access  public
     * @return  array List of quarantined issues
     */
    function getQuarantinedIssueList()
    {
        // XXX: would be nice to restrict the result list to only one project
        $stmt = "SELECT
                    iss_id,
                    iss_summary
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue,
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_quarantine
                 WHERE
                    iqu_iss_id=iss_id AND
                    iqu_expiration >= '" . Date_Helper::getCurrentDateGMT() . "' AND
                    iqu_expiration IS NOT NULL";
        $res = DB_Helper::getInstance()->getAll($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        self::getAssignedUsersByIssues($res);
        return $res;
    }


    /**
     * Returns the status of a quarantine.
     *
     * @param   integer $issue_id The issue ID
     * @return  integer Indicates what the current state of quarantine is.
     */
    function getQuarantineInfo($issue_id)
    {
        $stmt = "SELECT
                    iqu_status,
                    iqu_expiration
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_quarantine
                 WHERE
                    iqu_iss_id = " . Misc::escapeInteger($issue_id) . " AND
                        (iqu_expiration > '" . Date_Helper::getCurrentDateGMT() . "' OR
                        iqu_expiration IS NULL)";
        $res = DB_Helper::getInstance()->getRow($stmt, DB_FETCHMODE_ASSOC);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return array();
        }

        if (!empty($res["iqu_expiration"])) {
            $expiration_ts = Date_Helper::getUnixTimestamp($res['iqu_expiration'], Date_Helper::getDefaultTimezone());
            $res["time_till_expiration"] = Date_Helper::getFormattedDateDiff($expiration_ts, Date_Helper::getCurrentUnixTimestampGMT());
        }
        return $res;
    }


    /**
     * Sets the quarantine status. Optionally an expiration date can be set
     * to indicate when the quarantine expires. A status > 0 indicates that quarantine is active.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @param   integer $status The quarantine status
     * @param   string  $expiration The expiration date of quarantine (default empty)
     */
    function setQuarantine($issue_id, $status, $expiration = '')
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $status = Misc::escapeInteger($status);

        // see if there is an existing record
        $stmt = "SELECT
                    COUNT(*)
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_quarantine
                 WHERE
                    iqu_iss_id = $issue_id";
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }
        if ($res > 0) {
            // update
            $stmt = "UPDATE
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_quarantine
                     SET
                        iqu_status = $status";
            if (!empty($expiration)) {
                $stmt .= ",\niqu_expiration = '" . Misc::escapeString($expiration) . "'";
            }
            $stmt .= "\nWHERE
                        iqu_iss_id = $issue_id";
            $res = DB_Helper::getInstance()->query($stmt);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            } else {
                // add history entry about this change taking place
                if ($status == 0) {
                    History::add($issue_id, Auth::getUserID(), History::getTypeID('issue_quarantine_removed'),
                            "Issue quarantine status cleared by " . User::getFullName(Auth::getUserID()));
                }
            }
        } else {
            // insert
            $stmt = "INSERT INTO
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue_quarantine
                     (
                        iqu_iss_id,
                        iqu_status";
            if (!empty($expiration)) {
                $stmt .= ",\niqu_expiration\n";
            }
            $stmt .= ") VALUES (
                        $issue_id,
                        $status";
            if (!empty($expiration)) {
                $stmt .= ",\n'" . Misc::escapeString($expiration) . "'\n";
            }
            $stmt .= ")";
            $res = DB_Helper::getInstance()->query($stmt);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return -1;
            }
        }
        return 1;
    }


    /**
     * Sets the group of the issue.
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @param   integer $group_id The ID of the group
     * @return  integer 1 if successful, -1 or -2 otherwise
     */
    function setGroup($issue_id, $group_id)
    {
        $issue_id = Misc::escapeInteger($issue_id);
        $group_id = Misc::escapeInteger($group_id);

        $current = self::getDetails($issue_id);
        if ($current["iss_grp_id"] == $group_id) {
            return -2;
        }
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_grp_id = $group_id
                 WHERE
                    iss_id = $issue_id";
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }
        $current_user = Auth::getUserID();
        if (empty($current_user)) {
            $current_user = APP_SYSTEM_USER_ID;
        }
        History::add($issue_id, $current_user, History::getTypeID('group_changed'),
                "Group changed (" . History::formatChanges(Group::getName($current["iss_grp_id"]), Group::getName($group_id)) . ") by " . User::getFullName($current_user));
        return 1;
    }


    /**
     * Returns the group ID associated with the given issue ID.
     *
     * @access  public
     * @param   integer $issue_id The issue ID
     * @return  integer The associated group ID
     */
    function getGroupID($issue_id)
    {
        $stmt = "SELECT
                    iss_grp_id
                 FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return 0;
        }

        return $res;
    }

    /**
     * Method to determine if user can access a particular issue
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue.
     * @param   integer $usr_id The ID of the user
     * @return  boolean If the user can access the issue
     */
    function canAccess($issue_id, $usr_id)
    {
        static $access;

        if (empty($issue_id)) {
            return true;
        }

        if (isset($access[$issue_id . "-" . $usr_id])) {
            return $access[$issue_id . "-" . $usr_id];
        }

        $details = self::getDetails($issue_id);
        if (empty($details)) {
            return true;
        }
        $usr_details = User::getDetails($usr_id);
        $usr_role = User::getRoleByUser($usr_id, $details['iss_prj_id']);
        $prj_id = self::getProjectID($issue_id);


        if (empty($usr_role)) {
            // check if they are even allowed to access the project
            $return = false;
        } elseif ((Customer::hasCustomerIntegration($details['iss_prj_id'])) && ($usr_role == User::getRoleID("Customer")) &&
                ($details['iss_customer_id'] != $usr_details['usr_customer_id'])) {
            // check customer permissions
            $return = false;
        } elseif ($details['iss_private'] == 1) {
            // check if the issue is even private

            // check role, reporter, assigment and group
            if ($usr_role > User::getRoleID("Developer")) {
                $return = true;
            } elseif ($details['iss_usr_id'] == $usr_id) {
                $return = true;
            } elseif (self::isAssignedToUser($issue_id, $usr_id)) {
                $return = true;
            } elseif ((!empty($details['iss_grp_id'])) && (!empty($usr_details['usr_grp_id'])) &&
                        ($details['iss_grp_id'] == $usr_details['usr_grp_id'])) {
                $return = true;
            } elseif (Authorized_Replier::isUserAuthorizedReplier($issue_id, $usr_id)) {
                $return = true;
            } else {
                $return = false;
            }
        } elseif ((Auth::getCurrentRole() == User::getRoleID("Reporter")) && (Project::getSegregateReporters($prj_id)) &&
                ($details['iss_usr_id'] != $usr_id) && (!Authorized_Replier::isUserAuthorizedReplier($issue_id, $usr_id))) {
            return false;
        } else {
            $return = true;
        }

        $access[$issue_id . "-" . $usr_id] = $return;
        return $return;
    }


    /**
     * Returns true if the specified issue is private, false otherwise
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     * @return  boolean If the issue is private or not
     */
    function isPrivate($issue_id)
    {
        static $returns;

        if (!isset($returns[$issue_id])) {
            $sql = "SELECT
                        iss_private
                    FROM
                        " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                    WHERE
                        iss_id=$issue_id";
            $res = DB_Helper::getInstance()->getOne($sql);
            if (PEAR::isError($res)) {
                Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
                return true;
            } else {
                if ($res == 1) {
                    $returns[$issue_id] = true;
                } else {
                    $returns[$issue_id] = false;
                }
            }
        }
        return $returns[$issue_id];
    }


    /**
     * Clears closed information from an issues.
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     */
    function clearClosed($issue_id)
    {
        $stmt = "UPDATE
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                 SET
                    iss_closed_date = null,
                    iss_res_id = null
                 WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->query($stmt);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return -1;
        }
    }


    /**
     * Returns the message ID that should be used as the parent ID for all messages
     *
     * @access  public
     * @param   integer $issue_id The ID of the issue
     */
    function getRootMessageID($issue_id)
    {
        $sql = "SELECT
                    iss_root_message_id
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_id=" . Misc::escapeInteger($issue_id);
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }

        return $res;
    }


    /**
     * Returns the issue ID of the issue with the specified root message ID, or false
     * @access  public
     * @param   string $msg_id The Message ID
     * @return  integer The ID of the issue
     */
    function getIssueByRootMessageID($msg_id)
    {
        static $returns;

        if (!empty($returns[$msg_id])) {
            return $returns[$msg_id];
        }
        $sql = "SELECT
                    iss_id
                FROM
                    " . APP_DEFAULT_DB . "." . APP_TABLE_PREFIX . "issue
                WHERE
                    iss_root_message_id = '" . Misc::escapeString($msg_id) . "'";
        $res = DB_Helper::getInstance()->getOne($sql);
        if (PEAR::isError($res)) {
            Error_Handler::logError(array($res->getMessage(), $res->getDebugInfo()), __FILE__, __LINE__);
            return false;
        }
        if (empty($res)) {
            $returns[$msg_id] = false;
        } else {
            $returns[$msg_id] =  $res;
        }
        return $returns[$msg_id];
    }


    /**
     * Sets the assignees for the issue
     *
     * @param   integer $issue_id
     * @param   array   $assignees
     */
    function setAssignees($issue_id, $assignees)
    {
        if (!is_array($assignees)) {
            $assignees = array();
        }

        // see if there is anything to change
        $old_assignees = self::getAssignedUserIDs($issue_id);
        if ((count(array_diff($old_assignees, $assignees)) == 0) && (count(array_diff($assignees, $old_assignees)) == 0)) {
            return;
        }

        $old_assignee_names = self::getAssignedUsers($issue_id);

        Workflow::handleAssignmentChange(self::getProjectID($issue_id), $issue_id, Auth::getUserID(), self::getDetails($issue_id), $assignees, true);
        // clear up the assignments for this issue, and then assign it to the current user
        self::deleteUserAssociations($issue_id);
        $assignee_names = array();
        foreach ($assignees as $assignee) {
            $res = self::addUserAssociation(Auth::getUserID(), $issue_id, $assignee, false);
            if ($res == -1) {
                return false;
            }
            $assignee_names[] = User::getFullName($assignee);
            Notification::subscribeUser(Auth::getUserID(), $issue_id, $assignee, Notification::getDefaultActions($issue_id, User::getEmail($assignee), 'set_assignees'), false);
        }

        Notification::notifyNewAssignment($assignees, $issue_id);

        // save a history entry about this...
        History::add($issue_id, Auth::getUserID(), History::getTypeID('user_associated'),
                        "Issue assignment to changed (" . History::formatChanges(join(', ', $old_assignee_names), join(', ', $assignee_names)) . ") by " . User::getFullName(Auth::getUserID()));
    }
}
