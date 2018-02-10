<?php

/**
* @package	Bad Behavior
* @author	Eric Sizemore <admin@secondversion.com>
* @version	1.0.1
* @license	GNU LGPL http://www.gnu.org/licenses/lgpl.txt
* 
*	Bad Behavior - Integrates MyBB and Bad Behavior
*	Copyright (C) 2011 - 2014 Eric Sizemore
*
*	Bad Behavior is free software; you can redistribute it and/or modify it under
*	the terms of the GNU Lesser General Public License as published by the Free
*	Software Foundation; either version 3 of the License, or (at your option) any
*	later version.
*
*	This program is distributed in the hope that it will be useful, but WITHOUT ANY
*	WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
*	PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.
*
*	You should have received a copy of the GNU Lesser General Public License along
*	with this program. If not, see <http://www.gnu.org/licenses/>.
*/

/*
Bad Behavior - detects and blocks unwanted Web accesses
Copyright (C) 2005,2006,2007,2008,2009,2010,2011,2012,2013 Michael Hampton

Bad Behavior is free software; you can redistribute it and/or modify it under
the terms of the GNU Lesser General Public License as published by the Free
Software Foundation; either version 3 of the License, or (at your option) any
later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY
WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
PARTICULAR PURPOSE. See the GNU Lesser General Public License for more details.

You should have received a copy of the GNU Lesser General Public License along
with this program. If not, see <http://www.gnu.org/licenses/>.

Please report any problems to bad . bots AT ioerror DOT us
http://www.bad-behavior.ioerror.us/
*/

// Disallow direct access to this file for security reasons
if (!defined('IN_MYBB'))
{
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

$plugins->add_hook('global_end', 'badbehavior');

/**
* Functions/options needed by Bad Behavior
*/
define('BB2_CWD', dirname(dirname(__FILE__)));

// Settings you can adjust for Bad Behavior.
// Most of these are unused in non-database mode.
// DO NOT EDIT HERE; instead make changes in MyBB Options
$bb2_settings_defaults = array(
	'log_table'               => TABLE_PREFIX . 'vb_badbehavior',
	'display_stats'           => false,
	'strict'                  => false,
	'verbose'                 => false,
	'logging'                 => true,
	'httpbl_key'              => '',
	'httpbl_threat'           => '25',
	'httpbl_maxage'           => '30',
	'offsite_forms'           => true,
	'eu_cookie'               => false,
	'reverse_proxy'           => false,
	'reverse_proxy_header'    => 'X-Forwarded-For',
	'reverse_proxy_addresses' => array()
);
	
// Bad Behavior callback functions.

// Return current time in the format preferred by your database.
function bb2_db_date()
{
	return gmdate('d-m-Y H:i:s');
}

// Return affected rows from most recent query.
function bb2_db_affected_rows()
{
	global $db;

	return $db->affected_rows();
}

// Escape a string for database usage
function bb2_db_escape($string)
{
	global $db;

	return $db->escape_string($string);
}

// Return the number of rows in a particular query.
function bb2_db_num_rows($result)
{
	if ($result !== false)
	{
		return count($result);
	}
	return 0;
}

// Run a query and return the results, if any.
// Should return FALSE if an error occurred.
// Bad Behavior will use the return value here in other callbacks.
function bb2_db_query($query)
{
	global $db, $config;

	$db->error_reporting = 0;

	if (preg_match("/^\\s*(insert|delete|update|replace|alter|set) /i", $query))
	{
		$db->query($query);

		return bb2_db_affected_rows();
	}

	$result = array();
	$results = array();

	$query = $db->query($query);
	$func = ($config['database']['type'] == 'mysql') ? 'mysql_fetch_object' : 'mysqli_fetch_object';

	if (!$query)
	{
		return false;
	}

	$i = 0;

	while ($row = @$func($query))
	{
		$results[$i] = $row;
		$i++;
	}

	if (!$results)
	{
		return false;
	}

	foreach ((array)$results AS $row)
	{
		$result[] = get_object_vars($row);
	}

	$db->error_reporting = 1;

	return $result;
}

// Return all rows in a particular query.
// Should contain an array of all rows generated by calling mysql_fetch_assoc()
// or equivalent and appending the result of each call to an array.
function bb2_db_rows($result)
{
	return $result;
}

// Our log table structure
function bb2_table_structure($name)
{
	// It's not paranoia if they really are out to get you.
	$name_escaped = bb2_db_escape($name);
	return "CREATE TABLE IF NOT EXISTS `$name_escaped` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`ip` TEXT NOT NULL,
	`date` DATETIME NOT NULL default '0000-00-00 00:00:00',
	`request_method` TEXT NOT NULL,
	`request_uri` TEXT NOT NULL,
	`server_protocol` TEXT NOT NULL,
	`http_headers` TEXT NOT NULL,
	`user_agent` TEXT NOT NULL,
	`request_entity` TEXT NOT NULL,
	`key` TEXT NOT NULL,
	INDEX (`ip`(15)),
	INDEX (`user_agent`(10)),
	PRIMARY KEY (`id`)
);";
}

