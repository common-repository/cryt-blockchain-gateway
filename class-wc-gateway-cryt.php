<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin Name: CRYT Blockchain Gateway
 * Plugin URI: https://pay.cryt.org/
 * Description:  Provides a CRYT Blockchain Payment Gateway.
 * Author: CRYT Blockchain
 * Author URI: https://cryt.org/
 * Version: 1.0.0
 * License: GPLv2 or later
 */
add_action( 'plugins_loaded', 'cryt_gateway_load', 0 );
function cryt_gateway_load() {

    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter( 'woocommerce_payment_gateways', 'wccryt_add_gateway' );

    function wccryt_add_gateway( $methods ) {
    	if (!in_array('WC_Gateway_Cryt', $methods)) {
				$methods[] = 'WC_Gateway_Cryt';
			}
			return $methods;
    }


    class WC_Gateway_Cryt extends WC_Payment_Gateway {

	var $ipn_url;

    /**
     * Constructor for the gateway.
     *
     * @access public
     * @return void
     */
	public function __construct() {
		global $woocommerce;

        $this->id           = 'cryt';
        $this->icon         = apply_filters( 'woocommerce_cryt_icon', plugins_url().'/cryt-payment-gateway/assets/images/icons/cryt.png' );
        $this->has_fields   = false;
        $this->method_title = __( 'CRYT Blockchain', 'woocommerce' );
        $this->ipn_url   = add_query_arg( 'wc-api', 'WC_Gateway_Cryt', home_url( '/' ) );

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->title 			= $this->get_option( 'title' );
		$this->description 		= $this->get_option( 'description' );
		$this->merchant_id 			= $this->get_option( 'merchant_id' );
		$this->form_submission_method = $this->get_option( 'form_submission_method' ) == 'yes' ? true : false;
		$this->invoice_prefix	= $this->get_option( 'invoice_prefix', 'EXAMPLE.COM-' );
		$this->simple_total = $this->get_option( 'simple_total' ) == 'yes' ? true : false;

		// Logs
		$this->log = new WC_Logger();

		// Actions
		add_action( 'woocommerce_receipt_cryt', array( $this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_cryt', array( $this, 'check_ipn_response' ) );

		if ( !$this->is_valid_for_use() ) $this->enabled = false;
    }


    /**
     * Check if this gateway is enabled and available in the user's country
     *
     * @access public
     * @return bool
     */
    function is_valid_for_use() {
        return true;
    }

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 1.0.0
	 */
	public function admin_options() {

		?>
		<h3><?php _e( 'CRYT Blockchain', 'woocommerce' ); ?></h3>
		<p><?php _e( 'Completes checkout via CRYT-Pay', 'woocommerce' ); ?></p>

    	<?php if ( $this->is_valid_for_use() ) : ?>

			<table class="form-table">
			<?php
    			// Generate the HTML For the settings form.
    			$this->generate_settings_html();
			?>
			</table><!--/.form-table-->

		<?php else : ?>
            <div class="inline error"><p><strong><?php _e( 'Gateway Disabled', 'woocommerce' ); ?></strong>: <?php _e( 'CRYT-Pay does not support your store currency.', 'woocommerce' ); ?></p></div>
		<?php
			endif;
	}

    /**
     * Initialise Gateway Settings Form Fields
     *
     * @access public
     * @return void
     */
	 
    function init_form_fields() {

    	$this->form_fields = array(
			'enabled' => array(
							'title' => __( 'Enable/Disable', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( 'Enable CRYT Blockchain', 'woocommerce' ),
							'default' => 'yes'
						),
			'title' => array(
							'title' => __( 'Title', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'CRYT Blockchain', 'woocommerce' ),
							'desc_tip'      => true,
						),
			'description' => array(
							'title' => __( 'Description', 'woocommerce' ),
							'type' => 'textarea',
							'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
							'default' => __( 'Pay with CRYT via CRYT-Pay', 'woocommerce' )
						),
			'merchant_id' => array(
							'title' => __( 'CRYT Address', 'woocommerce' ),
							'type' 			=> 'text',
							'description' => __( 'Please enter your CRYT Wallet Address.', 'woocommerce' ),
							'default' => '',
						),
			'simple_total' => array(
							'title' => __( 'Compatibility Mode', 'woocommerce' ),
							'type' => 'checkbox',
							'label' => __( "This may be needed for compatibility with certain addons if the order total isn't correct.", 'woocommerce' ),
							'default' => ''
						),
			'invoice_prefix' => array(
							'title' => __( 'Invoice Prefix', 'woocommerce' ),
							'type' => 'text',
							'description' => __( 'Please enter a prefix for your invoice numbers. For compatibility and Security add your DOMAIN as Prefix in this format EXAMPLE.COM to ensure this prefix is unique.', 'woocommerce' ),
							'default' => 'EXAMPLE.COM',
							'desc_tip'      => true,
						),
			'testing' => array(
							'title' => __( 'Gateway Testing', 'woocommerce' ),
							'type' => 'title',
							'description' => '',
						)
			);

    }


	/**
	 * Get CRYT Blockchain Args
	 *
	 * @access public
	 * @param mixed $order
	 * @return array
	 */
	function get_cryt_args( $order ) {
		global $woocommerce;

		$order_id = $order->get_id();

		if ( in_array( $order->get_billing_country(), array( 'US','CA' ) ) ) {
			$order->set_billing_phone(str_replace( array( '( ', '-', ' ', ' )', '.' ), '', $order->get_billing_phone() ));
		}
$post = [
    'cryt_address' => $this->merchant_id,
    'amount_usd' => number_format( $order->get_total(), 2, '.', '' ),
    'currency'   => $order->get_currency(),
    'order_id'   => $this->invoice_prefix . $order->get_order_number(),
    'custom'   => $order->get_id(),
    'website'   => 'https://shop.crytrex.com',
    'success_url'   => $this->ipn_url . '&order_id=' . $this->invoice_prefix . $order->get_order_number() . '&custom=' . $order->get_id(),
    'cancel_url'   => esc_url_raw($order->get_cancel_order_url_raw()),
];

$args = array(
    'body'        => $post,
    'timeout'     => '5',
    'redirection' => '5',
    'httpversion' => '1.0',
    'blocking'    => true,
    'headers'     => array(),
    'cookies'     => array(),
);
$response = wp_remote_post( 'https://pay.cryt.org/new_order_web.php', $args );


		// Cryt.org Args
		$cryt_args = array(
				// Order key + ID
				'order_id'				=> $this->invoice_prefix . $order->get_order_number(),
				'custom' 				=> $order->get_id(),
		);
		$cryt_args = apply_filters( 'woocommerce_cryt_args', $cryt_args );

		return $cryt_args;
	}


    /**
	 * Generate the cryt button link
     *
     * @access public
     * @param mixed $order_id
     * @return string
     */
    function generate_cryt_url($order) {
		global $woocommerce;

		if ( $order->get_status() != 'completed' && get_post_meta($order->get_id(), 'CRYT payment complete', true ) != 'Yes' ) {
			$order->update_status('pending', 'Customer is being redirected to CRYT-Pay...');
		}

		$cryt_adr = "https://pay.cryt.org/pay_web.html?";
		$cryt_args = $this->get_cryt_args( $order );
		$cryt_adr .= http_build_query( $cryt_args, '', '&' );
		return $cryt_adr;
	}


    /**
     * Process the payment and return the result
     *
     * @access public
     * @param int $order_id
     * @return array
     */
	function process_payment( $order_id ) {

		$order          = wc_get_order( $order_id );

		return array(
				'result' 	=> 'success',
				'redirect'	=> $this->generate_cryt_url($order),
		);

	}


    /**
     * Output for the order received page.
     *
     * @access public
     * @return void
     */
	function receipt_page( $order ) {
		echo '<p>'.__( 'Thank you for your order, please click the button below to pay with CRYT.', 'woocommerce' ).'</p>';

		echo $this->generate_cryt_form( $order );
	}

	/**
	 * Check Cryt.org IPN validity
	 **/
	function check_ipn_request_is_valid() {
		global $woocommerce;

		$order = false;
		$error_msg = "Unknown error";
		$auth_ok = false;

$orderid = sanitize_text_field($_GET['order_id']);
$customid = sanitize_text_field($_GET['custom']);

						if ($orderid && $customid) {
							$auth_ok = true;
						} else {
							$error_msg = 'No Orders Found';
						}


		if ($auth_ok) {
	    if (!empty($orderid) && !empty($customid)) {
		$order = wc_get_order( $customid );
	    }


$response = wp_remote_get( 'https://pay.cryt.org/order_details_ipn.php?order_id='.$orderid.'&custom='.$customid.'' );
$data     = wp_remote_retrieve_body( $response );

$obj = json_decode($data);
$status = print_r($obj->{'status'}, true);
$website = print_r($obj->{'website'}, true);
$amount_usd = print_r($obj->{'amount_usd'}, true);
$currency = print_r($obj->{'currency'}, true);
$cryt_address = print_r($obj->{'cryt_address'}, true);

			if ($order !== FALSE) {
					if ($cryt_address == $this->merchant_id) {
						if ($currency == $order->get_currency()) {
							if ($amount_usd >= $order->get_total()) {
								return true;
							} else {
								$error_msg = "Amount received is less than the total!";
							}
						} else {
							$error_msg = "Original currency doesn't match!";
						}
					} else {
						$error_msg = "CRYT Address doesn't match!";
					}
			} else {
				$error_msg = "Could not find order info for order: ".$orderid;
			}
		}

		$report = "Error Message: ".$error_msg."\n\n";

		$report .= "GET Fields\n\n";
		foreach ($_GET as $key => $value) {
			$report .= $key.'='.$value."\n";
		}

		if ($order) {
			$order->update_status('on-hold', sprintf( __( 'CRYT Blockchain Error: %s', 'woocommerce' ), $error_msg ) );
		}
		return false;
	}

	/**
	 * Successful Payment!
	 *
	 * @access public
	 * @param array $posted
	 * @return void
	 */
	function successful_request( $posted ) {
		global $woocommerce;

$response = wp_remote_get( 'https://pay.cryt.org/order_details_ipn.php?order_id='.$posted['order_id'].'&custom='.$posted['custom'].'' );
$data     = wp_remote_retrieve_body( $response );

$obj = json_decode($data);
$status = print_r($obj->{'status'}, true);
$website = print_r($obj->{'website'}, true);
$amount_usd = print_r($obj->{'amount_usd'}, true);
$currency = print_r($obj->{'currency'}, true);
$cryt_address = print_r($obj->{'cryt_address'}, true);
$txid = print_r($obj->{'txid'}, true);
$cancel_url = print_r($obj->{'cancel_url'}, true);
$successorder = 'https://shop.crytrex.com/my-account/orders/';

		// Custom holds post ID
	    if (!empty($posted['order_id']) && !empty($posted['custom'])) {
				$order = wc_get_order( $posted['custom'] );

			    if ($order === FALSE) {
					header("location: ".$cancel_url."");
			    	die("Error: Could not find order info for order: ".$posted['order_id']);
			    }

if ($status == '1') {
$statusinfo = 'Complete';
} else {
$statusinfo = 'Pending';
}
        	$this->log->add( 'cryt', 'Order #'.$order->get_id().' payment status: ' . $statusinfo );
         	$order->add_order_note('CRYT Blockchain Payment Status: '.$statusinfo);

         	if ( $order->get_status() != 'Complete' ) {
         		// no need to update status if it's already done
            if ( ! empty( $txid ) )
             	update_post_meta( $order->get_id(), 'Transaction ID', $txid );

						if ($status == 1) {
							print "Marking complete\n";
				update_post_meta( $order->get_id(), 'CRYT Blockchain Payment complete', 'Yes' );
             	$order->payment_complete();
				header("location: ".$this->get_return_url( $order )."");

						} else if ($status < 0 || $status > 1) {
              $order->update_status('cancelled', 'CRYT Blockchain Payment cancelled/timed out: '.$statusinfo);
			  mail( get_option( 'admin_email' ), sprintf( __( 'Payment for order %s cancelled/timed out', 'woocommerce' ), $order->get_order_number() ), $statusinfo );
			  header("location: ".$cancel_url."");
			  die("Error: MARK CANCELLED: ".$posted['order_id']);

            } else {
							$order->update_status('pending', 'CRYT Blockchain Payment pending: '.$statusinfo);
							header("location: ".$successorder."");
						}
					header("location: ".$successorder."");
			    	die("Error: LAST LINE: ".$posted['order_id']);

	        }

	    }
	}

	/**
	 * Check for Cryt IPN Response
	 *
	 * @access public
	 * @return void
	 */
	function check_ipn_response() {

		@ob_clean();

		if ( !empty($_GET) ) {
			$this->successful_request($_GET);
		} else {
			wp_die( "CRYT Blockchain IPN Request Failure" );
 		}
	}

	/**
	 * get_cryt_order function.
	 *
	 * @access public
	 * @param mixed $posted
	 * @return void
	 */
	function get_cryt_order($posted) {
		$custom = $posted;

    	// Backwards comp for IPN requests
	    	$order_id = $custom;
	    	$order_key = str_replace( $this->invoice_prefix, '', $custom );


		$order = wc_get_order( $order_id );

		// Validate key
		if ($order === FALSE || $order->get_order_key() !== $order_key ) {
			return FALSE;
		}

		return $order;
	}

}

class WC_Cryt extends WC_Gateway_Cryt {
	public function __construct() {
		_deprecated_function( 'WC_Cryt', '1.4', 'WC_Gateway_Cryt' );
		parent::__construct();
	}
}
}
