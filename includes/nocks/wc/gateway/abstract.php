<?php
abstract class Nocks_WC_Gateway_Abstract extends WC_Payment_Gateway
{
    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
	const STATUS_ON_HOLD    = 'on-hold';
	const STATUS_COMPLETED  = 'completed';
    const STATUS_CANCELLED  = 'cancelled';
    const STATUS_FAILED     = 'failed';

    /**
     * @var string
     */
    protected $default_title;

    /**
     * @var string
     */
    protected $default_description;

    /**
     * @var bool
     */
    protected $display_logo;

    /**
     * Minimum transaction amount, zero does not define a minimum
     *
     * @var int
     */
    public $min_amount = 0;

    /**
     * Maximum transaction amount, zero does not define a maximum
     *
     * @var int
     */
    public $max_amount = 0;

    /**
     *
     */
    public function __construct ()
    {
        // No plugin id, gateway id is unique enough
        $this->plugin_id    = '';
        // Use gateway class name as gateway id
        $this->id           = strtolower(get_class($this));
        // Set gateway title (visible in admin)
        $this->method_title = 'Nocks - ' . $this->getDefaultTitle();
        $this->method_description = $this->getSettingsDescription();

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        $this->title        = $this->get_option('title');
        $this->display_logo = $this->get_option('display_logo') == 'yes';

        $this->_initDescription();
        $this->_initIcon();
        $this->_initMinMaxAmount();

        if(!has_action('woocommerce_thankyou_' . $this->id)) {
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        add_action('woocommerce_api_' . $this->id, array($this, 'webhookAction'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_email_after_order_table', array($this, 'displayInstructions'), 10, 3);

        if (!$this->isValidForUse())
        {
            // Disable gateway if it's not valid for use
            $this->enabled = false;
        }
    }

    /**
     * Initialise Gateway Settings Form Fields
     */
    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title'       => __('Enable/Disable', 'nocks-crypto-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => sprintf(__('Enable %s', 'nocks-crypto-for-woocommerce'), $this->getDefaultTitle()),
                'default'     => 'yes'
            ),
            'address' => array(
	            'title'       => __('Address', 'nocks-crypto-for-woocommerce'),
	            'type'        => 'text',
	            'label'       => sprintf(__('Receive address for %s', 'nocks-crypto-for-woocommerce'), $this->getDefaultTitle()),
	            'default'     => ''
            ),
            'title' => array(
                'title'       => __('Title', 'nocks-crypto-for-woocommerce'),
                'type'        => 'text',
                'description' => sprintf(__('This controls the title which the user sees during checkout. Default <code>%s</code>', 'nocks-crypto-for-woocommerce'), $this->getDefaultTitle()),
                'default'     => $this->getDefaultTitle(),
                'desc_tip'    => true,
            ),
            'display_logo' => array(
                'title'       => __('Display logo', 'nocks-crypto-for-woocommerce'),
                'type'        => 'checkbox',
                'label'       => __('Display logo on checkout page. Default <code>enabled</code>', 'nocks-crypto-for-woocommerce'),
                'default'     => 'yes'
            ),
            'description' => array(
                'title'       => __('Description', 'nocks-checkout-for-woocommerce'),
                'type'        => 'textarea',
                'description' => sprintf(__('Payment method description that the customer will see on your checkout. Default <code>%s</code>', 'nocks-checkout-for-woocommerce'), $this->getDefaultDescription()),
                'default'     => $this->getDefaultDescription(),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * @return string
     */
    public function getIconUrl ()
    {
        return Nocks_WC_Plugin::getPluginUrl('assets/images/' . $this->getNocksMethodId() . '.png');
    }

    protected function _initIcon ()
    {
        if ($this->display_logo)
        {
            $default_icon = $this->getIconUrl();
            $this->icon   = apply_filters($this->id . '_icon_url', $default_icon);
        }
    }

    protected function _initDescription ()
    {
	    $this->description = $this->get_option('description', $this->getDefaultDescription());
    }

    protected function _initMinMaxAmount ()
    {
        if ($nocks_method = $this->getNocksMethod())
        {
            $this->min_amount = $nocks_method->getMinimumAmount() ? $nocks_method->getMinimumAmount() : 0;
            $this->max_amount = $nocks_method->getMaximumAmount() ? $nocks_method->getMaximumAmount() : 0;
        }
    }

    public function admin_options ()
    {
        if (!$this->enabled && count($this->errors))
        {
            echo '<div class="inline error"><p><strong>' . __('Gateway Disabled', 'nocks-crypto-for-woocommerce') . '</strong>: '
                . implode('<br/>', $this->errors)
                . '</p></div>';

            return;
        }

        parent::admin_options();
    }

    /**
     * Check if this gateway can be used
     *
     * @return bool
     */
    protected function isValidForUse()
    {
	    if (!$this->isCurrencySupported())
        {
            $this->errors[] = sprintf(
            /* translators: Placeholder 1: WooCommerce currency, placeholder 2: Supported Nocks currencies */
                __('Shop currency %s not supported by Nocks. Nocks only supports: %s.', 'nocks-crypto-for-woocommerce'),
                get_woocommerce_currency(),
                implode(', ', $this->getSupportedCurrencies())
            );

            return false;
        }

        return true;
    }

    /**
     * Check if the gateway is available for use
     *
     * @return bool
     */
    public function is_available()
    {
        if (!parent::is_available())
        {
            return false;
        }

	    if (!$this->get_option('address'))
	    {
		    return false;
	    }

        if (WC()->cart && $this->get_order_total() > 0)
        {
            // Validate min amount
            if (0 < $this->min_amount && $this->min_amount > $this->get_order_total())
            {
                return false;
            }

            // Validate max amount
            if (0 < $this->max_amount && $this->max_amount < $this->get_order_total())
            {
                return false;
            }
        }

        return true;
    }

    /**
     * @param int $order_id
     * @return array
     */
    public function process_payment ($order_id)
    {
        $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);

        if (!$order) {
            Nocks_WC_Plugin::debug($this->id . ': Could not process payment, order ' . $order_id . ' not found.');

            Nocks_WC_Plugin::addNotice(sprintf(__('Could not load order %s', 'nocks-crypto-for-woocommerce'), $order_id), 'error');

            return array('result' => 'failure');
        }

	    $initial_order_status = $this->getInitialOrderStatus();

        // Overwrite plugin-wide
        $initial_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_initial_order_status', $initial_order_status);

        // Overwrite gateway-wide
        $initial_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_initial_order_status_' . $this->id, $initial_order_status);

        $paymentRequestData = $this->getPaymentRequestData($order);

        $data = array_filter($paymentRequestData);
        $data = apply_filters('woocommerce_' . $this->id . '_args', $data, $order);

        try {
	        Nocks_WC_Plugin::debug( $this->id . ': Create payment for order ' . $data['reference'], true );
            do_action(Nocks_WC_Plugin::PLUGIN_ID . '_create_payment', $data, $order);

	        $transaction = Nocks_WC_Plugin::getApiHelper()->getApiClient()->createTransaction($data);

            if(isset($transaction['data']) && $transaction['status'] == 201 && isset($transaction['data']['uuid']))
            {
                $transaction_id = $transaction['data']['uuid'];//$nocks_checkout_transaction['success']['transactionId'];
                $payment_id = $transaction['data']['payments']["data"][0]['uuid'];

                $this->updateOrderStatus($order, $initial_order_status, 'Nocks Crypto transaction ID created: '.$transaction_id);
                update_post_meta( $order_id, 'nocks_transaction_id', $transaction_id);
                update_post_meta( $order_id, 'nocks_payment_id', $payment_id);
            } else {
                exit;
            }

            $this->saveNocksInfo($order, $payment_id);

            do_action(Nocks_WC_Plugin::PLUGIN_ID . '_payment_created', $payment_id, $order);
	        Nocks_WC_Plugin::debug( $this->id . ': Payment ' . $payment_id . ' created for order ' . $data['reference'] );

	        $order->add_order_note(sprintf(
            /* translators: Placeholder 1: Payment method title, placeholder 2: payment ID */
                __('%s payment started (%s).', 'nocks-crypto-for-woocommerce'),
                $this->method_title,
                $payment_id
            ));

            return array(
                'result'   => 'success',
                'redirect' => $transaction['data']['payments']['data'][0]['metadata']['url'],
            );
        }
        catch (Nocks_Exception $e) {
	        Nocks_WC_Plugin::debug( $this->id . ': Failed to create payment for order ' . $data['reference'] . ': ' . $e->getMessage() );
            $message = sprintf(__('Could not create %s payment.', 'nocks-crypto-for-woocommerce'), $this->title);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                $message .= ' ' . $e->getMessage();
            }

            Nocks_WC_Plugin::addNotice($message, 'error');
        }

        return array('result' => 'failure');
    }

    /**
     * @param $order WC_Order
     * @param $payment
     */
    protected function saveNocksInfo($order, $payment)
    {
	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    // Set active Nocks payment
		    Nocks_WC_Plugin::getDataHelper()->setActiveNocksPayment($order->id, $payment);

	    } else {
		    // Set active Nocks payment
		    Nocks_WC_Plugin::getDataHelper()->setActiveNocksPayment($order->get_id(), $payment);

	    }
    }

