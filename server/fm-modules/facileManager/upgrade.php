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
*/

/**
 * facileManager Upgrader Functions
 *
 * @package facileManager
 * @subpackage Upgrader
 *
 */

/**
 * Processes upgrade.
 *
 * @since 1.0
 * @package facileManager
 * @subpackage Upgrader
 */
function fmUpgrade($database) {
	global $fmdb;
	include(ABSPATH . 'fm-includes/version.php');
	include(ABSPATH . 'fm-modules/facileManager/variables.inc.php');
	
	$GLOBALS['running_db_version'] = getOption('fm_db_version');
	
	echo '<center><table class="form-table">' . "\n";
	
	/** Checks to support older versions (ie n-3 upgrade scenarios */
	$success = ($GLOBALS['running_db_version'] < 34) ? fmUpgrade_1202($database) : true;

	if ($success) {
		$success = upgradeConfig('fm_db_version', $fm_db_version);
		setOption($fm_name . '_version_check', array('timestamp' => date("Y-m-d H:i:s", strtotime("2 months ago")), 'data' => null), 'update');
	}
	
	displayProgress('Upgrading Schema', $success);

	echo "</table>\n</center>\n";
	
	if ($success) {
		displaySetupMessage(1, $GLOBALS['RELPATH'] . 'admin-modules.php');
	} else {
		displaySetupMessage(2);
	}
}


function fmUpgrade_100($database) {
	global $fmdb;
	
	$table[] = "ALTER TABLE  $database.`fm_users` CHANGE  `user_ipaddr`  `user_ipaddr` VARCHAR( 255 ) NULL DEFAULT NULL ;";
	
	/** Create table schema */
	if (count($table) && $table[0]) {
		foreach ($table as $schema) {
			$fmdb->query($schema);
			if (!$fmdb->result || $fmdb->sql_errors) return false;
		}
	}
	
	upgradeConfig('fm_db_version', 11, false);

	return true;
}


