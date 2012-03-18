<?php
/**
 * All the code required for handling logins via wp-login.php.  These functions should not be considered public, 
 * and may change without notice.
 */


add_action( 'login_head', 'liberatid_wp_login_head');
add_action( 'login_form', 'liberatid_wp_login_form');
add_action( 'register_form', 'liberatid_wp_register_form', 9);
add_action( 'register_post', 'liberatid_register_post', 10, 3);
add_action( 'liberatid_finish_auth', 'liberatid_finish_login', 10, 3);
add_filter( 'registration_errors', 'liberatid_clean_registration_errors', -99);
add_filter( 'registration_errors', 'liberatid_registration_errors');
add_action( 'init', 'liberatid_login_errors' );


/**
 * Authenticate user to WordPress using OpenID.
 *
 * @param mixed $user authenticated user object, or WP_Error or null
 */
function liberatid_authenticate($user) {
    if (
             (array_key_exists('openid_identifier', $_POST) && $_POST['openid_identifier'] )
          || (array_key_exists('isLiberatID', $_POST) && $_POST['isLiberatID'] && "true" == $_POST['isLiberatID'] )
           ) {
                
                if (!empty($_POST['openid_identifier']) ) {
                    $liberatid_url = $_POST['openid_identifier'];
                } else {
                    $liberatid_url = 'https://login.liberatid.com';
                }

		$redirect_to = array_key_exists('redirect_to', $_REQUEST) ? $_REQUEST['redirect_to'] : null;
		liberatid_start_login($liberatid_url, 'login', $redirect_to);

		// if we got this far, something is wrong
		global $error;
		$error = liberatid_message();
		$user = new WP_Error( 'liberatid_login_error', $error );

	} else if ( array_key_exists('finish_liberatid', $_REQUEST) ) {

		$identity_url= $_REQUEST['identity_url'];

		if ( !wp_verify_nonce($_REQUEST['_wpnonce'], 'liberatid_login_' . md5($identity_url)) ) {
			$user = new WP_Error('liberatid_login_error', 'Error during OpenID authentication.  Please try again. (invalid nonce)');
		}

		if ( $identity_url ) {
			$user_id = get_user_by_liberatid($identity_url);
			if ( $user_id ) {
				$user = new WP_User($user_id);
			} else {
				$user = new WP_Error('liberatid_registration_closed', __('Your have entered a valid OpenID, but this site is not currently accepting new accounts.', 'liberatid'));
			}
		} else if ( array_key_exists('liberatid_error', $_REQUEST) ) {
			$user = new WP_Error('liberatid_login_error', htmlentities2($_REQUEST['liberatid_error']));
		}

	}

	return $user;
}
add_action( 'authenticate', 'liberatid_authenticate' );


/**
 * Action method for completing the 'login' action.  This action is used when a user is logging in from
 * wp-login.php.
 *
 * @param string $identity_url verified OpenID URL
 */
function liberatid_finish_login($identity_url, $action, $data) {
	if ($action != 'login') return;
        session_start(); // start up your PHP session! 
		
	// create new user account if appropriate
	$user_id = get_user_by_liberatid($identity_url);
	if ( $identity_url && !$user_id && get_option('users_can_register') ) {
            
            $user_data =& liberatid_get_user_data($identity_url);
            $user_data = array_merge($user_data, $data);
            
            //Store the user_data in session and redirect to registration page
            $_SESSION['user_data'] = $user_data;
            //$url = get_option('siteurl') . '/wp-content/plugins/liberatid/liberatid-register.php';
            $url = plugins_url('liberatid-register.php', __FILE__);
            wp_safe_redirect($url);
            exit;
            
            //liberatid_create_new_user($identity_url, $user_data);
            
	} else if ($user_id) {
            //See if we need to update
            if ( !empty ($data['user_email']) or  !empty ($data['nickname']) or  !empty ($data['user_nicename']) or !empty ($data['display_name']) 
                    or !empty ($data['first_name']) or !empty ($data['last_name']) ) {

                    
                $data['ID'] = $user_id;

                liberatid_update_user($identity_url, &$data);

            }
            
        }
	
	// return to wp-login page
	$url = get_option('siteurl') . '/wp-login.php';
	if (empty($identity_url)) {
		$url = add_query_arg('liberatid_error', liberatid_message(), $url);
	}

	$url = add_query_arg( array( 
		'finish_liberatid' => 1, 
		'identity_url' => urlencode($identity_url), 
		'redirect_to' => $_SESSION['liberatid_finish_url'],
		'_wpnonce' => wp_create_nonce('liberatid_login_' . md5($identity_url)), 
	), $url);
		
	wp_safe_redirect($url);
	exit;
}


/**
 * Setup OpenID errors to be displayed to the user.
 */
function liberatid_login_errors() {
	$self = basename( $GLOBALS['pagenow'] );
	if ($self != 'wp-login.php') return;

	if ( array_key_exists('liberatid_error', $_REQUEST) ) {
		global $error;
		$error = htmlentities2($_REQUEST['liberatid_error']);
	}
}


/**
 * Add style and script to login page.
 */
function liberatid_wp_login_head() {
	liberatid_style();
}


/**
 * Add OpenID input field to wp-login.php
 *
 * @action: login_form
 **/
