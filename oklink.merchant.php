<?php

/*  Copyright 2014 Coinbase Inc.

MIT License

Permission is hereby granted, free of charge, to any person obtaining
a copy of this software and associated documentation files (the
"Software"), to deal in the Software without restriction, including
without limitation the rights to use, copy, modify, merge, publish,
distribute, sublicense, and/or sell copies of the Software, and to
permit persons to whom the Software is furnished to do so, subject to
the following conditions:

The above copyright notice and this permission notice shall be
included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

*/

$nzshpcrt_gateways[$num] = array(
  'name'                   => 'Oklink',
  'api_version'            => 2.0,
  'class_name'             => 'wpsc_merchant_oklink',
  'wp_admin_cannot_cancel' => true,
  'display_name'           => 'Bitcoin',
  'requirements'           => array(
                                /// so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
                                'php_version' => 5.3,
                                 /// for modules that may not be present, like curl
                                'extra_modules' => array('curl', 'openssl')
                              ),
  'internalname'           => 'wpsc_merchant_oklink',
  'form'                   => 'form_oklink_wpsc',
  'submit_function'        => 'submit_oklink_wpsc'
);

/**
  * WP eCommerce oklink Merchant Class
  *
  * This is the oklink merchant class, it extends the base merchant class
*/
class wpsc_merchant_oklink extends wpsc_merchant {

  var $oklink_order = null;

  function __construct( $purchase_id = null, $is_receiving = false ) {
    $this->name = 'oklink';
    parent::__construct( $purchase_id, $is_receiving );
  }

  // Called on gateway execution (payment logic)
  function submit() {

    $callback_secret = get_option("oklink_wpe_callbacksecret");
    if($callback_secret == false) {
      $callback_secret = sha1(openssl_random_pseudo_bytes(20));
      update_option("oklink_wpe_callbacksecret", $callback_secret);
    }
    $callback_url = $this->cart_data['notification_url'];
    $callback_url = add_query_arg('gateway', 'wpsc_merchant_oklink', $callback_url);
    $callback_url = add_query_arg('callback_secret', $callback_secret, $callback_url);

    $return_url = add_query_arg( 'sessionid', $this->cart_data['session_id'], $this->cart_data['transaction_results_url'] );
    $return_url = add_query_arg( 'wpsc_oklink_return', true, $return_url );
    $cancel_url = add_query_arg( 'cancelled', true, $return_url );

    if ( !in_array($this->cart_data['store_currency'],array('USD','BTC','CNY')) ){
        $_SESSION['WpscGatewayErrorMessage'] = 'only support USD/CNY/BTC';
        wp_redirect(get_option( 'shopping_cart_url' ));
        exit();
    }
    $params = array (
      'name'               => "Your Order {$this->cart_data['session_id']}",
      'price'              => $this->cart_data['total_price'],
      'price_currency'     => $this->cart_data['store_currency'],
      'callback_url'       => $callback_url,
      'custom'             => $this->cart_data['session_id'],
      'success_url'        => $return_url,
    );

    try {
      require_once(dirname(__FILE__) . "/oklink/Oklink.php");

      $api_key = get_option("oklink_wpe_api_key");
      $api_secret = get_option("oklink_wpe_api_secret");
      $client = Oklink::withApiKey($api_key, $api_secret);      
      $code = $client->buttonsButton($params)->button->id;
    } catch (Exception $e) {
      $msg = $e->getMessage();
      error_log ("There was an error creating a oklink checkout page: $msg. Make sure you've connected a merchant account in Oklink settings.");
      exit();
    }

    wp_redirect(OklinkBase::WEB_BASE."merchant/mPayOrderStemp1.do?buttonid=$code");
    exit();
  }

  function parse_gateway_notification() {
    $callback_secret = get_option("oklink_wpe_callbacksecret");

    require_once(dirname(__FILE__) . "/oklink/Oklink.php");

    $api_key = get_option("oklink_wpe_api_key");
    $api_secret = get_option("oklink_wpe_api_secret");
    $client = Oklink::withApiKey($api_key, $api_secret);

    if ( $callback_secret != false && $callback_secret == $_REQUEST['callback_secret'] && $client->checkCallback()) {
      $post_body = json_decode(file_get_contents("php://input"));
      if (isset ($post_body)) {
        $this->oklink_order = $post_body;
        $this->session_id   = $this->oklink_order->custom;
      } else {
        exit( "oklink Unrecognized Callback");
      }
    } else {
      exit( "oklink Callback Failure" );
    }
  }

  function process_gateway_notification()  {
    $status = 1;

    switch ( strtolower( $this->oklink_order->status ) ) {
      case 'completed':
        $status = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
        break;
      case 'canceled':
        $status = WPSC_Purchase_Log::PAYMENT_DECLINED;
        break;
    }

    if ( $status > 1 ) {
      $this->set_transaction_details( $this->oklink_order->id, $status );
    }
  }
}

// Returns a form for the admin section
function form_oklink_wpsc() {

  $apiKey = get_option("oklink_wpe_api_key", "");
  $apiSecret = get_option("oklink_wpe_api_secret", "");

  $apiKey = htmlentities($apiKey, ENT_QUOTES);
  $apiSecret = htmlentities($apiSecret, ENT_QUOTES);
  $content = "
  <tr>
    <td>Merchant Account</td>
    <td>
      If you don't have an API Key, please generate one <a href='https://oklink.com/settings/api' target='_blank'>here</a> with the 'user' and 'merchant' permissions.
        </td>
  </tr>";
  
  $content .= "<tr>
    <td>API Key</td>
    <td><input type='text' name='oklink_wpe_api_key' value='$apiKey' /></td>
  </tr>
  <tr>
    <td>API Secret</td>
    <td><input type='text' name='oklink_wpe_api_secret' value='[REDACTED]' autocomplete='off'/></td>
  </tr>";

  return $content;
}

// Validate and submit form fields from oklink_wpe_form
function submit_oklink_wpsc() {
  if ($_POST['oklink_wpe_api_secret'] != null && $_POST['oklink_wpe_api_secret'] != '[REDACTED]') {
    update_option("oklink_wpe_api_key", $_POST['oklink_wpe_api_key']);
    update_option("oklink_wpe_api_secret", $_POST['oklink_wpe_api_secret']);
  }

  return true;
}


// Handle redirect back from oklink
function _wpsc_oklink_return() {

  if ( !isset( $_REQUEST['wpsc_oklink_return'] ) ) {
    return;
  }

  // oklink order param interferes with wordpress
  unset($_REQUEST['order']);
  unset($_GET['order']);

  if (! isset( $_REQUEST['sessionid'] ) ) {
    return;
  }

  global $sessionid;

  $purchase_log = new WPSC_Purchase_Log( $_REQUEST['sessionid'], 'sessionid' );

  if ( ! $purchase_log->exists() || $purchase_log->is_transaction_completed() )
    return;

  $status = 1;

  if ( isset( $_REQUEST['cancelled'] ) ) {
    # Unsetting sessionid to show error
    do_action('wpsc_payment_failed');
    $sessionid = false;
    unset ( $_REQUEST['sessionid'] );
    unset ( $_GET['sessionid'] );
  } else {
    $status = WPSC_Purchase_Log::ORDER_RECEIVED;
    $purchase_log->set( 'processed', $status );
    $purchase_log->save();
    wpsc_empty_cart();
  }

}

add_action( 'init', '_wpsc_oklink_return' );
