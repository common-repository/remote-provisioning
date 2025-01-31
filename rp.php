<?php
/*
 Plugin Name: WHMCS Multi-Site Provisioning
 Plugin URI: http://i-plugins.com
 Description: This plugin allows provisioning of blogs on a WordPress multi-site installation from external packages and billing systems such as WHMCS.
 Author: globalprogramming
 Version: 1.7.0
 Author URI: http://i-plugins.com/
 */

// Pre-2.6 compatibility for wp-content folder location
if (!defined("WP_CONTENT_URL")) {
	define("WP_CONTENT_URL", get_option("siteurl") . "/wp-content");
}
if (!defined("WP_CONTENT_DIR")) {
	define("WP_CONTENT_DIR", ABSPATH . "wp-content");
}

if (!defined("CC_RP_PLUGIN")) {
	$cc_rp_plugin=str_replace(realpath(dirname(__FILE__).'/..'),"",dirname(__FILE__));
	$cc_rp_plugin=substr($cc_rp_plugin,1);
	define("CC_RP_PLUGIN", $cc_rp_plugin);
}

define("CC_RP_URL", WP_CONTENT_URL . "/plugins/".CC_RP_PLUGIN."/");

add_action('admin_notices','cc_rp_check');
add_action("init","cc_rp_init");

register_activation_hook(__FILE__,'cc_rp_activate');
register_deactivation_hook(__FILE__,'cc_rp_deactivate');

function cc_rp_check() {
	global $wpdb;
	$errors=array();
	$warnings=array();
	$files=array();
	$dirs=array();

	foreach ($files as $file) {
		if (!is_writable($file)) $warnings[]='File '.$file.' is not writable, please chmod to 666';
	}

	foreach ($dirs as $file) {
		if (!is_writable($file)) $errors[]='Directory '.$file.' is not writable, please chmod to 777';
	}

	if (phpversion() < '5')	$warnings[]="You are running PHP version ".phpversion().". We recommend you upgra§ to PHP 5.3 or higher.";
	if (ini_get("zend.ze1_compatibility_mode")) $warnings[]="You are running PHP in PHP 4 compatibility mode. We recommend you turn this option off.";
	if (!function_exists('curl_init')) $errors[]="You need to have cURL installed. Contact your hosting provider to do so.";

	if (count($errors)) {
		foreach ($errors as $message) {
			echo "<div id='zing-warning' style='background-color:greenyellow' class='updated fade'><p><strong>".$message."</strong> "."</p></div>";
		}
	}
	if (count($warnings)) {
		foreach ($warnings as $message) {
			echo "<div id='zing-warning' style='background-color:greenyellow' class='updated fade'><p><strong>".$message."</strong> "."</p></div>";
		}
	}
}


/**
 * Activation: creation of database tables & set up of pages
 * @return unknown_type
 */
function cc_rp_activate() {
	//nothing much to do
}

/**
 * Deactivation: nothing to do
 * @return void
 */
function cc_rp_deactivate() {
	//nothing much to do
}

/**
 * Initialization of page, action & page_id arrays
 * @return unknown_type
 */
function cc_rp_init() {
	ob_start();
}

function cc_rp_add_admin() {
	add_options_page('Remote Provisioning', 'Remote Provisioning', 'administrator', 'cc-rp-cp', 'cc_rp_admin');
}

