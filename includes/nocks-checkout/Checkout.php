<?php

/**
 * Class Nocks
 * @package NocksCheckout
 */
class Nocks_Checkout
{
    /* @var Nocks_RestClient $client */
    protected $client;

    protected $testMode;

    public function __construct($testMode = null) {
	    $settings = Nocks_WC_Plugin::getSettingsHelper();
	    $this->testMode = $testMode === null ? $settings->isTestModeEnabled() : $testMode;

	    $this->client = new Nocks_RestClient(self::getEndpoint($this->testMode));

        $curl_version = curl_version();
        $this->addVersionString("PHP/" . phpversion());
        $this->addVersionString("cURL/" . $curl_version["version"]);
        $this->addVersionString($curl_version["ssl_version"]);
    }

    public static function getEndpoint($testMode = false) {
	    return $testMode ? 'https://sandbox.nocks.com/api/v2/' : 'https://api.nocks.com/api/v2/';
    }

    public function addVersionString($string) {
        $this->client->versionHeaders[] = $string;
    }

    public function round_up ( $value, $precision ) {
        $pow = pow ( 10, $precision );
        return ( ceil ( $pow * $value ) + ceil ( $pow * $value - ceil ( $pow * $value ) ) ) / $pow;
    }

	/**
	 * @param $data
	 *
	 * @return array|mixed|null|object
	 */
    public function createTransaction($data) {
        $amount = $data['amount'];
        $currency = $data['currency'];
        $callback_url = $data['webhookUrl'];
        $return_url = $data['redirectUrl'];

        $post = array(
            'amount'           => array(
                'amount'   => (string)($currency==="NLG"?$this->round_up($amount, 8):$this->round_up($amount,2)),
                'currency' => $currency
            ),
            'payment_method'   => array(
                'method' => $data['method'],
            ),
            'source_currency' => $data['source_currency'],
            'target_currency' => $data['source_currency'],
            'target_address' => $data['target_address'],
            'metadata'         => [
	            'nocks_plugin' => 'woocommerce:' . Nocks_WC_Plugin::PLUGIN_VERSION,
	            'woocommerce_version'  => WC_VERSION,
            ],
            'redirect_url'     => $return_url,
            'callback_url'     => $callback_url,
            'locale'           => Nocks_WC_Plugin::getDataHelper()->getCurrentLocale(),
            'description'      => $data['reference'] . ' - ' . get_bloginfo('name'),
        );

        $response = ($this->client->post('transaction', null, $post));
        $transaction = json_decode($response, true);

        return $transaction;
    }

    public function getTransaction($uuid) {
        $response = ($this->client->get('transaction/'.$uuid, null));
        $transaction = json_decode($response, true);

        return new Nocks_Transaction($transaction);
    }

    /**
     * Calculates the price for the transaction
     *
     * @param $target_currency
     * @param $amount
     * @param $source_currency
     * @return int
     */
    public function calculatePrice($target_currency, $amount, $source_currency, $method) {
        $data = array(
            'source_currency'  => $source_currency,
            'target_currency'  => $target_currency,
            'amount'           => array(
                "amount"   => (string)$amount,
                "currency" => $target_currency
            ),
            'payment_method'   => array("method" => $method)
        );

        try {
	        $price = $this->client->post('transaction/quote', null, $data);
	        $price = json_decode($price, true);

	        if (isset($price['data']) && isset($price['data'])) {
		        return $price['data'];
	        }

	        return 0;
        } catch ( Exception $e ) {
        	return 0;
        }
    }
}