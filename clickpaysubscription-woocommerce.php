<?php
/*
Plugin Name: Clickpay - WooCommerce Subscription Gateway
Plugin URI:    https://clickpay.com.sa/
Description:   Clickpay is a <strong>3rd party payment gateway</strong>. Ideal payment solutions for subscriptions.
Version: 1.0.0
Author: Clickpay
Author URI: support@clickpay.com
*/

if (!defined('ABSPATH')) {
  exit; // Exit if accessed directly
}

define('CLICKPAYSUBSCRIPTION_VERSION', '1.0.0');
define('CLICKPAYSUBSCRIPTION_DIR', plugin_dir_path(__FILE__));
define('CLICKPAYSUBSCRIPTION_URL', plugins_url("/", __FILE__));
define('CLICKPAYSUBSCRIPTION_IMAGES_URL', plugins_url("images/", __FILE__));

define('CLICKPAYSUBSCRIPTION_METHODS', [
  'cpsubscription' => 'WC_Gateway_Clickpay_Subscription',
]);

require_once CLICKPAYSUBSCRIPTION_DIR . 'includes/clickpaysubscription_functions.php';

// Plugin activated
register_activation_hook(__FILE__, 'woocommerce_clickpaysubscription_activated');

// Load plugin function when woocommerce loaded
add_action('plugins_loaded', 'woocommerce_clickpaysubscription_init', 0);


function woocommerce_clickpaysubscription_init()
{

  if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
    add_action('admin_notices', 'woocommerce_clickpaysubscription_missing_wc_notice');
    return;
  }

  require_once CLICKPAYSUBSCRIPTION_DIR . 'includes/clickpaysubscription_payment_methods.php';
  require_once CLICKPAYSUBSCRIPTION_DIR . 'includes/clickpaysubscription_gateways.php';

  /**
   * Add the Gateway to WooCommerce
   **/
  function woocommerce_add_clickpaysubscription_gateway($gateways)
  {
    $clickpay_gateways = array_values(CLICKPAYSUBSCRIPTION_METHODS);
    $gateways = array_merge($gateways, $clickpay_gateways);
   
    return $gateways;
  }

  function clickpaysubscription_filter_gateways($load_gateways)
  {
    if (is_admin()) return $load_gateways;

    $gateways = [];
    $currency = get_woocommerce_currency();

    foreach ($load_gateways as $gateway) {

      $code = array_search($gateway, CLICKPAYSUBSCRIPTION_METHODS);

      if ($code) {
        $allowed = true;      //do any processing to allow / disallow methods
        if ($allowed) {
          $gateways[] = $gateway;
        }
      } else {
        // Not Clickpay Gateway
        $gateways[] = $gateway;
      }
    }

    return $gateways;
  }


  /**
   * Add URL link to Clickpay plugin name pointing to WooCommerce payment tab
   */
  function clickpaysubscription_add_action_links($links)
  {
    $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout');

    $links[] = "<a href='{$settings_url}'>Settings</a>";

    return $links;
  }

  add_filter('woocommerce_payment_gateways', 'woocommerce_add_clickpaysubscription_gateway'); 
  add_filter('woocommerce_payment_gateways', 'clickpaysubscription_filter_gateways', 10, 1);

  add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'clickpaysubscription_add_action_links');


  add_action('woocommerce_blocks_loaded', 'woocommerce_gateway_clickpaysubscription_woocommerce_block_support');
  
  
  function woocommerce_gateway_clickpaysubscription_woocommerce_block_support()
  {
    if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
      require_once CLICKPAYSUBSCRIPTION_DIR . 'includes/blocks/cpsubscription-block.php';
	  
      add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
          $payment_method_registry->register(new WC_Gateway_CPsubscription_Blocks_Support);
        }
      );
	  
    }

  }

}

function woocommerce_clickpaysubscription_missing_wc_notice()
{
    echo '<div class="error"><p><strong>Clickpay Subscription requires WooCommerce to be installed and active.</strong></p></div>';
}

function woocommerce_clickpaysubscription_activated()
{
  ClickpaySubscriptionHelper::log("Clickpay Subscription Activated.");
}