function cc_rp_action($action) {
	global $wpdb,$current_user,$current_site,$base;

	$wpdb->hide_errors();

	$plugin=get_plugin_data(__FILE__,false,false);
	$ret=array('action' => $action,'version'=>$plugin['Version']);

	if ($action=='create') {
	    $mu_options = (isset($_POST['mu_options'])) ? $_POST['mu_options'] : [];
		$blog = $_POST['blog'];
		$domain = '';
		if ( ! preg_match( '/(--)/', $blog['domain'] ) && preg_match( '|^([a-zA-Z0-9\-\.])+$|', $blog['domain'] ) ) $domain = strtolower( $blog['domain'] );
		else {
			$ret['error']=__('Invalid site address.');
			$ret['error_en']='Invalid site address.';
			$ret['error_num']=100;
			return $ret;
		}
		// If not a subdomain install, make sure the domain isn't a reserved word
		if ( ! is_subdomain_install() ) {
			$subdirectory_reserved_names = apply_filters( 'subdirectory_reserved_names', array( 'page', 'comments', 'blog', 'files', 'feed' ) );
			if ( in_array( $domain, $subdirectory_reserved_names ) ) {
				$ret['error']=sprintf(__('The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>'), implode( '</code>, <code>', $subdirectory_reserved_names ));
				$ret['error_en']=sprintf('The following words are reserved for use by WordPress functions and cannot be used as blog names: <code>%s</code>', implode( '</code>, <code>', $subdirectory_reserved_names ));
				$ret['error_num']=101;
				return $ret;
			}
		}
		$email = sanitize_email( $blog['email'] );
		$title = $blog['title'];

		if ( empty( $domain ) ) {
			$ret['error']=__('Missing site address.');
			$ret['error_en']='Missing site address.';
			$ret['error_num']=102;
			return $ret;
		}
		if ( empty( $email ) ) {
			$ret['error']=__('Missing email address.');
			$ret['error_en']='Missing email address.';
			$ret['error_num']=103;
			return $ret;
		}
		if ( !is_email( $email ) ) {
			$ret['error']=__('Invalid email address.');
			$ret['error_en']='Invalid email address.';
			$ret['error_num']=104;
			return $ret;
		}

		$userName=$_POST['blog']['username'];
		if (!$userName) $userName=$email;
		if ( is_subdomain_install() ) {
			$ret['install_type']='subdomain';
			$parent_domain = preg_replace('|^www\.|', '', $current_site->domain);
//			if (stristr($domain, $parent_domain) === false)
				$newdomain = $domain;
//			else
//				$newdomain = $domain . '.' . $parent_domain;

			$path = $base;
			$ret['domain']=$newdomain;
			$ret['path']=$path;
		} else {
			$ret['install_type']='subdirectory';
			$newdomain = $current_site->domain;

            if (substr($base, -1) != '/')
                $path = $base . '/' . $domain . '/';
            else
                $path = $base . $domain . '/';

			$ret['domain']=$newdomain;
			$ret['path']=$path;
		}

		if (domain_exists($newdomain, $path)) {
			$ret['error']=__('Domain exists already.');
			$ret['error_en']='Domain exists already.';
			$ret['error_num']=106;
			return $ret;
		}
		
		$user_id = email_exists($email);
		if ( !$user_id ) { // Create a new user with a random password
			$password=$blog['password'];
			$user_id = wpmu_create_user( $userName, $blog['password'], $email );
			if ( false == $user_id ) {
				$ret['error']=__( 'There was an error creating the user.' );
				$ret['error_en']='There was an error creating the user.';
				$ret['error_num']=105;
				return $ret;
			} else {
				if ($_POST['blog']['last_name']) update_user_option( $user_id, 'last_name', $_POST['blog']['last_name'], true );
				if ($_POST['blog']['first_name']) update_user_option( $user_id, 'first_name', $_POST['blog']['first_name'], true );
				if ($_POST['blog']['nickname']) update_user_option( $user_id, 'nickname', $_POST['blog']['nickname'], true );
				if (!isset($_POST['blog']['notify']) || (isset($_POST['blog']['notify']) && $_POST['blog']['notify'])) wp_new_user_notification( $user_id, $password );

			}
		}

		$userdata=get_userdata( $user_id );
		$ret['login']=$userdata->user_login;

		remove_user_from_blog( $user_id, $current_site->id ); //removes new user from main blog

		$blog_id = wpmu_create_blog( $newdomain, $path, $title, $user_id , array( 'public' => 1 ) );

		$ret['blog_id']=$blog_id;

		if (!empty($blog['defaultrole'])) {
			$roleName=$blog['defaultrole'];
			$roleSlug=str_replace(' ','_',$roleName);
			$roleSlug=strtolower($roleSlug);
			$roleSlug=preg_replace("/[^a-zA-Z0-9\s]/", "", $roleSlug);
			$caps=array('read' => 'read');
			if (isset($blog['capabilities'])) {
				$cs=explode(",",$blog['capabilities']);
				if (is_array($cs)) {
					foreach ($cs as $c) $caps[$c]=$c;
				}
			}
			switch_to_blog( $blog_id );

			if (!get_role($roleSlug)) {
				$roles=new WP_Roles();
				$roles->add_role($roleSlug,$roleName,$caps);
			}

			remove_user_from_blog($user_id, $blog_id);

			add_user_to_blog($blog_id, $user_id, $roleSlug);

			$user=new WP_User($user_id);

			restore_current_blog();
		}

		if (!empty($mu_options['theme'])) {
            switch_to_blog( $blog_id );
            switch_theme($mu_options['theme']);
            restore_current_blog();
        }

		if ( !is_wp_error( $blog_id ) ) {
			if ( !is_super_admin( $user_id ) && !get_user_option( 'primary_blog', $user_id ) ) update_user_option( $user_id, 'primary_blog', $blog_id, true );
		} else {
			$ret['error']=$blog_id->get_error_message();
			$ret['error_en']='Error creating domain (2)';
			$ret['error_num']=107;
			return $ret;
		}

		//options
		switch_to_blog($blog_id);
		if (isset($_POST['blog']['upload_space']) && is_numeric($_POST['blog']['upload_space'])) update_option('blog_upload_space',intval($_POST['blog']['upload_space']));

		try {
			$ret['blog_dir']=WP_CONTENT_DIR.'/blogs.dir/'.$blog_id;
			if (!file_exists(WP_CONTENT_DIR.'/blogs.dir/'.$blog_id)) @mkdir(WP_CONTENT_DIR.'/blogs.dir/'.$blog_id);
			if (!file_exists(WP_CONTENT_DIR.'/blogs.dir/'.$blog_id.'/files')) @mkdir(WP_CONTENT_DIR.'/blogs.dir/'.$blog_id.'/files');
		} catch (Exception $e) {
			$ret['error']=__('Could not create blog directories, verify installation.');
			$ret['error_en']='Could not create blog directories, verify installation.';
			return $ret;
		}

	} elseif ($action=='suspend') {
		$domain=$_POST['blog']['domain'];
		$id=get_blog_id_from_url($domain);
        $ret['blog_id'] = $id;
		update_blog_status( $id, 'archived', '1' );

	} elseif ($action=='unsuspend') {
		$domain=$_POST['blog']['domain'];
		$id=get_blog_id_from_url($domain);
		$ret['blog_id'] = $id;
		update_blog_status( $id, 'archived', '0' );

	} elseif ($action=='terminate') {
		$domain=$_POST['blog']['domain'];
		$id=get_blog_id_from_url($domain);
        $ret['blog_id'] = $id;
		//update_blog_status( $id, 'deleted', '1' );
		wpmu_delete_blog($id,true);
	}
	$ret['success']=1;
	return $ret;
}

