<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Sales\Model\Order;

/**
 * Payment method "blokbay".
 *
 * The order is placed as pending payment, then the customer is redirected to the Blokbay payment page.
 */
class Blokbay extends AbstractMethod
{
    public const CODE = 'blokbay';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canUseForMultishipping = false;

    /**
     * New orders start as "pending payment".
     *
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('is_notified', false);
        return $this;
    }

    /**
     * Blokbay amounts are in US dollars.
     *
     * @param string $currencyCode
     * @return bool
     */
    public function canUseForCurrency($currencyCode)
    {
        return $currencyCode === 'USD';
    }

    /**
     * Page that creates the Blokbay payment after "Place Order".
     *
     * @return string
     */
    public function getOrderPlaceRedirectUrl()
    {
        return 'blokbay/payment/redirect';
    }
}
