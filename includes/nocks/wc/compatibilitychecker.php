<?php

class Nocks_WC_CompatibilityChecker
{
    /**
     * @var string
     */
    public static $MIN_PHP_VERSION = '5.4.0';

    /**
     * Used cURL functions
     *
     * @var array
     */
    public static $REQUIRED_CURL_FUNCTIONS = array(
        'curl_init',
        'curl_setopt',
        'curl_exec',
        'curl_error',
        'curl_errno',
        'curl_close',
        'curl_version',
    );

    /**
     * @throws Nocks_Exception_IncompatiblePlatform
     * @return void
     */
    public function checkCompatibility() {
        if (!$this->phpIsCompatible()) {
            throw new Nocks_Exception_IncompatiblePlatform("The client requires PHP version >= " . self::$MIN_PHP_VERSION . ", you have " . PHP_VERSION . ".", Nocks_Exception_IncompatiblePlatform::INCOMPATIBLE_PHP_VERSION);
        }
    }

    /**
     * @return bool
     */
    public function phpIsCompatible() {
        return (bool)version_compare(PHP_VERSION, self::$MIN_PHP_VERSION, ">=");
    }
}