function liberatid_wp_login_form() {
    echo '<hr id="liberatid_split" style="clear: both; margin-bottom: 1.0em; border: 0; border-top: 1px solid #999; height: 1px;" />';
    echo '
    <script type="text/javascript">
        function liberatIDSubmit() {
            document.forms["loginform"].elements["isLiberatID"].value = "true";
            document.forms["loginform"].submit();
        }
    </script>';

    echo '<input type="hidden" name="isLiberatID" value="false" />';
    echo '<p class="submit" id="liberatIDButton"><img style="background-image: none;background-color:#EEE;cursor:pointer;" src="' .plugins_url( 'f/LoginWithLiberatID36.png' , __FILE__ ). '" class="button-primary" onclick="javascript:liberatIDSubmit();" /><br /><br /></p>';

            echo '
            <script type="text/javascript">
                    $("#liberatIDButton").insertBefore($("#user_login").parent());
                    $("#liberatid_split").insertAfter("#liberatIDButton");
            </script>';

    if (get_option('liberatid_enable_other_openids') ) {
        echo '<hr id="openid_split" style="clear: both; margin-bottom: 1.0em; border: 0; border-top: 1px solid #999; height: 1px;" />';
        echo '
        <p style="margin-bottom: 8px;">
                <label style="display: block; margin-bottom: 5px;">' . __('Or login using an OpenID', 'liberatid') . '<br />
                <input type="text" name="openid_identifier" id="openid_identifier" class="input openid_identifier" value="" size="20" tabindex="25" /></label>
        </p>

        <p style="font-size: 0.9em; margin: 8px 0 24px 0;" id="what_is_liberatid">
                <a href="http://openid.net/what/" target="_blank">'.__('Learn about OpenID', 'liberatid').'</a>
        </p>';
    }
}


/**
 * Add information about registration to wp-login.php?action=register 
 *
 * @action: register_form
 **/
function liberatid_wp_register_form() {
    echo '<div style="width:100%;">'; //Added to fix IE problem

    if (get_option('liberatid_required_for_registration')) {
        $label = __('Register using LiberatID:', 'liberatid');
        if (get_option('liberatid_enable_other_openids') ) {
            $label = __('Register using LiberatID (or an OpenID):', 'liberatid');
        }
        echo '
        <script type="text/javascript">
            jQuery(function() {
                jQuery("#registerform > p:first").hide();
                jQuery("#registerform > p:first + p").hide();
                jQuery("#reg_passmail").hide();
                jQuery("p.submit").css("margin", "1em 0");
                var link = jQuery("#nav a:first");
                jQuery("#nav").text("").append(link);
            });
        </script>';
    } else {
        $label = __('Or register using an OpenID:', 'liberatid');

        if (get_option('liberatid_enable_other_openids')) {
            echo '<hr id="openid_split" style="clear: both; margin-bottom: 1.5em; border: 0; border-top: 1px solid #999; height: 1px;" />';
        }

        echo '<hr id="liberatid_split" style="clear: both; margin-bottom: 1.5em; border: 0; border-top: 1px solid #999; height: 1px;" />';
    }


    echo '<input type="hidden" name="isLiberatID" value="false" />';
    echo '<p class="submit" id="liberatIDButton"><img style="background-image: none;background-color:#EEE;cursor:pointer" src="' .plugins_url( 'f/RegisterWithLiberatID36.png' , __FILE__ ). '" class="button-primary" onclick="javascript:liberatIDSubmit();" /><br /><br /></p>';
    
    if ( ! get_option('liberatid_required_for_registration')) {

        echo '
            <script type="text/javascript">
                function liberatIDSubmit() {
                    document.forms["registerform"].elements["isLiberatID"].value = "true";
                    document.forms["registerform"].submit();
                }
            </script>';

        if (get_option('liberatid_enable_other_openids')) {
            echo '
                <script type="text/javascript">
                    jQuery(function() {
                        jQuery("#reg_passmail").insertBefore("#openid_split");
                    });
                </script>';
        }
        echo '
            <script type="text/javascript">
                $("#liberatIDButton").insertBefore($("#user_login").parent());
                jQuery(function() {
                    jQuery("#liberatid_split").insertAfter("#liberatIDButton");
                });
            </script>';
        
    }
    if (get_option('liberatid_enable_other_openids')) {

        echo '
            <p>
                    <label style="display: block; margin-bottom: 5px;">' . $label . '<br />
                    <input type="text" style="margin-bottom:2px;" name="openid_identifier" id="openid_identifier" class="input openid_identifier" value="" size="20" tabindex="25" />
            <span style="float: left; font-size: 0.8em;" id="what_is_liberatid">
                    <a href="http://openid.net/what/" target="_blank">'.__('Learn about OpenID', 'liberatid').'</a>
            </span>
            </label>
            </p>';
    }
    
    echo '</div>';

}


/**
 * Clean out registration errors that don't apply.
 */
function liberatid_clean_registration_errors($errors) {
	if (get_option('liberatid_required_for_registration') || !empty($_POST['openid_identifier'])) {
		$new = new WP_Error();
		foreach ($errors->get_error_codes() as $code) {
			if (in_array($code, array('empty_username', 'empty_email'))) continue;

			$message = $errors->get_error_message($code);
			$data = $errors->get_error_data($code);
			$new->add($code, $message, $data);
		}

		$errors = $new;
	}

	if (get_option('liberatid_required_for_registration') && empty($_POST['openid_identifier'])) {
		$errors->add('liberatid_only', __('<strong>ERROR</strong>: ', 'liberatid') . __('New users must register using OpenID.', 'liberatid'));
	}

	return $errors;
}

/**
 * Handle WordPress registration errors.
 */
function liberatid_registration_errors($errors) {
	if (!empty($_POST['openid_identifier'])) {
		$errors->add('invalid_liberatid', __('<strong>ERROR</strong>: ', 'liberatid') . liberatid_message());
	}

	return $errors;
}


/**
 * Handle WordPress registrations.
 */
function liberatid_register_post($username, $password, $errors) {
	if ( !empty($_POST['isLiberatID']) && "true" == $_POST['isLiberatID'] ) {
		wp_signon();
        }
	if ( !empty($_POST['openid_identifier']) ) {
		wp_signon();
	}
}
?>
