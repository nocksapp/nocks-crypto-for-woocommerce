<?php

class Nocks_WC_Helper_Data
{
    /**
     * Transient prefix. We can not use plugin slug because this
     * will generate to long keys for the wp_options table.
     *
     * @var string
     */
    const TRANSIENT_PREFIX = 'nocks-wc-';

    /**
     * @var Nocks_Object_Method[]|Nocks_Object_List|array
     */
    protected static $regular_api_methods = array();

    /**
     * @var Nocks_WC_Helper_Api
     */
    protected $api_helper;

    /**
     * @param Nocks_WC_Helper_Api $api_helper
     */
    public function __construct(Nocks_WC_Helper_Api $api_helper) {
        $this->api_helper = $api_helper;
    }

    /**
     * Get WooCommerce order
     *
     * @param int $order_id Order ID
     * @return WC_Order|bool
     */
    public function getWcOrder($order_id) {
        if (function_exists('wc_get_order')) {
            /**
             * @since WooCommerce 2.2
             */
            return wc_get_order($order_id);
        }

        $order = new WC_Order();

        if ($order->get_order($order_id)) {
            return $order;
        }

        return false;
    }

    /**
     * @param WC_Order $order
     * @return string
     */
    public function getOrderStatus(WC_Order $order) {
        if (method_exists($order, 'get_status')) {
            /**
             * @since WooCommerce 2.2
             */
            return $order->get_status();
        }

        return $order->status;
    }

    /**
     * Check if a order has a status
     *
     * @param string|string[] $status
     * @return bool
     */
    public function hasOrderStatus(WC_Order $order, $status) {
        if (method_exists($order, 'has_status')) {
            /**
             * @since WooCommerce 2.2
             */
            return $order->has_status($status);
        }

        if (!is_array($status)) {
            $status = array($status);
        }

        return in_array($this->getOrderStatus($order), $status);
    }

    /**
     * Get payment gateway class by order data.
     *
     * @param int|WC_Order $order
     * @return WC_Payment_Gateway|bool
     */
    public function getWcPaymentGatewayByOrder($order) {
        if (function_exists('wc_get_payment_gateway_by_order')) {
            /**
             * @since WooCommerce 2.2
             */
            return wc_get_payment_gateway_by_order($order);
        }

        if (WC()->payment_gateways()) {
            $payment_gateways = WC()->payment_gateways->payment_gateways();
        }
        else {
            $payment_gateways = array();
        }

        if (!($order instanceof WC_Order)) {
            $order = $this->getWcOrder($order);

            if (!$order) {
                return false;
            }
        }

        $order_payment_method = (version_compare(WC_VERSION, '3.0', '<')) ? $order->payment_method : $order->get_payment_method();

        return isset($payment_gateways[$order_payment_method]) ? $payment_gateways[$order_payment_method] : false;
    }

    /**
     * Called when page 'WooCommerce -> Checkout -> Checkout Options' is saved
     *
     * @see \Nocks_WC_Plugin::init
     */
    public function deleteTransients() {
        Nocks_WC_Plugin::debug(__METHOD__ . ': Nocks settings saved, delete transients');

        $transient_names = array(
            'api_methods_test',
            'api_methods_live',
        );

        $languages = array_keys(apply_filters('wpml_active_languages', array()));
        $languages[] = $this->getCurrentLocale();

        foreach ($transient_names as $transient_name) {
            foreach ($languages as $language) {
                delete_transient($this->getTransientId($transient_name . "_$language"));
            }
        }
    }

    /**
     * Get Nocks payment from cache or load from Nocks
     * Skip cache by setting $use_cache to false
     *
     * @param string $transaction_id
     * @param bool $use_cache (default: true)
     * @return Nocks_Transaction|null
     */
    public function getTransaction($transaction_id, $use_cache = true) {
        try {
            $transient_id = $this->getTransientId('transaction_' . $transaction_id);
            if ($use_cache) {
                $transaction = unserialize(get_transient($transient_id));
                if ($transaction && $transaction instanceof Nocks_Transaction) {
                    return $transaction;
                }
            }

            $transaction = $this->api_helper->getApiClient()->getTransaction($transaction_id);

            set_transient($transient_id, serialize($transaction), MINUTE_IN_SECONDS * 5);

            return $transaction;
        } catch (Exception $e) {
            Nocks_WC_Plugin::debug(__FUNCTION__ . ": Could not load transaction $transaction_id: " . $e->getMessage() . ' (' . get_class($e) . ')');
        }

        return null;
    }

    /**
     * @param bool|false $test_mode
     * @param bool|true $use_cache
     * @return array
     */
    public function getAllPaymentMethods($test_mode = false, $use_cache = true) {
        $result = $this->getRegularPaymentMethods($test_mode, $use_cache);
        return $result;
    }

