<?php
class Nocks_WC_Gateway_Ethereum extends Nocks_WC_Gateway_Abstract
{
	/**
	 * @return string
	 */
	public function getNocksMethodId ()
	{
		return 'ethereum';
	}

	public function getSourceCurrency()
	{
		return 'ETH';
	}

	/**
	 * @return string
	 */
	public function getDefaultTitle ()
	{
		return __('Ethereum', 'nocks-crypto-for-woocommerce');
	}

	/**
	 * @return string
	 */
	protected function getSettingsDescription()
	{
		return __('Accept Ethereum payments with Nocks', 'nocks-crypto-for-woocommerce');
	}

	/**
	 * @return string
	 */
	protected function getDefaultDescription ()
	{
		/* translators: Default description, displayed above dropdown */
		return __('Pay with Ethereum', 'nocks-crypto-for-woocommerce');
	}

	/**
	 * Display fields below payment method in checkout
	 */
	public function payment_fields() {
		parent::payment_fields();
		try {
			$currency = get_woocommerce_currency();
			$amount = WC()->cart->total;
			if ($currency !== $this->getSourceCurrency()) {
				$priceData = Nocks_WC_Plugin::getApiHelper()->getApiClient()->calculatePrice(get_woocommerce_currency(), WC()->cart->total, $this->getSourceCurrency(), $this->getNocksMethodId());
				$amount    = $priceData['source_amount']['amount'];
			}

			$html = '<br/>' . __('Estimated total amount of Ethereum: ', 'nocks-crypto-for-woocommerce') . ' ' . $this->getSourceCurrency() . ' ' . $amount;
		} catch(Exception $e) {
			$html = '<br/>' . __('We cannot calculate the amount of Ethereums at this moment.', 'nocks-crypto-for-woocommerce');
		}


		echo wpautop(wptexturize($html));
	}
}