    /**
     * @param $order
     * @return array
     */
    protected function getPaymentRequestData($order)
    {
    	$data = [
    		'amount' => $order->get_total(),
		    'currency' => get_woocommerce_currency(),
		    'method' => $this->getNocksMethodId(),
		    'source_currency' => $this->getSourceCurrency(),
		    'target_address' => $this->get_option('address'),
		    'redirectUrl' => $this->getReturnUrl($order),
		    'webhookUrl' => $this->getWebhookUrl($order),
	    ];

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $paymentRequestData = array(
			    'billingAddress'  => $order->billing_address_1,
			    'billingCity'     => $order->billing_city,
			    'billingRegion'   => $order->billing_state,
			    'billingPostal'   => $order->billing_postcode,
			    'billingCountry'  => $order->billing_country,
			    'shippingAddress' => $order->shipping_address_1,
			    'shippingCity'    => $order->shipping_city,
			    'shippingRegion'  => $order->shipping_state,
			    'shippingPostal'  => $order->shipping_postcode,
			    'shippingCountry' => $order->shipping_country,
			    'reference'       => $order->id,
		    );
	    } else {
		    $paymentRequestData = array(
			    'billingAddress'  => $order->get_billing_address_1(),
			    'billingCity'     => $order->get_billing_city(),
			    'billingRegion'   => $order->get_billing_state(),
			    'billingPostal'   => $order->get_billing_postcode(),
			    'billingCountry'  => $order->get_billing_country(),
			    'shippingAddress' => $order->get_shipping_address_1(),
			    'shippingCity'    => $order->get_shipping_city(),
			    'shippingRegion'  => $order->get_shipping_state(),
			    'shippingPostal'  => $order->get_shipping_postcode(),
			    'shippingCountry' => $order->get_shipping_country(),
			    'reference'       => $order->get_id(),
		    );
	    }

