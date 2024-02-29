<?php

/**
 * @package CheckMail
 * @author Spuds
 * @copyright (c) 2016-2024 Spuds
 * @license This Source Code is subject to the terms of the Mozilla Public License
 * version 1.1 (the "License"). You can obtain a copy of the License at
 * http://mozilla.org/MPL/1.1/.
 *
 * @version 1.0.0
 *
 */

/**
 * Makes an API request to the check_mail service to validate an email
 *
 * - integrate_register_check, called from members.subs
 * - Adds additional registration checks in place
 *
 * @param array $regOptions
 * @param object $reg_errors
 */
function irc_check_mail($regOptions, $reg_errors)
{
	global $modSettings;

	// The Addon is enabled
	if (empty($modSettings['check_mail_enabled']) || empty($modSettings['check_mail_key']))
	{
		return;
	}

	// If there are already errors, lets avoid spending a request
	if ($reg_errors->hasErrors())
	{
		return;
	}

	// See if this is valid
	$valid = check_mail_validate_email($regOptions['email']);

	// If its does not meet the requirements set an error
	if ($valid === false)
	{
		loadLanguage('check_mail');
		$reg_errors->addError('check_mail_error_email');
	}
}

/**
 * Add check_mail options to the manage registration page
 *
 * - integrate_modify_registration_settings called from ManageRegistration_controller
 *
 * @param array $config_vars
 */
function imrs_check_mail(&$config_vars)
{
	global $modSettings, $txt;

	loadLanguage('check_mail');

	// Check if the api key works.
	$status = $txt['not_applicable'];

	// Entering the ACP, lets check the key if we have one and the addon is enabled.
	if (!empty($modSettings['check_mail_key']))
	{
		$url = 'https://check-mail.p.rapidapi.com/?domain=mailinator.com';
		$status = check_mail_status($url);

		if ($status->request_status !== 'ok')
		{
			$status = sprintf($txt['check_mail_bad_key'], $status->apikeystatus);
		}
		else
		{
			$status = sprintf($txt['check_mail_status_key'], ucwords($status->apikeystatus));
		}
	}
	elseif (!empty($modSettings['check_mail_enabled']))
	{
		$status = $txt['check_mail_missing_key'];
	}

	// Our settings to add to the form
	$add = [
		'',
		['check', 'check_mail_enabled', 'postinput' => $txt['check_mail_desc']],
		['text', 'check_mail_key', 'size' => 40],
		['text', 'check_mail_status', 'value' => $status, 'disabled' => true, 'size' => 80],
		''
	];

	$config_vars = elk_array_insert($config_vars, 4, $add);
}

/**
 * Save values as set in the manage registration form
 *
 * - integrate_save_registration_settings called from ManageRegistration_controller
 */
