<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Stripe Gateway
 *
 * Provides a Stripe Payment Gateway.
 *
 * @class		WC_Stripe_Gateway
 * @extends		WC_Payment_Gateway
 * @version		0.1.0
 * @package		WooCommerce/Classes/Payment
 * @author		Stephen Zuniga
 */
class WC_Stripe_Gateway extends WC_Payment_Gateway {
	protected $GATEWAY_NAME				= 'wc_stripe';
	protected $order					= null;
	protected $transactionId			= null;
	protected $transactionErrorMessage	= null;

	/**
	 * Constructor for the gateway.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		global $wc_stripe;

		$this->id						= 'wc_stripe';
		$this->method_title				= 'WooCommerce Stripe';
		$this->has_fields				= true;
		$this->api_endpoint				= 'https://api.stripe.com/';

		// Init settings
		$this->init_form_fields();
		$this->init_settings();

		// Get settings
		$this->enabled					= $this->settings['enabled'];
		$this->title					= $this->settings['title'];
		$this->description				= $this->settings['description'];
		$this->testmode					= $this->settings['testmode'];
		$this->capture					= $this->settings['capture'];
		$this->additional_fields		= $this->settings['additional_fields'];

		// Get current user information
		$this->current_user				= wp_get_current_user();
		$this->current_user_id			= get_current_user_id();
		$this->stripe_customer_info		= get_user_meta( $this->current_user_id, $wc_stripe->settings['stripe_db_location'] );

		// Hooks
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_notices', array( $this, 'perform_checks' ) );
		add_action( 'wp_enqueue_scripts', array( &$this, 'load_scripts' ) );
	}

	/**
	 * Check if this gateway is enabled and all dependencies are fine.
	 * Warn the user if any of the requirements fail.
	 *
	 * @access public
	 * @return bool
	 */
	public function perform_checks() {
		global $woocommerce, $wc_stripe;

		if ( $this->enabled == 'no') {
			return false;
		}

		// We're using the credit card field bundles with WC 2.1.0, and this entire plugin won't work without it
		if ( $woocommerce->version < '2.1.0' ) {
			echo '<div class="error"><p>Stripe for WooCommerce uses some advanced features introduced in WooCommerce 2.1.0. Please update WooCommerce to continue using Stripe for WooCommerce.</p></div>';
			return false;
		}

		// Check for API Keys
		if ( ! $wc_stripe->settings['publishable_key'] && ! $wc_stripe->settings['secret_key'] ) {
			echo '<div class="error"><p>Stripe needs API Keys to work, please find your secret and publishable keys in the <a href="https://manage.stripe.com/account/apikeys" target="_blank">Stripe accounts section</a>.</p></div>';
			return false;
		}

		// Force SSL on production
		if ( $this->testmode == 'no' && get_option( 'woocommerce_force_ssl_checkout' ) == 'no' ) {
			echo '<div class="error"><p>Stripe needs SSL in order to be secure. Read mode about forcing SSL on checkout in <a href="http://docs.woothemes.com/document/ssl-and-https/" target="_blank">the WooCommerce docs</a>.</p></div>';
			return false;
		}
	}

