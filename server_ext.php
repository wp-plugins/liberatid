<?php

require_once 'Auth/OpenID/SReg.php';

add_filter('liberatid_server_xrds_types', 'liberatid_server_sreg_xrds_types');
add_action('liberatid_server_post_auth', 'liberatid_server_sreg_post_auth');

function liberatid_server_sreg_xrds_types($types) {
	$types[] = 'http://openid.net/extensions/sreg/1.1';
	$types[] = 'http://openid.net/sreg/1.0';
	return $types;
}


/**
 * See if the OpenID authentication request includes SReg and add additional hooks if so.
 */
function liberatid_server_sreg_post_auth($request) {
	$sreg_request = Auth_OpenID_SRegRequest::fromOpenIDRequest($request);
	if ($sreg_request) {
		$GLOBALS['liberatid_server_sreg_request'] = $sreg_request;
		add_action('liberatid_server_trust_form', 'liberatid_server_attributes_trust_form');
		add_filter('liberatid_server_trust_form_attributes', 'liberatid_server_sreg_trust_form');
		add_action('liberatid_server_trust_submit', 'liberatid_server_sreg_trust_submit', 10, 2);
		add_filter('liberatid_server_store_trusted_site', 'liberatid_server_sreg_store_trusted_site');
		add_action('liberatid_server_auth_response', 'liberatid_server_sreg_auth_response' );
	}
}


/**
 * Add SReg input fields to the OpenID Trust Form
 */
function liberatid_server_sreg_trust_form( $attributes ) {
	$sreg_request = $GLOBALS['liberatid_server_sreg_request'];
	$sreg_fields = $sreg_request->allRequestedFields();

	if (!empty($sreg_fields)) {
		foreach ($sreg_fields as $field) {
			$value = liberatid_server_sreg_from_profile($field);
			if (!empty($value)) {
				$attributes[] = strtolower($GLOBALS['Auth_OpenID_sreg_data_fields'][$field]);
			}
		}
	}

	return $attributes;
}


/**
 * Add attribute input fields to the OpenID Trust Form
 */
function liberatid_server_attributes_trust_form() {
	$attributes = apply_filters('liberatid_server_trust_form_attributes', array());

	if (!empty($attributes)) {
		$attr_string = liberatid_server_attributes_string($attributes);

		echo '
		<p class="trust_form_add" style="padding: 0">
			<input type="checkbox" id="include_sreg" name="include_sreg" checked="checked" style="display: block; float: left; margin: 0.8em;" />
			<label for="include_sreg" style="display: block; padding: 0.5em 2em;">'.sprintf(__('Also grant access to see my %s.', 'liberatid'), $attr_string) . '</label>
		</p>';
	}
}


/**
 * Convert list of attribute names to human readable string.
 */
function liberatid_server_attributes_string($fields, $string = '') {
	if (empty($fields)) return $string;

	if (empty($string)) {
		if (sizeof($fields) == 2) 
			return join(' and ', $fields);
		$string = array_shift($fields);
	} else if (sizeof($fields) == 1) {
		$string .= ', and ' . array_shift($fields);
	} else if (sizeof($fields) > 1) {
		$string .= ', ' . array_shift($fields);
	}

	return liberatid_server_attributes_string($fields, $string);
}


/**
 * Based on input from the OpenID trust form, prep data to be included in the authentication response
 */
function liberatid_server_sreg_trust_submit($trust, $request) {
	if ($trust && $_REQUEST['include_sreg'] == 'on') {
		$GLOBALS['liberatid_server_sreg_trust'] = true;
	} else {
		$GLOBALS['liberatid_server_sreg_trust'] = false;
	}
}


/**
 * Store user's decision on whether to release attributes to the site.
 */
function liberatid_server_sreg_store_trusted_site($site) {
	$site['release_attributes'] = $GLOBALS['liberatid_server_sreg_trust'];
	return $site;
}


/**
 * Attach SReg response to authentication response.
 */
function liberatid_server_sreg_auth_response($response) {
	$user = wp_get_current_user();

	// should we include SREG in the response?
	$include_sreg = false;

	if (isset($GLOBALS['liberatid_server_sreg_trust'])) {
		$include_sreg = $GLOBALS['liberatid_server_sreg_trust'];
	} else {
		$trusted_sites = get_user_meta($user->ID, 'liberatid_trusted_sites', true);
		$request = $response->request;
		$site_hash = md5($request->trust_root);
		if (is_array($trusted_sites) && array_key_exists($site_hash, $trusted_sites)) {
			$include_sreg = $trusted_sites[$site_hash]['release_attributes'];
		}
	}

	if ($include_sreg) {
		$sreg_data = array();
		foreach ($GLOBALS['Auth_OpenID_sreg_data_fields'] as $field => $name) {
			$value = liberatid_server_sreg_from_profile($field);
			if (!empty($value)) {
				$sreg_data[$field] = $value;
			}
		}

		$sreg_response = Auth_OpenID_SRegResponse::extractResponse($GLOBALS['liberatid_server_sreg_request'], $sreg_data);
		if (!empty($sreg_response)) $response->addExtension($sreg_response);
	}

	return $response;
}


/**
 * Try to pre-populate SReg data from user's profile.  The following fields 
 * are not handled by the plugin: dob, gender, postcode, country, and language.
 * Other plugins may provide this data by implementing the filter 
 * liberatid_server_sreg_${fieldname}.
 *
 * @uses apply_filters() Calls 'liberatid_server_sreg_*' before returning sreg values, 
 *       where '*' is the name of the sreg attribute.
 */
function liberatid_server_sreg_from_profile($field) {
	$user = wp_get_current_user();
	$value = '';

	switch($field) {
		case 'nickname':
			$value = get_user_meta($user->ID, 'nickname', true);
			break;

		case 'email':
			$value = $user->user_email;
			break;

		case 'fullname':
			$value = get_user_meta($user->ID, 'display_name', true);
			break;
	}

	$value = apply_filters('liberatid_server_sreg_' . $field, $value, $user->ID);
	return $value;
}


?>
