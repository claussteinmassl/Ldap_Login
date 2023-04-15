<?php
if (!defined('PHPWG_ROOT_PATH')) die('Hacking attempt!');


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

#get ad groups - check
#list ad groups - check
#list top, subgroups, path, max depth, users - check
#activate / submit groups - check
#ech day when page visit, sync groups and members to piwigo groups
#resync adusers, remove users not in ad
#separate sync with ad users




###
### Load data file and $ld_sync_data
###



$ldap = new Ldap();
$ldap->load_config();


// initialize ld_sync_data if exist
if (isset($ldap->config['ld_sync_data'])){
	$ld_sync_data = unserialize($ldap->config['ld_sync_data']); #retrieve from config file
}
else{
	$ld_sync_data = null;
}


###
### Functions
###

if (!function_exists('sync_create_group')) {
function sync_create_group($groups){
    /**
     * Creates missing groups based on Group Management settings
     * 		$groups = 'piwigo_groupname'
     *
     *
     * @since 2.10.1
     *
     */
	global $page;
	foreach($groups as $key => $value){
		//CREATE GROUPS
		if($value != False) {
			$tmp_group=$key;
			$err=False;
			#from group_list.php:
			if(empty($tmp_group)){
				$err = True;
				$page['errors'][] = l10n('The name of a group must not contain " or \' or be empty.');
			}
			if ($err != True){
				// is the group not already existing ?
				$query = '
				SELECT COUNT(*)
				FROM '.GROUPS_TABLE.'
				WHERE name = \''.pwg_db_real_escape_string($tmp_group).'\'
				;';
				list($count) = pwg_db_fetch_row(pwg_query($query));
				if ($count != 0)
				{
					$err=True;
					$page['errors'][] = l10n('This name for the group (%s) already exist.', $tmp_group);
				}
				#delete sync / reverse sync
			}
			if ($err!=True){
				// creating the group
				$query = '
				INSERT INTO '.GROUPS_TABLE.'
				(name)
				VALUES
				(\''.pwg_db_real_escape_string($tmp_group).'\')
				;';
				pwg_query($query);

				$page['infos'][] = l10n('group "%s" added', $tmp_group);
			}
		unset($err);
		unset($tmp_group);
		}
	}			
}
}

if (!function_exists('sync_get_groups')) {
function sync_get_groups(){
/**
 * Get all piwigo groups
 * 		
 *
 *
 * @since 2.10.1 
 *
 */
	$query = '
		SELECT id, name
		  FROM `'.GROUPS_TABLE.'`
		  ORDER BY id ASC
		;';
	$grouplist=array();
	foreach(query2array($query) as $k=>$v){
		$grouplist[$v['name']] = ($v['id']);
	};
	unset($query);
	return $grouplist;
}
}

if (!function_exists('sync_get_users')) {
function sync_get_users($q2a=False){
/**
 * Get all piwigo users
 * 		
 *
 *
 * @since 2.10.1 
 *
 */
	$query = '
		SELECT id, username
		  FROM `'.USERS_TABLE.'` WHERE id >2
		  ORDER BY id ASC
		;';

	$result = query2array($query);
	if($q2a == False){
		$userlist=array();
		foreach($result as $k=>$v){
			$userlist[strtolower($v['username'])] = ($v['id']);
		};
	return $userlist;
	}
	else {
		return $result;
	}	
}
}

if (!function_exists('sync_usergroups_del')) {
function sync_usergroups_del($active){
/**
 * Delete usergroups data for active groups in group management
 * 	$active = groupid	
 *
 *
 * @since 2.10.1 
 *
 */
	// destruction of the users links for this group
	$query = '
	DELETE
	FROM '.USER_GROUP_TABLE.'
	WHERE group_id IN ('.implode(",",$active).')
	;';
	pwg_query($query);
	unset($query);
}
}

if (!function_exists('sync_usergroups_add')) {
function sync_usergroups_add($ldap, $active, $grouplist, $userlist){
    /**
     * Add new users in Piwigo to active grouplist if LDAP has same group/user
     * 	$active=active groups + name users
     *  $grouplist = name/id
     *  $userlist = name/id
     *
     *
     * @since 2.10.1
     *
     */
	$inserts = array();

	foreach ($active as $group_cn => $group_data){ //going through each active group
		$members = $group_data['member'];
		$member_count = $group_data['memberCount'];
		$count = 0;
		$ldap->write_log("[sync_usergroups_add]> group_cn: " . $group_cn);
		if(isset($members)) {
			$ldap->write_log("[sync_usergroups_add]> members exist");
			foreach($members as $member){ //for every user in that group
				// member is a string like "uid=firstname.lastname,cn=users,dc=domain,dc=tld"
				$uid_part = explode(",", $member);
				$parts = explode("=", $uid_part[0]);
				$uid = $parts[1];
				$ldap->write_log("[sync_usergroups_add]> check " . $uid);
				if(array_key_exists($uid, $userlist)){
					$inserts[]=array(
						'user_id' => $userlist[$uid], //corresponding id
						'group_id' => $grouplist[$group_cn], //corresponding id
					);
					$ldap->write_log("[sync_usergroups_add]> inserts: " . json_encode($inserts));
					$count++;
				}
			}
		}
		$page['infos'][] = l10n('group "%s" synced "%s" user(s)',$group_cn,$count);																	  
	}

	mass_inserts(
	USER_GROUP_TABLE,
	array('user_id', 'group_id'),
	$inserts,
	array('ignore'=>true)
	);
}
}

if (!function_exists('sync_group_membership')) {
function sync_group_membership($ldap, $ld_sync_data=null) {
    /**
     * Sync the group membership of all piwigo users from ldap to piwigo.
     */
    if(!isset($ldap)) {
        return false;
    }
    if(!isset($ld_sync_data)){
        $ld_sync_data = $ldap->ldap_get_groups($ldap->config['ld_group_basedn']);
        $ldap->config['ld_sync_data']=serialize($ld_sync_data);
    }

    $grouplist = sync_get_groups(); //get piwigo groups
    $userlist = sync_get_users(); //get piwigo users

    foreach ($ld_sync_data[0] as $k=>$v){
        if($v['active']){
            $activegrouplist['cn'][]=$k; //get all active ldap groups
            $activegrouplist['id'][]=$grouplist[$k]; //get id's from these groups
        }
    }

    sync_usergroups_del($activegrouplist['id']); //delete users from activegroups

    // clear group mapping of all ldap users
    $ld_user_attr = $ldap->config['ld_user_attr'];
    $users_ldap = $ldap->getUsers(null, $ld_user_attr);
    if($users_ldap){
        $uids = array();
        foreach ($users_ldap as $ldap_user) {
            $uids[] = ldap_get_user_id($ldap_user);
        }

        $query = '
            DELETE
            FROM '.USER_GROUP_TABLE.'
            WHERE user_id IN ('.implode(",", $uids).')
            ;';
        //$ldap->write_log("[sync_group_membership] clear group mapping query: " . $query);
        pwg_query($query);
        unset($query);
    }

    //exclude inactive groups from ld_sync_data
    $ld_sync_data_active = array_intersect_key($ld_sync_data[0], array_flip($activegrouplist['cn']));

    //add users
    sync_usergroups_add($ldap, $ld_sync_data_active,$grouplist,$userlist);

    $page['infos'][] = l10n('Users were synced with the group(s).');
    invalidate_user_cache();
    return true;
}
}

if (!function_exists('sync_ldap')) {
function sync_ldap() {
    /**
    * Removes users not in LDAP/Minimum group
    *
    *
    * @since 2.10.1
    *
    */

	$users = sync_get_users();	
	global $ldap;
	global $page;
	$ld_user_attr=$ldap->config['ld_user_attr'];
	$users_ldap=$ldap->getUsers(null, $ld_user_attr);
	if($users_ldap){
		$diff = array_diff_key($users, array_flip($users_ldap));
		$page['infos'][] = l10n('"%s" users removed:', count($diff));																  
		foreach($diff as $username => $id){
			if($id >2){
				delete_user($id);
				$page['infos'][] = l10n('User "%s" deleted', $username);
			}
        }
		
    }
	else {
		$page['errors'][] = l10n('An error occurred, please contact your webmaster or the plugin developer');
	//delete_user .\piwigo\admin\include\functions.php
	}
}
}
 
###
### POST (submit/load page)
###

// only run this, if the file is not included, but run directly
//if (!debug_backtrace()) {
global $page;
if(isset($page['tab']) && $page['tab'] == 'group_management') {

    // Save LDAP configuration when submitted
    if (isset($_POST['sync_action_submit']) || isset($_POST['sync_action_refresh'])){
        $ldap->ldap_conn();
        if(isset($_POST['sync_action_submit'])) {

            //activate groups.
            if(!($ld_sync_data==null)){
                foreach($ld_sync_data[0] as $key=>$value){
                    if(isset($_POST['sync']['groups'][$key])) {
                        $ld_sync_data[0][$key]['active']=True;
                    }
                    else {
                        $ld_sync_data[0][$key]['active']=False;
                    }
                }

            }
            //save to database for activation.
            $ldap->config['ld_group_basedn']=$_POST['ld_group_basedn'];
            $ldap->config['ld_sync_data']=serialize($ld_sync_data);
            $ldap->save_config();


            if(isset($_POST['sync']['item']['groups'])){
                if($_POST['sync']['item']['groups'] ==1){
                    sync_create_group($_POST['sync']['groups']);
                }
            }

            if(isset($_POST['sync']['item']['users'])){
                if($_POST['sync']['item']['users'] ==1){
                    /*
                    $grouplist = sync_get_groups(); //get piwigo groups
                    $userlist = sync_get_users(); //get piwigo users

                    foreach ($ld_sync_data[0] as $k=>$v){
                        if($v['active']){
                            $activegrouplist['cn'][]=$k; //get all active ldap groups
                            $activegrouplist['id'][]=$grouplist[$k]; //get id's from these groups
                        }
                    }

                    sync_usergroups_del($activegrouplist['id']); //delete users from activegroups

                    //exclude inactive groups from ld_sync_data
                    $ld_sync_data_active = array_intersect_key($ld_sync_data[0], array_flip($activegrouplist['cn']));

                    //add users
                    sync_usergroups_add($ldap, $ld_sync_data_active,$grouplist,$userlist);

                    $page['infos'][] = l10n('Users were synced with the group(s).');
                    invalidate_user_cache();
                    */
                    sync_group_membership($ldap, $ld_sync_data);
                }
            }

            if(isset($_POST['sync']['item']['ldap'])){
                if($_POST['sync']['item']['ldap'] ==1){
                    //what goes here?
                    sync_ldap();
                }
            }
        }

        //Refresh button on page.
        if (isset($_POST['sync_action_refresh'])){
            $ld_sync_data = $ldap->ldap_get_groups($ldap->config['ld_group_basedn']);
            $ldap->config['ld_sync_data']=serialize($ld_sync_data);
            $ldap->save_config();


    ###
    ### Debug
    ###
         /*
            if(isset($_POST['sync_action'])){
                echo('<div style="margin-left:220px;"><pre>');
                print_r($ld_sync_data);
                echo("</pre></div>");
                //die;
            }
        */
        }

    }



    ###
    ### TEMPLATE
    ###

    global $template;
    $template->assign('LD_SYNC_DATA',$ld_sync_data);
    $template->assign('LD_GROUP_BASEDN',$ldap->config['ld_group_basedn']);

    $template->set_filenames( array('plugin_admin_content' => dirname(__FILE__).'/group_management.tpl') );
    $template->assign(
      array(
        'PLUGIN_ACTION' => get_root_url().'admin.php?page=plugin-Ldap_Login-group_management',
        'PLUGIN_CHECK' => get_root_url().'admin.php?page=plugin-Ldap_Login-group_management',
        ));
    $template->assign_var_from_handle( 'ADMIN_CONTENT', 'plugin_admin_content');

}


?>