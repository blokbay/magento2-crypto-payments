<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Model\System;

use Blokbay\Payment\Model\Api;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Notification\MessageInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Admin notice: the crypto method is enabled but the store currency is not accepted by Blokbay, so the method stays hidden.
 */
class CurrencyMessage implements MessageInterface
{
    /** @var ScopeConfigInterface */
    private $config;
    /** @var StoreManagerInterface */
    private $stores;

    public function __construct(ScopeConfigInterface $config, StoreManagerInterface $stores)
    {
        $this->config = $config;
        $this->stores = $stores;
    }

    /**
     * Currencies of the stores that Blokbay does not accept (default display currencies).
     *
     * @return string[]
     */
    private function unsupported(): array
    {
        $out = [];
        try {
            foreach ($this->stores->getStores() as $store) {
                $code = strtoupper((string) $store->getDefaultCurrencyCode());
                if ($code !== '' && !Api::supported($code)) {
                    $out[$code] = $code;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }
        return array_values($out);
    }

    public function getIdentity()
    {
        return 'blokbay_payment_currency';
    }

    public function isDisplayed()
    {
        return $this->config->isSetFlag('payment/blokbay/active') && $this->unsupported() !== [];
    }

    public function getText()
    {
        return __(
            'Crypto (Blokbay): Blokbay accepts prices in %1. Your store currency %2 is not accepted, so the payment method stays hidden at checkout.',
            implode(', ', array_keys(Api::CURRENCIES)),
            implode(', ', $this->unsupported())
        )->render();
    }

    public function getSeverity()
    {
        return self::SEVERITY_MAJOR;
    }
}
