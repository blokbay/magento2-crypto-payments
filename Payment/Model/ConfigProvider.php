<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;

/**
 * Checkout configuration of the payment method.
 */
class ConfigProvider implements ConfigProviderInterface
{
    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @param UrlInterface $url
     */
    public function __construct(UrlInterface $url)
    {
        $this->url = $url;
    }

    /**
     * @inheritdoc
     */
    public function getConfig()
    {
        return ['payment' => ['blokbay' => ['redirectUrl' => $this->url->getUrl('blokbay/payment/redirect')]]];
    }
}