function fmUpgrade_101($database) {
	global $fmdb;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 11) ? fmUpgrade_100($database) : true;
	
	if ($success) {
		/** Schema change */
		$table[] = "ALTER TABLE  $database.`fm_users` ADD  `user_force_pwd_change` ENUM(  'yes',  'no' ) NOT NULL DEFAULT  'no' AFTER  `user_ipaddr` ;";
		
		/** Create table schema */
		if (count($table) && $table[0]) {
			foreach ($table as $schema) {
				$fmdb->query($schema);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	}

	upgradeConfig('fm_db_version', 14, false);
	
	return $success;
}


function fmUpgrade_104($database) {
	global $fmdb;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 14) ? fmUpgrade_101($database) : true;
	
	if ($success) {
		$table = $inserts = $updates = null;
		
		/** Schema change */
		if ($GLOBALS['running_db_version'] < 14) {
			$table[] = "ALTER TABLE $database. `fm_logs` ENGINE = INNODB;";
			$table[] = "ALTER TABLE $database. `fm_perms` ADD  `perm_extra` TEXT NULL ;";
		}
		if ($GLOBALS['running_db_version'] < 15) {
			$inserts[] = <<<INSERT
		INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
			SELECT 'auth_method', '1' FROM DUAL
		WHERE NOT EXISTS
			(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'auth_method');
INSERT;

			$inserts[] = <<<INSERT
		INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
			SELECT 'mail_enable', '1' FROM DUAL
		WHERE NOT EXISTS
			(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_enable');
INSERT;

			$inserts[] = <<<INSERT
		INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
			SELECT 'mail_smtp_host', 'localhost' FROM DUAL
		WHERE NOT EXISTS
			(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_smtp_host');
INSERT;

			$inserts[] = "
		INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
			SELECT 'mail_from', 'noreply@" . php_uname('n') . "' FROM DUAL
		WHERE NOT EXISTS
			(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_from');
		";
		}
		if ($GLOBALS['running_db_version'] < 17) {
			$table[] = "ALTER TABLE  $database.`fm_users` CHANGE  `user_status`  `user_status` ENUM(  'active',  'disabled',  'deleted' ) NOT NULL DEFAULT  'active';";
		}
		if ($GLOBALS['running_db_version'] < 18) {
			if ($GLOBALS['running_db_version'] >= 15) {
				$query = "SELECT * FROM $database.`fm_preferences`";
				$fmdb->get_results($query);
				$count = $fmdb->num_rows;
				$all_prefs = $fmdb->last_result;
				for ($i=0; $i<$count; $i++) {
					$inserts[] = <<<INSERT
						INSERT INTO `fm_options` (`option_name`, `option_value`) 
							SELECT '{$all_prefs[$i]->pref_name}', '{$all_prefs[$i]->pref_value}' FROM DUAL
						WHERE NOT EXISTS
							(SELECT option_name FROM $database.`fm_options` WHERE option_name = '{$all_prefs[$i]->pref_name}');
INSERT;
				}
			}
			
			$default_timezone = date_default_timezone_get() ? date_default_timezone_get() : 'Europe/London';
			
			$query = "SELECT account_id FROM $database.`fm_accounts`";
			$fmdb->get_results($query);
			$count = $fmdb->num_rows;
			$all_accounts = $fmdb->last_result;
			for ($j=0; $j<$count; $j++) {
				$inserts[] = <<<INSERT
			INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
				SELECT 'mail_smtp_tls', '0' FROM DUAL
			WHERE NOT EXISTS
				(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'mail_smtp_tls');
INSERT;

				$inserts[] = <<<INSERT
			INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
				SELECT {$all_accounts[$j]->account_id}, 'timezone', '$default_timezone' FROM DUAL
			WHERE NOT EXISTS
				(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'timezone');
INSERT;

				$inserts[] = <<<INSERT
			INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
				SELECT {$all_accounts[$j]->account_id}, 'date_format', 'D, d M Y' FROM DUAL
			WHERE NOT EXISTS
				(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'date_format');
INSERT;

				$inserts[] = <<<INSERT
			INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
				SELECT {$all_accounts[$j]->account_id}, 'time_format', 'H:i:s O' FROM DUAL
			WHERE NOT EXISTS
				(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'time_format');
INSERT;

			}
			
			if ($GLOBALS['running_db_version'] >= 15) {
				$inserts[] = "DROP TABLE $database.`fm_preferences`";
			}

			/** Update timestamp fields with unix epoch seconds */
			$table[] = "ALTER TABLE  $database.`fm_logs` CHANGE  `log_timestamp`  `log_timestamp` VARCHAR( 20 ) NOT NULL DEFAULT  '0';";
			$query = "SELECT * FROM $database.`fm_logs`";
			$fmdb->get_results($query);
			$count = $fmdb->num_rows;
			$all_results = $fmdb->last_result;
			for ($k=0; $k<$count; $k++) {
				$timestamp = strtotime($all_results[$k]->log_timestamp . ' ' . 'America/Denver');
				$updates[] = "UPDATE $database.`fm_logs` SET log_timestamp='$timestamp' WHERE log_id={$all_results[$k]->log_id}";
			}
			$updates[] = "ALTER TABLE  $database.`fm_logs` CHANGE  `log_timestamp`  `log_timestamp` INT( 10 ) NOT NULL DEFAULT  '0';";
			
			$table[] = "ALTER TABLE  $database.`fm_users` CHANGE  `user_last_login`  `user_last_login` VARCHAR( 20 ) NOT NULL DEFAULT  '0';";
			$query = "SELECT * FROM $database.`fm_users`";
			$fmdb->get_results($query);
			$count = $fmdb->num_rows;
			$all_results = $fmdb->last_result;
			for ($k=0; $k<$count; $k++) {
				$timestamp = strtotime($all_results[$k]->user_last_login . ' ' . 'America/Denver');
				$updates[] = "UPDATE $database.`fm_users` SET user_last_login='$timestamp' WHERE user_id={$all_results[$k]->user_id}";
			}
			$updates[] = "ALTER TABLE  $database.`fm_users` CHANGE  `user_last_login`  `user_last_login` INT( 10 ) NOT NULL DEFAULT  '0';";
			
			$table[] = "ALTER TABLE  $database.`fm_pwd_resets` CHANGE  `pwd_timestamp`  `pwd_timestamp` VARCHAR( 20 ) NOT NULL DEFAULT  '0';";
			$query = "SELECT * FROM $database.`fm_pwd_resets`";
			$fmdb->get_results($query);
			$count = $fmdb->num_rows;
			$all_results = $fmdb->last_result;
			for ($k=0; $k<$count; $k++) {
				$timestamp = strtotime($all_results[$k]->pwd_timestamp . ' ' . 'America/Denver');
				$updates[] = "UPDATE $database.`fm_pwd_resets` SET pwd_timestamp='$timestamp' WHERE pwd_id='{$all_results[$k]->pwd_id}'";
			}
			$updates[] = "ALTER TABLE  $database.`fm_pwd_resets` CHANGE  `pwd_timestamp`  `pwd_timestamp` INT( 10 ) NOT NULL DEFAULT  '0';";

			$table[] = "ALTER TABLE  $database.`fm_users` ADD  `user_auth_type` INT( 1 ) NOT NULL DEFAULT  '1' AFTER  `user_email` ;";
			$table[] = "ALTER TABLE  $database.`fm_users` ADD  `user_template_only` ENUM(  'yes',  'no' ) NOT NULL DEFAULT  'no' AFTER  `user_force_pwd_change` ;";
		}
		
		/** Create table schema */
		if (count($table) && $table[0]) {
			foreach ($table as $schema) {
				$fmdb->query($schema);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	
		if (count($inserts) && $inserts[0] && $success) {
			foreach ($inserts as $query) {
				$fmdb->query($query);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	
		if (count($updates) && $updates[0] && $success) {
			foreach ($updates as $query) {
				$fmdb->query($query);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	}

	upgradeConfig('fm_db_version', 18, false);
	
	return $success;
}


function fmUpgrade_105($database) {
	global $fmdb;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 18) ? fmUpgrade_104($database) : true;
	
	if ($success) {
		$table = $inserts = $updates = null;

		/** Schema change */
		$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`option_name`, `option_value`) 
	SELECT 'fm_temp_directory', '/tmp' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'fm_temp_directory');
INSERT;

		if (count($inserts) && $inserts[0] && $success) {
			foreach ($inserts as $query) {
				$fmdb->query($query);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	}

	return $success;
}


/** fM v1.0 **/
function fmUpgrade_106($database) {
	global $fmdb;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 22) ? fmUpgrade_105($database) : true;
	
	if ($success) {
		/** Schema change */
		$table[] = "ALTER TABLE  $database.`fm_users` ADD  `user_default_module` VARCHAR( 255 ) NULL DEFAULT NULL AFTER  `user_email` ;";

		/** Create table schema */
		if (count($table) && $table[0]) {
			foreach ($table as $schema) {
				$fmdb->query($schema);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	}

	return $success;
}


/** fM v1.0.1 **/
function fmUpgrade_107($database) {
	global $fmdb;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 27) ? fmUpgrade_106($database) : true;
	
	if ($success) {
		/** Schema change */
		$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 0, 'software_update', 1 FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'software_update');
INSERT;

		$inserts[] = <<<INSERT
INSERT INTO $database.`fm_options` (`account_id` ,`option_name`, `option_value`) 
	SELECT 0, 'software_update_interval', 'week' FROM DUAL
WHERE NOT EXISTS
	(SELECT option_name FROM $database.`fm_options` WHERE option_name = 'software_update_interval');
INSERT;

		if (count($inserts) && $inserts[0] && $success) {
			foreach ($inserts as $query) {
				$fmdb->query($query);
				if ($fmdb->last_error) {
					echo $fmdb->last_error;
					return false;
				}
			}
		}
	}

	return $success;
}


/** fM v1.2-beta1 **/
function fmUpgrade_1201($database) {
	global $fmdb, $fm_name;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 28) ? fmUpgrade_107($database) : true;
	
	if ($success) {
		/** Schema change */
		$table[] = "ALTER TABLE  $database.`fm_options` ADD  `module_name` VARCHAR( 255 ) NULL AFTER  `account_id` ;";
		$table[] = "ALTER TABLE  `fm_users` ADD  `user_caps` TEXT NULL AFTER  `user_auth_type` ;";

		/** Create table schema */
		if (count($table) && $table[0]) {
			foreach ($table as $schema) {
				$fmdb->query($schema);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
	
		$inserts = null;
		if (count($inserts) && $inserts[0] && $success) {
			foreach ($inserts as $query) {
				$fmdb->query($query);
				if ($fmdb->last_error) {
					echo $fmdb->last_error;
					return false;
				}
			}
		}
		
		/** Update fm_options */
		$version_check = getOption($fm_name . '_version_check');
		if ($version_check !== false) {
			if (!setOption('version_check', $version_check, 'auto', true, 0, $fm_name)) return false;
			$query = "DELETE FROM $database.`fm_options` WHERE option_name='{$fm_name}_version_check'";
			$fmdb->query($query);
			if (!$fmdb->result || $fmdb->sql_errors) return false;
		}
		$modules = getAvailableModules();
		if (count($modules)) {
			foreach ($modules as $module_name) {
				$module_version = getOption($module_name . '_version');
				if ($module_version !== false) {
					if (!setOption('version', $module_version, 'auto', false, 0, $module_name)) return false;
				}
				$module_version_check = getOption($module_name . '_version_check');
				if ($module_version_check !== false) {
					if (!setOption('version_check', $module_version_check, 'auto', true, 0, $module_name)) return false;
				}
				$module_client_version = getOption($module_name . '_client_version');
				if ($module_client_version !== false) {
					if (!setOption('client_version', $module_client_version, 'auto', false, 0, $module_name)) return false;
				}
				$query = "DELETE FROM $database.`fm_options` WHERE option_name LIKE '{$module_name}%_version%'";
				$fmdb->query($query);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
		
		/** Update user capabilities */
		$fm_user_caps[$fm_name] = array(
				'do_everything'		=> '<b>Super Admin</b>',
				'manage_modules'	=> 'Module Management',
				'manage_users'		=> 'User Management',
				'run_tools'			=> 'Run Tools',
				'manage_settings'	=> 'Manage Settings'
			);
		if (!setOption('fm_user_caps', $fm_user_caps)) return false;
		
		$fmdb->get_results("SELECT * FROM `fm_users`");
		if ($fmdb->num_rows) {
			$count = $fmdb->num_rows;
			$result = $fmdb->last_result;
			for ($i=0; $i<$count; $i++) {
				$user_caps = null;
				/** Update user capabilities */
				$j = 1;
				foreach ($fm_user_caps[$fm_name] as $slug => $trash) {
					if ($j & $result[$i]->user_perms) $user_caps[$fm_name][$slug] = 1;
					$j = $j*2 ;
				}
				$fmdb->query("UPDATE fm_users SET user_caps = '" . serialize($user_caps) . "' WHERE user_id=" . $result[$i]->user_id);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
		$fmdb->query("ALTER TABLE `fm_users` DROP `user_perms`;");
		if (!$fmdb->result || $fmdb->sql_errors) return false;
		
		/** Temporarily move the module user capabilities to fm_users */
		$fmdb->get_results("SELECT * FROM `fm_perms`");
		if ($fmdb->num_rows) {
			$count = $fmdb->num_rows;
			$result = $fmdb->last_result;
			for ($i=0; $i<$count; $i++) {
				if (!$user_info = getUserInfo($result[$i]->user_id)) continue;
				/** Update user capabilities */
				$user_caps = isSerialized($user_info['user_caps']) ? unserialize($user_info['user_caps']) : $user_info['user_caps'];
				$user_caps[$result[$i]->perm_module] = isSerialized($result[$i]->perm_extra) ? unserialize($result[$i]->perm_extra) : $result[$i]->perm_extra;
				$user_caps[$result[$i]->perm_module]['imported_perms'] = $result[$i]->perm_value;

				$fmdb->query("UPDATE fm_users SET user_caps = '" . serialize($user_caps) . "' WHERE user_id=" . $result[$i]->user_id);
				if (!$fmdb->result || $fmdb->sql_errors) return false;
			}
		}
		$fmdb->query("DROP TABLE `fm_perms`");
		if (!$fmdb->result || $fmdb->sql_errors) return false;
		
	}

	return $success;
}


/** fM v1.2-rc1 **/
function fmUpgrade_1202($database) {
	global $fmdb, $fm_name;
	
	$success = true;
	
	/** Prereq */
	$success = ($GLOBALS['running_db_version'] < 32) ? fmUpgrade_1201($database) : true;
	
	if ($success) {
		$fm_user_caps = getOption('fm_user_caps');
	
		/** Update user capabilities */
		$fm_user_caps[$fm_name] = array(
				'do_everything'		=> '<b>Super Admin</b>',
				'manage_modules'	=> 'Module Management',
				'manage_users'		=> 'User Management',
				'run_tools'			=> 'Run Tools',
				'manage_settings'	=> 'Manage Settings',
				'view_logs'			=> 'View Logs'
			);
		if (!setOption('fm_user_caps', $fm_user_caps)) return false;
	}

	return $success;
}


/**
 * Updates the database with the db version number.
 *
 * @since 1.0
 * @package facileManager
 * @subpackage Upgrader
 */
function upgradeConfig($field, $value, $logit = true) {
	global $fmdb;
	
	$query = "UPDATE `fm_options` SET option_value='$value' WHERE option_name='$field'";
	$fmdb->query($query);
	if ($fmdb->last_error) {
		echo $fmdb->last_error;
		return false;
	}

	session_id($_COOKIE['myid']);
	@session_start();
	unset($_SESSION['user']['fm_perms']);
	
	if ($logit) {
		include(ABSPATH . 'fm-includes/version.php');
		include(ABSPATH . 'fm-modules/facileManager/variables.inc.php');
		
		addLogEntry("$fm_name was upgraded to $fm_version.", $fm_name);
	}
	
	return true;
}


/**
 * Displays a message during setup.
 *
 * @since 1.1.1
 * @package facileManager
 * @subpackage Upgrader
 */
function displaySetupMessage($message = 1, $url = null) {
	global $fm_name;
	
	switch ($message) {
		case 1:
			echo <<<HTML
	<center>
	<p>Database upgrade for $fm_name is complete!  Click 'Next' to start using $fm_name.</p>
	<p class="step"><a href="$url" class="button">Next</a></p>
	</center>

HTML;
			break;
		case 2:
			echo <<<HTML
	<p style="text-align: center;">Database upgrade failed.  Please try again.</p>
	<p class="step"><a href="?step=2" class="button">Try Again</a></p>

HTML;
			break;
	}
}

?>