// Create the SQL query for inserting a record in the database.
function bb2_insert($settings, $package, $key)
{
	$ip = bb2_db_escape($package['ip']);
	$request_method = bb2_db_escape($package['request_method']);
	$request_uri = bb2_db_escape($package['request_uri']);
	$server_protocol = bb2_db_escape($package['server_protocol']);
	$user_agent = bb2_db_escape($package['user_agent']);
	$headers = "$request_method $request_uri $server_protocol\n";

	foreach ($package['headers'] AS $h => $v)
	{
		$headers .= bb2_db_escape("$h: $v\n");
	}

	$request_entity = '';

	if (!strcasecmp($request_method, 'POST'))
	{
		foreach ($package['request_entity'] AS $h => $v)
		{
			$request_entity .= bb2_db_escape("$h: $v\n");
		}
	}

	return "INSERT INTO `" . bb2_db_escape($settings['log_table']) . "`
	(`ip`, `date`, `request_method`, `request_uri`, `server_protocol`, `http_headers`, `user_agent`, `request_entity`, `key`)
VALUES
	('$ip', NOW(), '$request_method', '$request_uri', '$server_protocol', '$headers', '$user_agent', '$request_entity', '$key')";
}

// Return emergency contact email address.
function bb2_email()
{
	global $mybb;

	if ($mybb->settings['adminemail'] != '')
	{
		return str_replace(array('@', '.'), array('(&#64;)', '(&#46;)'), $mybb->settings['webmasteremail']);
	}
	else
	{
		return '';
	}
}

// Converts yes/no in MyBB Options to true/false
function __bb2_read_settings_helper($value)
{
	return ($value == 1) ? true : false;
}

// retrieve settings from database
function bb2_read_settings()
{
	global $mybb;

	// http:BL Do we have an API Key?
	// All Access Keys are 12-characters in length, lower case, and contain only alpha characters (no numbers).
	if (strlen($mybb->settings['badbehavior_httpbl_key']) != 12 OR !ctype_lower($mybb->settings['badbehavior_httpbl_key']))
	{
		$mybb->settings['badbehavior_httpbl_key'] = '';
	}

	// http:BL Threat Level needs to be an integer
	if ((int)$mybb->settings['badbehavior_httpbl_threat'] == 0)
	{
		$mybb->settings['badbehavior_httpbl_threat'] = 25;
	}

	// http:BL Max. Age needs to be an integer as well
	if ((int)$mybb->settings['badbehavior_httpbl_maxage'] == 0)
	{
		$mybb->settings['badbehavior_httpbl_maxage'] = 30;
	}

	// Make sure that the proxy addresses are split into an array, and if it's empty - make sure reverse proxy is disabled
	if (!empty($mybb->settings['badbehavior_reverse_proxy_addresses']))
	{
		$mybb->settings['badbehavior_reverse_proxy_addresses'] = preg_split("#\n#", trim($mybb->settings['badbehavior_reverse_proxy_addresses']), -1, PREG_SPLIT_NO_EMPTY);
	}
	else
	{
		$mybb->settings['badbehavior_reverse_proxy_addresses'] = array();
		$mybb->settings['badbehavior_reverse_proxy'] = 0;
	}

	// also, make sure the header is set
	if (empty($mybb->settings['badbehavior_reverse_proxy_header']))
	{
		$mybb->settings['badbehavior_reverse_proxy_header'] = 'X-Forwarded-For';
	}

	// return settings
	return array(
		'log_table'               => TABLE_PREFIX . 'badbehavior',
		'display_stats'           => false,
		'strict'                  => __bb2_read_settings_helper($mybb->settings['badbehavior_strict']),
		'verbose'                 => __bb2_read_settings_helper($mybb->settings['badbehavior_verbose']),
		'logging'                 => __bb2_read_settings_helper($mybb->settings['badbehavior_logging']),
		'httpbl_key'              => $mybb->settings['badbehavior_httpbl_key'],
		'httpbl_threat'           => $mybb->settings['badbehavior_httpbl_threat'],
		'httpbl_maxage'           => $mybb->settings['badbehavior_httpbl_maxage'],
		'offsite_forms'           => true,
		'eu_cookie'               => __bb2_read_settings_helper($mybb->settings['badbehavior_eu_cookie']),
		'reverse_proxy'           => __bb2_read_settings_helper($mybb->settings['badbehavior_reverse_proxy']),
		'reverse_proxy_header'    => $mybb->settings['badbehavior_reverse_proxy_header'],
		'reverse_proxy_addresses' => $mybb->settings['badbehavior_reverse_proxy_addresses']
	);
}

