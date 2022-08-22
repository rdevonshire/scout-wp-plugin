<?php
/*
	Plugin Name: Cloudabove Scout Cache
	Description: This plugin allows you to manage the scout cache from within a WordPress website
	Version: 1.0.1
	Author: Tim Greenwood
	Author URI: https://www.timgreenwood.co.uk/
*/

namespace CloudAbove;

if (is_readable(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
}

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/wp-cli.php';
}

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use WP_Admin_Bar;

class Scout
{
    private const SCOUT_URL = 'https://scout.cloudabove.com/api/';
    private const ALLOWED_ROLES = ['administrator'];

    /**
     * Constructor to register everything
     */
    public function __construct()
    {
        // disable for preview pages because it doesn't seem to register properly
        if (isset($_GET['preview_id'])) {
            return;
        }

        add_action('plugins_loaded', [$this, 'check_current_user']);
    }

    /**
     * Check if the user is an admin
     */
    function check_current_user()
    {
        if (
            array_intersect(self::ALLOWED_ROLES, wp_get_current_user()->roles)
        ) {
            add_action('admin_bar_menu', [$this, 'add_toolbar_menu'], 90);

            add_action('admin_notices', [$this, 'display_flash_notices'], 12);

            $this->register_hooks();
        }
    }

    /**
     * Adds hooks to integrate with WordPress.
     *
     * @return void
     */
    public function register_hooks()
    {
        add_action('admin_post_cloudabove_scout_purge_cache', [$this, 'purge']);
        add_action('admin_action_cloudabove_scout_purge_cache', [
            $this,
            'purge',
        ]);
    }

    /**
     * Add link to the WP admin top bar
     *
     * @param WP_Admin_Bar $admin_bar WP_Admin_Bar instance, passed by reference
     */
    function add_toolbar_menu($admin_bar)
    {
        $icon_css =
            'height:0.8rem;width:0.8rem;margin-right:0.5ch; color: #3795be';
        $admin_bar->add_menu([
            'id' => 'cloudabove-scout',
            'title' =>
                '<span style="display:flex;flex-direction: row;align-items: center"><svg xmlns="http://www.w3.org/2000/svg" style="' .
                $icon_css .
                '" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
</svg> Scout</span>',
            'href' => '#',
            'meta' => [
                'title' => __('Scout'),
            ],
        ]);
        $admin_bar->add_menu([
            'id' => 'cloudabove-scout-cache',
            'parent' => 'cloudabove-scout',
            'title' =>
                '<span style="display:flex;flex-direction: row;align-items: center">
<svg xmlns="http://www.w3.org/2000/svg" style="' .
                $icon_css .
                '" fill="none" viewBox="0 0 24 24" stroke="currentColor">
  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
</svg> Refresh Scout cache</span>',
            'href' =>
                '/wp-admin/admin-post.php?action=cloudabove_scout_purge_cache&_wp_http_referrer=' .
                $_SERVER['HTTP_REFERER'],
            'meta' => [
                'title' => __('Purge Scout cache'),
                'target' => '',
                'class' => '',
            ],
        ]);
    }

    /**
     * Get request to scout to purge the cache then redirect to the previous page
     *
     * @throws GuzzleException
     */
    function purge()
    {
        //        var_dump($_GET, $_POST, $_REQUEST);
        //        die();
        if (!current_user_can('administrator')) {
            header(
                'Location:' .
                    $_SERVER['HTTP_REFERER'] .
                    '?error=unauthenticated'
            );
            exit();
        }

        $client = new Client([
            'base_uri' => self::SCOUT_URL,
            'timeout' => 2.0,
            'headers' => [
                'Accept' => 'application/json',
            ],
            'http_errors' => false,
        ]);

        try {
            $response = $client->get('cache/purge');
            if ($response->getStatusCode() === 200) {
                $this->add_flash_notice(
                    json_decode($response->getBody())->message,
                    'success'
                );
            } else {
                $this->add_flash_notice(
                    '<strong>Error:</strong> ' .
                        json_decode($response->getBody())->message,
                    'error'
                );
            }
        } catch (GuzzleException $e) {
            $this->add_flash_notice(
                '<strong>Exception:</strong> ' . $e->getMessage(),
                'error'
            );
        }

        wp_redirect(wp_get_referer());
    }

    /**
     * Add a flash notice to {prefix}options table until a full page refresh is done
     *
     * @param string $notice our notice message
     * @param string $type This can be "info", "warning", "error" or "success", "warning" as default
     * @param boolean $dismissible set this to TRUE to add is-dismissible functionality to your notice
     * @return void
     */
    function add_flash_notice(
        $notice = '',
        $type = 'warning',
        $dismissible = true
    ) {
        // Here we return the notices saved on our option, if there are no notices, then an empty array is returned
        $notices = get_option('cloudabove_scout_flash_notices', []);

        $dismissible_text = $dismissible ? 'is-dismissible' : '';

        // We add our new notice.
        array_push($notices, [
            'notice' => $notice,
            'type' => $type,
            'dismissible' => $dismissible_text,
        ]);

        // Then we update the option with our notices array
        update_option('cloudabove_scout_flash_notices', $notices);
    }

    /**
     * Function executed when the 'admin_notices' action is called, here we check if there are notices on
     * our database and display them, after that, we remove the option to prevent notices being displayed forever.
     * @return void
     */
    function display_flash_notices()
    {
        $notices = get_option('cloudabove_scout_flash_notices', []);

        // Iterate through our notices to be displayed and print them.
        foreach ($notices as $notice) {
            printf(
                '<div class="notice notice-%1$s %2$s"><p>%3$s</p></div>',
                $notice['type'],
                $notice['dismissible'],
                $notice['notice']
            );
        }

        // Now we reset our options to prevent notices being displayed forever.
        if (!empty($notices)) {
            delete_option('cloudabove_scout_flash_notices', []);
        }
    }
}

new Scout();