        return array_merge($data, $paymentRequestData);
    }

	/**
	 * @param WC_Order $order
	 * @param string $new_status
	 * @param string $note
	 */
    public function updateOrderStatus (WC_Order $order, $new_status, $note = '')
    {
        $order->update_status($new_status, $note);

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {

		    switch ($new_status)
		    {
			    case self::STATUS_ON_HOLD:
				    if (!get_post_meta($order->id, '_order_stock_reduced', $single = true)) {
					    // Reduce order stock
					    $order->reduce_order_stock();

					    Nocks_WC_Plugin::debug(__METHOD__ . " Stock for order {$order->id} reduced.");
				    }

				    break;

			    case self::STATUS_FAILED:
			    case self::STATUS_CANCELLED:
				    if (get_post_meta($order->id, '_order_stock_reduced', $single = true))
				    {
					    // Restore order stock
					    Nocks_WC_Plugin::getDataHelper()->restoreOrderStock($order);

					    Nocks_WC_Plugin::debug(__METHOD__ . " Stock for order {$order->id} restored.");
				    }

				    break;
		    }

	    } else {

		    switch ($new_status)
		    {
			    case self::STATUS_ON_HOLD:

				    if (!$order->get_meta( '_order_stock_reduced', true)) {
					    // Reduce order stock
					    wc_reduce_stock_levels($order->get_id());

					    Nocks_WC_Plugin::debug(__METHOD__ . " Stock for order {$order->get_id()} reduced.");
				    }

				    break;

			    case self::STATUS_PENDING:
			    case self::STATUS_FAILED:
			    case self::STATUS_CANCELLED:
				    if ($order->get_meta( '_order_stock_reduced', true ))
				    {
					    // Restore order stock
					    Nocks_WC_Plugin::getDataHelper()->restoreOrderStock($order);

					    Nocks_WC_Plugin::debug(__METHOD__ . " Stock for order {$order->get_id()} restored.");
				    }

				    break;
		    }

	    }
    }


    public function webhookAction ()
    {
        // Webhook test by Nocks
        if (isset($_GET['testByNocks']))
        {
            Nocks_WC_Plugin::debug(__METHOD__ . ': Webhook tested by Nocks.', true);
            return;
        }

        if (empty($_GET['order_id']) || empty($_GET['key']))
        {
            Nocks_WC_Plugin::setHttpResponseCode(400);
            Nocks_WC_Plugin::debug(__METHOD__ . ":  No order ID or order key provided.");
            return;
        }

	    $order_id = sanitize_text_field( $_GET['order_id'] );
	    $key      = sanitize_text_field( $_GET['key'] );

        $data_helper = Nocks_WC_Plugin::getDataHelper();
        $order       = $data_helper->getWcOrder($order_id);

        if (!$order)
        {
            Nocks_WC_Plugin::setHttpResponseCode(404);
            Nocks_WC_Plugin::debug(__METHOD__ . ":  Could not find order $order_id.");
            return;
        }

        if (!$order->key_is_valid($key))
        {
            Nocks_WC_Plugin::setHttpResponseCode(401);
            Nocks_WC_Plugin::debug(__METHOD__ . ":  Invalid key $key for order $order_id.");
            return;
        }

        // Load the payment from Nocks, do not use cache
        $transaction = $data_helper->getActiveNocksTransaction($order_id, $use_cache = false);

        // Payment not found
        if (!$transaction)
        {
            Nocks_WC_Plugin::setHttpResponseCode(404);
            Nocks_WC_Plugin::debug(__METHOD__ . ": payment $order_id not found.", true);
            return;
        }

        // Order does not need a payment
        if (!$this->orderNeedsPayment($order))
        {
            $this->handlePayedOrderWebhook($order, $transaction);
            return;
        }

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    Nocks_WC_Plugin::debug($this->id . ": Nocks payment {$transaction->id} webhook call for order {$order->id}.", true);
	    } else {
		    Nocks_WC_Plugin::debug($this->id . ": Nocks payment {$transaction->id} webhook call for order {$order->get_id()}.", true);
	    }

	    if ($transaction->isPaid()) {
	    	$this->onWebhookCompleted($order, $transaction);
	    } else if ($transaction->isCancelled()) {
		    $this->onWebhookCancelled($order, $transaction);
	    } else if ($transaction->isExpired()) {
	    	$this->onWebhookExpired($order, $transaction);
	    } else {
		    $order->add_order_note(sprintf(
		    /* translators: Placeholder 1: payment method title, placeholder 2: payment status, placeholder 3: payment ID */
			    __('%s payment %s (%s).', 'nocks-crypto-for-woocommerce'),
			    $this->method_title,
			    $payment->status,
			    $payment->id . ($payment->mode == 'test' ? (' - ' . __('test mode', 'nocks-crypto-for-woocommerce')) : '')
		    ));
	    }
    }

    /**
     * @param $order
     * @param $payment
     */
	protected function handlePayedOrderWebhook( $order, $payment ) {
		// Duplicate webhook call
		Nocks_WC_Plugin::setHttpResponseCode( 204 );

		if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
			$order_id = $order->id;
		} else {
			$order    = Nocks_WC_Plugin::getDataHelper()->getWcOrder( $order );
			$order_id = $order->get_id();
		}

		Nocks_WC_Plugin::debug( $this->id . ": Order $order_id does not need a payment (payment webhook {$payment->id}).", true );

	}

    /**
     * @param WC_Order $order
     * @param $transaction
     */
    protected function onWebhookCompleted(WC_Order $order, $transaction)
    {
	    // Get order ID in the correct way depending on WooCommerce version
	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $order_id = $order->id;
	    } else {
		    $order_id = $order->get_id();
	    }

	    // Add messages to log
	    Nocks_WC_Plugin::debug( __METHOD__ . ' called for order ' . $order_id );

        // WooCommerce 2.2.0 has the option to store the Payment transaction id.
        $woo_version = get_option('woocommerce_version', 'Unknown');

        if (version_compare($woo_version, '2.2.0', '>='))
        {
            $order->payment_complete($payment->id);
        }
        else
        {
            $order->payment_complete();
        }

        $paymentMethodTitle = $this->getPaymentMethodTitle($payment);
        $order->add_order_note(sprintf(
        /* translators: Placeholder 1: payment method title, placeholder 2: payment ID */
            __('Order completed using %s payment (%s).', 'nocks-crypto-for-woocommerce'),
            $paymentMethodTitle,
            $payment->id . ($payment->mode == 'test' ? (' - ' . __('test mode', 'nocks-crypto-for-woocommerce')) : '')
        ));

	    // Remove (old) cancelled payments from this order
	    Nocks_WC_Plugin::getDataHelper()->unsetCancelledNocksPaymentId( $order_id );

    }

    /**
     * @param $payment
     * @return string
     */
    protected function getPaymentMethodTitle($payment)
    {
        $paymentMethodTitle = '';
        if ($payment->method == $this->getNocksMethodId()){
            $paymentMethodTitle = $this->method_title;
        }
        return $paymentMethodTitle;
    }


    /**
     * @param WC_Order $order
     * @param Nocks_Object_Payment $transaction
     */
    protected function onWebhookCancelled(WC_Order $order, Nocks_Transaction $transaction)
    {

	    // Get order ID in the correct way depending on WooCommerce version
	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $order_id = $order->id;
	    } else {
		    $order_id = $order->get_id();
	    }

	    // Add messages to log
	    Nocks_WC_Plugin::debug( __METHOD__ . ' called for order ' . $order_id );

	    Nocks_WC_Plugin::getDataHelper()
		                    ->unsetActiveNocksPayment( $order_id, $transaction->id )
		                    ->setCancelledNocksPaymentId( $order_id, $transaction->id );


        // New order status
        $new_order_status = self::STATUS_PENDING;

        // Overwrite plugin-wide
        $new_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_order_status_cancelled', $new_order_status);

        // Overwrite gateway-wide
        $new_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_order_status_cancelled_' . $this->id, $new_order_status);

        // Reset state
        $this->updateOrderStatus($order, $new_order_status);

        $paymentMethodTitle = $this->getPaymentMethodTitle($transaction);

        // User cancelled payment, add a cancel note.. do not cancel order.
        $order->add_order_note(sprintf(
        /* translators: Placeholder 1: payment method title, placeholder 2: payment ID */
            __('%s payment cancelled (%s).', 'nocks-crypto-for-woocommerce'),
            $paymentMethodTitle,
            $payment->id
        ));
    }

    /**
     * @param WC_Order $order
     * @param Nocks_Object_Payment $payment
     */
    protected function onWebhookExpired(WC_Order $order, Nocks_Object_Payment $payment)
    {

	    // Get order ID in correct way depending on WooCommerce version
	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $order_id = $order->id;
		    $nocks_payment_id = get_post_meta( $order_id, '_nocks_payment_id', $single = true );
	    } else {
		    $order_id = $order->get_id();
		    $nocks_payment_id = $order->get_meta( '_nocks_payment_id', true );
	    }

	    // Add messages to log
	    Nocks_WC_Plugin::debug( __METHOD__ . ' called for order ' . $order_id );

	    // Get payment method title for use in log messages and order notes
	    $paymentMethodTitle = $this->getPaymentMethodTitle($payment);

	    // Check that this payment is the most recent, based on Nocks Payment ID from post meta, do not cancel the order if it isn't
	    if ( $nocks_payment_id != $payment->id) {
		    Nocks_WC_Plugin::debug( __METHOD__ . ' called for order ' . $order_id . ' and payment ' . $payment->id . ', not processed because of a newer pending payment ' . $nocks_payment_id );

		    $order->add_order_note(sprintf(
		    /* translators: Placeholder 1: payment method title, placeholder 2: payment ID */
			    __('%s payment expired (%s) but order not cancelled because of another pending payment (%s).', 'nocks-crypto-for-woocommerce'),
			    $paymentMethodTitle,
			    $payment->id . ($payment->mode == 'test' ? (' - ' . __('test mode', 'nocks-crypto-for-woocommerce')) : ''),
			    $nocks_payment_id
		    ));

	    	return;
	    }

        // New order status
        $new_order_status = self::STATUS_CANCELLED;

        // Overwrite plugin-wide
        $new_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_order_status_expired', $new_order_status);

        // Overwrite gateway-wide
        $new_order_status = apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_order_status_expired_' . $this->id, $new_order_status);

        // Cancel order
        $this->updateOrderStatus($order, $new_order_status);

        $order->add_order_note(sprintf(
        /* translators: Placeholder 1: payment method title, placeholder 2: payment ID */
            __('%s payment expired (%s).', 'nocks-crypto-for-woocommerce'),
            $paymentMethodTitle,
            $payment->id . ($payment->mode == 'test' ? (' - ' . __('test mode', 'nocks-crypto-for-woocommerce')) : '')
        ));

	    // Remove (old) cancelled payments from this order
	    Nocks_WC_Plugin::getDataHelper()->unsetCancelledNocksPaymentId( $order_id );

    }

    /**
     * @param WC_Order $order
     * @return string
     */
	public function getReturnRedirectUrlForOrder( WC_Order $order ) {
		$data_helper = Nocks_WC_Plugin::getDataHelper();


		if ( $this->orderNeedsPayment( $order ) ) {

			$hasCancelledNocksPayment = ( version_compare( WC_VERSION, '3.0', '<' ) ) ? $data_helper->hasCancelledNocksPayment( $order->id ) : $data_helper->hasCancelledNocksPayment( $order->get_id() );;

			if ( $hasCancelledNocksPayment ) {

				Nocks_WC_Plugin::addNotice( __( 'You have cancelled your payment. Please complete your order with a different payment method.', 'nocks-crypto-for-woocommerce' ) );

				if ( method_exists( $order, 'get_checkout_payment_url' ) ) {
					/*
					 * Return to order payment page
					 */
					return $order->get_checkout_payment_url( false );
				}

				/*
				 * Return to cart
				 */

				return WC()->cart->get_checkout_url();

			}
		}

		/*
		 * Return to order received page
		 */

		return $this->get_return_url( $order );
	}

    /**
     * Output for the order received page.
     */
    public function thankyou_page ($order_id)
    {
        $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);

        // Order not found
        if (!$order)
        {
            return;
        }

        // Empty cart
        if (WC()->cart) {
            WC()->cart->empty_cart();
        }

        // Same as email instructions, just run that
        $this->displayInstructions($order, $admin_instructions = false, $plain_text = false);
    }

    /**
     * Add content to the WC emails.
     *
     * @param WC_Order $order
     * @param bool     $admin_instructions (default: false)
     * @param bool     $plain_text (default: false)
     * @return void
     */
    public function displayInstructions(WC_Order $order, $admin_instructions = false, $plain_text = false)
    {
	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $order_payment_method = $order->payment_method;
	    } else {
		    $order_payment_method = $order->get_payment_method();
	    }

        // Invalid gateway
        if ($this->id !== $order_payment_method)
        {
            return;
        }

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $transaction = Nocks_WC_Plugin::getDataHelper()->getActiveNocksTransaction($order->id);
	    } else {
            $transaction = Nocks_WC_Plugin::getDataHelper()->getActiveNocksTransaction($order->get_id());
	    }
        // Nocks payment not found or invalid gateway
        if (!$transaction)
        {
            return;
        }



        $instructions = $this->getInstructions($order, $transaction, $admin_instructions, $plain_text);

        if (!empty($instructions))
        {
            $instructions = wptexturize($instructions);

            if ($plain_text)
            {
                echo $instructions . PHP_EOL;
            }
            else
            {
                echo '<h2>' . __('Payment', 'nocks-crypto-for-woocommerce') . '</h2>';
                echo wpautop($instructions) . PHP_EOL;
            }
        }
    }

    /**
     * @param WC_Order                  $order
     * @param Nocks_Transaction         $transaction
     * @param bool                      $admin_instructions
     * @param bool                      $plain_text
     * @return string|null
     */
    protected function getInstructions (WC_Order $order, $transaction, $admin_instructions, $plain_text)
    {
        // No definite payment status
        if ($transaction->isOpen())
        {
            if ($admin_instructions)
            {
                // Message to admin
                return __('We have not received a definite payment status.', 'nocks-crypto-for-woocommerce');
            }
            else
            {
                // Message to customer
                return __('We have not received a definite payment status. You will receive an email as soon as we receive a confirmation.', 'nocks-crypto-for-woocommerce');
            }
        } elseif ($transaction->isCancelled())
        {
            if ($admin_instructions)
            {
                // Message to admin
                return __('Your payment has been cancelled.', 'nocks-crypto-for-woocommerce');
            }
            else
            {
                // Message to customer
                return __('Your payment has been cancelled.', 'nocks-crypto-for-woocommerce');
            }
        }
        elseif ($transaction->isPaid())
        {
            return sprintf(
            /* translators: Placeholder 1: payment method */
                __('Payment completed with <strong>%s</strong>', 'nocks-crypto-for-woocommerce'),
                $this->get_title()
            );
        }

        return null;
    }

    /**
     * @param WC_Order $order
     * @return bool
     */
    protected function orderNeedsPayment (WC_Order $order)
    {
        if ($order->needs_payment())
        {
            return true;
        }

	    // Has initial order status 'on-hold'
	    if ($this->getInitialOrderStatus() === self::STATUS_ON_HOLD && Nocks_WC_Plugin::getDataHelper()->hasOrderStatus( $order, self::STATUS_ON_HOLD)) {
		    return true;
	    }

        return false;
    }

    /**
     * @return Nocks_Object_Method|null
     */
    public function getNocksMethod()
    {
        try
        {
            $test_mode = Nocks_WC_Plugin::getSettingsHelper()->isTestModeEnabled();

            return Nocks_WC_Plugin::getDataHelper()->getPaymentMethod(
                $test_mode,
                $this->getNocksMethodId()
            );
        }
        catch (Exception $e)
        {
        }

        return null;
    }

    /**
     * @param WC_Order $order
     * @return string
     */
    protected function getReturnUrl (WC_Order $order)
    {
        $site_url   = get_site_url();

	    $return_url = WC()->api_request_url('nocks_return');

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $return_url = add_query_arg(array(
			    'order_id'       => $order->id,
			    'key'            => $order->order_key,
		    ), $return_url);
	    } else {
		    $return_url = add_query_arg(array(
			    'order_id'       => $order->get_id(),
			    'key'            => $order->get_order_key(),
		    ), $return_url);
	    }

        $lang_url   = $this->getSiteUrlWithLanguage();
        $return_url = str_replace($site_url, $lang_url, $return_url);

        return apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_return_url', $return_url, $order);
    }

    /**
     * @param WC_Order $order
     * @return string
     */
    protected function getWebhookUrl (WC_Order $order)
    {
        $site_url    = get_site_url();

        $webhook_url = WC()->api_request_url(strtolower(get_class($this)));

	    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		    $webhook_url = add_query_arg(array(
			    'order_id' => $order->id,
			    'key'      => $order->order_key,
		    ), $webhook_url);
	    } else {
		    $webhook_url = add_query_arg(array(
			    'order_id' => $order->get_id(),
			    'key'      => $order->get_order_key(),
		    ), $webhook_url);
	    }

        $lang_url    = $this->getSiteUrlWithLanguage();
        $webhook_url = str_replace($site_url, $lang_url, $webhook_url);

        return apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_webhook_url', $webhook_url, $order);
    }

    /**
     * Check if any multi language plugins are enabled and return the correct site url.
     *
     * @return string
     */
    protected function getSiteUrlWithLanguage()
    {
        /**
         * function is_plugin_active() is not available. Lets include it to use it.
         */
        include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

        $site_url = get_site_url();
        $slug     = ''; // default is NO slug/language

        if (is_plugin_active('polylang/polylang.php')
            || is_plugin_active('mlang/mlang.php')
            || is_plugin_active('mlanguage/mlanguage.php')
        )
        {
            // we probably have a multilang site. Retrieve current language.
            $slug = get_bloginfo('language');
            $pos  = strpos($slug, '-');
            if ($pos !== false)
                $slug = substr($slug, 0, $pos);
                
            $slug = '/' . $slug;
        }

        return str_replace($site_url, $site_url . $slug, $site_url);
    }

    /**
     * @return array
     */
    protected function getSupportedCurrencies ()
    {
        $default = array('EUR', 'NLG');

        return apply_filters('woocommerce_' . $this->id . '_supported_currencies', $default);
    }

    /**
     * @return bool
     */
    protected function isCurrencySupported ()
    {
        return in_array(get_woocommerce_currency(), $this->getSupportedCurrencies());
    }

    /**
     * @return mixed
     */
    abstract public function getNocksMethodId ();

	/**
	 * @return string
	 */
	abstract public function getSourceCurrency ();

	public function getInitialOrderStatus()
	{
		return self::STATUS_PENDING;
	}

    /**
     * @return string
     */
    abstract public function getDefaultTitle ();

    /**
     * @return string
     */
    abstract protected function getSettingsDescription ();

    /**
     * @return string
     */
    abstract protected function getDefaultDescription ();
}
