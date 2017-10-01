=== Simple Google Analytics Tracking ===
Contributors: dustyn
Tags: google, analytics, google analytics, simple, tracking, google analytics tracking, simple google analytics tracking, sgat, simple google analytics, simple tracking
Requires at least: 3.8
Tested up to: 4.7.3
Stable tag: 1.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add Google Analytics to your site with just the Tracking ID through Simple Google Analytics Tracking.

== Description ==

Simple Google Analytics Tracking allows you to add Google Analytics to your WordPress site with only the Tracking ID. Once the Tracking ID is input the Google Analytics code is added automatically with your Tracking ID.

Simple Google Analytics Tracking does not track Editors and Administrators by default to prevent extra page views from showing up in your reports.
*Editors and Administrators can be allowed through the `sgat_output_ga_code` filter.*

== Installation ==

1. Upload the `simple-google-analytics-tracking` directory to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. In the "Settings" menu, go to "Google Analytics"
4. Enter your Tracking ID in the "Tracking ID" input box and click the "Save Tracking ID" button

== Frequently Asked Questions ==

= Can I include Editors and Administrators? =

Yes, Editors and Administrators can be included by using the `sgat_output_ga_code` filter.

= Can the Tracking ID be added programatically? =

Yes, the Tracking ID can be changed with the `sgat_tracking_id` filter.

= Can the Tracking Fields be added programatically? =

Yes, the Tracking Fields can be changed with the `sgat_tracker_create_fields` filter.

= Can additional tracking calls be added after Google Analytics? =

Yes, additional tracking calls be added after Google Analytics is added with the `sgat_after_tracker` action.

== Screenshots ==

1. Simple Google Analytics Tracking Admin screen

== Other Notes ==

= Filters =

`sgat_output_ga_code`: used to override the default user tracking setting.

Example Usage:
`
add_filter( 'sgat_output_ga_code', 'sgat_override_user_settings' );
function sgat_override_user_settings() {
  return true;
}
`

`sgat_tracking_id`: used to add the Tracking ID programmatically.

Example Usage:
`
add_filter( 'sgat_tracking_id', 'sgat_custom_ga_tracking_id' );
function sgat_custom_ga_tracking_id( $tracking_id ) {
  $tracking_id = 'UA-123456-7';
  return $tracking_id;
}
`

`sgat_tracker_create_fields `: Used to modify the information when creating the Google Analytics tracker.

Example Usage:
`
add_filter( 'sgat_tracker_create_fields', 'sgat_custom_tracker_create_fields' );
function sgat_custom_tracker_create_fields( $fields ) {
  $fields['siteSpeedSampleRate'] = 50;
  return $fields;
}
`

`sgat_after_tracker `: Used to add additional Google Analytics calls after the main tracker output.

Example Usage:
`
add_action( 'sgat_after_tracker', 'sgat_after_tracker_functionality’ );
function sgat_after_tracker_functionality() {
  echo "ga('send', 'event', 'MyCategory', 'MyAction', 'MyLabel', 50);";
}
`