	/**
	 * Check if this gateway is enabled and all dependencies are fine.
	 * Disable the plugin if dependencies fail.
	 *
	 * @access public
	 * @return bool
	 */
	public function is_available() {
		global $woocommerce;

		if ( $this->enabled == 'no' ) {
			return false;
		}

		// We're using the credit card field bundles with WC 2.1.0, and this entire plugin won't work without it
		if ( $woocommerce->version < '2.1.0' ) {
			return false;
		}

		// Stripe won't work without keys
		if ( ! $wc_stripe->settings['publishable_key'] && ! $wc_stripe->settings['secret_key'] ) {
			return false;
		}

		// Disable plugin if we don't use ssl
		if ( ! is_ssl() && $this->testmode == 'no' ) {
			return false;
		}

		return true;
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'type'			=> 'checkbox',
				'title'			=> __( 'Enable/Disable', 'wc_stripe' ),
				'label'			=> __( 'Enable Credit Card Payment', 'wc_stripe' ),
				'default'		=> 'yes'
			),
			'capture' => array(
				'type'			=> 'checkbox',
				'title'			=> __( 'Auth & Capture', 'wc_stripe' ),
				'description'	=> __( 'This authorizes payment on checkout, but doesn\'t charge until you manually set the order as processing', 'wc_stripe' ),
				'label'			=> __( 'Enable Authorization & Capture', 'wc_stripe' ),
				'default'		=> 'no'
			),
			'testmode' => array(
				'type'			=> 'checkbox',
				'title'			=> __( 'Testing', 'wc_stripe' ),
				'label'			=> __( 'Turn on testing', 'wc_stripe' ),
				'default'		=> 'no'
			),
			'title' => array(
				'type'			=> 'text',
				'title'			=> __( 'Title', 'wc_stripe' ),
				'description'	=> __( 'This controls the title which the user sees during checkout.', 'wc_stripe' ),
				'default'		=> __( 'Credit Card Payment', 'wc_stripe' )
			),
			'description' => array(
				'type'			=> 'text',
				'title'			=> __( 'Description', 'wc_stripe' ),
				'description'	=> __( 'This controls the description which the user sees during checkout.', 'wc_stripe' ),
				'default'		=> __( '', 'wc_stripe' )
			),
			'additional_fields' => array(
				'type'			=> 'checkbox',
				'title'			=> __( 'Additional Fields', 'wc_stripe' ),
				'description'	=> __( 'This enables the use of a Billing ZIP and a Name on Card for Stripe authentication purposes. This is only neccessary if you check the "Only ship to the users billing address" box on WooCommerce Shipping settings.', 'wc_stripe' ),
				'label'			=> __( 'Use Additional Fields', 'wc_stripe' ),
				'default'		=> 'no'
			),
			'test_secret_key'	=> array(
				'type'			=> 'text',
				'title'			=> __( 'Stripe API Test Secret key', 'wc_stripe' ),
				'default'		=> __( '', 'wc_stripe' )
			),
			'test_publishable_key' => array(
				'type'			=> 'text',
				'title'			=> __( 'Stripe API Test Publishable key', 'wc_stripe' ),
				'default'		=> __( '', 'wc_stripe' )
			),
			'live_secret_key'	=> array(
				'type'			=> 'text',
				'title'			=> __( 'Stripe API Live Secret key', 'wc_stripe' ),
				'default'		=> __( '', 'wc_stripe' )
			),
			'live_publishable_key' => array(
				'type'			=> 'text',
				'title'			=> __( 'Stripe API Live Publishable key', 'wc_stripe' ),
				'default'		=> __( '', 'wc_stripe' )
			),
		);
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @access public
	 * @return void
	 */
	public function admin_options() {
		?>
		<h3>Stripe Payment</h3>
		<p>Allows Credit Card payments through <a href="https://stripe.com/">Stripe</a>.</p>
		<p>You can find your API Keys in your <a href="https://dashboard.stripe.com/account/apikeys">Stripe Account Settings</a>.</p>
		<table class="form-table">
			<?php $this->generate_settings_html(); ?>
		</table>
		<?php
	}

	/**
	 * Load dependent scripts
	 * - stripe.js from the stripe servers
	 * - jquery.payment.js for styling the form fields
	 * - wc_stripe.js for handling the data to submit to stripe
	 *
	 * @access public
	 * @return void
	 */
	public function load_scripts() {
		global $wc_stripe;

		// Main stripe js
		wp_enqueue_script( 'stripe', 'https://js.stripe.com/v2/', '', '1.0', true );

		// jQuery Payment
		wp_enqueue_script( 'paymentjs', plugins_url( 'assets/js/jquery.payment.min.js', dirname( __FILE__ ) ), array( 'jquery' ), '1.4.0', true );

		// Plugin js
		wp_enqueue_script( 'wc_stripe_js', plugins_url( 'assets/js/wc_stripe.min.js', dirname( __FILE__ ) ), array( 'stripe', 'paymentjs' ), '1.0', true );

		// Plugin css
		wp_enqueue_style( 'wc_stripe_css', plugins_url( 'assets/css/wc_stripe.css', dirname( __FILE__ ) ), false, '1.0');

		$wc_stripe_info = array(
			'ajaxurl'			=> admin_url( 'admin-ajax.php' ),
			'publishableKey'	=> $wc_stripe->settings['publishable_key'],
			'hasCard'			=> $this->stripe_customer_info ? true : false
		);

		wp_localize_script( 'wc_stripe_js', 'wc_stripe_info', $wc_stripe_info );
	}

	/**
	 * Payment fields
	 *
	 * @access public
	 * @return void
	 */
	public function payment_fields() {
		if( is_user_logged_in() && $this->stripe_customer_info ) :

			// Add option to use a saved card
			foreach ( $this->stripe_customer_info as $i => $credit_card ) : ?>

				<input type="radio" id="stripe_card_<?php echo $i; ?>" name="wc_stripe_card" value="<?php echo $i; ?>" checked>
				<label for="stripe_card_<?php echo $i; ?>">Card ending with <?php echo $credit_card['last4']; ?> (<?php echo $credit_card['exp_month']; ?>/<?php echo $credit_card['exp_year']; ?>)</label><br>

			<?php endforeach; ?>

			<input type="radio" id="new_card" name="wc_stripe_card" value="new">
			<label for="new_card">Use a new credit card</label>

		<?php endif; ?>

		<div id="wc_stripe-creditcard-form">

		<?php
			if ( $this->additional_fields == 'yes' ) : 

				$billing_name = woocommerce_form_field( 'billing-name', array(
					'label'				=> 'Name on Card',
					'required'			=> true,
					'class'				=> array( 'form-row-first' ),
					'input_class'		=> array( 'wc_stripe-billing-name' ),
					'return'			=> true,
					'custom_attributes'	=> array(
						'autocomplete'	=> 'off',
						'novalidate'	=> 'novalidate'
					)
				) );
				echo $billing_name;

				$billing_zip = woocommerce_form_field( 'billing-zip', array(
					'label'				=> 'Billing Zip',
					'required'			=> true,
					'class'				=> array( 'form-row-last' ),
					'input_class'		=> array( 'wc_stripe-billing-zip' ),
					'return'			=> true,
					'clear'				=> true,
					'custom_attributes'	=> array(
						'autocomplete'	=> 'off',
						'novalidate'	=> 'novalidate'
					)
				) );
				echo $billing_zip;

			endif;

			$cc_number = woocommerce_form_field( 'card-number', array(
				'label'				=> 'Card Number',
				'placeholder'		=> '•••• •••• •••• ••••',
				'maxlength'			=> 20,
				'required'			=> true,
				'input_class'		=> array( 'wc_stripe-card-number' ),
				'return'			=> true,
				'custom_attributes'	=> array(
					'autocomplete'	=> 'off',
					'pattern'		=> '\d*',
					'novalidate'	=> 'novalidate'
				)
			) );
			$cc_number = preg_replace( '/name=".*?\"/i', '', $cc_number );
			echo $cc_number;

			$cc_expiry = woocommerce_form_field( 'card-expiry', array(
				'label'				=> 'Expiry (MM/YY)',
				'placeholder'		=> 'MM / YY',
				'required'			=> true,
				'class'				=> array( 'form-row-first' ),
				'input_class'		=> array( 'wc_stripe-card-expiry' ),
				'return'			=> true,
				'custom_attributes'	=> array(
					'autocomplete'	=> 'off',
					'pattern'		=> '\d*',
					'novalidate'	=> 'novalidate'
				)
			) );
			$cc_expiry = preg_replace( '/name=".*?\"/i', '', $cc_expiry );
			echo $cc_expiry;

			$cc_cvc = woocommerce_form_field( 'card-cvc', array(
				'label'			=> 'Card Code',
				'placeholder'		=> 'CVC',
				'required'			=> true,
				'class'				=> array( 'form-row-last' ),
				'input_class'		=> array( 'wc_stripe-card-cvc' ),
				'return'			=> true,
				'clear'				=> true,
				'custom_attributes'	=> array(
					'autocomplete'	=> 'off',
					'pattern'		=> '\d*',
					'novalidate'	=> 'novalidate'
				)
			) );
			$cc_cvc = preg_replace( '/name=".*?\"/i', '', $cc_cvc );
			echo $cc_cvc;

			?>
		</div>
		<?php
	}

	/**
	 * Send form data to Stripe
	 * Handles sending the charge to an existing customer, a new customer (that's logged in), or a guest
	 *
	 * @access public
	 * @return void
	 */
	protected function send_to_stripe() {
		global $woocommerce;

		// Get the credit card details submitted by the form
		$data = $this->get_form_data();

		// If there are errors on the form, don't bother sending to Stripe.
		if ( $data['errors'] == 1 ) {
			return;
		}

		// Set up basics for charging
		if ( is_user_logged_in() ) {
			$customer_description = $this->current_user->user_login . ' (#' . $this->current_user_id . ' - ' . $this->current_user->user_email . ') ' . $data['card']['name']; // username (user_id - user_email) Full Name
		} else {
			$customer_description = 'Guest (' . $this->order->billing_email . ') ' . $data['card']['name']; // Guest (user_email) Full Name
		}
		$stripe_charge_data = array(
			'amount'		=> $data['amount'], // amount in cents
			'currency'		=> $data['currency'],
			'description'	=> $customer_description,
			// 'capture'		=> $this->capture == 'yes' ? true : false,
		);

		// Set up the charge for Stripe's servers
		try {

			// Make sure we only create customers if a user is logged in
			if ( is_user_logged_in() ) {

				if ( ! $this->stripe_customer_info ) {
					$customer = WC_Stripe::create_customer( $this->current_user_id, $data, $customer_description );
				} else {
					// If the user is already registered on the stripe servers, retreive their information
					$customer = WC_Stripe::get_customer( $this->stripe_customer_info[0]['customer_id'] );

					if ( $data['chosen_card'] == 'new' ) {
						// Add new card on stripe servers
						$card = WC_Stripe::update_customer( $this->stripe_customer_info[0]['customer_id'] . '/cards', array(
							'card' => $data['token']
						) );

						// Make new card the default
						$customer = WC_Stripe::update_customer( $this->stripe_customer_info[0]['customer_id'], array(
							'default_card' => $card->id
						) );

						add_user_meta( $this->current_user_id, $this->stripe_db_location, array(
							'customer_id'	=> $customer->id,
							'card_id'		=> $card->id,
							'type'			=> $card->type,
							'last4'			=> $card->last4,
							'exp_year'		=> $card->exp_year,
							'exp_month'		=> $card->exp_month
						) );

						$stripe_charge_data['card'] = $card->id;
					} else {
						$stripe_charge_data['card'] = $this->stripe_customer_info[ $data['chosen_card'] ]['card_id'];
					}
				}

				// Set up charging data to include customer information
				$stripe_charge_data['customer'] = $customer->id;
			} else {
				// Set up one time charge
				$stripe_charge_data['card'] = $data['token'];
			}

			// Create the charge on Stripe's servers - this will charge the user's card
			$charge = WC_Stripe::create_charge( $stripe_charge_data );

			$this->transactionId = $charge->id;

			// Save data for the "Capture"
			update_post_meta( $this->order->id, 'transaction_id', $this->transactionId );
			update_post_meta( $this->order->id, 'auth_capture', strcmp( $this->capture, 'yes' ) == 0 );

			// Save data for cross-reference between Stripe Dashboard and WooCommerce
			update_post_meta( $this->order->id, 'customer_id', $customer->id );

			return true;

		} catch ( Exception $e ) {
			wc_add_notice( __( 'Error:', 'wc_stripe' ) . ' ' . $e->getMessage(), 'error' );

			return false;
		}
	}

	/**
	 * Process the payment and return the result
	 *
	 * @access public
	 * @param int $order_id
	 * @return array
	 */
	public function process_payment( $order_id ) {
		global $woocommerce;

		$this->order = new WC_Order( $order_id );

		if ( $this->send_to_stripe() ) {
			$this->order_complete();

			$result = array(
				'result' => 'success',
				'redirect' => $this->get_return_url( $this->order )
			);

			return $result;
		} else {
			$this->payment_failed();
			wc_add_notice( __( 'Transaction Error: Could not complete your payment.', 'wc_stripe' ), 'error' );
		}
	}

	/**
	 * Mark the payment as failed in the order notes
	 *
	 * @access protected
	 * @return void
	 */
	protected function payment_failed() {
		$this->order->add_order_note(
			sprintf(
				'%s Credit Card Payment Failed with message: "%s"',
				$this->GATEWAY_NAME,
				$this->transactionErrorMessage
			)
		);
	}

	/**
	 * Mark the payment as completed in the order notes
	 *
	 * @access protected
	 * @return void
	 */
	protected function order_complete() {
		global $woocommerce;

		if ( $this->order->status == 'completed' ) {
			return;
		}

		$this->order->payment_complete();
		$woocommerce->cart->empty_cart();

		$this->order->add_order_note(
			sprintf(
				'%s payment completed with Transaction Id of "%s"',
				$this->GATEWAY_NAME,
				$this->transactionId
			)
		);

		unset( $_SESSION['order_awaiting_payment'] );
	}

	/**
	 * Retrieve the form fields
	 *
	 * @access protected
	 * @return mixed
	 */
	protected function get_form_data() {
		if ( $this->order && $this->order != null ) {
			return array(
				'amount'		=> (float) $this->order->get_total() * 100,
				'currency'		=> strtolower( get_woocommerce_currency() ),
				'token'			=> isset( $_POST['stripe_token'] ) ? $_POST['stripe_token'] : '',
				'description'	=> 'Charge for %s' . $this->order->billing_email,
				'chosen_card'	=> isset( $_POST['wc_stripe_card'] ) ? $_POST['wc_stripe_card'] : '',
				'card'			=> array(
					'name'				=> $this->order->billing_first_name . ' ' . $this->order->billing_last_name,
					'billing_email'		=> $this->order->billing_email,
					'address_line1'		=> $this->order->billing_address_1,
					'address_line2'		=> $this->order->billing_address_2,
					'address_zip'		=> $this->order->billing_postcode,
					'address_state'		=> $this->order->billing_state,
					'address_country'	=> $this->order->billing_country,
				),
				'errors'		=> isset( $_POST['form_errors'] ) ? $_POST['form_errors'] : ''
			);
		}

		return false;
	}
}

