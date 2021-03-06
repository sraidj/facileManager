<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2013 The facileManager Team                               |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | facileManager: Easy System Administration                               |
 +-------------------------------------------------------------------------+
 | http://www.facilemanager.com/                                           |
 +-------------------------------------------------------------------------+
 | Processes client installations                                          |
 | Author: Jon LaBass                                                      |
 +-------------------------------------------------------------------------+
*/

/** Handle client installations */
if (arrayKeysExist(array('genserial', 'addserial', 'install', 'upgrade', 'sshkey'), $_GET)) {
	if (!defined('CLIENT')) define('CLIENT', true);
	
	require_once('fm-init.php');
	if (file_exists(ABSPATH . 'fm-modules/' . $_POST['module_name'] . '/variables.inc.php')) {
		include(ABSPATH . 'fm-modules/' . $_POST['module_name'] . '/variables.inc.php');
	}
	include(ABSPATH . 'fm-includes/version.php');
	
	/** Check account key */
	include(ABSPATH . 'fm-modules/facileManager/classes/class_accounts.php');
	$account_status = $fm_accounts->verifyAccount($_POST['AUTHKEY']);

	if ($account_status !== true) {
		$data = $account_status;
	} else {
		if (in_array($_POST['module_name'], getActiveModules())) {
			if (array_key_exists('genserial', $_GET)) {
				$module = ($_POST['module_name']) ? $_POST['module_name'] : $_SESSION['module'];
				$data['server_serial_no'] = generateSerialNo($module);
			}
			
			if (array_key_exists('addserial', $_GET)) {
				/** Client expects an array for a good return */
				$data = $_POST;
				
				/** Does the record already exist for this account? */
				basicGet('fm_' . $__FM_CONFIG[$_POST['module_name']]['prefix'] . 'servers', $_POST['server_name'], 'server_', 'server_name');
				if ($fmdb->num_rows) {
					$server_array = $fmdb->last_result;
					$_POST['server_id'] = $server_array[0]->server_id;
					$update_server = moduleAddServer('update');
				} else {
					/** Add new server */
					$add_server = moduleAddServer('add');
					if ($add_server === false) {
						$data = "Could not add server to account.\n";
					}
				}
			}
			
			/** Client installs */
			if (array_key_exists('install', $_GET)) {
				/** Set flags */
				$data = basicUpdate('fm_' . $__FM_CONFIG[$_POST['module_name']]['prefix'] . 'servers', $_POST['SERIALNO'], 'server_installed', 'yes', 'server_serial_no');
				if (function_exists('moduleCompleteClientInstallation')) {
					moduleCompleteClientInstallation();
				}
				$fm_shared_module_servers->updateClientVersion();
			}
			
			/** Client upgrades */
			if (array_key_exists('upgrade', $_GET)) {
				$current_module_version = getOption('client_version', 0, $_POST['module_name']);
				if ($_POST['server_client_version'] == $current_module_version) {
					$data = "Latest version: $current_module_version\nNo upgrade available.\n";
				} elseif ($current_module_version <= '1.2.3') {
					$data = "Latest version: $current_module_version\nThis upgrade requires a manual installation.\n";
				} else {
					$data = array(
								'latest_core_version' => $fm_version,
								'latest_module_version' => $current_module_version
							);
				}
				
				// Probably need to move/remove this
				$fm_shared_module_servers->updateClientVersion();
			}
			
			if (array_key_exists('sshkey', $_GET)) {
				$data = getOption('ssh_key_pub', $_SESSION['user']['account_id']);
			}
		} else {
			$data = "failed\n\nInstallation aborted. {$_POST['module_name']} is not an active module.\n";
		}
	}
	
	if ($_POST['compress']) echo gzcompress(serialize($data));
	else echo serialize($data);
	exit;
}

?>