function cc_rp_admin() {

	global $wpdb,$current_user,$current_site,$base;

	$action=$_POST['action'];
	if ($action) {
		while (count(ob_get_status(true)) > 0) ob_end_clean();
		$ret=cc_rp_action($action);
		echo json_encode($ret);
		exit;
	}

	?>
<div class="wrap">
	<h2>
		<b>Remote provisioning</b>
	</h2>
	<p>
		The Remote Provisioning plugin allows provisioning of blogs on a
		WordPress multi-site installation from your billing and support
		system.<br /> Basically this means you can charge for providing
		WordPress blogs using your preferred billing system. It supports
		creation, (un)suspension and cancellation of WordPress blogs.<br /> <br />
		A commercial addon is available is available for <a
			href="https://www.whmcs.com/members/aff.php?aff=23386" target="_blank">WHMCS</a> . Just order
		via this <a target="_blank" href="http://i-plugins.com/wordpress-multi-site-provisioning/">link</a>.
		<br /> <br /> That's it, no other settings.
	
    	<hr />
	    <a href="http://i-plugins.com" target="_blank" alt="i-Plugins" title="i-Plugins">
            <img src="<?php echo plugins_url()?>/remote-provisioning/logo.png" />
        </a>
	</p>

	<?php
	$cc_ew=cc_rp_check();
	$cc_errors=$cc_ew['errors'];
	$cc_warnings=$cc_ew['warnings'];
	if ($cc_errors) {
		echo '<div style="background-color:pink" id="message" class="updated fade"><p>';
		echo '<strong>Errors - you need to resolve these errors before continuing:</strong><br /><br />';
		foreach ($cc_errors as $cc_error) echo $cc_error.'<br />';
		echo '</p></div>';
	}
	if ($cc_warnings) {
		echo '<div style="background-color:peachpuff" id="message" class="updated fade"><p>';
		echo '<strong>Warnings - you might want to have a look at these issues to avoid surprises or unexpected behaviour:</strong><br /><br />';
		foreach ($cc_warnings as $cc_warning) echo $cc_warning.'<br />';
		echo '</p></div>';
	}

	echo '</div>'; //end wrap
}
add_action('admin_menu', 'cc_rp_add_admin');
