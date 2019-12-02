<?php
// Require WooCommerce fallback functions
require_once dirname(dirname(dirname(__FILE__))) . '/woocommerce_functions.php';

class Nocks_WC_Plugin
{
    const PLUGIN_ID      = 'nocks-crypto-for-woocommerce';
    const PLUGIN_TITLE   = 'Nocks Crypto for WooCommerce';
    const PLUGIN_VERSION = '0.1.0';

    const DB_VERSION     = '1.0';
    const DB_VERSION_PARAM_NAME = 'nocks-db-version';
    const PENDING_PAYMENT_DB_TABLE_NAME = 'nocks_pending_payment';

    /**
     * @var bool
     */
    private static $initiated = false;

    /**
     * @var array
     */
    public static $GATEWAYS = array();

    private function __construct () {}

    /**
     *
     */
    public static function initDb()
    {
        global $wpdb;
        $wpdb->nocks_pending_payment = $wpdb->prefix . self::PENDING_PAYMENT_DB_TABLE_NAME;
        if(get_option(self::DB_VERSION_PARAM_NAME, '') != self::DB_VERSION){

            global $wpdb;
            $pendingPaymentConfirmTable = $wpdb->prefix . self::PENDING_PAYMENT_DB_TABLE_NAME;
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            if($wpdb->get_var("show tables like '$pendingPaymentConfirmTable'") != $pendingPaymentConfirmTable) {
                $sql = "
					CREATE TABLE " . $pendingPaymentConfirmTable . " (
                    id int(11) NOT NULL AUTO_INCREMENT,
                    post_id bigint NOT NULL,
                    expired_time int NOT NULL,
                    UNIQUE KEY id (id)
                );";
                dbDelta($sql);

	            /**
	             * Remove redundant 'DESCRIBE *__nocks_pending_payment' error so it doesn't show up in error logs
	             */
	            global $EZSQL_ERROR;
				array_pop($EZSQL_ERROR);
            }
            update_option(self::DB_VERSION_PARAM_NAME, self::DB_VERSION);
        }

    }

