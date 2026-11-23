<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Controller\Payment;

use Blokbay\Payment\Model\Api;
use Blokbay\Payment\Model\Blokbay;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;

/**
 * After "Place Order": creates the Blokbay payment for the last order and redirects to the Blokbay payment page.
 */
class Redirect implements HttpGetActionInterface
{
    /**
     * @var Session
     */
    private $session;

    /**
     * @var Api
     */
    private $api;

    /**
     * @var RedirectFactory
     */
    private $redirect;

    /**
     * @var UrlInterface
     */
    private $url;

    /**
     * @var ManagerInterface
     */
    private $messages;

    /**
     * @var OrderRepositoryInterface
     */
    private $orders;

    /**
     * @param Session $session
     * @param Api $api
     * @param RedirectFactory $redirect
     * @param UrlInterface $url
     * @param ManagerInterface $messages
     * @param OrderRepositoryInterface $orders
     */
    public function __construct(
        Session $session,
        Api $api,
        RedirectFactory $redirect,
        UrlInterface $url,
        ManagerInterface $messages,
        OrderRepositoryInterface $orders
    ) {
        $this->session = $session;
        $this->api = $api;
        $this->redirect = $redirect;
        $this->url = $url;
        $this->messages = $messages;
        $this->orders = $orders;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $result = $this->redirect->create();
        $order = $this->session->getLastRealOrder();
        $payment = $order->getPayment();
        if (!$order->getId() || !$payment instanceof Payment || $payment->getMethod() !== Blokbay::CODE
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
        ) {
            return $result->setPath('checkout/cart');
        }
        $increment = (string) $order->getIncrementId();
        $currency = strtoupper((string) $order->getOrderCurrencyCode());
        $amount = $this->api->amount($order->getGrandTotal(), $currency);
        $fields = [
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $increment,
            'description' => 'Order ' . $increment,
            'customer_email' => (string) $order->getCustomerEmail(),
            'url_success' => $this->url->getUrl('checkout/onepage/success'),
            'url_cancel' => $this->url->getUrl('checkout/cart'),
        ];
        $key = $this->api->idempotencyKey(
            $this->url->getBaseUrl(),
            $increment,
            (string) $order->getCreatedAt(),
            $amount,
            $currency
        );
        $res = $this->api->createPayment($fields, $key);
        $foreign = !empty($res['id']) && !$this->api->matchesOrder($res, $increment, $amount, $currency);
        if ($foreign || ($res['status'] ?? '') === 'expired') {
            // Expired, or a payment of another order came back for this key: create a new one.
            $res = $this->api->createPayment($fields, substr($key, 0, 80) . '-' . time());
        }
        $valid = !empty($res['id']) && !empty($res['url']) && $this->api->matchesOrder($res, $increment, $amount, $currency);
        if (!$valid) {
            // The order was placed before the payment: cancel it and give the customer the cart back.
            if ($order->canCancel()) {
                $order->cancel()->addCommentToStatusHistory(
                    __('Blokbay: the crypto payment could not be created (%1).', $res['error'] ?? 'error')->render()
                );
                $this->orders->save($order);
            }
            $this->session->restoreQuote();
            $this->messages->addErrorMessage(
                __('The crypto payment could not be created. Please try again.')->render()
            );
            return $result->setPath('checkout/cart');
        }
        $ids = (array) $payment->getAdditionalInformation('blokbay_payment_ids');
        $ids[] = $res['id'];
        $payment->setAdditionalInformation('blokbay_payment_ids', array_values(array_unique($ids)));
        $this->orders->save($order);
        return $result->setUrl($res['url']);
    }
}