    /**
     * @param bool $test_mode (default: false)
     * @param bool $use_cache (default: true)
     * @return array|Nocks_Object_List|Nocks_Object_Method[]
     */
    public function getRegularPaymentMethods($test_mode = false, $use_cache = true) {
        // Already initialized
        if ($use_cache && !empty(self::$regular_api_methods)) {
            return self::$regular_api_methods;
        }

        self::$regular_api_methods = $this->getApiPaymentMethods($test_mode, $use_cache);

        return self::$regular_api_methods;
    }

    /**
     * @param bool $test_mode (default: false)
     * @param string $method
     * @return Nocks_Object_Method|null
     */
    public function getPaymentMethod($test_mode = false, $method) {
        $payment_methods = $this->getAllPaymentMethods($test_mode);

        foreach ($payment_methods as $payment_method) {
            if ($payment_method->id == $method) {
                return $payment_method;
            }
        }

        return null;
    }

    /**
     * Save active Nocks payment id for order
     *
     * @param int $order_id
     * @param string $payment
     * @return $this
     */
    public function setActiveNocksPayment($order_id, $payment) {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            update_post_meta($order_id, '_nocks_payment_id', $payment, $single = true);
            delete_post_meta($order_id, '_nocks_cancelled_payment_id');
        }
        else {
            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $order->update_meta_data('_nocks_payment_id', $payment);
            $order->delete_meta_data('_nocks_cancelled_payment_id');

            $order->save();
        }

