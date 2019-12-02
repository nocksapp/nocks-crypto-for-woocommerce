<?php
/**
 * Plugin Name: Nocks Crypto for WooCommerce
 * Description: Accept cryptocurrencies in WooCommerce with the official Nocks Crypto plugin
 * Version: 0.1
 * Author: Nocks
 * Author URI: https://www.nocks.com
 * Requires at least: 3.8
 * Tested up to: 4.8.2
 * Text Domain: nocks-crypto-for-woocommerce
 * Domain Path: /languages
 * License: GPLv2 or later
 * WC requires at least: 2.1.0
 * WC tested up to: 3.2.0
 */

require_once 'includes/nocks/wc/autoload.php';

// TODO: Add more constants WP-style, and move from classes to here.

// Plugin folder URL.
if ( ! defined( 'NOCKS_PLUGIN_URL' ) ) {
	define( 'NOCKS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Plugin directory
if ( ! defined( 'NOCKS_PLUGIN_DIR' ) ) {
	define( 'NOCKS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

/**
 * Called when plugin is loaded
 */
function nocks_wc_plugin_init ()
{
    if (!class_exists('WooCommerce'))
    {
        /*
         * Plugin depends on WooCommerce
         * is_plugin_active() is not available yet :(
         */
        return;
    }

    // Register Nocks autoloader
    Nocks_WC_Autoload::register();

    // Setup and start plugin
    Nocks_WC_Plugin::init();
}

/**
 * Called when plugin is activated
 */
function nocks_wc_plugin_activation_hook ()
{
    // WooCommerce plugin not activated
    if (!is_plugin_active('woocommerce/woocommerce.php'))
    {
        $title = sprintf(
            __('Could not activate plugin %s', 'nocks-crypto-for-woocommerce'),
            'Nocks Crypto for WooCommerce'
        );
        $message = ''
            . '<h1><strong>' . $title . '</strong></h1><br/>'
            . 'WooCommerce plugin not activated. Please activate WooCommerce plugin first.';

        wp_die($message, $title, array('back_link' => true));
        return;
    }

    // Register Nocks autoloader
    Nocks_WC_Autoload::register();

    $status_helper = Nocks_WC_Plugin::getStatusHelper();

    if (!$status_helper->isCompatible())
    {
        $title   = 'Could not activate plugin ' . Nocks_WC_Plugin::PLUGIN_TITLE;
        $message = '<h1><strong>Could not activate plugin ' . Nocks_WC_Plugin::PLUGIN_TITLE . '</strong></h1><br/>'
                 . implode('<br/>', $status_helper->getErrors());

        wp_die($message, $title, array('back_link' => true));
        return;
    }
}

/**
 * Called when admin is initialised
 */
function nocks_wc_plugin_admin_init ()
{
    // WooCommerce plugin not activated
    if (!is_plugin_active('woocommerce/woocommerce.php'))
    {
        // Deactivate myself
        deactivate_plugins(plugin_basename(__FILE__));

        add_action('admin_notices', 'nocks_wc_plugin_deactivated');
    }
}

function nocks_wc_plugin_deactivated ()
{
    $nextScheduledTime = wp_next_scheduled( 'pending_payment_confirmation_check' ) ;
    if ($nextScheduledTime) {
        wp_unschedule_event( $nextScheduledTime, 'pending_payment_confirmation_check' );
    }
    echo '<div class="error"><p>' . sprintf(__('%s deactivated because it depends on WooCommerce.', 'nocks-crypto-for-woocommerce'), Nocks_WC_Plugin::PLUGIN_TITLE) . '</p></div>';
}

register_activation_hook(__FILE__, 'nocks_wc_plugin_activation_hook');

add_action('admin_init', 'nocks_wc_plugin_admin_init');
add_action('init', 'nocks_wc_plugin_init');

/**
 * Load the plugin text domain for translations.
 */
function nocks_add_plugin_textdomain() {

	load_plugin_textdomain( 'nocks-crypto-for-woocommerce', false, 'nocks-crypto-for-woocommerce/languages');
}

add_action( 'plugins_loaded', 'nocks_add_plugin_textdomain' );
