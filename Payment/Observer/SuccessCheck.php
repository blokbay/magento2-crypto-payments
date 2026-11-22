<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Observer;

use Blokbay\Payment\Model\Api;
use Blokbay\Payment\Model\Applier;
use Blokbay\Payment\Model\Blokbay;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;

/**
 * Back on the success page: if the webhook is late, ask the API (server side, secret key) and apply the payment.
 */
class SuccessCheck implements ObserverInterface
{
    /**
     * @var Api
     */
    private $api;

    /**
     * @var Applier
     */
    private $applier;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Api $api
     * @param Applier $applier
     * @param LoggerInterface $logger
     */
    public function __construct(Api $api, Applier $applier, LoggerInterface $logger)
    {
        $this->api = $api;
        $this->applier = $applier;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');
        $payment = $order instanceof Order ? $order->getPayment() : null;
        if (!$order instanceof Order || !$order->getId() || !$payment instanceof Payment
            || $payment->getMethod() !== Blokbay::CODE
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
        ) {
            return;
        }
        $ids = (array) $payment->getAdditionalInformation('blokbay_payment_ids');
        $id = (string) end($ids);
        if ($id === '') {
            return;
        }
        try {
            $remote = $this->api->getPayment($id);
            if (($remote['id'] ?? '') === $id) {
                $this->applier->apply($remote);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Blokbay: status check failed, the webhook will still arrive. ' . $e->getMessage());
        }
    }
}
