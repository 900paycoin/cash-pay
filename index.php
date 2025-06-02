<?php
/**
 * Plugin Name: WooCommerce JPesa Payment Gateway Free
 * Plugin URI: https://my.jpesa.com/welcome.php?dad=info&jc=api&hs=ab
 * Description: JPesa is payment gateway for WooCommerce allowing you to take payments via JPesa.
 * Version: 1.7
 * Author: Abdi Joseph
 * Author URI: http://www.jpesa.com/
 */ 


add_action( 'plugins_loaded', 'init_nm_woo_gateway', 0);

function nm_jpesa_settings( $links ) {
    $settings_link = '<a href="'.admin_url( 'admin.php?page=wc-settings&tab=checkout&section=wc_gateway_nm_jpesa' ).'">Setup</a>';
  	array_push( $links, $settings_link );
  	return $links;
}
$plugin = plugin_basename( __FILE__ );
add_filter( "plugin_action_links_$plugin", 'nm_jpesa_settings' );

function init_nm_woo_gateway(){

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;

	class WC_Gateway_NM_JPesa extends WC_Payment_Gateway {

		var $seller_id;
		var $demo;
		var $plugin_url;

		public function __construct(){
			
			global $woocommerce;

			$this -> plugin_url = WP_PLUGIN_URL . DIRECTORY_SEPARATOR . 'woocommerce-jpesa-payment';
			
			$this->id 					= 'nmwoo_jpesa';
			$this->has_fields   		= false;
			$this->checkout_url     	= 'https://my.jpesa.com/';
			$this->checkout_url_sandbox	= 'https://my.jpesa.com/';
			$this->icon 				= $this -> plugin_url.'/images/jpesa_logo.png';
			$this->method_title 		= 'JPesa';
			$this->method_description 	= 'This plugin add JPesa payment gateway with Woocommerce based shop. Make sure you have set your JPesa account according <a href="http://www.jpesa.com/" target="_blank">these setting</a>';
				
			$this->title 				= $this->get_option('title');
			$this->description 			= $this->get_option('description');
			$this->seller_id			= $this->get_option('seller_id');
			$this->secret_word			= $this->get_option('secret_word');
			$this -> demo 				= $this->get_option('demo');
			$this -> pay_method 		= $this->get_option('pay_method'); 
				
				
			$this->init_form_fields();
			$this->init_settings();
				
			// Save options
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action('process_jpesa_ipn_request', array( $this, 'successful_request' ), 1 );
			
			// Payment listener/API hook
			add_action( 'woocommerce_api_wc_gateway_nm_jpesa', array( $this, 'jpesa_response' ) );
				
		}


		function init_form_fields(){

			$this->form_fields = array(
					'enabled' => array(
							'title' => __( 'Enable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Yes', 'woocommerce' ),
							'default' => 'yes'
					),
					'seller_id' => array(
							'title' => __( 'JPesa OwnerID', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'The OwnerID aka Username you use to access JPesa', 'woocommerce' ),
							'default' => '',
							'desc_tip'      => true,
					),
					'title' => array(
							'title' => __( 'Title', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'JPesa Mobile Money Payments', 'woocommerce' ),
							'desc_tip'      => true,
					),
					'description' => array(
							'title' => __( 'Customer Message', 'woocommerce' ),
							'type' => 'textarea',
							'default' => ''
					),
					'demo' => array(
							'title' => __( 'Enable Demo Mode', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Yes', 'woocommerce' ),
							'default' => 'yes'
					),
			);
		}


		/**
		 * Process the payment and return the result
		 *
		 * @access public
		 * @param int $order_id
		 * @return array
		 */
		function process_payment( $order_id ) {

			$order = new WC_Order( $order_id );


			$jpesa_args = $this->get_jpesa_args( $order );
			/*echo '<pre>';
			 print_r($jpesa_args);
			echo '</pre>';
			exit;*/
			
			$jpesa_args = http_build_query( $jpesa_args, '', '&' );
				
			
			//if demo is enabled
			$checkout_url = '';
			if ($this -> demo == 'yes'){
				$checkout_url =	$this->checkout_url_sandbox;
			}else{
				$checkout_url =	$this->checkout_url;
			}
			return array(
					'result' 	=> 'success',
					'redirect'	=> $checkout_url.'?'.$jpesa_args
			);


		}


		/**
		 * Get JPesa Args for passing to PP
		 *
		 * @access public
		 * @param mixed $order
		 * @return array
		 */
		function get_jpesa_args( $order ) {
			global $woocommerce;

			$order_id = $order->id;

			// JPesa Args
			$jpesa_args = array(
					'dad' 				=> 'xp',
					'ownerid' 			=> $this -> seller_id,
					'ref'				=> $order_id,
					'cur'				=> get_woocommerce_currency(),
			);

			$jpesa_args['callback'] 	= str_replace( 'https:', 'http:', add_query_arg( 'wc-api', 'WC_Gateway_NM_JPesa', home_url( '/' ) ) );
			$jpesa_args['return']		= str_replace('https', 'http', WC_Payment_Gateway::get_return_url());
			$jpesa_args['cancel']		= str_replace('https', 'http', $order->get_cancel_order_url());
			
			//setting payment method
			if ($this -> pay_method)
				$jpesa_args['pay_method'] = $this -> pay_method;
			
			
			//if demo is enabled
			if ($this -> demo == 'yes'){
				$jpesa_args['demo'] =	'Y';
			}

			$item_names = array();

			if ( sizeof( $order->get_items() ) > 0 ){
				
				$jpesa_product_index = 0;
				
				foreach ( $order->get_items() as $item ){
					if ( $item['qty'] )
						$item_names[] = $item['name'] . ' x ' . $item['qty'];
				
					/*echo '<pre>';
					print_r($item);
					echo '</pre>';
					exit;*/
					
					
					/**
					 * since version 1.6
					 * adding support for both WC Versions
					 */
					$_sku = '';
					if ( function_exists( 'get_product' ) ) {
							
						// Version 2.0
						$product = $order->get_product_from_item($item);
							
						// Get SKU or product id
						if ( $product->get_sku() ) {
							$_sku = $product->get_sku();
						} else {
							$_sku = $product->id;
						}
							
					} else {
							
						// Version 1.6.6
						$product = new WC_Product( $item['id'] );
							
						// Get SKU or product id
						if ( $product->get_sku() ) {
							$_sku = $product->get_sku();
						} else {
							$_sku = $item['id'];
						}	
					}
					
					if ( $product->is_virtual() || $product->is_downloadable() ) :
						$tangible = "N";
					else :
						$tangible = "Y";
					endif;
					
					$item_formatted_name 		= $item['name'] . ' (Product SKU: '.$item['product_id'].')';
					$jpesa_args['item_name'] 	= sprintf( __( 'Order %s' , 'woocommerce'), $order->get_order_number() ) . " - " . $item_formatted_name;
					$jpesa_args['amount'] 		= number_format( $order->get_total( $item, false ), 2, '.', '' );
					$jpesa_product_index++;
				}
				
				
				// Shipping Cost
				if ( $order -> get_total_shipping() > 0 ) {
					$jpesa_product_index++;
					$jpesa_args['item_name']   .= ' [Includes '.__( 'Shipping', 'woocommerce' ).']';
					$jpesa_args['amount'] 	   += number_format( $order -> get_total_shipping() , 2, '.', '' );
				}
				
				// Taxes (shipping tax too)
				if ( $order -> get_total_tax() > 0 ) {
					$jpesa_product_index++;
					$jpesa_args['item_name']   .= ' [Includes '.__( 'Tax', 'woocommerce' ).']';
					$jpesa_args['amount'] 	   += number_format( $order->get_total_tax() , 2, '.', '' );
				}

				$jpesa_args = apply_filters( 'woocommerce_jpesa_args', $jpesa_args );
			}

			return $jpesa_args;
		}
		
		/**
		 * this function is return product object for two
		 * differetn version of WC
		 */
		function get_product_object(){
			
			
			
			
			return $product;
		}
		
		
		/**
		 * Check for JPesa IPN Response
		 *
		 * @access public
		 * @return void
		 */
		function jpesa_response() {
			global $woocommerce;
			
			@ob_clean();
			
			$wc_order_id 	= $_REQUEST['ref'];
			
			$wc_order 	= new WC_Order( absint( $wc_order_id ) );
			// Mark order complete
			$wc_order->payment_complete();
			// Empty cart and clear session
			$woocommerce->cart->empty_cart();
			wp_redirect( WC_Payment_Gateway::get_return_url( $wc_order ) );
			exit;
		}
		
		
		/*
		 * valid requoest posed from JPesa
		 */
		function successful_request($posted){
			
			//testing ipn request
			
			
			if($posted['invoice_status'] == 'approved'){
				
				global $woocommerce;

				$order_id = $posted['ref'];
				
				//this was set for IPN Simulator
				//$order_id = $posted['vendor_order_id'];
				
				$order 		= new WC_Order( $order_id );
				
				// Store PP Details
				if ( ! empty( $posted['sale_id'] ) )
					update_post_meta( $order->id, 'Sale ID', $posted['sale_id'] );
				
				// Payment completed
				$order->add_order_note( __( 'IPN completed by JPesa', 'woocommerce' ) );
				$order->payment_complete();
				
				$woocommerce -> cart -> empty_cart();
				
			}
		}

	}
	
}


function add_nm_payment_gateway( $methods ) {
	$methods[] = 'WC_Gateway_NM_JPesa';
	return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'add_nm_payment_gateway' );
?>