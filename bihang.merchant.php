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
  'name'                   => 'Bihang',
  'api_version'            => 2.0,
  'class_name'             => 'wpsc_merchant_bihang',
  'wp_admin_cannot_cancel' => true,
  'display_name'           => 'Bitcoin',
  'requirements'           => array(
                                /// so that you can restrict merchant modules to PHP 5, if you use PHP 5 features
                                'php_version' => 5.3,
                                 /// for modules that may not be present, like curl
                                'extra_modules' => array('curl', 'openssl')
                              ),
  'internalname'           => 'wpsc_merchant_bihang',
  'form'                   => 'form_bihang_wpsc',
  'submit_function'        => 'submit_bihang_wpsc'
);

/**
  * WP eCommerce bihang Merchant Class
  *
  * This is the bihang merchant class, it extends the base merchant class
*/
class wpsc_merchant_bihang extends wpsc_merchant {

  var $bihang_order = null;

  function __construct( $purchase_id = null, $is_receiving = false ) {
    $this->name = 'bihang';
    parent::__construct( $purchase_id, $is_receiving );
  }

  // Called on gateway execution (payment logic)
  function submit() {

    $callback_secret = get_option("bihang_wpe_callbacksecret");
    if($callback_secret == false) {
      $callback_secret = sha1(openssl_random_pseudo_bytes(20));
      update_option("bihang_wpe_callbacksecret", $callback_secret);
    }
    $callback_url = $this->cart_data['notification_url'];
    $callback_url = add_query_arg('gateway', 'wpsc_merchant_bihang', $callback_url);
    $callback_url = add_query_arg('callback_secret', $callback_secret, $callback_url);

    $return_url = add_query_arg( 'sessionid', $this->cart_data['session_id'], $this->cart_data['transaction_results_url'] );
    $return_url = add_query_arg( 'wpsc_bihang_return', true, $return_url );
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
      require_once(dirname(__FILE__) . "/bihang/Bihang.php");

      $api_key = get_option("bihang_wpe_api_key");
      $api_secret = get_option("bihang_wpe_api_secret");
      $client = Bihang::withApiKey($api_key, $api_secret);      
      $code = $client->buttonsButton($params)->button->id;
    } catch (Exception $e) {
      $msg = $e->getMessage();
      error_log ("There was an error creating a bihang checkout page: $msg. Make sure you've connected a merchant account in Bihang settings.");
      exit();
    }

    wp_redirect(BihangBase::WEB_BASE."merchant/mPayOrderStemp1.do?buttonid=$code");
    exit();
  }

  function parse_gateway_notification() {
    $callback_secret = get_option("bihang_wpe_callbacksecret");

    require_once(dirname(__FILE__) . "/bihang/Bihang.php");

    $api_key = get_option("bihang_wpe_api_key");
    $api_secret = get_option("bihang_wpe_api_secret");
    $client = Bihang::withApiKey($api_key, $api_secret);

    if ( $callback_secret != false && $callback_secret == $_REQUEST['callback_secret'] && $client->checkCallback()) {
      $post_body = json_decode(file_get_contents("php://input"));
      if (isset ($post_body)) {
        $this->bihang_order = $post_body;
        $this->session_id   = $this->bihang_order->custom;
      } else {
        exit( "bihang Unrecognized Callback");
      }
    } else {
      exit( "bihang Callback Failure" );
    }
  }

  function process_gateway_notification()  {
    $status = 1;

    switch ( strtolower( $this->bihang_order->status ) ) {
      case 'completed':
        $status = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
        break;
      case 'canceled':
        $status = WPSC_Purchase_Log::PAYMENT_DECLINED;
        break;
    }

    if ( $status > 1 ) {
      $this->set_transaction_details( $this->bihang_order->id, $status );
    }
  }
}

// Returns a form for the admin section
function form_bihang_wpsc() {

  $apiKey = get_option("bihang_wpe_api_key", "");
  $apiSecret = get_option("bihang_wpe_api_secret", "");

  $apiKey = htmlentities($apiKey, ENT_QUOTES);
  $apiSecret = htmlentities($apiSecret, ENT_QUOTES);
  $content = "
  <tr>
    <td>Merchant Account</td>
    <td>
      If you don't have an API Key, please generate one <a href='https://bihang.com/settings/api' target='_blank'>here</a> with the 'user' and 'merchant' permissions.
        </td>
  </tr>";
  
  $content .= "<tr>
    <td>API Key</td>
    <td><input type='text' name='bihang_wpe_api_key' value='$apiKey' /></td>
  </tr>
  <tr>
    <td>API Secret</td>
    <td><input type='text' name='bihang_wpe_api_secret' value='[REDACTED]' autocomplete='off'/></td>
  </tr>";

  return $content;
}

// Validate and submit form fields from bihang_wpe_form
function submit_bihang_wpsc() {
  if ($_POST['bihang_wpe_api_secret'] != null && $_POST['bihang_wpe_api_secret'] != '[REDACTED]') {
    update_option("bihang_wpe_api_key", $_POST['bihang_wpe_api_key']);
    update_option("bihang_wpe_api_secret", $_POST['bihang_wpe_api_secret']);
  }

  return true;
}


// Handle redirect back from bihang
function _wpsc_bihang_return() {

  if ( !isset( $_REQUEST['wpsc_bihang_return'] ) ) {
    return;
  }

  // bihang order param interferes with wordpress
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

add_action( 'init', '_wpsc_bihang_return' );