/**
 * Process the captured payment when changing order status to completed
 *
 * @access public
 * @param int $order_id
 * @return bool
 */
function wc_stripe_order_status_completed( $order_id = null ) {
	global $woocommerce;

	if ( ! $order_id ) {
		$order_id = $_POST['order_id'];
	}

	$data = get_post_meta( $order_id );
	$total = $data['_order_total'][0] * 100;

	$params = array();
	if( isset( $_POST['amount'] ) && $amount = $_POST['amount'] ) {
		$params['amount'] = round( $amount );
	}

	if( get_post_meta( $order_id, 'auth_capture', true ) ) {

		try {
			$transaction_id = get_post_meta( $order_id, 'transaction_id', true );

			$charge_response = wp_remote_post( $this->api_endpoint . 'v1/charges/' . $transaction_id . '/capture', array(
				'method'		=> 'POST',
				'headers' 		=> array(
					'Authorization' => 'Basic ' . base64_encode( get_post_meta( $order_id, 'key', true ) . ':' ),
				),
				'body'			=> $params,
				'timeout' 		=> 70,
				'sslverify' 	=> false,
				'user-agent' 	=> 'WooCommerce-Stripe',
			) );

			if ( is_wp_error( $charge_response ) ) {
				throw new Exception( __( 'There was a problem connecting to the payment gateway.', 'wc_stripe' ) );
			}

			if( empty( $charge_response['body'] ) ) {
				throw new Exception( __( 'Empty response.', 'wc_stripe' ) );
			}

			$charge = json_decode( $charge_response['body'] );

			// Handle response
			if ( ! empty( $charge->error ) ) {
				throw new Exception( __( $charge->error->message, 'wc_stripe' ) );
			} elseif ( empty( $charge->id ) ) {
				throw new Exception( __( 'Invalid response.', 'wc_stripe' ) );
			}
		} catch ( Exception $e ) {
			// There was an error
			$body = $e->getJsonBody();
			$err  = $body['error'];

			error_log( 'Stripe Error:' . $err['message'] . '\n' );
			wc_add_notice( __( 'Payment error:', 'wc_stripe') . $err['message'], 'error' );

			return null;
		}

		return true;
	}
}
add_action( 'woocommerce_order_status_processing_to_completed', 'wc_stripe_order_status_completed' );

