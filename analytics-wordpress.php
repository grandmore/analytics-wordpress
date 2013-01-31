<?php
/*
Plugin Name: Analytics for WordPress — by Segment.io
Plugin URI: https://segment.io/plugins/wordpress
Description: The hassle-free way to integrate any analytics service into your Wordpress site.

Version: 0.2.0
License: GPLv2

Author: Segment.io
Author URI: https://segment.io
Author Email: friends@segment.io

References:
https://github.com/convissor/oop-plugin-template-solution
http://planetozh.com/blog/2009/09/top-10-most-common-coding-mistakes-in-wordpress-plugins/
http://markjaquith.wordpress.com/2006/06/02/wordpress-203-nonces/
*/

class Analytics_Wordpress {

    const ID      = 'analytics-wordpress';
    const NAME    = 'Analytics Wordpress';
    const VERSION = '0.2.0';

    private $option   = 'analytics_wordpress_options';
    private $defaults = array(
        'api_key' => ''
    );

    public function __construct() {
        // Setup our Wordpress hooks. Use a slightly higher priority for the
        // analytics Javascript includes.
        if (is_admin()) {
            add_action('admin_menu', array(&$this, 'admin_menu'));
        } else {
            add_action('wp_head', array(&$this, 'wp_head'), 9);
            add_action('wp_footer', array(&$this, 'wp_footer'), 9);
        }

        // Make sure our settings object exists and is backed by our defaults.
        $settings = $this->get_settings();
        if (!is_array($settings)) $settings = array();
        $settings = array_merge($this->defaults, $settings);
        $this->set_settings($settings);
    }



    // Hooks
    // -----

    public function wp_head() {
        // Render the snippet.
        $this->render_snippet($this->get_settings());
    }

    public function wp_footer() {
        // Identify the user if the current user merits it.
        $identify = $this->get_current_user_identify();
        if ($identify) $this->render_identify($identify['user_id'], $identify['traits']);

        // Track a custom page view event if the current page merits it.
        $track = $this->get_current_page_track();
        if ($track) $this->render_track($track['event'], $track['properties']);
    }

    public function admin_menu() {
        // Render an "Analytics" menu item in the "Settings" menu.
        // http://codex.wordpress.org/Function_Reference/add_options_page
        add_options_page(
            'Analytics',                   // Page Title
            'Analytics',                   // Menu Title
            'manage_options',              // Capability Required
            'analytics-wordpress',         // Menu Slug
            array(&$this, 'admin_page') // Function
        );
    }

    public function admin_page() {
        // Make sure the user has the required permissions to view the settings.
        if (!current_user_can('manage_options')) {
            wp_die('Sorry, you don\'t have the permissions to access this page.');
        }

        $settings = $this->get_settings();

        // If we're saving and the nonce matches, update our settings.
        if (isset($_POST['submit']) && check_admin_referer($this->option)) {
            $settings['api_key'] = $_POST['api_key'];
            $this->set_settings($settings);
        }

        include(WP_PLUGIN_DIR . '/analytics-wordpress/templates/settings.php');
    }



    // Renderers
    // ---------

    // Render the Segment.io Javascript snippet.
    private function render_snippet($settings) {
        if (!isset($settings['api_key']) || $settings['api_key'] == '') return;

        include(WP_PLUGIN_DIR . '/analytics-wordpress/templates/snippet.php');
    }

    // Render a Javascript `identify` call.
    private function render_identify($user_id, $traits = false) {
        if (!$user_id) return;

        include(WP_PLUGIN_DIR . '/analytics-wordpress/templates/identify.php');
    }

    // Render a Javascript `track` call.
    private function render_track($event, $properties = false) {
        if (!$event) return;

        include(WP_PLUGIN_DIR . '/analytics-wordpress/templates/track.php');
    }



    // Getters + Setters
    // -----------------

    // Get our plugin's settings.
    private function get_settings() {
        return get_option($this->option);
    }

    // Store new settings for our plugin.
    private function set_settings($settings) {
        return update_option($this->option, $settings);
    }

