<?php

use Automattic\WooCommerce\Utilities\OrderUtil;
use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

include_once CLICKPAYSUBSCRIPTION_DIR . 'includes/clickpaysubscription_functions.php';

if (!function_exists('wp_handle_upload')) {
  require_once(ABSPATH . 'wp-admin/includes/file.php');
}

/**
 * Gateway class
 */
class WC_Gateway_ClickpaySub extends WC_Payment_Gateway
{
  protected $msg = array();

  protected $_code = '';
  protected $_title = '';
  protected $_description = '';
  protected $_icon = null;

  protected $gateway_mode;
  protected $gateway_redirect;
  protected $redirect_page_id;
  protected $gateway_url;
  protected $profile_id;
  protected $server_key;
  protected $client_key;
  protected $category;
  protected $language;
  protected $payment_action;
  protected $allow_save_cards;

  protected $logger;

  public $supports = array(
    'products',
    'subscriptions',
    'subscription_cancellation'
  );

  private $payment_subscription = 'cpsubscription';

  public function __construct()
  {
    global $wpdb;

    $this->id = "{$this->_code}";
    $this->method_title = $this->_title;
    $this->method_description = $this->_description;
    $this->icon = $this->getIcon();

    $this->has_fields = false;

    $this->init_form_fields();

    $this->init_settings();

    $this->title = $this->settings['title'];
    $this->description = $this->settings['description'];
    $this->gateway_mode = $this->settings['gateway_mode'];
    $this->gateway_url = $this->settings['gateway_url'];
    $this->gateway_redirect = isset($this->settings['gateway_redirect']) ? $this->settings['gateway_redirect'] : 'noredirect';
    $this->redirect_page_id = $this->settings['redirect_page_id'];
    $this->profile_id = isset($this->settings['profile_id']) ? $this->settings['profile_id'] : '';
    $this->server_key = $this->settings['server_key'];
    $this->client_key = $this->settings['client_key'];
    $this->language = $this->settings['language'];
    $this->payment_action = 'sale';
    $this->allow_save_cards = isset($this->settings['allow_save_cards']) ? $this->settings['allow_save_cards'] : false;

    $this->msg['message'] = "";
    $this->msg['class'] = "";

    //handle the response returned from the payment gateway
    add_action('woocommerce_api_cpsubscriptionresponse', array($this, 'check_clickpaysubscription_response'));
    add_action('woocommerce_api_cpsubscriptioncallback', array($this, 'clickpaysubscription_webhook'));

    add_action('valid-clickpay-request-' . $this->id, array(&$this, 'SUCCESS'));

    //this action hook saves the settings
    if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
    } else {
      add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
    }

    add_action('woocommerce_receipt_' . $this->_code, array(&$this, 'receipt_page'));

    add_action('woocommerce_subscription_status_cancelled', array($this, 'clickpaysubscription_cancel_subscription'));

    // if ($this->settings['enabled'] == 'yes') //Update session cookies
    // {
    //   $this->manage_session();
    // }

