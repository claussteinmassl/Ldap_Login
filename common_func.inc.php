<?php
/*
 * This file contains several common functions used during login and administration.
 */


include_once(LDAP_LOGIN_PATH.'/admin/group_management.php');

if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');


function ldap_get_user_id($username) {
    /**
     * Retrieve the uid for a given username from the piwigo database.
     */
    global $pwg_db;
    $query = 'SELECT id FROM '.USERS_TABLE.' WHERE username="'.pwg_db_real_escape_string($username).'"';
    $result = pwg_query($query);
    if ($result->num_rows == 1) {
        $row = $result->fetch_assoc();
        return $row['id'];
    } else {
        return false;
    }
}


function ldap_new_piwigo_user($ldap, $username, $login=false){
    /**
     * Check if the given username already exists in the piwigo database.
     * If it exists, return it's id. If not, create the user and return its id.
     *
     * @param obj $ldap: The ldap instance
     * @param string $username: The user that shall be created
     * @param bool $login: Is this function called during login (true)?
     */
    global $conf;

    $username = strtolower($username);

    // retrieve user dn from ldap
    $user_dn = $ldap->ldap_search_dn($username);
    // if the user is not found in ldap, we can't setup the piwigo user
    if(!($user_dn && $ldap->check_ldap_group_membership($user_dn, $username))) {
        $ldap->write_log("[new_piwigo_user]> can't register $username, user not found in ldap");
        return null;
    }

    // let's check if the user already exists
    // search user in piwigo database
    $query = 'SELECT '.$conf['user_fields']['id'].' AS id FROM '.USERS_TABLE.' WHERE '.$conf['user_fields']['username'].' = \''.pwg_db_real_escape_string($username).'\' ;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    // if we find something in the database, the user already exists and we don't need to do anything
    if (!empty($row['id'])) {
        $ldap->write_log("[new_piwigo_user]> can't register $username, user already exists");

        // update group membership based on ldap
        sync_group_membership($ldap);

        return $row['id'];
    }

    // let's check if we are allowed to register new users at login
    if ($login && !$ldap->config['ld_allow_newusers']) {
        $ldap->write_log("[new_piwigo_user]> Not allowed to create user $username (ld_allow_newusers=false)");
        return null;
    }

    // retrieve mail address from ldap
    $mail = null;
    if($ldap->config['ld_use_mail']) {
        // retrieve LDAP e-mail address
        $mail = $ldap->ldap_get_email($user_dn);
    }

    // register new user
    $errors = [];
    $new_id = register_user($username, random_password(8), $mail, true, $errors);
    if(count($errors) > 0) {
        foreach ($errors as &$e){
            $ldap->write_log("[new_piwigo_user]> ".$e, 'ERROR');
        }
        return null;
    }
    $ldap->write_log("[new_piwigo_user]> registered new user $username in piwigo");

    // update group membership based on ldap
    sync_group_membership($ldap);

    return $new_id;
}


function ldap_set_user_status($ldap, $username) {
    /**
     * Set the status (normal user, admin, webmaster) of the given user id based on the ldap group (if setting is enabled).
     *
     * @param ldap $ldap: The ldap object used to make the connection to the ldap server.
     * @param string $username: The user name.
     * @return null
     */
    global $conf;

    $username = strtolower($username);

    // retrieve user dn from ldap
    $user_dn = $ldap->ldap_search_dn($username);
    // if the user is not found in ldap, we can't setup the user permissions
    if(!($user_dn && $ldap->check_ldap_group_membership($user_dn, $username))) {
        $ldap->write_log("[set_user_status]> can't set status of $username, user not found in ldap");
        return false;
    }

    // search user in piwigo database
    $query = 'SELECT '.$conf['user_fields']['id'].' AS id FROM '.USERS_TABLE.' WHERE '.$conf['user_fields']['username'].' = \''.pwg_db_real_escape_string($username).'\' ;';
    $row = pwg_db_fetch_assoc(pwg_query($query));
    // if the user does not exist in the database, we can't set its status
    if (!empty($row['id'])) {
        $uid = $row['id'];
    } else {
        $ldap->write_log("[set_user_status]> can't set status of $username, user not found in piwigo");
        return false;
    }

    // get current status from piwigo database
    $group_query = 'SELECT user_id, status FROM piwigo_user_infos  WHERE `piwigo_user_infos`.`user_id` = ' . $uid . ';';
    $pwg_status = pwg_db_fetch_assoc(pwg_query($group_query))['status']; //status in Piwigo
    $webmaster = null; // or True or False
    $admin = null;  // or True or False
    $gueest = null;
    $ldap->write_log("[set_user_status]> info: $username, current status: $pwg_status");

    //enable upgrade / downgrade from webmaster
    if ($ldap->config['ld_group_webmaster_active']) {
        $group_webm = $ldap->config['ld_group_webmaster'];
        //is user webmaster?
        $webmaster = $ldap->check_ldap_group_membership($user_dn, $username,$group_webm); //is webmaster in LDAP?
    }

    //enable upgrade / downgrade from admin
    if ($ldap->config['ld_group_admin_active']) {
        $group_adm = $ldap->config['ld_group_admin'];
        //is user admin?
        $admin = $ldap->check_ldap_group_membership($user_dn, $username,$group_adm); //is admin in LDAP?
    }
    $ldap->write_log("[set_user_status]> Admin_active:" . $ldap->config['ld_group_admin_active'] ." is_admin:$admin , WebmasterActive:" . $ldap->config['ld_group_webmaster_active'] . " is_webmaster:$webmaster");

    $status='normal';
    if (is_null($webmaster) && is_null($admin)) {}//ignore
    elseif($webmaster==false && $admin==true) {$status='admin';} //  admin | when NOT webmaster and admin.
    elseif($webmaster==true && (!is_null($admin))) {$status='webmaster';} // webmaster | when webmaster and whatever value for admin.

    elseif(is_null($webmaster)) {
        if($pwg_status=='webmaster') {}//ignore & keep webmaster
        elseif($admin) {$status='admin';} // admin
        elseif(!($admin)) {$status='normal';} // normal
    }
    elseif(is_null($admin)){
        if($webmaster) {$status='webmaster';} // webmaster
        elseif($pwg_status=='admin') {}//ignore & keep admin
        elseif(!($webmaster)) {$status='normal';} // normal
    }

    // if the account is disabled in ldap, we make the user a guest in piwigo
    if(!$ldap->ldap_get_user_status($user_dn)) {
        $status = "guest";
    }

    if(isset($status)){
        $ldap->write_log("[login]> Target status $status");
        if ($status!=$pwg_status) {
            $query = 'UPDATE `piwigo_user_infos` SET `status` = "'. $status . '" WHERE `piwigo_user_infos`.`user_id` = ' . $uid . ';';
            pwg_query($query);
            $ldap->write_log("[set_user_status]> Changed $username with id " . $row['id'] . " from ".$pwg_status. " to " . $status);
            include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
            invalidate_user_cache();
        }
    }

}



