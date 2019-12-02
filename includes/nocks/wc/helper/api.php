<?php
class Nocks_WC_Helper_Api
{
    /**
     * @var Nocks_Checkout
     */
    protected static $api_client;

    /**
     * @var Nocks_WC_Helper_Settings
     */
    protected $settings_helper;

    /**
     * @param Nocks_WC_Helper_Settings $settings_helper
     */
    public function __construct (Nocks_WC_Helper_Settings $settings_helper)
    {
        $this->settings_helper = $settings_helper;
    }

    /**
     * @return Nocks_Checkout
     */
    public function getApiClient ()
    {
        global $wp_version;

        if (empty(self::$api_client))
        {
            $client = new Nocks_Checkout();
            $client->addVersionString('WordPress/'   . (isset($wp_version) ? $wp_version : 'Unknown'));
            $client->addVersionString('WooCommerce/' . get_option('woocommerce_version', 'Unknown'));
            $client->addVersionString('NocksWoo/'   . Nocks_WC_Plugin::PLUGIN_VERSION);

            self::$api_client = $client;
        }

        return self::$api_client;
    }

    /**
     * Get API endpoint. Override using filter.
     * @return string
     */
    public static function getApiEndpoint ()
    {
        return apply_filters(Nocks_WC_Plugin::PLUGIN_ID . '_api_endpoint', Nocks_Checkout::API_ENDPOINT);
    }

}
