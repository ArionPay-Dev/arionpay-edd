<?php
/**
 * Plugin Name:       ArionPay for Easy Digital Downloads
 * Plugin URI:        https://arionpay.com
 * Description:       Accept Bitcoin, USDT, Litecoin, Ethereum, TRON and more in Easy Digital Downloads. The money goes straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            ArionPay
 * License:           MIT
 * Text Domain:       arionpay-for-edd
 *
 * Flow: checkout creates a pending EDD order and sends the customer to the ArionPay payment page. Only a signed
 * ArionPay webhook (invoice.paid) completes the order. Whether an invoice is paid is decided by ArionPay alone; this
 * plugin never compares crypto amounts.
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_EDD_VERSION', '1.0.0' );

if ( ! class_exists( '\ArionPay\Client', true ) ) {
	require_once __DIR__ . '/lib/arionpay-php/autoload.php';
}

final class ArionPay_EDD {

	const GATEWAY        = 'arionpay';
	const CURRENCIES     = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );
	const META_INVOICES  = '_arionpay_invoices';
	const META_EVENTS    = '_arionpay_events';
	const META_PAID      = '_arionpay_paid_invoice';
	const MAX_BODY_BYTES = 262144;

	public static function init() {
		add_filter( 'edd_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'edd_' . self::GATEWAY . '_cc_form', array( __CLASS__, 'checkout_text' ) );
		add_action( 'edd_gateway_' . self::GATEWAY, array( __CLASS__, 'process_purchase' ) );
		add_action( 'init', array( __CLASS__, 'listen' ), 20 );
		add_filter( 'edd_settings_sections_gateways', array( __CLASS__, 'settings_section' ) );
		add_filter( 'edd_settings_gateways', array( __CLASS__, 'settings' ) );
		add_action( 'edd_order_receipt_after_table', array( __CLASS__, 'receipt_box' ), 10, 2 );
		add_filter( 'edd_enabled_payment_gateways', array( __CLASS__, 'hide_when_unusable' ) );
		add_filter( 'pre_update_option_edd_settings', array( __CLASS__, 'keep_secrets' ), 10, 2 );
	}

	/**
	 * A secret field posted empty keeps the saved secret (the page never shows it). EDD drops settings posted empty,
	 * so they are put back just before WordPress stores the settings.
	 */
	public static function keep_secrets( $new, $old ) {
		if ( ! is_array( $new ) || ! is_array( $old ) || ! isset( $_POST['edd_settings'] ) || ! is_array( $_POST['edd_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php checked the nonce.
			return $new;
		}
		foreach ( array( 'arionpay_api_secret', 'arionpay_webhook_secret' ) as $key ) {
			$posted = isset( $_POST['edd_settings'][ $key ] ) ? trim( (string) wp_unslash( $_POST['edd_settings'][ $key ] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
			if ( '' === $posted && ! empty( $old[ $key ] ) ) {
				$new[ $key ] = $old[ $key ];
			} elseif ( null !== $posted && '' !== $posted ) {
				$new[ $key ] = $posted;
			}
		}
		return $new;
	}

	// ------------------------------------------------------------------ registration and settings

	public static function register_gateway( $gateways ) {
		$gateways[ self::GATEWAY ] = array(
			'admin_label'    => __( 'ArionPay (crypto)', 'arionpay-for-edd' ),
			'checkout_label' => self::option( 'title' ) ? self::option( 'title' ) : __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-edd' ),
			'supports'       => array(),
		);
		return $gateways;
	}

	/** Hide ArionPay when it cannot work: missing keys or a store currency ArionPay does not accept. */
	public static function hide_when_unusable( $gateways ) {
		if ( isset( $gateways[ self::GATEWAY ] ) && ( ! self::is_configured() || ! in_array( strtoupper( edd_get_currency() ), self::CURRENCIES, true ) ) ) {
			unset( $gateways[ self::GATEWAY ] );
		}
		return $gateways;
	}

	public static function settings_section( $sections ) {
		$sections[ self::GATEWAY ] = __( 'ArionPay', 'arionpay-for-edd' );
		return $sections;
	}

	public static function settings( $settings ) {
		$secret_note = __( 'Saved. Leave empty to keep it; paste a new value only to change it.', 'arionpay-for-edd' );
		$settings[ self::GATEWAY ] = array(
			'arionpay_header'         => array(
				'id'   => 'arionpay_header',
				'name' => '<strong>' . __( 'ArionPay', 'arionpay-for-edd' ) . '</strong>',
				'desc' => __( 'Your customers pay with Bitcoin, USDT, Litecoin, Ethereum, TRON and more. The money goes straight to your own wallet: ArionPay never holds it.', 'arionpay-for-edd' ),
				'type' => 'descriptive_text',
			),
			'arionpay_help'           => array(
				'id'   => 'arionpay_help',
				'name' => __( 'Keys', 'arionpay-for-edd' ),
				'desc' => __( 'In your ArionPay dashboard, open your store: copy the API key (pk_...) and the API secret (sk_...). On the Webhooks page, copy the signing secret (whsec_...). Nothing to paste into ArionPay: every invoice tells ArionPay where to send its payment updates. EDD\'s own Test Mode (Payments > General) makes ArionPay test invoices: no real money moves.', 'arionpay-for-edd' ),
				'type' => 'descriptive_text',
			),
			'arionpay_api_key'        => array(
				'id'   => 'arionpay_api_key',
				'name' => __( 'API key', 'arionpay-for-edd' ),
				'type' => 'text',
				'size' => 'regular',
			),
			'arionpay_api_secret'     => array(
				'id'   => 'arionpay_api_secret',
				'name' => __( 'API secret', 'arionpay-for-edd' ),
				'desc' => self::option( 'api_secret' ) ? $secret_note : __( 'Starts with sk_.', 'arionpay-for-edd' ),
				'type' => 'arionpay_secret',
			),
			'arionpay_webhook_secret' => array(
				'id'   => 'arionpay_webhook_secret',
				'name' => __( 'Webhook signing secret', 'arionpay-for-edd' ),
				'desc' => self::option( 'webhook_secret' ) ? $secret_note : __( 'Starts with whsec_.', 'arionpay-for-edd' ),
				'type' => 'arionpay_secret',
			),
			'arionpay_title'          => array(
				'id'          => 'arionpay_title',
				'name'        => __( 'Name at checkout', 'arionpay-for-edd' ),
				'type'        => 'text',
				'placeholder' => __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-edd' ),
			),
			'arionpay_debug'          => array(
				'id'   => 'arionpay_debug',
				'name' => __( 'Debug log', 'arionpay-for-edd' ),
				'desc' => __( 'Writes what the plugin does to the EDD debug log (Tools > Debug Log). Never writes your keys.', 'arionpay-for-edd' ),
				'type' => 'checkbox',
			),
			'arionpay_base_url'       => array(
				'id'          => 'arionpay_base_url',
				'name'        => __( 'ArionPay address', 'arionpay-for-edd' ),
				'desc'        => __( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-for-edd' ),
				'type'        => 'text',
				'placeholder' => 'https://merchant.arionpay.com',
			),
			'arionpay_webhook_url'    => array(
				'id'   => 'arionpay_webhook_url',
				'name' => __( 'Payment updates', 'arionpay-for-edd' ),
				'desc' => '<code>' . esc_html( self::webhook_url() ) . '</code><br>' . __( 'Optional: paste it on the ArionPay Webhooks page to use "Send test webhook" there.', 'arionpay-for-edd' )
					. ( 0 !== strpos( self::webhook_url(), 'https://' ) ? '<br><strong>' . __( 'Your site address does not start with https://. ArionPay only sends payment updates to https addresses, so orders will not be completed until your site uses https.', 'arionpay-for-edd' ) . '</strong>' : '' ),
				'type' => 'descriptive_text',
			),
		);
		return $settings;
	}

	public static function checkout_text() {
		echo '<fieldset id="edd_cc_fields" class="edd-do-validate"><p>' . esc_html__( 'After you confirm, you go to the secure ArionPay page to choose a coin and pay. Your purchase is completed as soon as the payment arrives.', 'arionpay-for-edd' ) . '</p>';
		if ( edd_is_test_mode() ) {
			echo '<p><strong>' . esc_html__( 'Test mode: no real money is taken.', 'arionpay-for-edd' ) . '</strong></p>';
		}
		echo '</fieldset>';
	}

	// ------------------------------------------------------------------ checkout

	public static function process_purchase( $purchase_data ) {
		if ( ! wp_verify_nonce( $purchase_data['gateway_nonce'], 'edd-gateway' ) ) {
			wp_die( esc_html__( 'Nonce verification has failed', 'arionpay-for-edd' ), esc_html__( 'Error', 'arionpay-for-edd' ), array( 'response' => 403 ) );
		}
		$payment_id = edd_insert_payment(
			array(
				'price'        => $purchase_data['price'],
				'date'         => $purchase_data['date'],
				'user_email'   => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency'     => edd_get_currency(),
				'downloads'    => $purchase_data['downloads'],
				'user_info'    => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'gateway'      => self::GATEWAY,
				'status'       => 'pending',
			)
		);
		if ( ! $payment_id ) {
			edd_record_gateway_error( 'ArionPay', 'The pending order could not be created.' );
			edd_set_error( 'arionpay_error', __( 'Your order could not be created. Please try again.', 'arionpay-for-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::GATEWAY );
			return;
		}
		$order = edd_get_order( $payment_id );
		try {
			$url = self::invoice_url( $order );
		} catch ( \Throwable $e ) {
			self::log( 'Could not create an ArionPay invoice for order ' . $payment_id . ': ' . $e->getMessage() );
			edd_insert_payment_note( $payment_id, 'ArionPay: the crypto payment could not be started (' . $e->getMessage() . '). The customer was sent back to checkout.' );
			edd_update_payment_status( $payment_id, 'failed' );
			edd_set_error( 'arionpay_error', __( 'The crypto payment could not be started right now. Please try again in a minute, or choose another payment method.', 'arionpay-for-edd' ) );
			edd_send_back_to_checkout( '?payment-mode=' . self::GATEWAY );
			return;
		}
		edd_empty_cart();
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the ArionPay payment page.
		exit;
	}

	/**
	 * The payment page for this order: the open invoice when it still fits, otherwise a new one.
	 *
	 * @param \EDD\Orders\Order $order Order.
	 * @return string
	 * @throws \Throwable On API errors.
	 */
	public static function invoice_url( $order ) {
		$amount   = self::amount( $order );
		$currency = strtoupper( $order->currency );
		$test     = 'test' === $order->mode;
		$list     = self::json_meta( $order->id, self::META_INVOICES );
		$latest   = $list ? end( $list ) : null;
		if ( is_array( $latest ) && $latest['amount'] === $amount && $latest['currency'] === $currency && (bool) $latest['test'] === $test
			&& ! empty( $latest['expires'] ) && $latest['expires'] > time() + 300 ) {
			$remote = self::client()->getInvoice( $latest['id'] );
			if ( 'new' === $remote['status'] ) {
				return $latest['url'];
			}
		}

		$site    = substr( hash( 'sha256', home_url() . '|' . DB_NAME . '|' . $GLOBALS['wpdb']->prefix ), 0, 12 );
		$receipt = self::receipt_url( $order );
		$invoice = self::client()->createInvoice(
			array(
				'amount'         => $amount,
				'currency'       => $currency,
				'orderId'        => (string) $order->id,
				'description'    => mb_substr( sprintf( 'Order #%s at %s', $order->get_number(), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ), 0, 500 ),
				'successUrl'     => $receipt,
				'cancelUrl'      => $receipt,
				'callbackUrl'    => self::webhook_url(),
				'metadata'       => array(
					'edd_order_id'    => (int) $order->id,
					'edd_payment_key' => (string) $order->payment_key,
					'platform'        => 'easy-digital-downloads',
				),
				'idempotencyKey' => 'edd-' . $site . '-' . $order->id . '-' . ( count( $list ) + 1 ),
				'test'           => $test,
			)
		);
		$list[] = array(
			'id'       => $invoice['id'],
			'url'      => $invoice['invoiceUrl'],
			'amount'   => $amount,
			'currency' => $currency,
			'test'     => $test,
			'expires'  => ! empty( $invoice['expiresAt'] ) ? (int) strtotime( $invoice['expiresAt'] ) : 0,
		);
		edd_update_order_meta( $order->id, self::META_INVOICES, wp_json_encode( array_slice( $list, -20 ) ) );
		edd_insert_payment_note( $order->id, ( $test ? '[TEST - no real money] ' : '' ) . sprintf( 'Customer sent to ArionPay to pay invoice %s (%s %s).', $invoice['id'], $amount, $currency ) );
		self::log( 'Invoice ' . $invoice['id'] . ' created for order ' . $order->id . ' (' . $amount . ' ' . $currency . ( $test ? ', test' : '' ) . ').' );
		return $invoice['invoiceUrl'];
	}

	/**
	 * The order's receipt (works without the customer's session, by the order's receipt hash): shows the status and,
	 * while unpaid, a "Pay now" button.
	 */
	public static function receipt_url( $order ) {
		return function_exists( 'edd_get_receipt_page_uri' )
			? edd_get_receipt_page_uri( $order->id )
			: add_query_arg( 'payment_key', rawurlencode( $order->payment_key ), edd_get_success_page_uri() );
	}

	public static function receipt_box( $order, $args = array() ) {
		if ( ! $order || self::GATEWAY !== $order->gateway ) {
			return;
		}
		echo '<div class="arionpay-receipt" id="arionpay-receipt">';
		if ( in_array( $order->status, array( 'complete', 'publish' ), true ) ) {
			echo '<p><strong>' . esc_html__( 'Thank you: your crypto payment has been received.', 'arionpay-for-edd' ) . '</strong></p>';
		} elseif ( 'pending' === $order->status ) {
			$pay = add_query_arg(
				array(
					'edd-listener' => 'arionpay-pay',
					'key'          => rawurlencode( $order->payment_key ),
				),
				home_url( 'index.php' )
			);
			echo '<p>' . esc_html__( 'We have not received your crypto payment yet. If you already paid, there is nothing to do: this page updates as soon as the network confirms the payment. If you have not paid yet, you can pay now.', 'arionpay-for-edd' ) . '</p>';
			echo '<p><a class="button edd-submit" id="arionpay-pay-now" href="' . esc_url( $pay ) . '">' . esc_html__( 'Pay now with crypto', 'arionpay-for-edd' ) . '</a></p>';
		}
		if ( 'test' === $order->mode ) {
			echo '<p><em>' . esc_html__( 'Test mode: no real money is taken.', 'arionpay-for-edd' ) . '</em></p>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------------ listeners: webhook and "Pay now"

	public static function listen() {
		if ( ! isset( $_GET['edd-listener'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$listener = sanitize_key( wp_unslash( $_GET['edd-listener'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'arionpay' === $listener ) {
			self::webhook();
		} elseif ( 'arionpay-pay' === $listener ) {
			self::pay_now();
		}
	}

	private static function pay_now() {
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order = '' !== $key ? edd_get_order_by( 'payment_key', $key ) : false;
		if ( ! $order || self::GATEWAY !== $order->gateway || ! hash_equals( (string) $order->payment_key, $key ) ) {
			wp_safe_redirect( home_url() );
			exit;
		}
		if ( 'pending' !== $order->status ) {
			wp_safe_redirect( self::receipt_url( $order ) );
			exit;
		}
		try {
			wp_redirect( self::invoice_url( $order ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		} catch ( \Throwable $e ) {
			self::log( 'Could not create an ArionPay invoice for order ' . $order->id . ': ' . $e->getMessage() );
			wp_safe_redirect( self::receipt_url( $order ) );
		}
		exit;
	}

	private static function webhook() {
		nocache_headers();
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			self::respond( 405, array( 'error' => 'method_not_allowed' ) );
		}
		$secret = self::option( 'webhook_secret' );
		if ( '' === $secret ) {
			self::log( 'Webhook refused: the webhook signing secret is not set.' );
			self::respond( 503, array( 'error' => 'not_configured' ) );
		}
		$raw = file_get_contents( 'php://input', false, null, 0, self::MAX_BODY_BYTES + 1 );
		if ( false === $raw || strlen( $raw ) > self::MAX_BODY_BYTES ) {
			self::respond( 413, array( 'error' => 'payload_too_large' ) );
		}
		// The body is signed byte for byte, so it is read raw and never sanitized before this check.
		$header = isset( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ? (string) wp_unslash( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		try {
			$event = \ArionPay\Webhook::verify( $raw, $header, $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			self::log( '' === $header && isset( $_SERVER['HTTP_X_SIGNATURE'] ) ? 'Webhook refused: it has the old format. Turn off "old API (v1) compatibility" for this store in ArionPay.' : 'Webhook refused: ' . $e->getMessage() );
			self::respond( 401, array( 'error' => 'invalid_signature' ) );
		}
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			self::log( 'Verified ArionPay test webhook received (' . $event['type'] . ').' );
			self::respond( 200, array( 'received' => true, 'test_event' => true ) );
		}
		if ( ! in_array( $event['type'], \ArionPay\Webhook::EVENT_TYPES, true ) ) {
			self::respond( 200, array( 'received' => true, 'ignored' => 'unknown_event' ) );
		}
		$invoice  = $event['data']['invoice'];
		$metadata = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
		if ( empty( $metadata['edd_order_id'] ) ) {
			self::respond( 200, array( 'received' => true, 'ignored' => 'not_an_edd_invoice' ) );
		}
		$result = self::apply_locked( (int) $metadata['edd_order_id'], $event );
		self::log( 'Webhook ' . $event['type'] . ' for invoice ' . ( isset( $invoice['id'] ) ? $invoice['id'] : '?' ) . ': ' . $result . '.' );
		switch ( $result ) {
			case 'busy':
				header( 'Retry-After: 30' );
				self::respond( 503, array( 'error' => 'busy' ) );
				break;
			case 'mismatch':
				self::respond( 409, array( 'error' => 'invoice_order_mismatch' ) );
				break;
			case 'not_found':
				self::respond( 404, array( 'error' => 'order_not_found' ) );
				break;
			case 'duplicate':
				self::respond( 200, array( 'received' => true, 'duplicate' => true ) );
				break;
			default:
				self::respond( 200, array( 'received' => true ) );
		}
	}

	/**
	 * Apply one verified event to an order under a per-order lock.
	 *
	 * @return string processed, duplicate, busy, mismatch or not_found
	 */
	public static function apply_locked( $order_id, array $event ) {
		if ( ! self::lock( $order_id ) ) {
			return 'busy';
		}
		try {
			$order = edd_get_order( $order_id );
			if ( ! $order ) {
				return 'not_found';
			}
			$invoice  = $event['data']['invoice'];
			$id       = isset( $invoice['id'] ) ? (string) $invoice['id'] : '';
			$metadata = isset( $invoice['metadata'] ) && is_array( $invoice['metadata'] ) ? $invoice['metadata'] : array();
			$mine     = wp_list_pluck( self::json_meta( $order_id, self::META_INVOICES ), 'id' );
			if ( ! in_array( $id, $mine, true ) || ! hash_equals( (string) $order->payment_key, (string) ( isset( $metadata['edd_payment_key'] ) ? $metadata['edd_payment_key'] : '' ) )
				|| (string) ( isset( $invoice['orderId'] ) ? $invoice['orderId'] : '' ) !== (string) $order_id
				|| (bool) ( isset( $invoice['test'] ) ? $invoice['test'] : false ) !== ( 'test' === $order->mode ) ) {
				return 'mismatch';
			}
			$seen = self::json_meta( $order_id, self::META_EVENTS );
			if ( in_array( $event['id'], $seen, true ) ) {
				return 'duplicate';
			}
			$list       = self::json_meta( $order_id, self::META_INVOICES );
			$latest     = $list ? end( $list ) : array();
			$is_current = isset( $latest['id'] ) && $latest['id'] === $id;
			$paid       = self::is_paid( $order );

			switch ( $event['type'] ) {
				case 'invoice.paid':
					self::on_paid( $order, $invoice );
					break;
				case 'invoice.pending':
					if ( $is_current && ! $paid ) {
						edd_insert_payment_note( $order_id, self::text( 'ArionPay invoice {id}: {paid} {asset} was seen on the network and is waiting for confirmations.', $invoice ) );
					}
					break;
				case 'invoice.partial':
					if ( $is_current && ! $paid ) {
						edd_insert_payment_note( $order_id, self::text( 'ArionPay invoice {id}: partial payment of {paid} of {due} {asset}. The invoice stays open for the rest.', $invoice ) );
					}
					break;
				case 'invoice.expired':
					if ( $is_current && ! $paid ) {
						edd_insert_payment_note( $order_id, self::text( 'ArionPay invoice {id} expired without full payment. The order stays pending. A late payment can still arrive; if ArionPay accepts it, the order is completed. The customer can also pay again from the receipt page.', $invoice ) );
					}
					break;
				case 'invoice.needs_review':
					edd_insert_payment_note( $order_id, self::text( 'ArionPay invoice {id} needs your review in the ArionPay dashboard: {reason} The order status was not changed.', $invoice ) );
					break;
			}
			$seen[] = (string) $event['id'];
			edd_update_order_meta( $order_id, self::META_EVENTS, wp_json_encode( array_slice( $seen, -40 ) ) );
			return 'processed';
		} finally {
			self::unlock( $order_id );
		}
	}

	private static function on_paid( $order, array $invoice ) {
		if ( self::is_paid( $order ) ) {
			edd_insert_payment_note( $order->id, self::text( 'ArionPay reports invoice {id} as paid, but this order is already complete. If it was paid another way, this may be a double payment: check your wallet and refund the customer if needed.', $invoice ) );
			return;
		}
		if ( in_array( $order->status, array( 'refunded', 'revoked', 'partially_refunded' ), true ) ) {
			edd_insert_payment_note( $order->id, self::text( 'ArionPay reports invoice {id} as paid, but this order is refunded or revoked. The order was not changed.', $invoice ) );
			return;
		}
		// The invoice must still match the order: same currency, same total to the cent (not a payment tolerance).
		$expected = self::amount( $order );
		$paid     = isset( $invoice['amountFiat'] ) && is_numeric( $invoice['amountFiat'] ) ? number_format( (float) $invoice['amountFiat'], 2, '.', '' ) : '';
		$currency = strtoupper( isset( $invoice['currency'] ) ? (string) $invoice['currency'] : '' );
		if ( $currency !== strtoupper( $order->currency ) || $paid !== $expected ) {
			edd_insert_payment_note( $order->id, sprintf( 'ArionPay invoice %s is paid (%s %s), but that does not match this order\'s total (%s %s), so the order was NOT completed. Please check the order and the invoice.', $invoice['id'], '' !== $paid ? $paid : '?', $currency, $expected, strtoupper( $order->currency ) ) );
			return;
		}
		$txid = '';
		if ( ! empty( $invoice['transactions'] ) && is_array( $invoice['transactions'] ) ) {
			$last = end( $invoice['transactions'] );
			$txid = is_array( $last ) && isset( $last['txid'] ) && is_scalar( $last['txid'] ) ? (string) $last['txid'] : '';
		}
		edd_update_order_meta( $order->id, self::META_PAID, (string) $invoice['id'] );
		edd_set_payment_transaction_id( $order->id, '' !== $txid ? $txid : (string) $invoice['id'], $expected );
		edd_insert_payment_note( $order->id, self::paid_note( $invoice, $txid ) );
		edd_update_payment_status( $order->id, 'complete' );
	}

	// ------------------------------------------------------------------ helpers

	private static function is_paid( $order ) {
		$fresh = edd_get_order( $order->id );
		return '' !== (string) edd_get_order_meta( $order->id, self::META_PAID, true ) || ( $fresh && in_array( $fresh->status, array( 'complete', 'publish' ), true ) );
	}

	public static function amount( $order ) {
		return number_format( (float) $order->total, 2, '.', '' );
	}

	private static function paid_note( array $invoice, $txid ) {
		$parts = array( self::text( 'ArionPay invoice {id} is paid: {paid} {asset} received on {network}. Transaction: {tx}.', $invoice ) );
		if ( ! empty( $invoice['late'] ) ) {
			$parts[] = 'Paid late: the payment arrived after the invoice expired.';
		}
		if ( ! empty( $invoice['manual'] ) ) {
			$parts[] = 'You accepted this payment by hand in the ArionPay dashboard.';
		}
		if ( ! empty( $invoice['overpaid'] ) ) {
			$parts[] = 'The customer paid more than the invoice amount.';
		}
		if ( ! empty( $invoice['underpaidWithinTolerance'] ) ) {
			$parts[] = 'The customer paid slightly less, and ArionPay accepted it under your store\'s payment tolerance.';
		}
		if ( ! empty( $invoice['needsReview'] ) ) {
			$parts[] = 'ArionPay flagged this invoice for review: see your ArionPay dashboard.';
		}
		return implode( ' ', $parts );
	}

	private static function text( $template, array $invoice ) {
		$s  = function ( $k ) use ( $invoice ) {
			return isset( $invoice[ $k ] ) && is_scalar( $invoice[ $k ] ) ? mb_substr( wp_strip_all_tags( (string) $invoice[ $k ] ), 0, 300 ) : '';
		};
		$tx = '';
		if ( ! empty( $invoice['transactions'] ) && is_array( $invoice['transactions'] ) ) {
			$last = end( $invoice['transactions'] );
			$tx   = is_array( $last ) && isset( $last['txid'] ) && is_scalar( $last['txid'] ) ? (string) $last['txid'] : '';
		}
		$text = strtr(
			$template,
			array(
				'{id}'      => $s( 'id' ),
				'{paid}'    => $s( 'paidCrypto' ),
				'{due}'     => $s( 'amountCrypto' ),
				'{asset}'   => $s( 'asset' ),
				'{network}' => $s( 'network' ),
				'{reason}'  => '' !== $s( 'reviewReason' ) ? $s( 'reviewReason' ) : '-',
				'{tx}'      => '' !== $tx ? $tx : '-',
			)
		);
		return ( ! empty( $invoice['test'] ) ? '[TEST - no real money] ' : '' ) . $text;
	}

	private static function json_meta( $order_id, $key ) {
		$list = json_decode( (string) edd_get_order_meta( $order_id, $key, true ), true );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	public static function option( $name ) {
		return trim( (string) edd_get_option( 'arionpay_' . $name, '' ) );
	}

	public static function is_configured() {
		return '' !== self::option( 'api_key' ) && '' !== self::option( 'api_secret' ) && '' !== self::option( 'webhook_secret' );
	}

	public static function webhook_url() {
		return add_query_arg( 'edd-listener', 'arionpay', home_url( 'index.php' ) );
	}

	/** @return \ArionPay\Client */
	private static function client() {
		$base = self::option( 'base_url' );
		return new \ArionPay\Client(
			self::option( 'api_key' ),
			self::option( 'api_secret' ),
			array(
				'baseUrl' => '' !== $base ? $base : 'https://merchant.arionpay.com',
				'appInfo' => 'wordpress edd/' . ( defined( 'EDD_VERSION' ) ? EDD_VERSION : '?' ) . ' arionpay-edd/' . ARIONPAY_EDD_VERSION,
			)
		);
	}

	/** Atomic per-order lock: the insert itself is the lock (the technique WordPress core uses for upgrades). */
	private static function lock( $order_id ) {
		global $wpdb;
		$name = 'arionpay_edd_lock_' . (int) $order_id;
		for ( $try = 0; $try < 8; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) ) ) {
				return true;
			}
			$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $since > 0 && $since < time() - 60 ) {
				self::unlock( $order_id );
				continue;
			}
			sleep( 1 );
		}
		return false;
	}

	private static function unlock( $order_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'arionpay_edd_lock_' . (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	private static function respond( $status, array $body ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}

	private static function log( $message ) {
		if ( '1' === self::option( 'debug' ) && function_exists( 'edd_debug_log' ) ) {
			edd_debug_log( 'ArionPay: ' . $message, true );
		}
	}
}

/**
 * Secret field for the EDD settings page: never prints the saved value back into the page.
 *
 * @param array $args Field arguments.
 */
function edd_arionpay_secret_callback( $args ) {
	$saved = '' !== (string) edd_get_option( $args['id'], '' );
	echo '<input type="password" autocomplete="new-password" class="regular-text" id="edd_settings[' . esc_attr( $args['id'] ) . ']" name="edd_settings[' . esc_attr( $args['id'] ) . ']" value="" placeholder="' . esc_attr( $saved ? __( 'Saved', 'arionpay-for-edd' ) : '' ) . '"/>';
	if ( ! empty( $args['desc'] ) ) {
		echo '<p class="description">' . wp_kses_post( $args['desc'] ) . '</p>';
	}
}

add_action( 'plugins_loaded', function () {
	if ( function_exists( 'edd_get_option' ) ) {
		ArionPay_EDD::init();
	}
} );