    // Based on the current user or commenter, see if we have enough information
    // to record an `identify` call. Since commenters don't have IDs, we
    // identify everyone by their email address.
    private function get_current_user_identify() {
        // We've got a logged-in user.
        // http://codex.wordpress.org/Function_Reference/wp_get_current_user
        if (is_user_logged_in() && $user = wp_get_current_user()) {
            $identify = array(
                'user_id' => $user->user_email,
                'traits'  => array(
                    'username'  => $user->user_login,
                    'email'     => $user->user_email,
                    'name'      => $user->display_name,
                    'firstName' => $user->user_firstname,
                    'lastName'  => $user->user_lastname,
                    'url'       => $user->user_url
                )
            );
        }
        // We've got a commenter.
        // http://codex.wordpress.org/Function_Reference/wp_get_current_commenter
        else if ($commenter = wp_get_current_commenter()) {
            $identify = array(
                'user_id' => $commenter['comment_author_email'],
                'traits'  => array(
                    'email' => $commenter['comment_author_email'],
                    'name'  => $commenter['comment_author'],
                    'url'   => $commenter['comment_author_url']
                )
            );
        }
        // We don't have a user.
        else return false;

        // Clean out empty traits before sending it back.
        $identify['traits'] = $this->clean_array($identify['traits']);

        return $identify;
    }

    // Based on the current page, get the event and properties that should be
    // tracked for the custom page view event. Getting the title for a page is
    // confusing depending on what type of page it is... so reference this:
    // http://core.trac.wordpress.org/browser/tags/3.5.1/wp-includes/general-template.php#L0
    private function get_current_page_track() {
        // The front page of their site, whether it's a page or a list of
        // recent blog entries. `is_home` only works if it's not a page, that's
        // why we don't use it.
        if (is_front_page()) {
            $track = array(
                'event' => 'View Home Page'
            );
        }
        // A normal WordPress page.
        else if (is_page()) {
            $track = array(
                'event' => 'View ' . single_post_title('', false) . ' Page'
            );
        }
        // An author archive page. Check the `wp_title` docs to see how they get
        // the title of the page, cuz it's weird.
        // http://core.trac.wordpress.org/browser/tags/3.5.1/wp-includes/general-template.php#L0
        else if (is_author()) {
            $author = get_queried_object();
            $track = array(
                'event'      => 'View Author Page',
                'properties' => array(
                    'author' => $author->display_name
                )
            );
        }
        // A tag archive page. Use `single_tag_title` to get the name.
        // http://codex.wordpress.org/Function_Reference/single_tag_title
        else if (is_tag()) {
            $track = array(
                'event'      => 'View Tag Page',
                'properties' => array(
                    'tag' => single_tag_title('', false)
                )
            );
        }
        // A category archive page. Use `single_cat_title` to get the name.
        // http://codex.wordpress.org/Function_Reference/single_cat_title
        else if (is_category()) {
            $track = array(
                'event'      => 'View Category Page',
                'properties' => array(
                    'category' => single_cat_title('', false)
                )
            );
        }
        // The search page.
        else if (is_search()) {
            $track = array(
                'event'      => 'View Search Page',
                'properties' => array(
                    'query' => get_query_var('s')
                )
            );
        }
        // A post or a custom post. `is_single` also returns attachments, so we
        // filter those out. The event name is based on the post's type, and is
        // uppercased.
        else if (is_single() && !is_attachment()) {
            $track = array(
                'event'      => 'View ' . ucfirst(get_post_type()),
                'properties' => array(
                    'title' => single_post_title('', false)
                )
            );
        }
        // We don't have a page we want to track.
        else return false;

        // Clean out empty properties before sending it back.
        $track['properties'] = $this->clean_array($track['properties']);

        return $track;
    }



    // Utils
    // -----

    // Removes any empty keys in an array.
    private function clean_array($array) {
        foreach ($array as $key => $value) {
            if ($array[$key] == '') unset($array[$key]);
        }
        return $array;
    }

}

// Start the party.
$analytics_wordpress = new Analytics_Wordpress();