    /**
     *
     */
    public static function checkPendingPaymentOrdersExpiration()
    {
        global $wpdb;
        $currentDate = new DateTime();
        $items = $wpdb->get_results("SELECT * FROM {$wpdb->nocks_pending_payment} WHERE expired_time < {$currentDate->getTimestamp()};");
        foreach ($items as $item){
	        $order = Nocks_WC_Plugin::getDataHelper()->getWcOrder( $item->post_id );
            if ($order->get_status() == Nocks_WC_Gateway_Abstract::STATUS_COMPLETED){

                $new_order_status = Nocks_WC_Gateway_Abstract::STATUS_FAILED;
	            if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		            $paymentMethodId = get_post_meta( $order->get_id(), '_payment_method_title', true );
		            $nocksPaymentId = get_post_meta( $order->get_id(), '_nocks_payment_id', true );
	            } else {
		            $paymentMethodId = $order->get_meta( '_payment_method_title', true );
		            $nocksPaymentId = $order->get_meta( '_nocks_payment_id', true );
	            }
                $order->add_order_note(sprintf(
                /* translators: Placeholder 1: payment method title, placeholder 2: payment ID */
                    __('%s payment failed (%s).', 'nocks-crypto-for-woocommerce'),
                    $paymentMethodId,$nocksPaymentId
                ));

                $order->update_status($new_order_status, '');

	            if ( version_compare( WC_VERSION, '3.0', '<' ) ) {
		            if ( get_post_meta( $order->get_id(), '_order_stock_reduced', $single = true ) ) {
			            // Restore order stock
			            Nocks_WC_Plugin::getDataHelper()->restoreOrderStock( $order );

			            Nocks_WC_Plugin::debug( __METHOD__ . " Stock for order {$order->get_id()} restored." );
		            }

		            $wpdb->delete(
			            $wpdb->nocks_pending_payment,
			            array(
				            'post_id' => $order->get_id(),
			            )
		            );
	            } else {
		            if ( $order->get_meta( '_order_stock_reduced', $single = true ) ) {
			            // Restore order stock
			            Nocks_WC_Plugin::getDataHelper()->restoreOrderStock( $order );

			            Nocks_WC_Plugin::debug( __METHOD__ . " Stock for order {$order->get_id()} restored." );
		            }

		            $wpdb->delete(
			            $wpdb->nocks_pending_payment,
			            array(
				            'post_id' => $order->get_id(),
			            )
		            );
	            }
            }
        }

    }

    /**
     * Initialize plugin
     */
	public static function init() {
		if ( self::$initiated ) {
			/*
			 * Already initialized
			 */
			return;
		}

		$plugin_basename = self::getPluginFile();
		$settings_helper = self::getSettingsHelper();
		$data_helper     = self::getDataHelper();

		// When page 'WooCommerce -> Checkout -> Checkout Options' is saved
		add_action( 'woocommerce_settings_save_checkout', array ( $data_helper, 'deleteTransients' ) );

        // Add global Nocks settings to 'WooCommerce -> Checkout -> Checkout Options'
        add_filter( 'woocommerce_payment_gateways_settings', array ( $settings_helper, 'addGlobalSettingsFields' ) );

		// Add Nocks gateways
		add_filter( 'woocommerce_payment_gateways', array ( __CLASS__, 'addGateways' ) );

		// Add settings link to plugins page
		add_filter( 'plugin_action_links_' . $plugin_basename, array ( __CLASS__, 'addPluginActionLinks' ) );

		// Listen to return URL call
		add_action( 'woocommerce_api_nocks_return', array ( __CLASS__, 'onNocksReturn' ) );

		// On order details
		add_action( 'woocommerce_order_details_after_order_table', array ( __CLASS__, 'onOrderDetails' ), 10, 1 );

		self::initDb();
		// Mark plugin initiated
		self::$initiated = true;
	}

    /**
     * Payment return url callback
     */
    public static function onNocksReturn ()
    {
        $data_helper = self::getDataHelper();

	    $order_id = ! empty( $_GET['order_id'] ) ? sanitize_text_field( $_GET['order_id'] ) : null;
	    $key      = ! empty( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : null;

        $order    = $data_helper->getWcOrder($order_id);

        if (!$order)
        {
            self::setHttpResponseCode(404);
            self::debug(__METHOD__ . ":  Could not find order $order_id.");
            return;
        }

        if (!$order->key_is_valid($key))
        {
            self::setHttpResponseCode(401);
            self::debug(__METHOD__ . ":  Invalid key $key for order $order_id.");
            return;
        }

        $gateway = $data_helper->getWcPaymentGatewayByOrder($order);

        if (!$gateway)
        {
            self::setHttpResponseCode(404);
            self::debug(__METHOD__ . ":  Could not find gateway for order $order_id.");
            return;
        }

        if (!($gateway instanceof Nocks_WC_Gateway_Abstract))
        {
            self::setHttpResponseCode(400);
            self::debug(__METHOD__ . ": Invalid gateway " . get_class($gateway) . " for this plugin. Order $order_id.");
            return;
        }

        /** @var Nocks_WC_Gateway_Abstract $gateway */
        $redirect_url = $gateway->getReturnRedirectUrlForOrder($order);

        self::debug(__METHOD__ . ": Redirect url on return order " . $gateway->id . ", order $order_id: $redirect_url");

        wp_safe_redirect($redirect_url);
    }

    /**
     * @param WC_Order $order
     */
    public static function onOrderDetails (WC_Order $order)
    {
        if (is_order_received_page())
        {
            /**
             * Do not show instruction again below details on order received page
             * Instructions already displayed on top of order received page by $gateway->thankyou_page()
             *
             * @see Nocks_WC_Gateway_Abstract::thankyou_page
             */
            return;
        }

        $gateway = Nocks_WC_Plugin::getDataHelper()->getWcPaymentGatewayByOrder($order);
        if (!$gateway || !($gateway instanceof Nocks_WC_Gateway_Abstract))
        {
            return;
        }

        /** @var Nocks_WC_Gateway_Abstract $gateway */

        $gateway->displayInstructions($order);
    }

    /**
     * Set HTTP status code
     *
     * @param int $status_code
     */
    public static function setHttpResponseCode ($status_code)
    {
        if (PHP_SAPI !== 'cli' && !headers_sent())
        {
            if (function_exists("http_response_code"))
            {
                http_response_code($status_code);
            }
            else
            {
                header(" ", TRUE, $status_code);
            }
        }
    }

    /**
     * Add Nocks gateways
     *
     * @param array $gateways
     * @return array
     */
    public static function addGateways (array $gateways)
    {
    	if (empty(self::$GATEWAYS)) {
		    foreach (glob(dirname(__FILE__)."/gateway/*") as $gateway) {
			    $gateway = pathinfo($gateway, PATHINFO_FILENAME);
			    if ($gateway === 'abstract')
				    continue;
			    $gateway = 'Nocks_WC_Gateway_'.ucfirst($gateway);
			    $gateways[] = $gateway;
			    self::$GATEWAYS[] = $gateway;
		    }

		    return $gateways;
	    }

	    return self::$GATEWAYS;
    }

    /**
     * Add a WooCommerce notification message
     *
     * @param string $message Notification message
     * @param string $type One of notice, error or success (default notice)
     * @return $this
     */
    public static function addNotice ($message, $type = 'notice')
    {
        $type = in_array($type, array('notice','error','success')) ? $type : 'notice';

        // Check for existence of new notification api (WooCommerce >= 2.1)
        if (function_exists('wc_add_notice'))
        {
            wc_add_notice($message, $type);
        }
        else
        {
            $woocommerce = WooCommerce::instance();

            switch ($type)
            {
                case 'error' :
                    $woocommerce->add_error($message);
                    break;
                default :
                    $woocommerce->add_message($message);
                    break;
            }
        }
    }

    /**
     * Log messages to WooCommerce log
     *
     * @param mixed $message
     * @param bool  $set_debug_header Set X-Nocks-Debug header (default false)
     */
    public static function debug ($message, $set_debug_header = false)
    {
        // Convert message to string
        if (!is_string($message))
        {
            $message = ( version_compare( WC_VERSION, '3.0', '<' ) ) ? print_r($message, true) : wc_print_r($message, true);
        }

        // Set debug header
        if ($set_debug_header && PHP_SAPI !== 'cli' && !headers_sent())
        {
            header("X-Nocks-Debug: $message");
        }

	    // Log message
	    if ( self::getSettingsHelper()->isDebugEnabled() ) {

		    if ( version_compare( WC_VERSION, '3.0', '<' ) ) {

			    static $logger;

			    if ( empty( $logger ) ) {
				    // TODO: Use error_log() fallback if Wc_Logger is not available
				    $logger = new WC_Logger();
			    }

			    $logger->add( self::PLUGIN_ID . '-' . date( 'Y-m-d' ), $message );

		    } else {

			    $logger = wc_get_logger();

			    $context = array ( 'source' => self::PLUGIN_ID . '-' . date( 'Y-m-d' ) );

			    $logger->debug( $message, $context );

		    }

	    }
    }

    /**
     * Get location of main plugin file
     *
     * @return string
     */
    public static function getPluginFile ()
    {
        return plugin_basename(self::PLUGIN_ID . '/' . self::PLUGIN_ID . '.php');
    }

    /**
     * Get plugin URL
     *
     * @param string $path
     * @return string
     */
    public static function getPluginUrl ($path = '')
    {
    	return NOCKS_PLUGIN_URL . $path;
    }

    /**
     * Add plugin action links
     * @param array $links
     * @return array
     */
    public static function addPluginActionLinks (array $links)
    {
        $action_links = array(
            // Add link to global Nocks settings
            '<a href="' . self::getSettingsHelper()->getGlobalSettingsUrl() . '">' . __('Nocks settings', 'nocks-crypto-for-woocommerce') . '</a>',
        );

        // Add link to log files viewer for WooCommerce >= 2.2.0
        if (version_compare(self::getStatusHelper()->getWooCommerceVersion(), '2.2.0', ">="))
        {
            // Add link to WooCommerce logs
            $action_links[] = '<a href="' . self::getSettingsHelper()->getLogsUrl() . '">' . __('Logs', 'nocks-crypto-for-woocommerce') . '</a>';
        }

        return array_merge($action_links, $links);
    }

    /**
     * @return Nocks_WC_Helper_Settings
     */
    public static function getSettingsHelper ()
    {
        static $settings_helper;

        if (!$settings_helper)
        {
            $settings_helper = new Nocks_WC_Helper_Settings();
        }

        return $settings_helper;
    }

    /**
     * @return Nocks_WC_Helper_Api
     */
    public static function getApiHelper ()
    {
        static $api_helper;

        if (!$api_helper)
        {
            $api_helper = new Nocks_WC_Helper_Api(self::getSettingsHelper());
        }

        return $api_helper;
    }

    /**
     * @return Nocks_WC_Helper_Data
     */
    public static function getDataHelper ()
    {
        static $data_helper;

        if (!$data_helper)
        {
            $data_helper = new Nocks_WC_Helper_Data(self::getApiHelper());
        }

        return $data_helper;
    }

    /**
     * @return Nocks_WC_Helper_Status
     */
    public static function getStatusHelper ()
    {
        static $status_helper;

        if (!$status_helper)
        {
            $status_helper = new Nocks_WC_Helper_Status();
        }

        return $status_helper;
    }
}