/**
 * Handles posting notifications to the user when their credit card information is invalid
 *
 * @access public
 * @return void
 */
function validation_errors() {

	foreach( $_POST['errors'] as $error ) {
		$message = '';

		$message .= '<strong>';
		switch ( $error['field'] ) {
			case 'number':
				$message .= __( 'Credit Card Number', 'wc_stripe' );
				break;
			case 'expiration':
				$message .= __( 'Credit Card Expiration', 'wc_stripe' );
				break;
			case 'cvc':
				$message .= __( 'Credit Card CVC', 'wc_stripe' );
				break;
		}
		$message .= '</strong>';

		switch ( $error['type'] ) {
			case 'undefined':
				$message .= ' ' . __( 'is a required field', 'wc_stripe' );
				break;
			case 'invalid':
				$message = __( 'Please enter a valid', 'wc_stripe' ) . ' ' . $message;
				break;
		}
		$message .= '.';

		wc_add_notice( $message, 'error' );
	}

	if ( is_ajax() ) {

		ob_start();
		wc_print_notices();
		$messages = ob_get_clean();

		echo '<!--WC_STRIPE_START-->' . json_encode(
			array(
				'result'	=> 'failure',
				'messages' 	=> $messages,
				'refresh' 	=> isset( WC()->session->refresh_totals ) ? 'true' : 'false',
				'reload'    => isset( WC()->session->reload_checkout ) ? 'true' : 'false'
			)
		) . '<!--WC_STRIPE_END-->';

		unset( WC()->session->refresh_totals, WC()->session->reload_checkout );
		exit;
	}
	die();
}
add_action( 'wp_ajax_stripe_form_validation', 'validation_errors' );
add_action( 'wp_ajax_nopriv_stripe_form_validation', 'validation_errors' );