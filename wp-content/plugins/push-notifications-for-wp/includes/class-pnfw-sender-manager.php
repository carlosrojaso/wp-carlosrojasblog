<?php

if (!defined('ABSPATH')) {
 exit; // Exit if accessed directly
}

global $sender_manager; // we have to be explicit and declare that variable as global (see "A Note on Variable Scope" http://codex.wordpress.org/Function_Reference/register_activation_hook)
$sender_manager = new PNFW_Sender_Manager();

class PNFW_Sender_Manager {

 public function __construct() {
  add_action('pnfw_send_notifications_for_post_in_background', array($this, 'notify_new_post_in_background'), 10, 1);
  add_action('transition_post_status', array($this, 'notify_new_custom_post'), 10, 3);




 }

 function notify_new_custom_post($new_status, $old_status, $post) {
  $enable_push_notifications = get_option('pnfw_enable_push_notifications');

  if (!$enable_push_notifications) {
   return; // nothing to do
  }

  $custom_post_types = get_option('pnfw_enabled_post_types', array());

  if (!$custom_post_types || !in_array($post->post_type, $custom_post_types)) {
   return; // nothing to do
  }

  if ('publish' == $new_status && 'publish' != $old_status && 'trash' != $old_status) {
   wp_schedule_single_event(time(), 'pnfw_send_notifications_for_post_in_background', array($post->ID));
  }
 }

 public function notify_new_post_in_background($post_id) {




  $do_not_send_push_notifications_for_this_post = get_post_meta($post_id, 'pnfw_do_not_send_push_notifications_for_this_post', true);

  // This check has to be done here and not in the notify_new_custom_post which is
  // performed too early (the value of the post meta has not yet been stored)
  if ($do_not_send_push_notifications_for_this_post) {
   $post = get_post($post_id);

   pnfw_log(PNFW_SYSTEM_LOG, sprintf(__('Notifications for the %s %s deliberately not sent', 'push-notifications-for-wp'), $post->post_type, $post->post_title));

   return; // nothing to do
  }






  $this->send_chunk($post_id, 'iOS', 0, true); // the first is called directly
 }

 public function send_chunk($post_id, $os, $offset, $is_first) {
  if (empty($os)) {
   pnfw_log(PNFW_SYSTEM_LOG, __('Finished'));
  }

  $log_type = pnfw_log_type($os);




  $send = true;
  $post = get_post($post_id);

  if ($is_first) {
   pnfw_log($log_type, sprintf(__('Starting %s notifications', 'push-notifications-for-wp'), $os));



  }

  pnfw_log($log_type, sprintf(__('Processing new chunk from offset %d', 'push-notifications-for-wp'), $offset));

  if ($send) {
   $sender = NULL;

   $total = 0;

   if ($os == 'iOS') {
    if (get_option('pnfw_ios_push_notifications')) {
     require_once dirname(__FILE__) . '/notifications/class-pnfw-post-notifications-ios.php';

     $sender = new PNFW_Post_Notifications_iOS();
     $result = $sender->send_chunk($post, $offset);
     $total = $result['total'];
    }
    else {
     pnfw_log($log_type, sprintf(__('Do not send %s notifications', 'push-notifications-for-wp'), $os));
    }
   }
   else if ($os == 'Android') {
    if (get_option('pnfw_android_push_notifications')) {
     require_once dirname(__FILE__) . '/notifications/class-pnfw-post-notifications-android.php';

     $sender = new PNFW_Post_Notifications_Android();
     $result = $sender->send_chunk($post, $offset);
     $total = $result['total'];
    }
    else {
     pnfw_log($log_type, sprintf(__('Do not send %s notifications', 'push-notifications-for-wp'), $os));
    }
   }
  }
  else {
   pnfw_log($log_type, sprintf(__('Do not send %s notifications', 'push-notifications-for-wp'), $os));
  }

  pnfw_log($log_type, sprintf(__('Finished %s notifications', 'push-notifications-for-wp'), $os));

  $next_os = $this->get_next_os($os);

  if (is_null($next_os)) {
   $send_in_progress = get_option('pnfw_send_in_progress', 1);

   if ($send_in_progress == 1) {
    delete_option('pnfw_send_in_progress');
   }
   else {
    update_option('pnfw_send_in_progress', $send_in_progress - 1);
   }

   return;
  }
  $this->send_chunk($post_id, $next_os, 0, true);

 }

 function get_next_os($os) {
  switch ($os) {







   case 'iOS': return 'Android';
   case 'Android': return NULL;

  }
 }
}
