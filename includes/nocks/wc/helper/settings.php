<?php

class Nocks_WC_Helper_Settings
{
    const DEFAULT_TIME_PAYMENT_CONFIRMATION_CHECK = '3:00';

    /**
     * @return bool
     */
    public function isTestModeEnabled() {
        return trim(get_option($this->getSettingId('test_mode_enabled'))) === 'yes';
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
     * @return bool
     */
    public function isDebugEnabled() {
        return get_option($this->getSettingId('debug'), 'yes') === 'yes';
    }

    /**
     * @return string
     */
    public function getGlobalSettingsUrl() {
        return admin_url('admin.php?page=wc-settings&tab=checkout#' . Nocks_WC_Plugin::PLUGIN_ID);
    }

    /**
     * @return string
     */
    public function getLogsUrl() {
        return admin_url('admin.php?page=wc-status&tab=logs');
    }

    /**
     * @param array $settings
     * @return array
     */
    public function addGlobalSettingsFields(array $settings) {
        $content = '' . $this->getPluginStatus() . $this->getNocksMethods();
        $debug_desc = __('Log plugin events.', 'nocks-crypto-for-woocommerce');

        // For WooCommerce 2.2.0+ display view logs link
        if (version_compare(Nocks_WC_Plugin::getStatusHelper()->getWooCommerceVersion(), '2.2.0', ">=")) {
            $debug_desc .= ' <a href="' . $this->getLogsUrl() . '">' . __('View logs', 'nocks-crypto-for-woocommerce') . '</a>';
        }
        // Display location of log files
        else {
            /* translators: Placeholder 1: Location of the log files */
            $debug_desc .= ' ' . sprintf(__('Log files are saved to <code>%s</code>', 'nocks-crypto-for-woocommerce'), defined('WC_LOG_DIR') ? WC_LOG_DIR : WC()->plugin_path() . '/logs/');
        }

        $settings_helper = Nocks_WC_Plugin::getSettingsHelper();

        // Global Nocks settings
        $nocks_settings = array(
            array(
                'id'    => $this->getSettingId('title'),
                'title' => __('Nocks Crypto settings', 'nocks-crypto-for-woocommerce'),
                'type'  => 'title',
                'desc'  => '<p id="' . Nocks_WC_Plugin::PLUGIN_ID . '">' . $content . '</p>',
            ),
	        array(
		        'id'       => $this->getSettingId('test_mode_enabled'),
		        'title'    => __('Enable test mode', 'nocks-crypto-for-woocommerce'),
		        'default'  => 'no',
		        'type'     => 'checkbox',
		        'desc_tip' => __('Enable test mode if you want to test the plugin without using real payments.', 'nocks-crypto-for-woocommerce'),
	        ),
            array(
                'id'      => $this->getSettingId('debug'),
                'title'   => __('Debug Log', 'nocks-crypto-for-woocommerce'),
                'type'    => 'checkbox',
                'desc'    => $debug_desc,
                'default' => 'yes',
            ),
            array(
                'id'   => $this->getSettingId('sectionend'),
                'type' => 'sectionend',
            ),
        );

        return $this->mergeSettings($settings, $nocks_settings);
    }

    public function getPaymentConfirmationCheckTime() {
        $time = strtotime(self::DEFAULT_TIME_PAYMENT_CONFIRMATION_CHECK);
        $date = new DateTime();

        if ($date->getTimestamp() > $time) {
            $date->setTimestamp($time);
            $date->add(new DateInterval('P1D'));
        }
        else {
            $date->setTimestamp($time);
        }

        return $date->getTimestamp();
    }

    /**
     * Get plugin status
     *
     * - Check compatibility
     * - Check Nocks API connectivity
     *
     * @return string
     */
    protected function getPluginStatus() {
        $status = Nocks_WC_Plugin::getStatusHelper();

        if (!$status->isCompatible()) {
            // Just stop here!
            return '' . '<div class="notice notice-error">' . '<p><strong>' . __('Error', 'nocks-crypto-for-woocommerce') . ':</strong> ' . implode('<br/>', $status->getErrors()) . '</p></div>';
        }

        return '';
    }

    /**
     * @param string $gateway_class_name
     * @return string
     */
    protected function getGatewaySettingsUrl($gateway_class_name) {
        return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . sanitize_title(strtolower($gateway_class_name)));
    }

    protected function getNocksMethods() {
        $content = '';
        $content .= __('Payment methods:', 'nocks-crypto-for-woocommerce');

        $content .= '<table style="width: 500px">';
        foreach (Nocks_WC_Plugin::$GATEWAYS as $gateway_classname) {
            $gateway = new $gateway_classname;

            if ($gateway instanceof Nocks_WC_Gateway_Abstract) {
                $content .= '<tr>';
                $content .= '<td style="width: 10px;"><img src="' . esc_attr($gateway->getIconUrl()) . '" alt="' . esc_attr($gateway->getDefaultTitle()) . '" title="' . esc_attr($gateway->getDefaultTitle()) . '" style="width: 25px; vertical-align: bottom;" /></td>';
                $content .= '<td>' . esc_html($gateway->getDefaultTitle()).'</td>';
                $content .= '<td><a href="' . $this->getGatewaySettingsUrl($gateway_classname) . '">' . strtolower(__('Edit', 'nocks-crypto-for-woocommerce')) . '</a></td>';
                $content .= '</tr>';
            }
        }

        $content .= '</table>';
        $content .= '<div class="clear"></div>';

        return $content;
    }

    /**
     * @param string $setting
     * @return string
     */
    protected function getSettingId($setting) {
        global $wp_version;

        $setting_id = Nocks_WC_Plugin::PLUGIN_ID . '_' . trim($setting);
        $setting_id_length = strlen($setting_id);

        $max_option_name_length = 191;

        /**
         * Prior to WooPress version 4.4.0, the maximum length for wp_options.option_name is 64 characters.
         * @see https://core.trac.wordpress.org/changeset/34030
         */
        if ($wp_version < '4.4.0') {
            $max_option_name_length = 64;
        }

        if ($setting_id_length > $max_option_name_length) {
            trigger_error("Setting id $setting_id ($setting_id_length) to long for database column wp_options.option_name which is varchar($max_option_name_length).", E_USER_WARNING);
        }

        return $setting_id;
    }

    /**
     * @param array $settings
     * @param array $nocks_settings
     * @return array
     */
    protected function mergeSettings(array $settings, array $nocks_settings) {
        $new_settings = array();
        $nocks_settings_merged = false;

        // Find payment gateway options index
        foreach ($settings as $index => $setting) {
            if (isset($setting['id']) && $setting['id'] == 'payment_gateways_options' && (!isset($setting['type']) || $setting['type'] != 'sectionend')) {
                $new_settings = array_merge($new_settings, $nocks_settings);
                $nocks_settings_merged = true;
            }

            $new_settings[] = $setting;
        }

        // Nocks settings not merged yet, payment_gateways_options not found
        if (!$nocks_settings_merged) {
            // Append Nocks settings
            $new_settings = array_merge($new_settings, $nocks_settings);
        }

        return $new_settings;
    }
}
