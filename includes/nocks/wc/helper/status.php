<?php

class Nocks_WC_Helper_Status
{
    /**
     * Minimal required WooCommerce version
     *
     * @var string
     */
    const MIN_WOOCOMMERCE_VERSION = '2.1.0';

    /**
     * @var string[]
     */
    protected $errors = array();

    /**
     * @return bool
     */
    public function hasErrors() {
        return !empty($this->errors);
    }

    /**
     * @return string[]
     */
    public function getErrors() {
        return $this->errors;
    }

    /**
     * Check if this plugin is compatible
     *
     * @return bool
     */
    public function isCompatible() {
        static $is_compatible = null;

        if ($is_compatible !== null) {
            return $is_compatible;
        }

        // Default
        $is_compatible = true;

        if (!$this->hasCompatibleWooCommerceVersion()) {
            $this->errors[] = sprintf(/* translators: Placeholder 1: Plugin name, placeholder 2: required WooCommerce version, placeholder 3: used WooCommerce version */
                __('The %s plugin requires at least WooCommerce version %s, you are using version %s. Please update your WooCommerce plugin.', 'nocks-crypto-for-woocommerce'), Nocks_WC_Plugin::PLUGIN_TITLE, self::MIN_WOOCOMMERCE_VERSION, $this->getWooCommerceVersion());

            return $is_compatible = false;
        }

        if (!$this->isApiClientInstalled()) {
            $this->errors[] = __('Nocks API client not installed. Please make sure the plugin is installed correctly.', 'nocks-crypto-for-woocommerce');

            return $is_compatible = false;
        }

        try {
            $checker = $this->getApiClientCompatibilityChecker();

            $checker->checkCompatibility();
        } catch (Nocks_Exception_IncompatiblePlatform $e) {
            switch ($e->getCode()) {
                case Nocks_Exception_IncompatiblePlatform::INCOMPATIBLE_PHP_VERSION:
                    $error = sprintf(/* translators: Placeholder 1: Required PHP version, placeholder 2: current PHP version */
                        __('The client requires PHP version >= %s, you have %s.', 'nocks-crypto-for-woocommerce'), Nocks_WC_CompatibilityChecker::$MIN_PHP_VERSION, PHP_VERSION);
                    break;
                default:
                    $error = $e->getMessage();
                    break;
            }

            $this->errors[] = $error;

            return $is_compatible = false;
        }

        return $is_compatible;
    }

    /**
     * @return string
     */
    public function getWooCommerceVersion() {
        return WooCommerce::instance()->version;
    }

    /**
     * @return bool
     */
    public function hasCompatibleWooCommerceVersion() {
        return (bool)version_compare($this->getWooCommerceVersion(), self::MIN_WOOCOMMERCE_VERSION, ">=");
    }

    /**
     * @return bool
     */
    protected function isApiClientInstalled() {
        $includes_dir = dirname(dirname(dirname(dirname(__FILE__))));

        return file_exists($includes_dir . '/nocks-checkout');
    }

    /**
     * @return Nocks_WC_CompatibilityChecker
     */
    protected function getApiClientCompatibilityChecker() {
        return new Nocks_WC_CompatibilityChecker();
    }
}