    $this->logger = wc_get_logger();
  }



  function init_form_fields()
  {

    $this->form_fields = array(
      'enabled' => array(
        'title' => __('Enable/Disable', 'clickpay'),
        'type' => 'checkbox',
        'label' => __('Enable ClickPay', 'clickpay'),
        'default' => 'no'
      ),
      'title' => array(
        'title' => 'Title',
        'type' => 'text',
        'description' => 'Title to appear at checkout',
        'default' => 'Pay your subscriptions easily.',
      ),
      'description' => array(
        'title' => __('Description:', 'clickpay'),
        'type' => 'textarea',
        'description' => __('This controls the description which the user sees during checkout.', 'clickpay'),
        'default' => __('Pay securely through ClickPay.', 'clickpay')
      ),
      'gateway_mode' => array(
        'title' => __('Gateway Mode', 'clickpay'),
        'type' => 'select',
        'options' => array("0" => "Select", "test" => "Test", "live" => "Live"),
        'description' => __('Mode of gateway subscription.', 'clickpay')
      ),
      'gateway_url' => array(
        'title' => __('Gateway Url', 'clickpay'),
        'type' => 'text',
        'description' => __('Gateway Url to connect to (with ending slash)', 'clickpay')
      ),
      'profile_id' => array(
        'title' => __('Profile Id', 'clickpay'),
        'type' => 'text',
        'description' => __('Profile Id', 'clickpay')
      ),
      'server_key' => array(
        'title' => __('Sever Key', 'clickpay'),
        'type' => 'text',
        'description' => __('Server Key (case sensitive)', 'clickpay')
      ),
      'client_key' => array(
        'title' => __('Client Key', 'clickpay'),
        'type' => 'text',
        'description' => __('Client Key (case sensitive)', 'clickpay')
      ),
      'payment_action' => array(
        'title' => __('Payment Action', 'clickpay'),
        'type' => 'select',
        'options' => array("sale" => "Sale", "auth" => "Authorize"),
        'description' => __('Payment action - request Authorize or Sale ', 'clickpay')
      ),
      'redirect_page_id' => array(
        'title' => __('Return Page'),
        'type' => 'select',
        'options' => $this->get_pages('Select Page'),
        'description' => "Page to redirect to after processing payment."
      ),
      'language' => array(
        'title' => __('Language', 'clickpay'),
        'type' => 'select',
        'options' => array("ar" => "Arabic", "en" => "English"),
        'description' => __('Language', 'clickpay')
      ),
    );

    $this->form_fields['gateway_redirect'] = array(
      'title' => __('Redirect / iFrame', 'clickpay'),
      'type' => 'select',
      'options' => array("redirect" => "Redirect", "iframe" => "iFrame"),
      'description' => __('Redirect the customer or use an iframe.', 'clickpay')
    );

    $this->form_fields['allow_save_cards'] = array(
      'title' => __('Save Cards', 'clickpay'),
      'type' => 'checkbox',
      'label' => __('Save Cards', 'clickpay'),
      'default' => 'no'
    );

  }

  public function process_admin_options()
  {

    // Process other settings
    parent::process_admin_options();
  }

  /**
   * Admin Panel Options
   * - Options for bits like 'title' and availability on a country-by-country basis
   **/
  public function admin_options()
  {
    echo '<h3>' . __('ClickPay', 'clickpay') . '</h3>';
    echo '<p>' . __('A popular gateways for online shopping.') . '</p>';
    if (PHP_VERSION_ID < 70300) {
      echo "<h1 style=\"color:red;\">**Notice: ClickPay payments plugin requires PHP v7.3 or higher.<br />
	  		 		Plugin will not work properly below PHP v7.3 due to SameSite cookie restriction.</h1>";
    }
    echo '<table class="form-table">';
    $this->generate_settings_html();
    echo '</table>';
  }


  /**
   *  There maybe payment fields
   **/
  function payment_fields()
  {
    if ($this->description)
      echo wpautop(wptexturize($this->description));

  }

  /**
   * Receipt Page
   **/
  function receipt_page($order)
  {
    echo '<p>' . __('Thank you for your order, please wait as you will be automatically redirected to ClickPay payments.', 'clickpay') . '</p>';
    echo $this->generate_clickpay_form($order);
  }

  /**
   * Process the payment and return the result
   **/
  function process_payment($order_id)
  {

    $order = new WC_Order($order_id);

    if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
      return array(
        'result' => 'success',
        'redirect' => add_query_arg(
          'order',
          $order->id,
          add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))
        )
      );
    } else {
      return array(
        'result' => 'success',
        'redirect' => add_query_arg(
          'order',
          $order->id,
          add_query_arg('key', $order->get_order_key(), get_permalink(get_option('woocommerce_pay_page_id')))
        )
      );
    }
  }

  /**
   * Check for valid clickpay pay response from the hosted payment page
   **/
  function check_clickpaysubscription_response()
  {
    error_log("Server API Call");

    global $woocommerce;

    $redirect_url = '';
    $is_valid = false;
    $response_data = $_POST;

    error_log("Check Payment Data Received " . print_r($_POST, true));

    $trans_ref = filter_input(INPUT_POST, 'tranRef');

    if ($trans_ref && $trans_ref != null && $trans_ref != "") {
      $is_valid = ClickpaySubscriptionHelper::is_valid_redirect($response_data, $this->server_key);
    }

    error_log("A");

    //the orderId in the response is the clickpay payments order reference number
    if ($is_valid && isset($response_data['cartId']) && !empty($response_data['cartId'])) {

      $txnid = $response_data['cartId'];
      $order_id = explode('_', $txnid);
      $order_id = (int) $order_id[0];    //get rid of time part

      $order = new WC_Order($order_id);
      $action = $this->payment_action;

      $this->msg['class'] = 'error';
      $this->msg['message'] = "Thank you for shopping with us. However, the transaction has been declined.";

      //call inquiry and confirm transaction is successful
      $is_success = false;
      $response_inquiry = $this->payment_inquiry($trans_ref);

      error_log("Check Payment Payment Inquiry " . print_r($response_inquiry, true));

      if (isset($response_inquiry['status']) && $response_inquiry['status'] == 'success') {
        if (isset($response_inquiry['data']['payment_result']['response_status']))
          $is_success = $response_inquiry['data']['payment_result']['response_status'] === 'A';
      }

      if ($is_success) {

        $this->msg['message'] = "Thank you for shopping with us. Your account has been charged and your transaction is successful for Order Id: $order_id <br/>
						We will be shipping your order to you soon.<br/><br/>";
        if ($this->payment_action == 'auth')
          $this->msg['message'] = "Thank you for shopping with us. Your payment has been authorized for Order Id: $order_id <br/>
						  We will be shipping your order to you soon.<br/><br/>";

        $this->msg['class'] = 'success';
        if ($order->get_status() == 'processing' || $order->get_status() == 'completed') {
          //do nothing
          $redirect_url = $order->get_checkout_order_received_url();
        } else {

          //complete the order
          $order->payment_complete($trans_ref);
          $order->add_order_note('ClickPay has processed the payment ' . ' Ref Number: ' . $trans_ref);
          $order->add_order_note($this->msg['message']);
          $order->add_order_note("Paid using ClickPay");
          $order->save();
          $woocommerce->cart->empty_cart();

          //save transactionid
          $order->update_meta_data('clickpaysubscription_transaction_ref', $trans_ref);
          $order->update_meta_data('clickpaysubscription_cart_id', $txnid);
          $order->save();

          $redirect_url = $order->get_checkout_order_received_url();
        }

        try {
          //check if we have to save card details
          if ($this->allow_save_cards) {
            $cardDetails = isset($response_data["token"]) ? $response_data["token"] : null;
            if ($cardDetails != null && $cardDetails != "") {
              $this->save_card_details($response_data);
            }
          }
        } catch (Exception $e) {
          //error_log($e->getMessage());
        }

      } else {
        //failed
        $this->msg['class'] = 'error';
        $this->msg['message'] = "Thank you for shopping with us. However, the payment failed<br/><br/>";
        $order->update_status('failed');
        $order->add_order_note('Failed');
        $order->add_order_note($this->msg['message']);
      }
    }

    //manage msessages
    if (function_exists('wc_add_notice')) {
      wc_clear_notices();
      if ($this->msg['class'] != 'success') {
        wc_add_notice($this->msg['message'], $this->msg['class']);
      }
    } else {
      if ($this->msg['class'] != 'success') {
        $woocommerce->add_error($this->msg['message']);
      }
      $woocommerce->set_messages();
    }


    if ($redirect_url == '') {
      $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
    }

    if ($this->gateway_redirect == 'iframe') {
      $html = '<script type="text/javascript">window.parent.location.href = "' . $redirect_url . '";</script>';
      echo $html;
    } else {
      wp_redirect($redirect_url);
    }
    exit;

  }

  /**
   * Webhook, called when recurring payments are processed
   */
  function clickpaysubscription_webhook()
  {

    error_log("Webhook");

    global $woocommerce;

    try {

      error_log("W-1");

      $post = file_get_contents('php://input');

      $webhook_data = json_decode($post, true);

      error_log("Webhook data " . print_r($webhook_data, true));

      $this->logger->info(wc_print_r("webhook data" . $post, true), array('source' => 'ClickpaySubscriptionWebhook'));

      $trans_ref = isset($webhook_data['tran_ref']) ? $webhook_data['tran_ref'] : '';

      if (is_array($webhook_data) && $trans_ref && $trans_ref != null && $trans_ref != "") {

        $txnid = $webhook_data['cart_id'];
        $order_id = explode('_', $txnid);
        $order_id = (int) $order_id[0];    //get rid of time part

        $order = new WC_Order($order_id);

        if ($order != null) {
          $is_success = false;
          if (isset($webhook_data['payment_result']['response_status']))
            $is_success = $webhook_data['payment_result']['response_status'] === 'A';

          if ($is_success) {
            
            $agreement_id = $webhook_data['agreement_id'];
            error_log("Agreement ID " . $agreement_id);

            $order->update_meta_data('clickpaysubscription_agreement_id', $agreement_id);

            //add note to the order
            $transaction_time = $webhook_data['payment_result']['transaction_time'];
            $order->add_order_note(esc_html__('Subscription Processed: Ref Number: '
                            . $trans_ref . ' Transaction Time: ' . $transaction_time, 'ClickPaySubscription'));
            $order->update_meta_data('clickpaysubscription_agreement_id', $agreement_id);
            $order->save();
            
            $this->logger->info(wc_print_r("Subscription Processed " . $order_id, true), array('source' => 'ClickPaySubscription'));
          }
        } else {
          $this->logger->info("ClickPay Subscription web hook - order not found");
        }

        header("Content-Type: application/json");
        echo json_encode(array("status" => "success"));
      }
    } catch (Exception $e) {
      $this->logger->info(wc_print_r("ClickPay Subscription webhook error " . $e->getMessage(), true), array('source' => 'ClickPaySubscriptionWebhook'));
      http_response_code(401);
    }
    exit();
  }

  // Save card details
  private function save_card_details($data)
  {
    //check if user is logged in      
    if (is_user_logged_in()) {
      $user_id = get_current_user_id();

      if ($user_id != null && is_numeric($user_id) && $user_id > 0) {
        $token = (isset($data['token'])) ? $data['token'] : '';
        $cardNumber = (isset($data['payment_info']['payment_description'])) ? $data['payment_info']['payment_description'] : '';
        $cardType = (isset($data['payment_info']['card_type'])) ? $data['payment_info']['card_type'] : '';
        $cardBrand = (isset($data['payment_info']['card_scheme'])) ? $data['payment_info']['card_scheme'] : '';
        $cdata = ["token" => $token, "cardNumber" => $cardNumber, "cardType" => $cardType, "cardBrand" => $cardBrand];
        $card = ["card" => $cdata];
        $json = json_encode($card);
        update_user_meta($user_id, 'user_clickpaysubscription_tokens', $json);
      }
    }
  }

  private function get_card_details()
  {
    //add the tokenized cards
    $token = "";

    if ($this->allow_save_cards && is_user_logged_in()) {
      $user_id = get_current_user_id();
      if ($user_id != null && is_numeric($user_id) && $user_id > 0) {
        $data = get_user_meta($user_id, "user_clickpaysubscription_tokens", true);

        if ($data != null) {
          $card = json_decode($data, true);
          if (isset($card['card']['token'])) {
            $token = $card['card']['token'];
          }
        }
      }
    }

    return $token;
  }

  //Payment Inquiry
  private function payment_inquiry($trans_ref)
  {
    $request_url = $this->gateway_url . 'payment/query';
    $data = [
      "tran_ref" => $trans_ref
    ];
    $response = ClickpaySubscriptionHelper::send_api_request($request_url, $data, $this->profile_id, $this->server_key);

    return $response;
  }

  /**
   * Generate payment button link
   **/
  public function generate_clickpay_form($order_id)
  {
    $html = "";

    if ($this->gateway_redirect == 'redirect' || $this->gateway_redirect == 'iframe')
      $html = $this->generate_hosted_form($order_id);

    return $html;
  }

  private function generate_hosted_form($order_id)
  {
    global $woocommerce;

    $order = new WC_Order($order_id);

    $order_currency = $order->get_currency();

    $txnid = $order_id . '_' . date("ymd") . ':' . rand(1, 100);

    $productInfo = "";
    $order_items = $order->get_items();
    foreach ($order_items as $item_id => $item_data) {
      $product = wc_get_product($item_data['product_id']);
      if ($product->get_sku() != "")
        $productInfo .= $product->get_sku() . " ";
    }
    if ($productInfo != "") {
      $productInfo = trim($productInfo);
      if (strlen($productInfo) > 50)
        $productInfo = substr($productInfo, 0, 50);
    } else
      $productInfo = "Product Info";

    $return_url = get_site_url() . '/wc-api/cpsubscriptionresponse';
    $callback_url = get_site_url() . '/wc-api/cpsubscriptioncallback';


    $tran_class = "ecom";    //hard coded value

    $signup_fee = 0;
    $recurring_amt = 0;
    $billing_period = 0;
    $billing_interval = 0;
    $trial_period = 0;
    $trial_end = 0;
    $number_of_payments = 0;
    $billing_period_int = 0;

    if (wcs_order_contains_subscription($order)) {

      error_log("Contains subscription items");


      // Get the WC_Subscriptions_Order object from the order
      $subscription_order = wcs_get_subscriptions_for_order($order);
      $count = 0;

      foreach ($subscription_order as $subscription) {
        $count++;
        if ($count >= 2) {
          $html = "<h3>Multiple subscriptions cannot be purchased in one order.</h3>";
          return $html;
        }

        $recurring_amt = $subscription->get_total(); // Recurring amount for the current billing period

        $billing_period = $subscription->get_billing_period(); // Period (e.g., 'day', 'week', 'month', 'year')
        if ($billing_period == 'day')
          $billing_period_int = 1;
        else if ($billing_period == 'week')
          $billing_period_int = 2;
        else if ($billing_period == 'month')
          $billing_period_int = 3;

        $billing_interval = $subscription->get_billing_interval(); // Interval (e.g., '1' for every 1 month)

        $trial_period = $subscription->get_trial_period(); // e.g., 'month', 'year', etc.
        $trial_end = $subscription->get_date('trial_end');

        $signup_fee = $subscription->get_sign_up_fee();       

        // Get the number of payments (if the subscription has a time limit)
        // Get the time limit (end date) of the subscription
        //$time_limit = $subscription->get_time_limit(); // Get the time limit (end date)
        $number_of_payments = 0; // Default if no time limit

        $start_date = $subscription->get_date('start');
        $end_date = $subscription->get_date('end');

        error_log("Start date: " . $start_date . ' ' . gettype($start_date));
        error_log("End date: " . $end_date . ' ' . gettype($end_date));

        // Ensure the time limit exists before proceeding
        if ($end_date) {
          $start_date = new DateTime($start_date);
          $end_date = new DateTime($end_date);

          $diff = $start_date->diff($end_date); // Get the difference between the start and end date

          // Depending on the billing period (day, week, month, year), calculate the number of payments
          switch ($billing_period) {
            case 'day':
              // For daily subscriptions, calculate how many billing days fit in the time limit
              $number_of_payments = $diff->days / $billing_interval;
              break;

            case 'week':
              // For weekly subscriptions, calculate how many weeks fit in the time limit
              $number_of_payments = $diff->days / 7 / $billing_interval;
              break;

            case 'month':
              // For monthly subscriptions, calculate how many months fit in the time limit
              $number_of_payments = ($diff->y * 12 + $diff->m) / $billing_interval;
              break;

            case 'year':
              // For yearly subscriptions, calculate how many years fit in the time limit
              $number_of_payments = $diff->y / $billing_interval;
              break;
          }

          // Round the number of payments if needed
          $number_of_payments = floor($number_of_payments);
        }


        // Display subscription details
        error_log("Signup Fees:" . $signup_fee);
        error_log("Rec Amt:" . $recurring_amt);
        error_log("Billing Period:" . $billing_period);
        error_log("Billing Interval:" . $billing_interval);
        error_log("Billing Count:" . $number_of_payments);
        error_log("Trial Period:" . $trial_period);
        error_log("Trial Enddate:" . $trial_end);

      }
    } else {
      $html = "<h3>This order is not a subscription.</h3>";
      return $html;
    }


    $data = [
      "tran_type" => $this->payment_action,
      "tran_class" => $tran_class,
      "cart_id" => $txnid,
      "cart_currency" => $order->get_currency(),
      "cart_amount" => $signup_fee > 0 ? $signup_fee : $recurring_amt,
      "cart_description" => $productInfo,
      "paypage_lang" => $this->language,
      "show_save_card" => ($this->allow_save_cards == 'yes') ? true : false,
      "callback" => $callback_url,  //webhook called by the server
      "return" => $return_url,      //url called after user completes the form
    ];


    if ($this->allow_save_cards == 'yes') {
      $data['tokenise'] = 2;
      $token = $this->get_card_details();
      if ($token != null && $token != "") {
        $data['token'] = $token;
      }
    }

    $customer_details = [
      "name" => $order->get_billing_first_name(),
      "email" => $order->get_billing_email(),
      "phone" => $order->get_billing_phone(),
      "street1" => $order->get_billing_address_1(),
      "city" => $order->get_billing_city(),
      "state" => $order->get_billing_state(),
      "country" => $order->get_billing_country(),
      "zip" => $order->get_billing_postcode()
    ];

    $shipping_details = [
      "name" => $order->get_shipping_first_name(),
      "email" => '',
      "phone" => '',
      "street1" => $order->get_shipping_address_1(),
      "city" => $order->get_shipping_city(),
      "state" => $order->get_shipping_state(),
      "country" => $order->get_shipping_country(),
      "zip" => $order->get_shipping_postcode()
    ];

    //ADD 1 DAY FOR TESTING
    $first_due = date('d/m/Y', strtotime("+1 day"));
    if ($trial_period != "") {
      $d = strtotime($trial_end);
      $first_due = date('d/m/Y',$d);
    }

    $agreement_details = [
      "agreement_description" => "Subscription",
      "agreement_currency" => $order->get_currency(),
      "initial_amount" => $signup_fee > 0 ? $signup_fee : $recurring_amt,
      "repeat_amount" => $recurring_amt,
      "final_amount" => 0,
      "repeat_terms" => (int) $number_of_payments,
      "repeat_period" => (int) $billing_period_int,
      "repeat_every" => (int) $billing_interval,
      "first_installment_due_date" => $first_due
    ];

    $plugin_info = [
      "cart_name" => "Woocommerce",
      "cart_version" => $woocommerce->version,
      "plugin_version" => "1.0.0"
    ];

    $data['customer_details'] = $customer_details;
    $data['shipping_details'] = $shipping_details;
    $data['agreement'] = $agreement_details;
    $data['plugin_info'] = $plugin_info;

    if ($this->gateway_redirect == "iframe") {
      $data["framed"] = true;
      $data["hide_shipping"] = true;
    }


    $request_url = $this->gateway_url . 'payment/request';
    $response = ClickpaySubscriptionHelper::send_api_request($request_url, $data, $this->profile_id, $this->server_key);

    $action = "";
    if ($response['status'] == 'success') {
      $rdata = $response['data'];
      if (isset($rdata['redirect_url']) && !empty($rdata['redirect_url'])) {
        $action = $rdata['redirect_url'];
      }
    }

    $html = "";
    $homeurl = get_home_url();

    if ($action != "") {
      if ($this->gateway_redirect == 'redirect') {
        $html = "
          <form action=\"" . $action . "\" method=\"post\" id=\"clickpaysubscriptionhosted_form\" name=\"clickpaysubscriptionhosted_form\">
            <button style='display:none' id='submit_clickpaysubscription_hosted_form' name='submit_clickpaysubscription_hosted_form'>Pay Now</button>
          </form>
          <script type=\"text/javascript\">document.getElementById(\"clickpaysubscriptionhosted_form\").submit();</script>
          ";
      } else {
        $html = '
          <script type="text/javascript">console.log("started");</script>
          <div id="clickpaysubscriptionif" style="display:none; cursor: default"> 
              <iframe id="framed" name="framed" width="800" height="600" src="' . $action . '"></iframe>
              <div style="clear:both"></div>
              <button id="cpifclose" name="cpifclose" onclick="closecpsubscriptioniframe()">Close</button>              
          </div>          
          <script type="text/javascript">
              setTimeout(
                () => {                
                 jQuery.blockUI({ message: jQuery("#clickpaysubscriptionif"), css: { width: "800px", height: "550px", top: "10%", left: "20%" }, centerX: true, centerY: true });
                },
                5000
              )
              function closecpsubscriptioniframe()
              {
                console.log("close");
                jQuery.unblockUI(); 
                window.location.href = "' . $homeurl . '";
                return false;
              }
          </script>
        ';
      }
    } else {
      $html = "<h3>Error processing payment. Please try again</h3>";
    }

    return $html;
  }


  function clickpaysubscription_cancel_subscription($subscription)
  {

    error_log("Cancel called");

    // Ensure $subscription is a WC_Subscription object
    if (!$subscription instanceof WC_Subscription) {
      return;
    }

    $subscription_status = $subscription->get_status();

    error_log("Subscription status " . $subscription_status);

    if ($subscription_status != "cancelled")
    {
      return;
    }

    //get agreement id
    $agreement_id = "";
    $order_ids = $subscription->get_related_orders(); 

    error_log("Cancel related order ids " . print_r($order_ids, true));

    if (!empty($order_ids)) {
      foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
      }
    }

    if ($order != null)
    {
      $agreement_id = $order->get_meta('clickpaysubscription_agreement_id');
    }

    error_log("Cancel agreement id " . $agreement_id);

    if ($agreement_id && $agreement_id != null && $agreement_id != "")
    {
      //call cancellation API
      $data = [
        "agreement_id" => $agreement_id,
      ];

      $request_url = $this->gateway_url . 'payment/agreement/cancel';
      $response = ClickpaySubscriptionHelper::send_api_request($request_url, $data, $this->profile_id, $this->server_key);

      $is_success = false;
      if ($response['status'] == 'success') {
        $rdata = $response['data'];
        if (isset($rdata['status']) && $rdata['status'] == 'success') {
          $is_success = true;
        }
      }

      $message = "";
      if ($is_success) 
      {
        error_log("Subscription cancelled successfully");
        $subscription->add_order_note('Subscription cancelled via call to ClickPay.');
        $message = 'ClickPay Subscription payment has been successfully cancelled.';
      }
      else
      {
        error_log("Subscription cancel ERROR");
        $subscription->add_order_note('ClickPay ERROR! Subscription could not be cancelled.');
        $message = 'ClickPay ERROR! Subscription could not be cancelled.';
      }
      
    }
  }

  function get_pages($title = false, $indent = true)
  {
    $wp_pages = get_pages('sort_column=menu_order');
    $page_list = array();
    if ($title)
      $page_list[] = $title;
    foreach ($wp_pages as $page) {
      $prefix = '';
      // show indented child pages?
      if ($indent) {
        $has_parent = $page->post_parent;
        while ($has_parent) {
          $prefix .= ' - ';
          $next_page = get_page($has_parent);
          $has_parent = $next_page->post_parent;
        }
      }
      // add to page list array array
      $page_list[$page->ID] = $prefix . $page->post_title;
    }
    return $page_list;
  }

  /**
   * Session patch CSRF Samesite=None; Secure
   **/
  function manage_session()
  {
    $context = array('source' => $this->id);
    try {
      if (PHP_VERSION_ID >= 70300) {
        $options = session_get_cookie_params();
        $options['samesite'] = 'None';
        $options['secure'] = true;
        unset($options['lifetime']);
        $cookies = $_COOKIE;
        foreach ($cookies as $key => $value) {
          if (!preg_match('/cart/', $key)) {
            setcookie($key, $value, $options);
          }
        }
      } else {
        $this->logger->error("clickpay payment plugin does not support this PHP version for cookie management.
												Required PHP v7.3 or higher.", $context);
      }
    } catch (Exception $e) {
      $this->logger->error($e->getMessage(), $context);
    }
  }

  public function getIcon()
  {
    $icon_name = $this->_icon ?? "{$this->_code}.png";

    $iconPath = CLICKPAYSUBSCRIPTION_DIR . "images/{$icon_name}";
    $icon = '';
    if (file_exists($iconPath)) {
      $icon = CLICKPAYSUBSCRIPTION_IMAGES_URL . "{$icon_name}";
    }

    return $icon;
  }

}