function isrs_check_mail()
{
	$req = HttpReq::instance();

	// Nothing to check
	if (empty($req->post->check_mail_enabled))
	{
		return;
	}

	// No key, no addon
	$check_mail_key = $req->getPost('check_mail_key', 'trim');
	if (empty($check_mail_key))
	{
		unset($req->post->check_mail_enabled);
	}
	// Is the key valid?
	else
	{
		updateSettings(['check_mail_key' => $check_mail_key]);
		$url = 'https://check-mail.p.rapidapi.com/?domain=mailinator.com';
		$status = check_mail_status($url);

		// Key or request is not valid, lets not enable the addon
		if ($status->request_status !== 'ok')
		{
			unset($req->post->check_mail_enabled);
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
function ilpf_check_mail(&$profile_fields)
{
	// Update the profile input_validate function with one that will use check_mail
	$profile_fields['email_address']['input_validate'] = static function ($value) {
		global $context, $old_profile, $profile_vars, $modSettings;

		if (strtolower($value) === strtolower($old_profile['email_address']))
		{
			return false;
		}

		$isValid = profileValidateEmail($value, $context['id_member']);

		// Perform a Mail check?
		if ($isValid === true && !empty($modSettings['check_mail_enabled']))
		{
			$isValid = check_mail_validate_email($value);
			if ($isValid === false)
			{
				loadLanguage('check_mail');
				$isValid = 'check_mail_email';
			}
		}

		// Do they need to revalidate? If so schedule the function!
		if ($isValid === true && !empty($modSettings['send_validation_onChange']) && !allowedTo('moderate_forum'))
		{
			require_once(SUBSDIR . '/Auth.subs.php');
			$profile_vars['validation_code'] = generateValidationCode();
			$profile_vars['is_activated'] = 2;
			$context['profile_execute_on_save'][] = 'profileSendActivation';
			unset($context['profile_execute_on_save']['reload_user']);
		}

		return $isValid;
	};
}

/**
 * Make the call to the service to validate / Check the API key
 *
 * @param string $url
 *
 * @return mixed|string
 */
function check_mail_status($url)
{
	$status = '';

	// No Curl, no dice
	if (!function_exists('curl_init'))
	{
		return (object) ['request_status' => 'fail', 'apikeystatus' => 'PHP Curl must be installed.'];
	}

	// Include the Curl_Fetch_Webdata class.
	require_once(SOURCEDIR . '/CurlFetchWebdata.class.php');

	// Prepare to make a curl request to check the api key
	$options = setOptions();
	$fetch_data = new Curl_Fetch_Webdata($options, 10);
	$fetch_data->get_url_data($url);

	if ($fetch_data->result('error') === false)
	{
		$status = json_decode($fetch_data->result('body'));

		// Valid request and response
		if (isset($status->block) && $status->block === true)
		{
			return (object) ['request_status' => 'ok', 'apikeystatus' => 'Valid'];
		}

		if (isset($status->message))
		{
			return (object) ['request_status' => 'fail', 'apikeystatus' => $status->message];
		}
	}

	return (object) ['request_status' => 'fail', 'apikeystatus' => 'Unknown'];
}

/**
 * Give the email domain a pat-down in the security line.
 *
 * @param string $email
 *
 * @return bool
 */
function check_mail_validate_email($email)
{
	// Include the file containing the Curl_Fetch_Webdata class.
	require_once(SOURCEDIR . '/CurlFetchWebdata.class.php');

	// Get the email domain, build the api request
	$email_parts = explode('@', $email);
	$email = $email_parts[1] ?? '';

	// Setup for a check
	$url = 'https://check-mail.p.rapidapi.com/?domain=' . trim($email);
	$options = setOptions();

	// Make the request
	$fetch_data = new Curl_Fetch_Webdata($options, 10);
	$fetch_data->get_url_data($url);

	// Review the results from the Email check
	if ($fetch_data->result('code') === 200 && $fetch_data->result('error') === false)
	{
		$check_mail = json_decode($fetch_data->result('body'));

		//   "valid": true,
		//   "block": true,
		//   "disposable": true,
		//   "domain": "nypato.com",
		//   "text": "Disposable e-mail",
		//   "reason": "Heuristics (1b)",
		//   "risk": 91,
		//   "mx_host": "mail57.nypato.com",
		//   "mx_info": "Using MX pointer mail57.nypato.com from DNS with priority: 5",
		//   "mx_ip": "109.236.80.110",
		//   "last_changed_at": "2020-06-11T09:56:02+02:00"
		if ($check_mail->block === true)
		{
			return false;
		}
	}

	return true;
}

/**
 * Set options for a cURL request
 *
 * - setOptions called from the method that performs cURL request
 *
 * @return array Returns an array of cURL options
 */
function setOptions()
{
	global $modSettings;

	return [
		CURLOPT_ENCODING => '',
		CURLOPT_TIMEOUT => 30,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_CUSTOMREQUEST => 'GET',
		CURLOPT_HTTPHEADER => [
			'x-rapidapi-host: check-mail.p.rapidapi.com',
			'x-rapidapi-key: ' . $modSettings['check_mail_key']
		],
	];
}
