<?php
/**
 * Functions related to the OpenID Consumer.
 */


// hooks for getting user data
add_filter('liberatid_auth_request_extensions', 'liberatid_add_sreg_extension', 10, 2);
add_filter('liberatid_auth_request_extensions', 'liberatid_add_ax_extension', 10, 2);

add_filter( 'xrds_simple', 'liberatid_consumer_xrds_simple');

/**
 * Get the internal OpenID Consumer object.  If it is not already initialized, do so.
 *
 * @return Auth_OpenID_Consumer OpenID consumer object
 */
function liberatid_getConsumer() {
	static $consumer;

	if (!$consumer) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once 'Auth/OpenID/Consumer.php';
		restore_include_path();

		$store = liberatid_getStore();
		$consumer = new Auth_OpenID_Consumer($store);
		if( null === $consumer ) {
			liberatid_error('OpenID consumer could not be created properly.');
			liberatid_enabled(false);
		}

	}

	return $consumer;
}


/**
 * Send the user to their OpenID provider to authenticate.
 *
 * @param Auth_OpenID_AuthRequest $auth_request OpenID authentication request object
 * @param string $trust_root OpenID trust root
 * @param string $return_to URL where the OpenID provider should return the user
 */
function liberatid_redirect($auth_request, $trust_root, $return_to) {
	do_action('liberatid_redirect', $auth_request, $trust_root, $return_to);

	$message = $auth_request->getMessage($trust_root, $return_to, false);

	if (Auth_OpenID::isFailure($message)) {
		return liberatid_error('Could not redirect to server: '.$message->message);
	}

	$_SESSION['liberatid_return_to'] = $message->getArg(Auth_OpenID_LIBERATID_NS, 'return_to');

	// send 302 redirect or POST
	if ($auth_request->shouldSendRedirect()) {
		$redirect_url = $auth_request->redirectURL($trust_root, $return_to);
		wp_redirect( $redirect_url );
	} else {
		liberatid_repost($auth_request->endpoint->server_url, $message->toPostArgs());
	}
}


/**
 * Finish OpenID Authentication.
 *
 * @return String authenticated identity URL, or null if authentication failed.
 */
function finish_liberatid_auth() {
	@session_start();

	$consumer = liberatid_getConsumer();
	if ( array_key_exists('liberatid_return_to', $_SESSION) ) {
		$liberatid_return_to = $_SESSION['liberatid_return_to'];
	}
	if ( empty($liberatid_return_to) ) {
		$liberatid_return_to = liberatid_service_url('consumer');
	}

	$response = $consumer->complete($liberatid_return_to);

	unset($_SESSION['liberatid_return_to']);
	liberatid_response($response);

	switch( $response->status ) {
		case Auth_OpenID_CANCEL:
			liberatid_message(__('OpenID login was cancelled.', 'liberatid'));
			liberatid_status('error');
			break;

		case Auth_OpenID_FAILURE:
			liberatid_message(sprintf(__('OpenID login failed: %s', 'liberatid'), $response->message));
			liberatid_status('error');
			break;

		case Auth_OpenID_SUCCESS:
			liberatid_message(__('OpenID login successful', 'liberatid'));
			liberatid_status('success');

			$identity_url = $response->identity_url;
			$escaped_url = htmlspecialchars($identity_url, ENT_QUOTES);
			return $escaped_url;

		default:
			liberatid_message(__('Unknown Status. Bind not successful. This is probably a bug.', 'liberatid'));
			liberatid_status('error');
	}

	return null;
}


/**
 * Begin login by activating the OpenID consumer.
 *
 * @param string $url claimed ID
 * @return Auth_OpenID_Request OpenID Request
 */
function liberatid_begin_consumer($url) {
	static $request;

	@session_start();
	if ($request == NULL) {
		set_error_handler( 'liberatid_customer_error_handler');

		$consumer = liberatid_getConsumer();
                
		$request = $consumer->begin($url);
                
                // Create a request for registration data
                $sreg = Auth_OpenID_SRegRequest::build(array('email', 'fullname'), array('nickname'));
                if (!$sreg) {
                    //TODO
                }
                $request->addExtension($sreg);


		restore_error_handler();
	}

	return $request;
}


/**
 * Start the OpenID authentication process.
 *
 * @param string $claimed_url claimed OpenID URL
 * @param string $action OpenID action being performed
 * @param string $finish_url stored in user session for later redirect
 * @uses apply_filters() Calls 'liberatid_auth_request_extensions' to gather extensions to be attached to auth request
 */