// write settings to database
function bb2_write_settings($settings)
{
	return false;
}

// installation
function bb2_install()
{
	return false;
}

// Screener
// Insert this into the <head> section of your HTML through a template call
// or whatever is appropriate. This is optional we'll fall back to cookies
// if you don't use it.
function bb2_insert_head()
{
	global $bb2_javascript;

	return $bb2_javascript;
}

// Display stats? This is optional.
function bb2_insert_stats($force = false)
{
	return '';
}

// Return the top-level relative path of wherever we are (for cookies)
// You should provide in $url the top-level URL for your site.
function bb2_relative_path()
{
	global $mybb;

	$url = parse_url($mybb->settings['bburl']);
	return $url['path'] . '/';
}

// Calls inward to Bad Behavor itself.
require_once(BB2_CWD . '/bad-behavior/core.inc.php');

// ##################################################################
function badbehavior_info()
{
	return array(
		"name"			=> "Bad Behavior",
		"description"	=> "This is an integration of the Bad Behavior software with MyBB. Bad Behavior is a PHP-based solution for blocking link spam and the robots which deliver it.",
		"website"		=> "http://www.secondversion.com/",
		"author"		=> "SecondV",
		"authorsite"	=> "http://www.secondversion.com/",
		"version"		=> "1.0.1",
		"guid"			=> "da9c0d9836a2bfa0d285e5a6273a6fc6",
		"compatibility"	=> "16*"
	);
}