        return $this;
    }

    /**
     * Delete active Nocks payment id for order
     *
     * @param int $order_id
     * @param string $payment_id
     *
     * @return $this
     */
    public function unsetActiveNocksPayment($order_id, $payment_id = null) {

        if (version_compare(WC_VERSION, '3.0', '<')) {

            // Only remove Nocks payment details if they belong to this payment, not when a new payment was already placed
            $nocks_payment_id = get_post_meta($order_id, '_nocks_payment_id', $single = true);

            if ($nocks_payment_id == $payment_id) {
                delete_post_meta($order_id, '_nocks_payment_id');
                delete_post_meta($order_id, '_nocks_payment_mode');
            }
        }
        else {

            // Only remove Nocks payment details if they belong to this payment, not when a new payment was already placed
            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $nocks_payment_id = $order->get_meta('_nocks_payment_id', true);

            if ($nocks_payment_id == $payment_id) {
                $order->delete_meta_data('_nocks_payment_id');
                $order->delete_meta_data('_nocks_payment_mode');
                $order->save();
            }
        }

        return $this;
    }

    /**
     * Get active Nocks transaction id for order
     *
     * @param int $order_id
     * @return string
     */
    public function getActiveNocksTransactionId($order_id) {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            $nocks_payment_id = get_post_meta($order_id, 'nocks_transaction_id', $single = true);
        }
        else {
            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $nocks_payment_id = $order->get_meta('nocks_transaction_id', true);
        }

        return $nocks_payment_id;
    }

    /**
     * @param int $order_id
     * @param bool $use_cache
     * @return null|Nocks_Transaction
     */
    public function getActiveNocksTransaction($order_id, $use_cache = true) {
        if ($this->hasActiveNocksTransaction($order_id)) {
            return $this->getTransaction($this->getActiveNocksTransactionId($order_id), $use_cache);
        }

        return null;
    }

    /**
     * Check if the order has an active Nocks transaction
     *
     * @param int $order_id
     * @return bool
     */
    public function hasActiveNocksTransaction($order_id) {
        $nocks_transaction_id = $this->getActiveNocksTransactionId($order_id);

        return !empty($nocks_transaction_id);
    }

    /**
     * @param int $order_id
     * @param string $payment_id
     * @return $this
     */
    public function setCancelledNocksPaymentId($order_id, $payment_id) {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            add_post_meta($order_id, '_nocks_cancelled_payment_id', $payment_id, $single = true);
        }
        else {
            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $order->update_meta_data('_nocks_cancelled_payment_id', $payment_id);
            $order->save();
        }

        return $this;
    }

    /**
     * @param int $order_id
     *
     * @return null
     */
    public function unsetCancelledNocksPaymentId($order_id) {

        // If this order contains a cancelled (previous) payment, remove it.
        if (version_compare(WC_VERSION, '3.0', '<')) {
            $nocks_cancelled_payment_id = get_post_meta($order_id, '_nocks_cancelled_payment_id', $single = true);

            if (!empty($nocks_cancelled_payment_id)) {
                delete_post_meta($order_id, '_nocks_cancelled_payment_id');
            }
        }
        else {

            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $nocks_cancelled_payment_id = $order->get_meta('_nocks_cancelled_payment_id', true);

            if (!empty($nocks_cancelled_payment_id)) {
                $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
                $order->delete_meta_data('_nocks_cancelled_payment_id');
                $order->save();
            }
        }

        return null;
    }

    /**
     * @param int $order_id
     * @return string|false
     */
    public function getCancelledNocksPaymentId($order_id) {
        if (version_compare(WC_VERSION, '3.0', '<')) {
            $nocks_cancelled_payment_id = get_post_meta($order_id, '_nocks_cancelled_payment_id', $single = true);
        }
        else {
            $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder($order_id);
            $nocks_cancelled_payment_id = $order->get_meta('_nocks_cancelled_payment_id', true);
        }

        return $nocks_cancelled_payment_id;
    }

    /**
     * Check if the order has been cancelled
     *
     * @param int $order_id
     * @return bool
     */
    public function hasCancelledNocksPayment($order_id) {
        $cancelled_payment_id = $this->getCancelledNocksPaymentId($order_id);

        return !empty($cancelled_payment_id);
    }

    /**
     * @param WC_Order $order
     */
    public function restoreOrderStock(WC_Order $order) {
        foreach ($order->get_items() as $item) {
            if ($item['product_id'] > 0) {
                $product = (version_compare(WC_VERSION, '3.0', '<')) ? $order->get_product_from_item($item) : $item->get_product();

                if ($product && $product->exists() && $product->managing_stock()) {
                    $old_stock = (version_compare(WC_VERSION, '3.0', '<')) ? $product->stock : $product->get_stock_quantity();

                    $qty = apply_filters('woocommerce_order_item_quantity', $item['qty'], $order, $item);

                    $new_quantity = (version_compare(WC_VERSION, '3.0', '<')) ? $product->increase_stock($qty) : wc_update_product_stock($product, $qty, 'increase');

                    do_action('woocommerce_auto_stock_restored', $product, $item);

                    $order->add_order_note(sprintf(__('Item #%s stock incremented from %s to %s.', 'woocommerce'), $item['product_id'], $old_stock, $new_quantity));

                    if (version_compare(WC_VERSION, '3.0', '<')) {
                        $order->send_stock_notifications($product, $new_quantity, $item['qty']);
                    }
                }
            }
        }

        // Mark order stock as not-reduced
        if (version_compare(WC_VERSION, '3.0', '<')) {
            delete_post_meta($order->get_id(), '_order_stock_reduced');
        }
        else {
            $order->delete_meta_data('_order_stock_reduced');
            $order->save();
        }
    }

    /**
     * Get current locale
     *
     * @return string
     */
    public function getCurrentLocale() {
        return apply_filters('wpml_current_language', get_locale());
    }

    /**
     * @param string $transient
     * @return string
     */
    public function getTransientId($transient) {
        global $wp_version;

        /*
         * WordPress will save two options to wp_options table:
         * 1. _transient_<transient_id>
         * 2. _transient_timeout_<transient_id>
         */
        $transient_id = self::TRANSIENT_PREFIX . $transient;
        $option_name = '_transient_timeout_' . $transient_id;
        $option_name_length = strlen($option_name);

        $max_option_name_length = 191;

        /**
         * Prior to WooPress version 4.4.0, the maximum length for wp_options.option_name is 64 characters.
         * @see https://core.trac.wordpress.org/changeset/34030
         */
        if ($wp_version < '4.4.0') {
            $max_option_name_length = 64;
        }

        if ($option_name_length > $max_option_name_length) {
            trigger_error("Transient id $transient_id is to long. Option name $option_name ($option_name_length) will be to long for database column wp_options.option_name which is varchar($max_option_name_length).", E_USER_WARNING);
        }

        return $transient_id;
    }

    protected function getApiPaymentMethods($test_mode = false, $use_cache = true, $filters = array()) {
        $result = array();

        $locale = $this->getCurrentLocale();
        try {
            $filtersKey = implode('_', array_keys($filters));
            $transient_id = $this->getTransientId('api_methods_' . ($test_mode ? 'test' : 'live') . "_$locale" . $filtersKey);

            if ($use_cache) {
                $cached = unserialize(get_transient($transient_id));

                if ($cached && $cached instanceof Nocks_Object_List) {
                    return $cached;
                }
            }

            $result = array();


            return $result;
        } catch (Nocks_Exception $e) {
            Nocks_WC_Plugin::debug(__FUNCTION__ . ": Could not load Nocks methods (" . ($test_mode ? 'test' : 'live') . "): " . $e->getMessage() . ' (' . get_class($e) . ')');
        }

        return $result;
    }
}
