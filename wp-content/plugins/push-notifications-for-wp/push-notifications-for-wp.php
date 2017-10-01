<?php
/*
Plugin Name: Push Notifications for WordPress (Lite)
Plugin URI: https://delitestudio.com/wordpress/push-notifications-for-wordpress/
Description: Send push notifications to iOS and Android devices when you publish a new post.
Version: 3.2
Text Domain: push-notifications-for-wp
Domain Path: /languages
Author: Delite Studio S.r.l.
Author URI: https://www.delitestudio.com/
*/

if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}
if (class_exists('PNFW_Push_Notifications_for_Posts') || class_exists('PNFW_Push_Notifications_for_WordPress')) {
 wp_die(
  __('To activate Push Notifications for WordPress (Lite) you must first disable Push Notifications for Posts and Push Notifications for WordPress', 'push-notifications-for-wp'),
  __('Plugin Activation Error', 'push-notifications-for-wp'),
  array('response' => 500, 'back_link' => true)
 );

 exit;
}


if (!function_exists('pnfw_log')) {
 define('PNFW_SYSTEM_LOG', 0);
 define('PNFW_IOS_LOG', 1);
 define('PNFW_ANDROID_LOG', 2);
 define('PNFW_FEEDBACK_PROVIDER_LOG', 4);
 define('PNFW_ALERT_LOG', 5);
 define('PNFW_SAFARI_LOG', 6);
 define('PNFW_CHROME_LOG', 7);
 define('PNFW_FIREFOX_LOG', 8);

 define('PNFW_OTHER_LOG', 100);

 function pnfw_log($type, $text) {
  global $wpdb;

  $table_name = $wpdb->get_blog_prefix() . 'push_logs';

  $data = array('type' => $type, 'text' => $text, 'timestamp' => current_time('mysql'));
  $wpdb->insert($table_name, $data, array('%d', '%s', '%s'));
 }
}

if (!function_exists('pnfw_log_type')) {
 function pnfw_log_type($os) {
  switch ($os) {
   case 'iOS':
    return PNFW_IOS_LOG;
   case 'Android':
    return PNFW_ANDROID_LOG;
   case 'Safari':
    return PNFW_SAFARI_LOG;
   case 'Chrome':
    return PNFW_CHROME_LOG;
   case 'Firefox':
    return PNFW_FIREFOX_LOG;
   default:
    return PNFW_SYSTEM_LOG;
  }
 }
}

if (is_admin()) {
 require_once dirname(__FILE__) . '/admin/class-pnfw-admin.php';
}
require_once dirname(__FILE__) . '/includes/class-pnfw-sender-manager.php';
require_once dirname(__FILE__) . '/includes/class-pnfw-ios-feedback-provider.php';




$push_notifications_for_wordpress = new PNFW_Push_Notifications_for_WordPress_Lite();

final class PNFW_Push_Notifications_for_WordPress_Lite {
 const MIN_PHP_REQUIRED = '5.3.0';
 const DB_VERSION = 2;
 const POSTS_PER_PAGE = 40;
 const USER_ROLE = 'app_subscriber';

 public function __construct() {
  register_activation_hook(__FILE__, array($this, 'activate'));
  register_deactivation_hook(__FILE__, array($this, 'deactivate'));
  add_filter('query_vars', array($this, 'manage_routes_query_vars'));
  add_action('init', array($this, 'plugin_init'));
  add_action('template_redirect', array($this, 'front_controller'));

  add_action('deleted_user', array($this, 'user_deleted'));
  add_action('delete_post', array($this, 'post_delete'));

  add_action('rest_api_init', array($this, 'rest_api_init'));
  add_filter('rest_prepare_post', array(&$this, 'rest_prepare_post'), 10, 3);
 }