function badbehavior_install()
{
	global $db, $mybb;

	$db->query("
		CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "badbehavior` (
			`id` INT(11) NOT NULL AUTO_INCREMENT,
			`ip` TEXT NOT NULL,
			`date` DATETIME NOT NULL default '0000-00-00 00:00:00',
			`request_method` TEXT NOT NULL,
			`request_uri` TEXT NOT NULL,
			`server_protocol` TEXT NOT NULL,
			`http_headers` TEXT NOT NULL,
			`user_agent` TEXT NOT NULL,
			`request_entity` TEXT NOT NULL,
			`key` TEXT NOT NULL,
			INDEX (`ip`(15)),
			INDEX (`user_agent`(10)),
			PRIMARY KEY (`id`)
		)
	");

	$badbehavior_group = array(
		"gid"			=> "NULL",
		"name"			=> "badbehavior",
		"title"			=> "Bad Behavior",
		"description"	=> "Bad Behavior is a PHP-based solution for blocking link spam and the robots which deliver it.",
		"disporder"		=> 5,
		"isdefault"		=> 0
	);

	$group['gid'] = $db->insert_query("settinggroups", $badbehavior_group);
	$mybb->smallquote_insert_gid = $group['gid'];

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_enabled",
		"title"			=> "Bad Behavior Enabled",
		"description"	=> "Set to \"yes\" to enable the vB Bad Behavior protection on your forum.",
		"optionscode"	=> "yesno", 
		"value"			=> 1,
		"disporder"		=> 1,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_strict",
		"title"			=> "Operating Mode",
		"description"	=> "Bad Behavior operates in two blocking modes: normal and strict. When strict mode is enabled, some additional checks for buggy software which have been spam sources are enabled, but occasional legitimate users using the same software (usually corporate or government users using very old software) may be blocked as well.",
		"optionscode"	=> "yesno",
		"value"			=> 0,
		"disporder"		=> 2,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_logging",
		"title"			=> "Logging",
		"description"	=> "Should Bad Behavior keep a log of requests? On by default, and it is not recommended to disable it, since it will cause additional spam to get through.",
		"optionscode"	=> "yesno",
		"value"			=> 1,
		"disporder"		=> 3,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_verbose",
		"title"			=> "Verbose Logging",
		"description"	=> "Turning on verbose mode causes all HTTP requests to be logged. When verbose mode is off, only blocked requests and a few suspicious (but permitted) requests are logged.<br /><br />Verbose mode is off by default. Using verbose mode is not recommended as it can significantly slow down your site; it exists to capture data from live spammers which are not being blocked.",
		"optionscode"	=> "yesno",
		"value"			=> 0,
		"disporder"		=> 4,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);
	
	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_httpbl_key",
		"title"			=> "http:BL Api Key",
		"description"	=> "Bad Behavior is capable of using data from the <a href=\"http://www.projecthoneypot.org/faq.php#g\" target=\"_blank\">http:BL</a> service provided by <a href=\"http://www.projecthoneypot.org/\" target=\"_blank\">Project Honey Pot</a> to screen requests.<br /><br />This is purely optional; however if you wish to use it, you must <a href=\"http://www.projecthoneypot.org/httpbl_configure.php\" target=\"_blank\">sign up for the service</a> and obtain an API key. To disable http:BL use, remove the API key from your settings.",
		"optionscode"	=> "text",
		"value"			=> "",
		"disporder"		=> 5,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_httpbl_threat",
		"title"			=> "http:BL Thread Level",
		"description"	=> "This number provides a measure of how suspicious an IP address is, based on activity observed at Project Honey Pot. Bad Behavior will block requests with a threat level equal or higher to this setting. Project Honey Pot has <a href=\"http://www.projecthoneypot.org/threat_info.php\" target=\"_blank\">more information on this parameter</a>.",
		"optionscode"	=> "text",
		"value"			=> "25",
		"disporder"		=> 6,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_httpbl_maxage",
		"title"			=> "http:BL Maximum Age",
		"description"	=> "This is the number of days since suspicious activity was last observed from an IP address by Project Honey Pot. Bad Behavior will block requests with a maximum age equal to or less than this setting.",
		"optionscode"	=> "text",
		"value"			=> "30",
		"disporder"		=> 7,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_eu_cookie",
		"title"			=> "EU Cookie",
		"description"	=> "Set this option to \"yes\" if you believe Bad Behavior\'s site security cookie is not exempt from the 2012 EU cookie regulation. <a href=\"http://bad-behavior.ioerror.us/2012/05/03/bad-behavior-2-2-4/\">[more info]</a>",
		"optionscode"	=> "yesno",
		"value"			=> 0,
		"disporder"		=> 8,
		"gid"	 		=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_reverse_proxy",
		"title"			=> "Reverse Proxy",
		"description"	=> "When enabled, Bad Behavior will assume it is receiving a connection from a reverse proxy, when a specific HTTP header is received.",
		"optionscode"	=> "yesno",
		"value"			=> 0,
		"disporder"		=> 9,
		"gid"	 		=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_reverse_proxy_header",
		"title"			=> "Reverse Proxy Header",
		"description"	=> "When Reverse Proxy is enabled, Bad Behavior checks this header to locate the true IP address of the connecting client.",
		"optionscode"	=> "text",
		"value"			=> "X-Forwarded-For",
		"disporder"		=> 10,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	$badbehavior_setting = array(
		"sid"			=> "NULL",
		"name"			=> "badbehavior_reverse_proxy_addresses",
		"title"			=> "Reverse Proxy Addresses",
		"description"	=> "IP address or CIDR netblocks which Bad Behavior trusts to provide reliable information in the HTTP header given above. If no addresses are given, Bad Behavior will assume that the HTTP header given is always trustworthy and that the right-most IP address appearing in the header is correct.<br /><br />If you have a chain of two or more proxies this is probably not what you want; in this scenario you should either set this option and provide all proxy server IP addresses (or ranges) which could conceivably handle the request, or have your edge servers set a unique HTTP header with the clients IP address.<br /><br />For instance, when using CloudFlare, it is impossible to provide a list of IP addresses, so you would set the HTTP header to CloudFlares provided \"CF-Connecting-IP\" header instead.<br /><br /><strong style=\"color: #ff0000;\">NOTE: Enter one ip address/CIDR netblock per line.</strong>",
		"optionscode"	=> "textarea",
		"value"			=> "",
		"disporder"		=> 11,
		"gid"			=> $group['gid']
	);

	$db->insert_query("settings", $badbehavior_setting);

	rebuild_settings();
	
}

