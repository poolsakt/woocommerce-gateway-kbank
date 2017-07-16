<?php
/**
 * Plugin Name: WooCommerce K-Bank Gateway
 * Plugin URI: poolsak.t@gmail.com
 * Description: K-Bank gateway to create KBank payment method.
 * Author: Poolsak T.
 * Author email: poolsak.t@gmail.com
 * Version: 1.0.0
 */
 
if (!defined('ABSPATH')) exit; // just in case


function wc_kbank_add_to_gateways( $gateways ) {
	$gateways[] = 'WC_Gateway_KBank';
	return $gateways;
}
add_filter( 'woocommerce_payment_gateways', 'wc_kbank_add_to_gateways' );


function wc_kbank_gateway_plugin_links( $links ) {

	$plugin_links = array(
		'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_kbank' ) . '">' . 'Configure' . '</a>'
	);

	return array_merge( $plugin_links, $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_kbank_gateway_plugin_links' );
add_action( 'plugins_loaded', 'wc_kbank_gateway_init', 11 );

function wc_kbank_gateway_init() {

	class WC_Gateway_KBank extends WC_Payment_Gateway {

		/**
		 * Constructor for the gateway.
		 */
		public function __construct() {
	  
			$this->id                 = 'kbank_gateway';
            $this->icon 			  = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/images/kbank.gif';
			$this->has_fields         = false;
			$this->method_title       = 'KBank';
			$this->method_description = 'Kasikorn Payment Gateway Plug-in for WooCommerce';
		  
			// Load the settings.
			$this->init_form_fields();
			$this->init_settings();
			
		  
			// Define user set variables
			$this->title        = $this->get_option( 'title' );
			$this->description  = $this->get_option( 'description' );
			$this->instructions = $this->get_option( 'instructions', $this->description );
			$this->merchantid   = $this->get_option( 'merchantid' );
			$this->terminalid   = $this->get_option( 'terminalid' );
			$this->secretkey       = $this->get_option( 'secretkey' );
		  
			// Actions
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
		  
			// Customer Emails
			add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
			
			// below is the hook you need for that purpose
			add_action( 'woocommerce_receipt_' . $this->id, array($this,'pay_for_order'	) );
			add_action( 'woocommerce_api_wc_gateway_kbank' , array( $this, 'check_response' ) );
			add_action( 'valid-kbank-request_'. $this->id, array( $this, 'valid_response' ) );
			add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
    			
		}
	
	
		public function check_response() {

		if ( ! empty( $_POST ) ) {
			$posted = wp_unslash( $_POST );

			do_action( 'valid-kbank-request_'.$this->id, $posted );
			exit;
		}

		wp_die( "KBank Request Failure", "KBank Post", array( 'response' => 200 ) );


			
		}
		
		public function valid_response( $posted ) {
 
			$str4checksum = "";
			$verify_md5 = "";
			foreach ($posted as $key => $value){
				${strtolower($key)} = "{$value}";
				if(strtolower($key) != "md5checksum") {
					$str4checksum = $str4checksum."{$value}";
				}
			}

			if(isset($pmgwresp2)) {
				
				$merchantid = substr($pmgwresp2,4,15);
				$terminalid = substr($pmgwresp2,19,8);
				$amount 	= substr($pmgwresp2,85,12);
				$returninv 	= substr($pmgwresp2,32,12);
				$hostresp 	= substr($pmgwresp2,97,2);
			
				if ($this->merchantid != $merchantid){
					wp_die( "KBank Request Merchant ID not match.", "KBank Post", array( 'response' => 200 ) );
				}
				if ($this->terminalid != $terminalid){
					wp_die( "KBank Request Terminal ID not match.", "KBank Post", array( 'response' => 200 ) );
				}

				
			} else {
				
				$verify_md5 = md5($str4checksum.$this->secretkey);
				
				if($verify_md5 != $md5checksum) {
					wp_die( "KBank Request Checksum Failure - ", "KBank Post", array( 'response' => 200 ) );
				}
				
				
			}

			$order_id = (int)$returninv;
			$order = new WC_Order($order_id);
			$order_key = $order->order_key;			

			if ($order->order_total != ($amount / 100)){
				wp_die( "KBank Request amount not match order total.", "KBank Post", array( 'response' => 200 ) );
			}

		
			if ($order->status == 'processing') {

			} else {			
				if ($hostresp == "00") {
					 $order->payment_complete();
					 $order->add_order_note('KBank payment successful<br/>KBank Transaction ID: ' );

				}
			}

 
			wp_redirect( get_home_url() . '/checkout/order-received/'. $order_id .'/?key=' . $order_key );
		}
			
		private function payment_status_completed( $order, $posted ) {
		}

	
		public function thankyou_page() {
			if ( $this->instructions ) {
				echo wpautop( wptexturize( $this->instructions ) );
			}
		}
			
		public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
				
			if ( $this->instructions && ! $sent_to_admin && 'offline' === $order->payment_method && $order->has_status( 'on-hold' ) ) {
				echo wpautop( wptexturize( $this->instructions ) ) . PHP_EOL;
			}
		}	
	
		public function init_form_fields() {
	  
			$this->form_fields = apply_filters( 'wc_kbank_form_fields', array(
		  
				'enabled' => array(
					'title'   => 'Enable/Disable',
					'type'    => 'checkbox',
					'label'   => 'Enable K-Bank Payment',
					'default' => 'no'
				),
				
				'title' => array(
					'title'       => 'Title',
					'type'        => 'text',
					'description' => 'Payment with Visa, MasterCard and JCB with K-Bank Payment Gateway.',
					'default'     => 'K-Payment Gateway',
					'desc_tip'    => true,
				),
				
				'description' => array(
					'title'       => 'Description',
					'type'        => 'textarea',
					'description' => 'This controls the description which the user sees during checkout.',
					'default'     => 'Pay via KBank; you can pay with your credit card.',
					'desc_tip'    => true,
				),

				
				'merchantid' => array(
					'title'       => 'Merchant ID',
					'type'        => 'text',
					'description' => 'Get your Merchant ID from KBank, begin with 401004XXXXXXXXX',
					'default'     => '',
					'desc_tip'    => true,
				),

				'terminalid' => array(
					'title'       => 'Terminal ID',
					'type'        => 'text',
					'description' => 'Get your Terminal ID from KBank (8 chars)',
					'default'     => '',
					'desc_tip'    => true,
				),

				'secretkey' => array(
					'title'       => 'Secret Key',
					'type'        => 'text',
					'description' => 'For security each transaction. Please enter MD5 Secret Key from KBank (32 chars)',
					'default'     => '',
					'desc_tip'    => true,
				),
				
				'instructions' => array(
					'title'       => 'Instructions',
					'type'        => 'textarea',
					'description' => 'Instructions that will be added to the thank you page and emails.', 
					'default'     => '',
					'desc_tip'    => true,
				),				
			) );
		}
	
	
	
		protected function get_firstorder_item_names( $order ) {
			$item_name = "";

			foreach ( $order->get_items() as $item ) {
				$item_name = $item['name'] . ' x ' . $item['qty'];
				break;
			}

			return substr($item_name,0,30);
		}	

		public function process_payment( $order_id ) {
	
			$order = new WC_Order( $order_id );

			return array(
				'result' => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			);
		}
		

		// here, prepare your form and submit it to the required URL
		public function pay_for_order( $order_id ) {
			
			
			$order = new WC_Order( $order_id );
			echo '<p>' .  'Redirecting to payment provider.' . '</p>';
			// add a note to show order has been placed and the user redirected
			$order->add_order_note( 'Order placed and user redirected.');
			// update the status of the order should need be
			$order->update_status( 'on-hold', 'Awaiting payment.' );
			// remember to empty the cart of the user
			WC()->cart->empty_cart();

			// perform a click action on the submit button of the form you are going to return
			wc_enqueue_js( 'jQuery( "#submit-form" ).click();' );

			$payment_url = wp_is_mobile() ? "https://rt05.kasikornbank.com/mobilepay/payment.aspx" : "https://rt05.kasikornbank.com/pgpayment/payment.aspx";
			$url2 = get_home_url() . "/?wc-api=wc_gateway_kbank";
			$respurl = get_home_url() . "/?wc-api=wc_gateway_kbank";
			$amount =  str_pad( ($order->order_total * 100), 12, "0", STR_PAD_LEFT);
			$kbankorder = str_pad( $order->get_order_number(),12,"0",STR_PAD_LEFT);
			$itemname = trim($this->get_firstorder_item_names( $order ));
			$str4md5 = $this->merchantid.$this->terminalid.$amount. $url2. $respurl . $_SERVER['REMOTE_ADDR'] . $itemname . $kbankorder . $this->secretkey;
			$checksum =  md5($str4md5) ;

				
			// return your form with the needed parameters
			echo '<form action="' . $payment_url. '" method="post" target="_top">
				<input type="hidden" name="MERCHANT2" value="'.$this->merchantid.'">
				<input type="hidden" name="TERM2" value="'.$this->terminalid.'">
				<input type="hidden" name="AMOUNT2" value="'. $amount .'">
				<input type="hidden" name="URL2" value="' . $url2 . '">
				<input type="hidden" name="RESPURL" value="' . $respurl . '">
				<input type="hidden" name="IPCUST2" value="' . $_SERVER['REMOTE_ADDR'] . '">
				<input type="hidden" name="DETAIL2" value="' . $itemname . '">
				<input type="hidden" name="INVMERCHANT" value="'.$kbankorder.'">
				<input type="hidden" name="checksum" value="'.$checksum.'">

				<div class="btn-submit-payment" style="display: none;">
					<button type="submit" id="submit-form"></button>
				</div>
			</form>';
			
		
		}		
	
  } 
}
