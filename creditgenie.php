<?php
/**
 * Plugin Name: CreditGenie
 * Plugin URI: https://creditgenie.co/
 * Description: CreditGenie - Woocommerce Payment Method.
 * Version: 1.1.2
 * Author: creditgenieplugins
 * Author URI: https://creditgenie.co/about-us/
 * Developer: creditgenieplugins
 * Developer URI: https://creditgenie.co/
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

define( 'CREDITGENIE__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Check if WooCommerce is active
 **/
 if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    add_filter('woocommerce_payment_gateways','add_creditgenie_payment_gateway' );

    function add_creditgenie_payment_gateway( $gateways ){
        $gateways[] = 'WC_CreditGenie_Payment_Gateway';
        return $gateways;
    }

    function init_creditgenie_payment_gateway(){
        require CREDITGENIE__PLUGIN_DIR.'WCGatewayCreditgenie.php';

        // because through ajax construct is not initiated
        if (wp_doing_ajax()) {
            WC_CreditGenie_Payment_Gateway::init();
        }
    }

    add_action("wp_ajax_woocommerce_refund_line_items", array( 'WC_CreditGenie_Payment_Gateway', 'creditgenie_handle_refund'));

    // so woocommerce / payment system classes all loaded
    add_action('plugins_loaded', 'init_creditgenie_payment_gateway', 0 );
}


?>