<?php

/*
  Plugin Name: WooCommerce Konto Checkout plugin
  Plugin URI: http://wcplugin.konto.is/
  Description: Extends WooCommerce with Konto Checkout plugin.
  Version: 1.4.0
  Author: Konto
  Author URI: https://konto.is/
 */
define( 'KONTO_DIR', plugin_dir_path( __FILE__ ) );
define( 'KONTO_URL', plugin_dir_url( __FILE__ ) );
define( 'KONTO_VERSION', '1.4.0' );
 
add_action('plugins_loaded', 'woocommerce_konto_gateway_init', 0);
function woocommerce_konto_gateway_init() {

	//Ensure WooCommerce is loaded
    if (!class_exists('WC_Payment_Gateway'))
        return;
    
    //curl_init is required for this plugin
    if (!function_exists('curl_version'))
		return;
    
    //Add the gateway to woocommerce
    add_filter('woocommerce_payment_gateways', 'add_konto_gateway');
    function add_konto_gateway($methods) {
		global $woocommerce;
		
		$methods[] = 'WC_Gateway_Konto';
		
        return $methods;
    }
	
	//Icelandic Social Security Number (Kennitala required by Konto) added to woocommerce checkout fields
	add_filter( 'woocommerce_checkout_fields' , 'konto_override_checkout_fields' );
	function konto_override_checkout_fields( $fields ) {
		 $fields['billing']['billing_ssn'] = array(
			'type' => 'text',
			'label' => __('Kennitala', 'woocommerce'),
			'placeholder' => _x('Kennitala', 'placeholder', 'woocommerce'),
			'required' => true,
			'clear' => true,
			'label_class' => array('billing_ssn'),
		 );
		 $fields['billing']['billing_konto_send_xml'] = array(
			'type' => 'checkbox',
			'label' => __('Send invoice as XML', 'woocommerce'),
			'clear' => true,
			'label_class' => array('billing_ssn'),
		 );
		 return $fields;
	}
	
	//Custom Field Validation for the Icelandic Kennitala
	add_action('woocommerce_checkout_process', 'konto_checkout_field_process');
	function konto_checkout_field_process() {
		if ( ! $_POST['billing_ssn'] )
			wc_add_notice( __( 'Þú verður að slá inn Kennitölu.' ), 'error' );
	}
		
	//Modify icon size only for this gateway
	add_filter( 'woocommerce_gateway_icon', 'konto_authorize_gateway_icon', 10, 2);
	function konto_authorize_gateway_icon( $icon, $id ) {
		if ( strlen($id) >= 9 && substr($id, 0, 9) === 'konto' ) {
			return '<div style="width: 200px;">' . $icon . '</div>'; 
		} else {
			return $icon;
		}
	}
	
	function konto_get_woo_version_number() {
		// If get_plugins() isn't available, require it
		if ( ! function_exists( 'get_plugins' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
			
			// Create the plugins folder and file variables
			$plugin_folder = get_plugins( '/' . 'woocommerce' );
			$plugin_file = 'woocommerce.php';
			
			// If the plugin version number is set, return it
			if ( isset( $plugin_folder[$plugin_file]['Version'] ) ) {
				return $plugin_folder[$plugin_file]['Version'];
				
			} else {
				// Otherwise return null
				return NULL;
			}
	}
	
	function add_action_to_order( $actions, $order ) {
		if ( $order->has_status( array('processing' ) ) && $order->payment_method != 'konto') {
			$invoice = get_post_meta($order->id,'konto_invoice',true);
			if (!$invoice)
			{
				$version = konto_get_woo_version_number();
				$check = true;
				if ($version)
				{
					$array = explode('.', $version);
					if (isset($array['0']) && isset($array['1']) && $array['0'] >= 3 && $array['1'] >= 3)
					{
						$check = false;
					}
				}
				
				if ($check)
				{
					$actions['konto'] = array(
						'url'  => admin_url( 'admin-ajax.php?action=create_invoice&order_id=' . $order->id ),
						'name' => 'Import order to Konto, issue and send invoice',
						'action' =>'konto',
					);
					$actions['konto_draft'] = array(
						'url'  => admin_url( 'admin-ajax.php?action=create_draft_invoice&order_id=' . $order->id ),
						'name' => 'Import order to Konto as a saved invoice',
						'action' =>'konto_draft',
					);
				}
				else
				{
					$actions['konto'] = array(
						'url'  => admin_url( 'admin-ajax.php?action=create_invoice&order_id=' . $order->id ),
						'name' => 'Import order to Konto, issue and send invoice',
						'action' =>'konto',
					);

					$actions['konto_draft'] = array(
						'url'  => admin_url( 'admin-ajax.php?action=create_draft_invoice&order_id=' . $order->id ),
						'name' => 'Import order to Konto as a saved invoice',
						'action' =>'konto_draft',
					);
				}
			}
		}
		return $actions;
	}
	
	function create_invoice() {
		$order  = wc_get_order( absint( $_GET['order_id'] ) );
		
		if ( $order ) {
			if ( $order->has_status( array('processing' ) ) && $order->payment_method != 'konto') {
				$invoice = get_post_meta($order->id,'konto_invoice',true);
				if (! $invoice)
				{
					$gateway = new WC_Gateway_Konto();
					try {
						$result = $gateway->process_payment($_GET['order_id'],0,true,true);
						
						update_post_meta($_GET['order_id'],'konto_invoice',$result['result']);
					}
					catch (Exception $e)
					{
						if(!session_id()) {
							session_start();
						}
						$_SESSION['konto_error_message'] = $e->getMessage();
					}
				}
			}
		}
		
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}

	function create_draft_invoice() {
		$order  = wc_get_order( absint( $_GET['order_id'] ) );
		
		if ( $order ) {
			if ( $order->has_status( array('processing' ) ) && $order->payment_method != 'konto') {
				$invoice = get_post_meta($order->id,'konto_invoice',true);
				if (! $invoice)
				{
					$gateway = new WC_Gateway_Konto();
					try {
						$result = $gateway->process_payment($_GET['order_id'],0,true,true, true);
						
						update_post_meta($_GET['order_id'],'konto_invoice',$result['result']);
					}
					catch (Exception $e)
					{
						if(!session_id()) {
							session_start();
						}
						$_SESSION['konto_error_message'] = $e->getMessage();
					}
				}
			}
		}
		
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'edit.php?post_type=shop_order' ) );
		exit;
	}
	
	function konto_admin_notice_error() {
		if(!session_id()) {
			session_start();
		}
		if (isset($_SESSION['konto_error_message'])):
		?>
	    <div class="notice notice-error">
	        <p><?php echo $_SESSION['konto_error_message'] ?></p>
	    </div>
	    <?php
	    unset($_SESSION['konto_error_message']);
	    endif;
	}
	
	$settings = get_option('woocommerce_konto_settings',null);
	if ($settings && $settings['enabled'] == 'yes')
	{
		add_filter( 'woocommerce_admin_order_actions', 'add_action_to_order',10,2);	
		add_action( 'wp_ajax_create_invoice', 'create_invoice' );
		add_action( 'wp_ajax_create_draft_invoice', 'create_draft_invoice' );
		
		add_action( 'admin_notices', 'konto_admin_notice_error' );
	}
    
    class WC_Gateway_Konto extends WC_Payment_Gateway {
    	public static $log_enabled = false;
    	public static $log = false;
    	protected $_username;
    	protected $_api_key;
    	protected $_test;
    	protected $_array_currency = array("ISK","EUR","USD","DKK","NOK","SEK","JPY","GBP","AUD","PLN","CAD","CHF","CNY","NZD","MXN","SGD","HKD","KRW","TRY","RUB","INR","VND","BRL","ZAR","UAH","CZK");
    	protected $_mark;
    	
    	public function __construct() {
    		$this->id = 'konto';
			$this->icon = KONTO_URL . '/konto_netbanki.png';
    		$this->has_fields = false;
    		// Load the form fields
    		$this->init_form_fields();
    		$this->init_settings();
    		
    		$this->title = $this->get_option( 'title');
    		$this->description = $this->get_option( 'description');
    		
    		$this->_username = $this->get_option( 'username');
    		$this->_api_key= $this->get_option( 'api_key');
    		$this->_test = 'yes' === $this->get_option( 'testmode', 'no' );
    		$this->_mark = 'yes' === $this->get_option( 'mark', 'no' );
    		
    		self::$log_enabled = 'yes' === $this->get_option( 'log', 'no' );
    		
    		
    		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
    		
    		if (!$this->is_valid_for_use()) $this->enabled = false;
    	}
    	
    	public static function log( $message, $level = 'info' ) {
    		if ( self::$log_enabled ) {
    			if ( empty( self::$log ) ) {
    				self::$log = wc_get_logger();
    			}
    			if (is_array($message))
    			{
    				$message = print_r($message,true);
    			}
    			self::$log->log( $level, $message, array( 'source' => 'konto' ) );
    		}
    	}
    	
    	public function admin_options() {
    		?>
            <h3>Konto</h3>
            <p>Reikningar sendir frá Konto og krafa stofnuð í netbanka greiðanda</p>
            <?php if ( $this->is_valid_for_use() ) : ?>
                <table class="form-table"><?php $this->generate_settings_html(); ?></table>
            <?php else : ?>
                <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: Current Store currency is not valid for Konto plugin. Must be in <?php echo implode(",", $this->_array_currency_)?>.</p></div>
            <?php
                endif;
        }
        
        //Check if this gateway is enabled and available in the user's country
        function is_valid_for_use() {
            
            if (!in_array(get_woocommerce_currency(), $this->_array_currency)) {
                return false;
            }
            return true;
        }
    	
    	public function process_payment( $order_id ,$is_claim = true, $is_mark_paid = false, $is_return= false, $is_draft = false) {   		
    		$order = new WC_Order( $order_id );
    		
    		$url = 'https://konto.is';
    		
    		if ($this->_test)
    		{
    			$url = 'http://dev.konto.is';
    		}
    		$items = $order->get_items();
    		$tmp = array();
    		$amount_without_ship = 0;
    		foreach ($items as $item_id=> $item)
    		{
    			$taxs = WC_Tax::get_rates($item['tax_class']);
    			$price = $order->get_line_total( $item, true)/$item['quantity'];
    			$amount_without_ship += $order->get_line_total( $item, true);
    			$tax_name = 'Z';
    			if (isset($taxs[key($taxs)]['rate']))
    			{
    				switch ($taxs[key($taxs)]['rate'])
    				{
    					case 24:
    						$tax_name = 'S';
    						break;
    					case 11:
    						$tax_name = 'AA';
    						break;
    				}
    			}
    			
    			switch ($tax_name)
    			{
    				case 'S':
    					$price = round($price*100/124,4);
    					break;
    				case 'AA':
    					$price = round($price*100/111,4);
    					break;
    			}
    			
    			$tmp[] = array(
    					'item_number' => $item_id,
    					'description' => $item['name'],
    					'qty' => $item['quantity'],
    					'uom' => 'C62',
    					'tax' => $tax_name,
    					'unit_price' => $price
    			);
    		}
    		$ship = $order->get_total() - $amount_without_ship;
    		if ( $ship > 0)
    		{
    			$tmp[] = array(
    				'item_number' => $item_id++,
    				'description' => 'Ship',
    				'qty' => 1,
    				'uom' => 'C62',
    				'tax' => 'Z',
    				'unit_price' => $ship
    			);
    		}
    		$items = $tmp;
			$trading_partner_id = '';
    		if ($order->billing_konto_send_xml && $order->billing_ssn) {
				$trading_partner_id = $order->billing_ssn;
			}
    		$invoice_data = array(
    			'amount' => $order->get_total(),
    			'currency' => $order->get_currency(),
    			'customer' => array(
    				'output_select' => $trading_partner_id ? 2 : 3,
    				'email' => $order->billing_email,
    				'name' => $order->billing_first_name.' '.$order->billing_last_name,
    				'address' => $order->billing_address_1.' '.$order->billing_address_2,
    				'zip' => $order->billing_postcode,
    				'city' => $order->billing_city,
    				'phone_number' => $order->billing_phone,
    				'registration_no' => $order->billing_ssn,
    				'currency' =>$order->get_currency(),
    				'lang' => 'is',
    				'due_date' => 5,
    				'final_date' => 7,
					'trading_partner_id' => $trading_partner_id
    			),
    			'settlement_date' => date('Y-m-d',strtotime('+7 days')),
    			'due_date' => date('Y-m-d',strtotime('+5 days')),
    			'issue_date' => date('Y-m-d'),
    			'type' => 'invoice',
    			'is_claim' => $is_claim,
    			'default_payment_fee' => true,
    			'items' => $items,
    			'mark_paid' => $is_mark_paid,
				'customer_default' => $order->billing_ssn ? false : true,
				'cost_provide' => $order->get_order_number(),
				'save' => $is_draft,
    		);
    		self::log("Start create invoice");
    		self::log(array_merge(array('username' => $this->_username,'api_key' => $this->_api_key),$invoice_data));
    		$data = array(
    			'username' => $this->_username,
    			'api_key' => $this->_api_key,
    			'data' => json_encode($invoice_data)
    		);
    		
    		$response = wp_remote_post( $url.'/api/v1/create-invoice', array(
	    		'method' => 'POST',
				'sslverify' => false,
	    		'timeout' => 45,
	    		'redirection' => 5,
	    		'httpversion' => '1.0',
	    		'blocking' => true,
	    		'headers' => array(),
	    		'body' => $data,
	    		'cookies' => array()
    		)
    		);
    		
    		if ( is_wp_error( $response ) ) {
    			$error_message = $response->get_error_message();
    			self::log($error_message);
    			throw new Exception( $error_message);
    		} else {
    			self::log($response['body']);
    			
    			$result = json_decode($response['body'],true);
    			if (json_last_error() !== JSON_ERROR_NONE)
    			{
    				throw new Exception( 'Error connect server');
    			}
    		}
    		
    		if (!$result['status'])		
    			throw new Exception( $result['message']);
    		
    		// Return thankyou redirect
    		if ($this->_mark && !$is_return)
    		{
    			$order->update_status('processing');
    		}
    		if ($is_return)
    			return $result;
    		
    		return array(
    			'result' => 'success',
    			'redirect' => $this->get_return_url( $order )
    		);
    	}
    	
    	function init_form_fields() {
    		$this->form_fields = array(
    				
    				'enabled' => array(
    						'title' => 'Enable/Disable',
    						'label' => 'Enable ' . $this->title,
    						'type' => 'checkbox',
    						'description' => '',
    						'default' => 'yes'
    				),
    				'title'              => array(
    						'title'       => 'Title',
    						'type'        => 'text',
    						'description' => 'This controls the title which the user sees during checkout.',
    						'default'     => 'Reikning í netbanka'
    				),
    				'description'        => array(
    						'title'       => 'Description',
    						'type'        => 'textarea',
    						'description' => 'This controls the description which the user sees during checkout.',
    						'default'     => 'Rafrænn reikningur á PDF berst á netfangið þitt og krafa birtist í netbanka undir ,,Ógreiddir reikningar"'
    				),
    				'username' => array(
    						'title' => 'Username',
    						'type' => 'text',
    						'description' => 'This is the Username supplied by Konto.',
    						'default' => 'Sjá Vefþjónustuaðgangur undir Áskriftir og viðbætur á konto.is'
    				),
    				'api_key' => array(
    						'title' => 'Api Key',
    						'type' => 'text',
    						'description' => 'This is the Api Key supplied by Konto.',
    						'default' => 'Sjá Vefþjónustuaðgangur undir Áskriftir og viðbætur á konto.is'
    				),
    				'testmode' => array(
    						'title' => 'Konto Test Mode',
    						'label' => 'Enable Test Mode',
    						'type' => 'checkbox',
    						'description' => 'Place the payment gateway in development mode.',
    						'default' => 'no'
    				),
    				'log' => array(
    						'title'       => 'Debug log',
    						'type'        => 'checkbox',
    						'label'       => 'Enable logging',
    						'default'     => 'no',
    						'description' => sprintf(( 'Log Konto create invoice inside %s'), '<code>' . WC_Log_Handler_File::get_log_file_path( 'konto' ) . '</code>' )
    				),
    				'mark' => array(
    						'title'       => 'Mark as Processing',
    						'type'        => 'checkbox',
							'description' => 'Marks order status as Processing - instead of Pending payment.',
    						'default'     => 'yes'
    				),
    		);
    	}
    }
	
	
	function action_admin_head() {
        echo '<style>.wc-action-button-konto::after { content: "\f170" !important; }</style>';
		echo '<style>.wc-action-button-konto_draft::after { content: "\f137" !important; }</style>';
    }
    add_action( 'admin_head', 'action_admin_head' );
	
}