 /**
	  * Stuff that's done when the plugin is activated
	  */
 function activate($network_wide) {
  if (version_compare(PHP_VERSION, self::MIN_PHP_REQUIRED, '<')) {
   deactivate_plugins(basename(__FILE__));

   wp_die(
    sprintf(__('Push Notifications for WordPress (Lite) requires PHP version %s or later.', 'push-notifications-for-wp'), self::MIN_PHP_REQUIRED),
    __('Plugin Activation Error', 'push-notifications-for-wp'),
    array('response' => 500, 'back_link' => true)
   );

   return;
  }

  if (is_multisite()) {
   deactivate_plugins(basename(__FILE__));

   wp_die(
    __('Push Notifications for WordPress (Lite) is incompatible with WordPress Multisite.', 'push-notifications-for-wp'),
    __('Plugin Activation Error', 'push-notifications-for-wp'),
    array('response' => 500, 'back_link' => true)
   );

   return;

  }

  global $wpdb;

  // If first run save actual db version to avoid upgrade
  $table_name = $wpdb->get_blog_prefix() . 'push_tokens';
  if (is_null($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)))) {
   add_option('pnfw_db_version', self::DB_VERSION);
  }
  else {
   $this->upgrade();
  }

  // Create tables 	
  $table_name = $wpdb->get_blog_prefix() . 'push_tokens';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token` VARCHAR (1000) NULL,
    `os` VARCHAR (50) NULL,
    `lang` VARCHAR (2) NULL,
    `timestamp` DATETIME NOT NULL,
    `user_id` BIGINT (20) UNSIGNED NULL,
    `active` BOOLEAN,
    `activation_code` VARCHAR (40) NULL,
    PRIMARY KEY (`id`));";
  $wpdb->query($sql);

  $table_name = $wpdb->get_blog_prefix() . 'push_viewed';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `user_id` BIGINT (20) UNSIGNED NOT NULL,
    `post_id` BIGINT (20) UNSIGNED NOT NULL,
    `timestamp` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`, `post_id`));";
  $wpdb->query($sql);

  $table_name = $wpdb->get_blog_prefix() . 'push_sent';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `user_id` BIGINT (20) UNSIGNED NOT NULL,
    `post_id` BIGINT (20) UNSIGNED NOT NULL,
    `timestamp` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`, `post_id`));";
  $wpdb->query($sql);

  $table_name = $wpdb->get_blog_prefix() . 'push_excluded_categories';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `user_id` BIGINT (20) UNSIGNED NOT NULL,
    `category_id` BIGINT (20) UNSIGNED NOT NULL,
    PRIMARY KEY (`user_id`, `category_id`));";
  $wpdb->query($sql);

  $table_name = $wpdb->get_blog_prefix() . 'push_logs';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` INT UNSIGNED NOT NULL,
    `text` TEXT,
    `timestamp` DATETIME NOT NULL,
    PRIMARY KEY (`id`));";
  $wpdb->query($sql);

  $table_name = $wpdb->get_blog_prefix() . 'push_encryption_keys';
  $sql = "CREATE TABLE IF NOT EXISTS $table_name (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_id` BIGINT (20) UNSIGNED NOT NULL,
    `user_public_key` VARCHAR (88) NOT NULL,
    `user_auth_token` VARCHAR (24) NOT NULL,
    PRIMARY KEY (`id`));";
  $wpdb->query($sql);

  update_option('pnfw_posts_per_page', self::POSTS_PER_PAGE);

  // Creare new user role
  add_role(self::USER_ROLE,
   __('App Subscriber', 'push-notifications-for-wp'),
   array('read' => true)
  );

  $this->manage_routes();
  flush_rewrite_rules();

  // Schedule Feedback Provider if needed	
  if (get_option('pnfw_ios_push_notifications')) {
   global $feedback_provider;
   $feedback_provider->run();
  }
 }

 /**
	  * Stuff that's done when the plugin is deactivated
	  */
 function deactivate() {
  remove_role(self::USER_ROLE);

  global $feedback_provider;
  $feedback_provider->stop();
 }

 function plugin_init() {
  pnfw_switch_lang($this->opt_parameter('lang'));

  load_plugin_textdomain('push-notifications-for-wp', false, basename(dirname(__FILE__)) . '/languages/');
  $this->upgrade();
  $this->manage_routes();



 }

 function manage_routes() {
  add_rewrite_rule('pnfw/([^/]+)/?$', 'index.php?control_action=$matches[1]', 'top');





 }

 function manage_routes_query_vars($query_vars) {
  array_push($query_vars, 'control_action');



  return $query_vars;
 }
 function front_controller() {
  global $wp_query;

  $control_action = isset($wp_query->query_vars['control_action']) ? $wp_query->query_vars['control_action'] : '';

  switch ($control_action) {
   case 'register':
    require_once(dirname(__FILE__) . '/includes/api/class-pnfw-api-register.php');
    $register = new PNFW_API_Register();
    break;
   case 'unregister':
    require_once(dirname(__FILE__) . '/includes/api/class-pnfw-api-unregister.php');
    $unregister = new PNFW_API_Unregister();
    break;
   case 'categories':
    require_once(dirname(__FILE__) . '/includes/api/class-pnfw-api-categories.php');
    $categories = new PNFW_API_Categories();
    break;
   case 'activate':
    require_once(dirname(__FILE__) . '/includes/api/class-pnfw-api-activate.php');
    $activate = new PNFW_API_Activate();
    break;
   case 'posts':
    require_once(dirname(__FILE__) . '/includes/api/class-pnfw-api-posts.php');
    $posts = new PNFW_API_Posts();
    break;
  }
 }

 /**
	  * Remove rows from custom tables when a user is deleted.
	  */
 function user_deleted($user_id) {
  pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Automatically deleted the tokens for user %s.', 'push-notifications-for-wp'), $user_id));

  global $wpdb;
  $push_tokens = $wpdb->get_blog_prefix() . 'push_tokens';
  $push_encryption_keys = $wpdb->get_blog_prefix() . 'push_encryption_keys';

  $token_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM $push_tokens WHERE user_id=%s", $user_id));
  foreach ($token_ids as $token_id) {
   $wpdb->delete($push_tokens, array('id' => $token_id));
   $wpdb->delete($push_encryption_keys, array('token_id' => $token_id));
  }

  $wpdb->delete($wpdb->get_blog_prefix() . 'push_excluded_categories', array('user_id' => $user_id));
 }

 function post_delete($post_id) {
  global $wpdb;
  $wpdb->delete($wpdb->get_blog_prefix() . 'push_viewed', array('post_id' => $post_id));
  $wpdb->delete($wpdb->get_blog_prefix() . 'push_sent', array('post_id' => $post_id));
 }

 function upgrade() {
  global $wpdb;
  switch(get_option('pnfw_db_version', 0)) {
   case 0: {
    // Must be done BEFORE pnfw_log
    $table_name = $wpdb->get_blog_prefix() . 'push_logs';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `type` INT UNSIGNED NOT NULL,
      `text` TEXT,
      `timestamp` DATETIME NOT NULL,
      PRIMARY KEY (`id`));";
    $wpdb->query($sql);

    pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Upgrading from version %d.', 'push-notifications-for-wp'), 0));

    // Create new tables
    $table_name = $wpdb->get_blog_prefix() . 'push_viewed';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      `user_id` BIGINT (20) UNSIGNED NOT NULL,
      `post_id` BIGINT (20) UNSIGNED NOT NULL,
      `timestamp` DATETIME NOT NULL,
      PRIMARY KEY (`user_id`, `post_id`));";
    $wpdb->query($sql);

    $table_name = $wpdb->get_blog_prefix() . 'push_sent';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      `user_id` BIGINT (20) UNSIGNED NOT NULL,
      `post_id` BIGINT (20) UNSIGNED NOT NULL,
      `timestamp` DATETIME NOT NULL,
      PRIMARY KEY (`user_id`, `post_id`));";
    $wpdb->query($sql);

    $table_name = $wpdb->get_blog_prefix() . 'push_excluded_categories';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
      `user_id` BIGINT (20) UNSIGNED NOT NULL,
      `category_id` BIGINT (20) UNSIGNED NOT NULL,
      PRIMARY KEY (`user_id`, `category_id`));";
    $wpdb->query($sql);

    // Add user_id column
    $push_tokens = $wpdb->get_blog_prefix() . 'push_tokens';
    $wpdb->query("ALTER TABLE {$push_tokens} ADD (
      `user_id` BIGINT (20) UNSIGNED NULL,
      `lang` VARCHAR (2) NULL,
      `active` BOOLEAN,
      `activation_code` VARCHAR (40) NULL);");

    // Creare new user role
    add_role(self::USER_ROLE,
     __('App Subscriber', 'push-notifications-for-wp'),
     array('read' => true)
    );

    // Create a user for every token with user_id NULL
    $rows = $wpdb->get_results("SELECT token, os FROM {$push_tokens} WHERE user_id IS NULL");
    foreach ($rows as $row) {
     // Create anonymous wordpress user
     $user_id = wp_insert_user(array(
      'user_login' => $this->create_unique_user_login(),
      'user_email' => NULL,
      'user_pass' => NULL,
      'role' => self::USER_ROLE
     ));

     // Add its user_id to push_tokens table
     if (!is_wp_error($user_id)) {
      $wpdb->update($push_tokens, array('user_id' => $user_id, 'active' => true), array('token' => $row->token, 'os' => $row->os));
     }
    }

    // Drop deprecated table
    $table_name = $wpdb->get_blog_prefix() . 'push_notifications_sent_per_day';
    $wpdb->query("DROP TABLE IF EXISTS $table_name;");

    // Remove deprecated register page
    $page = get_page_by_path('register');
    if (isset($page))
     wp_delete_post($page->ID, true);

    // Disable old feedback provider
    wp_clear_scheduled_hook('ds_feedback_provider_event');

    // Rename options
    $this->rename_option('ds_enable_push_notifications', 'pnfw_enable_push_notifications');
    $this->rename_option('ds_ios_push_notifications', 'pnfw_ios_push_notifications');
    $this->rename_option('ds_android_push_notifications', 'pnfw_android_push_notifications');
    $this->rename_option('ds_kindle_push_notifications', 'pnfw_kindle_push_notifications');
    $this->rename_option('ds_ios_use_sandbox', 'pnfw_ios_use_sandbox');
    $this->rename_option('ds_sandbox_ssl_certificate_media_id', 'pnfw_sandbox_ssl_certificate_media_id');
    $this->rename_option('ds_sandbox_ssl_certificate_password', 'pnfw_sandbox_ssl_certificate_password');
    $this->rename_option('ds_production_ssl_certificate_media_id', 'pnfw_production_ssl_certificate_media_id');
    $this->rename_option('ds_production_ssl_certificate_password', 'pnfw_production_ssl_certificate_password');
    $this->rename_option('ds_google_api_key', 'pnfw_google_api_key');
    $this->rename_option('ds_adm_client_id', 'pnfw_adm_client_id');
    $this->rename_option('ds_adm_client_secret', 'pnfw_adm_client_secret');
    $this->rename_option('ds_api_consumer_key', 'pnfw_api_consumer_key');
    $this->rename_option('ds_api_consumer_secret', 'pnfw_api_consumer_secret');
    $this->rename_option('ds_enabled_post_types', 'pnfw_enabled_post_types');

    // Upgrade option to new values
    $post_types = get_option('pnfw_enabled_post_types', array());
    $post_types[] = 'post';
    update_option('pnfw_enabled_post_types', $post_types);

    // Schedule new Feedback Provider if needed	
    if (get_option('pnfw_ios_push_notifications')) {
     global $feedback_provider;
     $feedback_provider->run();
    }

    pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Db version %d upgraded.', 'push-notifications-for-wp'), 0));
    update_option('pnfw_db_version', 1);
   }
   case 1: {
    pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Upgrading from version %d.', 'push-notifications-for-wp'), 1));

    // Create new table
    $table_name = $wpdb->get_blog_prefix() . 'push_encryption_keys';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
     `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
     `token_id` BIGINT (20) UNSIGNED NOT NULL,
     `user_public_key` VARCHAR (88) NOT NULL,
     `user_auth_token` VARCHAR (24) NOT NULL,
     PRIMARY KEY (`id`));";
    $wpdb->query($sql);

    $this->manage_routes();
    flush_rewrite_rules();

    pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Db version %d upgraded.', 'push-notifications-for-wp'), 1));
    update_option('pnfw_db_version', 2);
   }
  }
 }

 private function rename_option($old_option, $new_option) {
  $old_value = get_option($old_option);
  if ($old_value === false)
   return;
  update_option($new_option, $old_value);
  delete_option($old_option);
 }

 private function create_unique_user_login() {
  $tmp_user_login = 'anonymous' . wp_rand();
  return username_exists($tmp_user_login) ? $this->create_unique_user_login() : $tmp_user_login;
 }

 function opt_parameter($parameter) {
  $pars = strtoupper($_SERVER['REQUEST_METHOD']) == 'POST' ? $_POST : $_GET;

  if (!array_key_exists($parameter, $pars))
   return NULL;

  $res = filter_var($pars[$parameter], FILTER_SANITIZE_STRING);

  return $res ? $res : NULL;
 }

 function rest_api_init() {
  if (function_exists('register_rest_field')) {
   register_rest_field('post',
    'read',
    array(
     'get_callback' => array($this, 'slug_get_read'),
     'update_callback' => null,
     'schema' => null,
    )
   );
  }
 }

 function slug_get_read($object, $field_name, $request) {
  global $wpdb;

  $token = $request['token'];
  $os = $request['os'];

  $is_read = true;

  if (isset($token, $os)) {
   $push_tokens = $wpdb->get_blog_prefix() . 'push_tokens';
   $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $push_tokens WHERE token=%s AND os=%s", $token, $os));

   $push_viewed = $wpdb->get_blog_prefix() . 'push_viewed';
   $is_viewed = (boolean)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $push_viewed WHERE post_id=%d AND user_id=%d", $object['id'], $user_id));

   $push_sent = $wpdb->get_blog_prefix() . 'push_sent';

   if ((boolean)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $push_sent WHERE post_id=%d AND user_id=%d", $object['id'], $user_id))) {
    $is_read = $is_viewed;
   }
  }

  return $is_read;
 }

 function rest_prepare_post($data, $post, $request) {
  global $wpdb;

  $token = $request['token'];
  $os = $request['os'];

  if (isset($token, $os)) {
   $push_tokens = $wpdb->get_blog_prefix() . 'push_tokens';
   $user_id = $wpdb->get_var($wpdb->prepare("SELECT user_id FROM $push_tokens WHERE token=%s AND os=%s", $token, $os));

   $push_viewed = $wpdb->get_blog_prefix() . 'push_viewed';
   $is_viewed = (boolean)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $push_viewed WHERE post_id=%d AND user_id=%d", $post->ID, $user_id));

   if (isset($request['id'])) {
    if (!$is_viewed) {
     $wpdb->insert($push_viewed, array('post_id' => $post->ID, 'user_id' => $user_id, 'timestamp' => current_time('mysql')));
    }
   }
  }

  return $data;
 }

}


// Functions
if (!function_exists('pnfw_get_post')) {
 function pnfw_get_post($key, $default = false) {
  return isset($_POST[$key]) ? $_POST[$key] : $default;
 }
}

if (!function_exists('pnfw_get_term_taxonomy')) {
 function pnfw_get_term_taxonomy($term_id) {
  global $wpdb;
  return $wpdb->get_var($wpdb->prepare("SELECT taxonomy FROM $wpdb->term_taxonomy WHERE term_id=%d", $term_id));
 }
}

if (!function_exists('pnfw_get_current_language')) {
 function pnfw_get_current_language() {
  return null;

 }
}

if (!function_exists('pnfw_switch_lang')) {
 function pnfw_switch_lang($lang) {
 }
}

if (!function_exists('pnfw_get_post_lang')) {
 function pnfw_get_post_lang($post_id) {
  return null;

 }
}

if (!function_exists('pnfw_get_normalized_term_id')) {
 function pnfw_get_normalized_term_id($term_id) {
  return $term_id;

 }
}

if (!function_exists('pnfw_get_wpml_langs')) {
 function pnfw_get_wpml_langs() {
  $lang = array();
  return $lang;
 }
}

if (!function_exists('pnfw_suppress_filters')) {
 function pnfw_suppress_filters() {
  $ret = true;





  return $ret;
 }
}
if (!function_exists('pnfw_is_exclusive_user_member_of_blog')) {
 function pnfw_is_exclusive_user_member_of_blog($user_id = 0, $blog_id = 0) {
  $user_id = (int)$user_id;
  $blog_id = (int)$blog_id;

  if (empty($user_id))
   $user_id = get_current_user_id();

  if (empty($blog_id))
   $blog_id = get_current_blog_id();

  $blogs = get_blogs_of_user($user_id);

  return array_key_exists($blog_id, $blogs) && count($blogs) == 1;
 }
}

if (!function_exists('pnfw_starts_with')) {
 function pnfw_starts_with($haystack, $needle) {
  $length = strlen($needle);
  return (substr($haystack, 0, $length) === $needle);
 }
}

if (!function_exists('pnfw_get_blogname')) {
 function pnfw_get_blogname() {
  if (is_multisite()) {
   global $blog_id;
   $current_blog_details = get_blog_details(array('blog_id' => $blog_id));
   return $current_blog_details->blogname;
  }
  else {
   return get_bloginfo('name');
  }
 }
}

if (!function_exists('pnfw_certificate_expiration')) {
 function pnfw_certificate_expiration($media_id) {
  if (!$media_id)
   return NULL;

  if (!function_exists('openssl_x509_parse'))
   return NULL;

  $path = get_attached_file($media_id);

  if (empty($path))
   return NULL;

  $certificate = file_get_contents($path);
  $parsed = openssl_x509_parse($certificate);
  $expires = $parsed['validTo_time_t'];

  if (!isset($expires))
   return NULL;

  return $expires;
 }
}
