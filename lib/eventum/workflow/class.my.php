<?php
require_once(APP_INC_PATH . "/workflow/class.abstract_workflow_backend.php");
class My_Workflow_Backend extends Abstract_Workflow_Backend
{
    /**
     * Called when a new message is recieved.
     *
     * @param   integer $prj_id The projectID
     * @param   integer $issue_id The ID of the issue.
     * @param   object $message An object containing the new email
     * @param   array $row The array of data that was inserted into the database.
     * @param   boolean $closing If we are closing the issue.
     */
    function handleNewEmail($prj_id, $issue_id, $message, $row = false, $closing = false)
    {
        $current_status_id = Issue::getStatusID($issue_id);
        $closed_status_id = Status::getStatusID(MY_CLOSED); // some closed context status
        if ((!empty($closed_status_id)) && ($current_status_id == $closed_status_id))
        {
            $open_status_id = Status::getStatusID(MY_DETECTED); // some not closed context status
            Issue::setStatus($issue_id, $open_status_id);
        }
    }

    /**
     * Determines if the address should be emailed.
     *
     * @param   integer $prj_id The project ID
     * @param   string $address The email address to check
     * @return  boolean
     */
    function shouldEmailAddress($prj_id, $address)
    {
        if (stripos($address, MY_DOMAIN.MY_TLD.".") ||
            stripos($address, "SYNTAX-ERROR") ||
            stripos($address, "MISSING-HOST-NAME") ||
            stripos($address, "E@".MY_DOMAIN.MY_TLD) ||
            stripos($address, "localhost") ||
	    stripos($address, "@localhost") ||
            stripos($address, "tobok@".MY_DOMAIN) ||
            stripos($address, "postmaster@")
           )
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    /**
     * Called when an attempt is made to add a user or email address to the
     * notification list.
     *
     * @param   integer $prj_id The project ID
     * @param   integer $issue_id The ID of the issue.
     * @param   integer $subscriber_usr_id The ID of the user to subscribe if this is a real user (false otherwise).
     * @param   string $email The email address to subscribe to subscribe (if this is not a real user).
     * @param   array $types The action types.
     * @return  mixed An array of information or true to continue unchanged or false to prevent the user from being added.
     */
     function handleSubscription($prj_id, $issue_id, &$subscriber_usr_id, &$email, &$actions)
     {
        if (stripos($email, MY_DOMAIN.MY_TLD.".") ||
            stripos($email, "SYNTAX-ERROR") ||
            stripos($email, "?utf-8?q?")
           )
        {
            return false;
        }
        return true;
     }

}