function badbehavior_uninstall()
{
	global $db, $mybb, $lang;

	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_enabled'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_strict'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_logging'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_verbose'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_httpbl_key'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_httpbl_threat'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_httpbl_maxage'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_eu_cookie'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_reverse_proxy'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_reverse_proxy_header'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settings WHERE name='badbehavior_reverse_proxy_addresses'");
	$db->query("DELETE FROM " . TABLE_PREFIX . "settinggroups WHERE name='badbehavior'"); 
	$db->query("DROP TABLE IF EXISTS " . TABLE_PREFIX . "badbehavior");

	rebuild_settings();
}

function badbehavior_is_installed()
{
	global $db;

	$query = $db->simple_select("settinggroups", "COUNT(*) as rows", "name = 'badbehavior'");
	$bbcnt = $db->fetch_field($query, "rows");

	if($bbcnt == 1)
	{
		return true;
	}
	return false;
}

function badbehavior_activate() {}

function badbehavior_deactivate() {}

function badbehavior()
{
	global $headerinclude, $mybb;

	if ($mybb->settings['badbehavior_enabled'])
	{
		bb2_start(bb2_read_settings());
	}
	$headerinclude .= bb2_insert_head();
}

// ##################################################################
$plugins->add_hook('admin_load', 'badbehavior_admin');
$plugins->add_hook('admin_tools_menu_logs', 'badbehavior_admin_tools_menu');
$plugins->add_hook('admin_tools_action_handler', 'badbehavior_admin_tools_action_handler');
$plugins->add_hook('admin_tools_permissions', 'badbehavior_admin_permissions');

function badbehavior_admin_tools_menu(&$sub_menu)
{
	global $lang;
	
	$lang->load('badbehavior');

	$sub_menu[] = array('id' => 'badbehavior', 'title' => $lang->badbehavior_logs_index, 'link' => 'index.php?module=tools-badbehavior');
}

function badbehavior_admin_tools_action_handler(&$actions)
{
	$actions['badbehavior'] = array('active' => 'badbehavior', 'file' => 'badbehavior');
}

function badbehavior_admin_permissions(&$admin_permissions)
{
  	global $db, $mybb, $lang;
  
	$lang->load('badbehavior', false, true);

	$admin_permissions['badbehavior'] = $lang->badbehavior_logs_canmanage;
}