function liberatid_start_login( $claimed_url, $action, $finish_url = null) {
	if ( empty($claimed_url) ) return; // do nothing.

	$auth_request = liberatid_begin_consumer( $claimed_url );

	if ( null === $auth_request ) {
		liberatid_status('error');
		liberatid_message(sprintf(
			__('Could not discover an OpenID identity server endpoint at the url: %s', 'liberatid'),
			htmlentities($claimed_url)
		));

		return;
	}

	@session_start();
	$_SESSION['liberatid_action'] = $action;
	$_SESSION['liberatid_finish_url'] = $finish_url;

	$extensions = apply_filters('liberatid_auth_request_extensions', array(), $auth_request);
	foreach ($extensions as $e) {
		if (is_a($e, 'Auth_OpenID_Extension')) {
			$auth_request->addExtension($e);
		}
	}

	$return_to = liberatid_service_url('consumer', 'login_post');
	$return_to = apply_filters('liberatid_return_to', $return_to);

	$trust_root = liberatid_trust_root($return_to);

	liberatid_redirect($auth_request, $trust_root, $return_to);
	exit(0);
}


/**
 * Build an Attribute Exchange attribute query extension if we've never seen this OpenID before.
 */
function liberatid_add_ax_extension($extensions, $auth_request) {
	if(!get_user_by_liberatid($auth_request->endpoint->claimed_id)) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once('Auth/OpenID/AX.php');
		restore_include_path();

		if ($auth_request->endpoint->usesExtension(Auth_OpenID_AX_NS_URI)) {
			$ax_request = new Auth_OpenID_AX_FetchRequest();
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson/friendly', 1, true));
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/contact/email', 1, true));
			$ax_request->add(Auth_OpenID_AX_AttrInfo::make('http://axschema.org/namePerson', 1, true));

			$extensions[] = $ax_request;
		}
	}

	return $extensions;
}


/**
 * Build an SReg attribute query extension if we've never seen this OpenID before.
 */
function liberatid_add_sreg_extension($extensions, $auth_request) {
	if(!get_user_by_liberatid($auth_request->endpoint->claimed_id)) {
		set_include_path( dirname(__FILE__) . PATH_SEPARATOR . get_include_path() );
		require_once('Auth/OpenID/SReg.php');
		restore_include_path();

		if ($auth_request->endpoint->usesExtension(Auth_OpenID_SREG_NS_URI_1_0) || $auth_request->endpoint->usesExtension(Auth_OpenID_SREG_NS_URI_1_1)) {
			$extensions[] = Auth_OpenID_SRegRequest::build(array(),array('nickname','email','fullname'));
		}
	}

	return $extensions;
}


/**
 * Finish OpenID authentication.
 *
 * @param string $action login action that is being performed
 * @uses do_action() Calls 'liberatid_finish_auth' hook action after processing the authentication response.
 */
function finish_liberatid($action) {

        $identity_url = finish_liberatid_auth();

        //LiberatID Changes - BEGIN
        $data = array();
        $isLiberatIDOP = stripos($identity_url, 'liberatid');
        if ( $isLiberatIDOP !== FALSE) { //Extract SREG or Attribute Exchange data only for LiberatID
            
            // Get Simple Registration info
            $data = liberatid_get_user_data_sreg($data, $identity_url);
            $data = liberatid_get_user_data_ax($data, $identity_url);
        }

        do_action('liberatid_finish_auth', $identity_url, $action, $data);
        //LiberatID Changes - END

        //do_action('liberatid_finish_auth', $identity_url, $action);
}


/**
 *
 * @uses apply_filters() Calls 'liberatid_consumer_return_urls' to collect return_to URLs to be included in XRDS document.
 */
function liberatid_consumer_xrds_simple($xrds) {

	if (get_option('liberatid_xrds_returnto')) {
		// OpenID Consumer Service
		$return_urls = array_unique(apply_filters('liberatid_consumer_return_urls', array(liberatid_service_url('consumer', 'login_post'))));
		if (!empty($return_urls)) {
			$xrds = xrds_add_simple_service($xrds, 'OpenID Consumer Service', 'http://specs.openidid.net/auth/2.0/return_to', $return_urls);
		}
	}

	return $xrds;
}




