<?php

/*
  Plugin Name: WooCommerce Konto Checkout plugin
  Plugin URI: https://konto.is/
  Description: Extends WooCommerce with Konto Checkout plugin.
  Version: 1.0.0
  Author: Konto
  Author URI: https://konto.is/
 */
define( 'KONTO_DIR', plugin_dir_path( __FILE__ ) );
define( 'KONTO_URL', plugin_dir_url( __FILE__ ) );
define( 'KONTO_VERSION', '1.0.0' );
 
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
		 return $fields;
	}
	
	//Custom Field Validation for the Icelandic Kennitala
	add_action('woocommerce_checkout_process', 'konto_checkout_field_process');
	function konto_checkout_field_process() {
		if ( ! $_POST['billing_ssn'] )
			wc_add_notice( __( 'Þú verður að slá inn Kennitölu.' ), 'error' );
	}
		
	//Modify icon size only for this gateway
	add_filter( 'woocommerce_gateway_icon', 'authorize_gateway_icon', 10, 2);
	function authorize_gateway_icon( $icon, $id ) {
		if ( strlen($id) >= 9 && substr($id, 0, 9) === 'konto' ) {
			return '<div style="width: 200px;">' . $icon . '</div>'; 
		} else {
			return $icon;
		}
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
    	
    	public function process_payment( $order_id ) {
    		global $woocommerce;
    		$order = new WC_Order( $order_id );
    		
    		$url = 'https://konto.is';
    		
    		if ($this->_test)
    		{
    			$url = 'http://dev.konto.is';
    		}
    		$items = $order->get_items();
    		$tmp = array();
    		foreach ($items as $item_id=> $item)
    		{
    			$taxs = WC_Tax::get_rates($item['tax_class']);
    			$price = WC_Abstract_Order::get_line_total( $item, true)/$item['quantity'];
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
    		$items = $tmp;
    		
    		$invoice_data = array(
    			'amount' => $order->get_total(),
    			'currency' => $order->get_currency(),
    			'customer' => array(
    				'output_select' => 3,
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
    				'final_date' => 7
    			),
    			'settlement_date' => date('Y-m-d',strtotime('+7 days')),
    			'due_date' => date('Y-m-d',strtotime('+5 days')),
    			'issue_date' => date('Y-m-d'),
    			'type' => 'invoice',
    			'is_claim' => 1,
    			'items' => $items
    		);
    		self::log("Start create invoice");
    		self::log(array_merge(array('username' => $this->_username,'api_key' => $this->_api_key),$invoice_data));
    		$data = array(
    			'username' => $this->_username,
    			'api_key' => $this->_api_key,
    			'data' => json_encode($invoice_data)
    		);
    		
    		$ch = curl_init($url.'/api/v1/create-invoice');
    		curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    		curl_setopt($ch, CURLOPT_POSTFIELDS,$query = http_build_query($data, '', '&'));
    		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    		$result = curl_exec($ch);
    		
    		if ($result === FALSE)
    		{
    			self::log('Result: '.curl_error($ch));
    			throw new Exception( 'Error connect server');
    		}
    		else
    		{
    			self::log('Result: '.$result);
    			$result = json_decode($result,true);
    			if (json_last_error() !== JSON_ERROR_NONE)
    			{
    				throw new Exception( 'Error connect server');
    			}
    		}
    		curl_close($ch);  // Seems like good practice
    		
    		if (!$result['status'])		
    			throw new Exception( $result['message']);
    		
    		// Return thankyou redirect
    		if ($this->_mark)
    		{
    			$order->update_status('processing');
    		}
    			
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
	
	
}