function badbehavior_admin()
{
	global $db, $lang, $mybb, $page, $run_module, $action_file, $mybbadmin, $plugins;
	
	$lang->load('badbehavior', false, true);
	
	if ($run_module == 'tools' AND $action_file == 'badbehavior')
	{
		if ($mybb->input['action'] == 'keycheck')
		{
			define('BB2_CORE', dirname(dirname(__FILE__)) . '/bad-behavior/');
			require_once(BB2_CORE . '/responses.inc.php');

			$response = bb2_get_response($mybb->input['key']);

			if ($response[0] == '00000000')
			{
				echo 'Unknown';
			}
			else
			{
				echo <<<KEY
HTTP Response: $response[response]<br />\n
Explanation: $response[explanation]<br />\n
Log Message: $response[log]<br />\n
KEY;
			}
			exit;
		}

		if (!$mybb->input['action'])
		{
			$page->add_breadcrumb_item($lang->badbehavior_logs, 'index.php?module=tools-badbehavior');

			$page->output_header($lang->badbehavior_logs);

			$sub_tabs['badbehavior_logs'] = array(
				'title'			=> $lang->badbehavior_logs,
				'link'			=> 'index.php?module=tools-badbehavior',
				'description'	=> $lang->badbehavior_logs_desc
			);
		}

		if (!$mybb->input['action'])
		{
			$page->output_nav_tabs($sub_tabs, 'badbehavior_logs');

			$per_page = 15;

			if ($mybb->input['page'] AND intval($mybb->input['page']) > 1)
			{
				$mybb->input['page'] = intval($mybb->input['page']);
				$start = ($mybb->input['page'] * $per_page) - $per_page;
			}
			else
			{
				$mybb->input['page'] = 1;
				$start = 0;
			}

			$query = $db->simple_select('badbehavior', 'COUNT(id) AS logs');
			$total_rows = $db->fetch_field($query, 'logs');
		
			echo '<br />' . draw_admin_pagination(
				$mybb->input['page'], 
				$per_page, 
				$total_rows, 
				'index.php?module=tools-badbehavior&amp;page={page}'
			);

			// table
			$table = new Table;
			$table->construct_header($lang->badbehavior_logs_ipaddress);
			$table->construct_header($lang->badbehavior_logs_date);
			$table->construct_header($lang->badbehavior_logs_key);
			$table->construct_header($lang->badbehavior_logs_useragent);
			$table->construct_header($lang->badbehavior_logs_request_method . '/' . $lang->badbehavior_logs_server_protocol);
			$table->construct_header($lang->badbehavior_logs_request_uri);
			$table->construct_header($lang->badbehavior_logs_request_entity . '/' . $lang->badbehavior_logs_http_headers);
			$table->construct_header($lang->badbehavior_logs_options);

			$query = $db->query("
				SELECT * 
				FROM " . TABLE_PREFIX . "badbehavior
				ORDER BY date DESC
				LIMIT $start, $per_page
			");

			if ($db->num_rows($query) == 0)
			{
				$table->construct_cell($lang->badbehavior_logs_none, array('colspan' => 10));
				$table->construct_row();
			}
			else
			{
				while ($log = $db->fetch_array($query))
				{
					$table->construct_cell($log['ip']);
					$table->construct_cell($log['date']);
					$table->construct_cell("<a href=\"#\" onclick=\"window.open('index.php?module=tools-badbehavior&amp;action=keycheck&amp;key=$log[key]', 'keycheck', 'width=200,height=200');return false;\">$log[key]</a>");
					$table->construct_cell("<input type=\"text\" value=\"$log[user_agent]\" onclick=\"alert(this.value);\" />");
					$table->construct_cell($log['request_method'] . '<br />' . $log['server_protocol']);
					$table->construct_cell("<input type=\"text\" value=\"$log[request_uri]\" onclick=\"alert(this.value);\" />");
					$table->construct_cell(($log['request_entity'] ? "<textarea style=\"width: 80%;\" onclick=\"alert(this.value);\">$log[request_entity]</textarea>" : '') . '<br />' . "<textarea style=\"width: 80%;\" onclick=\"alert(this.value);\">$log[http_headers]</textarea>");
					$table->construct_cell("<a href=\"index.php?module=tools-badbehavior&amp;action=delete_log&amp;id=$log[id]\">$lang->badbehavior_logs_delete</a>");
					$table->construct_row();
				}
			}

			$table->output($lang->badbehavior_logs);

			echo '<br />';

			$form = new Form('index.php?module=tools-badbehavior&amp;action=prune', 'post', 'badbehavior_logs');

			echo $form->generate_hidden_field('my_post_key', $mybb->post_code);

			$form_container = new FormContainer($lang->badbehavior_logs_prune);
			$form_container->output_row(
				$lang->badbehavior_logs_prune_days, 
				$lang->badbehavior_logs_prune_days_desc, 
				$form->generate_text_box('days', 30, array('id' => 'days')), 
				'days'
			);
			$form_container->end();

			$buttons = array();;
			$buttons[] = $form->generate_submit_button($lang->badbehavior_logs_submit);
			$buttons[] = $form->generate_reset_button($lang->badbehavior_logs_reset);

			$form->output_submit_wrapper($buttons);
			$form->end();
		}
		else if ($mybb->input['action'] == 'delete_log')
		{
			if ($mybb->input['no'])
			{
				admin_redirect('index.php?module=tools-badbehavior');
			}

			if ($mybb->request_method == 'post')
			{
				if (!isset($mybb->input['my_post_key']) OR $mybb->post_code != $mybb->input['my_post_key'])
				{
					$mybb->request_method = 'get';
					flash_message($lang->badbehavior_logs_error, 'error');
					admin_redirect('index.php?module=tools-badbehavior');
				}

				if (!$db->fetch_field($db->simple_select('badbehavior', 'id', 'id=' . intval($mybb->input['id']), array('limit' => 1)), 'id'))
				{
					flash_message($lang->badbehavior_logs_invalid, 'error');
					admin_redirect('index.php?module=tools-badbehavior');
				}
				else
				{																			 
					$db->delete_query('badbehavior', 'id=' . intval($mybb->input['id']));
					flash_message($lang->badbehavior_logs_deleted, 'success');
					admin_redirect('index.php?module=tools-badbehavior');
				}
			}
			else
			{
				$page->add_breadcrumb_item($lang->badbehavior_logs, 'index.php?module=tools-badbehavior');

				$page->output_header($lang->badbehavior_logs);

				$mybb->input['id'] = intval($mybb->input['id']);

				$form = new Form("index.php?module=tools-badbehavior&amp;action=delete_log&amp;id={$mybb->input['id']}&amp;my_post_key={$mybb->post_code}", 'post');
				echo "<div class=\"confirm_action\"><p>{$lang->badbehavior_logs_deleteconfirm}</p>\n<br />\n";
				echo "<p class=\"buttons\">\n";
				echo $form->generate_submit_button($lang->yes, array('class' => 'button_yes'));
				echo $form->generate_submit_button($lang->no, array("name" => "no", 'class' => 'button_no'));
				echo "</p>\n";
				echo "</div>\n";
				$form->end();
			}
		}
		else if ($mybb->input['action'] == 'prune')
		{
			if ($mybb->input['no'])
			{
				admin_redirect('index.php?module=tools-badbehavior');
			}

			if ($mybb->request_method == 'post')
			{
				if (!isset($mybb->input['my_post_key']) OR $mybb->post_code != $mybb->input['my_post_key'])
				{
					$mybb->request_method = 'get';
					flash_message($lang->badbehavior_logs_error, 'error');
					admin_redirect('index.php?module=tools-badbehavior');
				}

				$db->delete_query('badbehavior', 'UNIX_TIMESTAMP(date) < ' . (TIME_NOW - intval($mybb->input['days']) * 60 * 60 * 24));
				flash_message($lang->badbehavior_logs_pruned, 'success');
				admin_redirect('index.php?module=tools-badbehavior');
			}
			else
			{
				$page->add_breadcrumb_item($lang->badbehavior_logs, 'index.php?module=tools-badbehavior');

				$page->output_header($lang->badbehavior_logs);

				$mybb->input['days'] = intval($mybb->input['days']);

				$form = new Form("index.php?module=tools-badbehavior&amp;action=prune&amp;days={$mybb->input['days']}&amp;my_post_key={$mybb->post_code}", 'post');
				echo "<div class=\"confirm_action\">\n<p>{$lang->badbehavior_logs_pruneconfirm}</p>\n<br />\n";
				echo "<p class=\"buttons\">\n";
				echo $form->generate_submit_button($lang->yes, array('class' => 'button_yes'));
				echo $form->generate_submit_button($lang->no, array("name" => "no", 'class' => 'button_no'));
				echo "</p>\n";
				echo "</div>\n";
				$form->end();
			}
		}
		$page->output_footer();
		exit;
	}
}

?>