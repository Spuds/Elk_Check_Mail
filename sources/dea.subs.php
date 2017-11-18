<?php

/**
 * @package Dea
 * @author Spuds
 * @copyright (c) 2016 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.2
 *
 */

if (!defined('ELK'))
{
	die('No access...');
}

/**
 * Makes an API request to the DEA service to validate an email
 *
 * - integrate_register_check, called from members.subs
 * - Adds additional registration checks in place, here DEA checking
 *
 * @param mixed[] $regOptions
 * @param object $reg_errors
 */
function irc_dea(&$regOptions, &$reg_errors)
{
	global $modSettings;

	// The Addon is enabled
	if (empty($modSettings['dea_enabled']) || empty($modSettings['dea_key']))
	{
		return;
	}

	// If there are already errors, lets avoid spending a request
	if ($reg_errors->hasErrors())
	{
		return;
	}

	// Lets see if this is valid
	$valid = dea_validate_email($regOptions['email']);

	// If its does not meet the requirements set an error
	if ($valid === false)
	{
		loadLanguage('dea');
		$reg_errors->addError('dea_error_email');
	}
}

/**
 * Add DEA options to the manage registration page
 *
 * - integrate_modify_registration_settings called from ManageRegistration_controller
 *
 * @param mixed $config_vars
 */
function imrs_dea(&$config_vars)
{
	global $modSettings, $txt;

	loadLanguage('dea');

	// Set / check status of the api key
	$status = $txt['not_applicable'];
	if (!empty($modSettings['dea_key']))
	{
		$url = 'http://status.block-disposable-email.com/status/?apikey=' . $modSettings['dea_key'];
		$status = dea_status($url);

		if ($status->request_status !== 'ok' || $status->apikeystatus !== 'active')
		{
			$status = sprintf($txt['dea_bad_key'], $status->apikeystatus);
		}
		else
		{
			$status = sprintf($txt['dea_status_key'], ucwords($status->apikeystatus), $status->credits);
		}
	}
	elseif (!empty($modSettings['dea_enabled']))
	{
		$status = $txt['dea_missing_key'];
	}

	// Our settings to add to the form
	$add = array(
		'',
		array('check', 'dea_enabled', 'postinput' => $txt['dea_desc']),
		array('text', 'dea_key', 'size' => 40),
		array('text', 'dea_status', 'value' => $status, 'disabled' => true, 'size' => 80),
		''
	);

	$config_vars = elk_array_insert($config_vars, 4, $add);
}

/**
 * Save dea values as set in the manage registration form
 *
 * - integrate_save_registration_settings called from ManageRegistration_controller
 */
function isrs_dea()
{
	$elkarte_version = substr(FORUM_VERSION, 8, 3);

	// Based on the version, dispatch to the right handler
	if ($elkarte_version === '1.1')
	{
		isrs_dea_for_11();
	}
	else
	{
		isrs_dea_for_10();
	}
}

/**
 * Save dea values as set in the manage registration form for 1.0.x installs
 */
function isrs_dea_for_10()
{
	// Nothing to check
	if (empty($_POST['dea_enabled']))
	{
		return;
	}

	// No key, no addon
	if (empty($_POST['dea_key']))
	{
		unset($_POST['dea_enabled']);
	}
	// Is the key valid?
	else
	{
		$url = 'http://status.block-disposable-email.com/status/?apikey=' . $_POST['dea_key'];
		$status = dea_status($url);

		// Key or request is not valid, lets not enable the addon
		if ($status->request_status !== 'ok' || $status->apikeystatus !== 'active')
		{
			unset($_POST['dea_enabled']);
		}
	}
}

/**
 * Save dea values as set in the manage registration form for 1.1.x installs
 */
function isrs_dea_for_11()
{
	$req = HttpReq::instance();

	// Nothing to check
	if (empty($req->post->dea_enabled))
	{
		return;
	}

	// No key, no addon
	if (empty($req->post->dea_key))
	{
		unset($req->post->dea_enabled);
	}
	// Is the key valid?
	else
	{
		$url = 'http://status.block-disposable-email.com/status/?apikey=' . $_POST['dea_key'];
		$status = dea_status($url);

		// Key or request is not valid, lets not enable the addon
		if ($status->request_status !== 'ok' || $status->apikeystatus !== 'active')
		{
			unset($req->post->dea_enabled);
		}
	}
}

/**
 * Profile hook, use to check when the user updates their email address
 *
 * - integrate_load_profile_fields, called from profile.subs
 * - Used to inject our validation requirements in the input_valid functions
 * - Ugly, just like profile fields, fragile to other addons that may effect this area
 *
 * @param array $profile_fields
 */
function ilpf_dea(&$profile_fields)
{
	// Update the profile input_validate function with one that will use DEA
	$profile_fields['email_address']['input_validate'] = create_function('&$value', '
		global $context, $old_profile, $profile_vars, $modSettings;

		if (strtolower($value) == strtolower($old_profile[\'email_address\']))
			return false;

		$isValid = profileValidateEmail($value, $context[\'id_member\']);

		// Perform a DEA check as well?
		if ($isValid === true && !empty($modSettings[\'dea_enabled\']))
		{
			$isValid = dea_validate_email($value);
			if ($isValid === false)
			{
				loadLanguage(\'dea\');
				$isValid = \'dea_email\';
			}
		}

		// Do they need to revalidate? If so schedule the function!
		if ($isValid === true && !empty($modSettings[\'send_validation_onChange\']) && !allowedTo(\'moderate_forum\'))
		{
			require_once(SUBSDIR . \'/Auth.subs.php\');
			$profile_vars[\'validation_code\'] = generateValidationCode();
			$profile_vars[\'is_activated\'] = 2;
			$context[\'profile_execute_on_save\'][] = \'profileSendActivation\';
			unset($context[\'profile_execute_on_save\'][\'reload_user\']);
		}

		return $isValid;
	');

	return;
}

/**
 * Make the call to the service to validate / Check the API key
 *
 * @param string $url
 *
 * @return mixed|string
 */
function dea_status($url)
{
	$status = '';

	// No Curl, no dice
	if (!function_exists('curl_init'))
	{
		return (object) array('request_status' => 'fail', 'apikeystatus' => 'PHP Curl must be installed.');
	}

	// Include the Curl_Fetch_Webdata class.
	require_once(SOURCEDIR . '/CurlFetchWebdata.class.php');

	// Prepare to make a curl request to check the api key
	$fetch_data = new Curl_Fetch_Webdata();
	$fetch_data->get_url_data($url);

	// Valid request and response
	if ($fetch_data->result('code') == 200 && !$fetch_data->result('error'))
	{
		$status = json_decode($fetch_data->result('body'));
	}

	return $status;
}

/**
 * Give the email domain a pat-down in the security line.
 *
 * @param string $email
 *
 * @return bool
 */
function dea_validate_email($email)
{
	global $modSettings;

	// Include the file containing the Curl_Fetch_Webdata class.
	require_once(SOURCEDIR . '/CurlFetchWebdata.class.php');

	// Get the email domain, build the api request
	$valid = true;
	$email_parts = explode('@', $email);
	$email = array_pop($email_parts);
	$url = 'http://check.block-disposable-email.com/easyapi/json/' . $modSettings['dea_key'] . '/' . trim($email);

	// Make the request
	$fetch_data = new Curl_Fetch_Webdata();
	$fetch_data->get_url_data($url);

	// Review the results from the Email check
	if ($fetch_data->result('code') == 200 && !$fetch_data->result('error'))
	{
		$dea = json_decode($fetch_data->result('body'));

		if ($dea->domain_status === 'block')
		{
			$valid = false;
		}
	}

	return $valid;
}