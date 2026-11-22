<?php
/**
 * Copyright Blokbay. Licensed under the MIT License, see LICENSE.txt.
 */
declare(strict_types=1);

namespace Blokbay\Payment\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Applies a Blokbay payment to its order.
 *
 * Paid: offline invoice and "processing", once, with the amount checked. Expired: cancel if still pending.
 */
class Applier
{
    /**
     * @var OrderRepositoryInterface
     */
    private $orders;

    /**
     * @var SearchCriteriaBuilder
     */
    private $criteria;

    /**
     * @var InvoiceService
     */
    private $invoices;

    /**
     * @var TransactionFactory
     */
    private $transactions;

    /**
     * @var Api
     */
    private $api;

    /**
     * @param OrderRepositoryInterface $orders
     * @param SearchCriteriaBuilder $criteria
     * @param InvoiceService $invoices
     * @param TransactionFactory $transactions
     * @param Api $api
     */
    public function __construct(
        OrderRepositoryInterface $orders,
        SearchCriteriaBuilder $criteria,
        InvoiceService $invoices,
        TransactionFactory $transactions,
        Api $api
    ) {
        $this->orders = $orders;
        $this->criteria = $criteria;
        $this->invoices = $invoices;
        $this->transactions = $transactions;
        $this->api = $api;
    }

    /**
     * Order with this increment id, or null.
     *
     * @param string $incrementId
     * @return Order|null
     */
    private function findOrder(string $incrementId): ?Order
    {
        if ($incrementId === '') {
            return null;
        }
        $search = $this->criteria->addFilter(OrderInterface::INCREMENT_ID, $incrementId)->setPageSize(1)->create();
        foreach ($this->orders->getList($search)->getItems() as $order) {
            return $order instanceof Order ? $order : null;
        }
        return null;
    }

    /**
     * Applies the payment and returns a short result for the webhook answer.
     *
     * @param array $payment
     * @return string
     */
    public function apply(array $payment): string
    {
        $order = $this->findOrder((string) ($payment['reference'] ?? ''));
        $orderPayment = $order ? $order->getPayment() : null;
        if (!$order || !$orderPayment instanceof Payment || $orderPayment->getMethod() !== Blokbay::CODE) {
            return 'unknown order';
        }
        $ids = (array) $orderPayment->getAdditionalInformation('blokbay_payment_ids');
        if (!in_array($payment['id'] ?? '', $ids, true)) {
            return 'unknown payment';
        }
        $status = $payment['status'] ?? '';
        if ($status === 'paid') {
            return $this->paid($order, $payment);
        }
        if ($status === 'expired' && $order->getState() === Order::STATE_PENDING_PAYMENT && $order->canCancel()) {
            $order->cancel();
            $order->addCommentToStatusHistory(__('Blokbay: the crypto payment was not made in time.')->render());
            $this->orders->save($order);
            return 'expired';
        }
        return 'ok';
    }

    /**
     * Invoices a paid order (once, only if amount and currency match).
     *
     * @param Order $order
     * @param array $payment
     * @return string
     */
    private function paid(Order $order, array $payment): string
    {
        $sameAmount = $this->api->amount($order->getGrandTotal()) === ($payment['amount'] ?? '');
        if (!$sameAmount || ($payment['currency'] ?? '') !== 'USD' || $order->getOrderCurrencyCode() !== 'USD') {
            $order->addCommentToStatusHistory(
                __('Blokbay: amount does not match the order, check it in the Blokbay dashboard.')->render()
            );
            $this->orders->save($order);
            return 'amount mismatch';
        }
        if ($order->getTotalPaid() >= $order->getGrandTotal() || !$order->canInvoice()) {
            return 'already paid';
        }
        $tx = (string) ($payment['tx'] ?? '');
        $invoice = $this->invoices->prepareInvoice($order);
        $invoice->setData('requested_capture_case', Invoice::CAPTURE_OFFLINE);
        $invoice->setTransactionId($tx);
        $invoice->register();
        $order->getPayment()->setLastTransId($tx);
        $order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            __('Blokbay: paid in %1 on %2. Transaction %3', $payment['coin'] ?? '', $payment['network'] ?? '', $tx)
                ->render()
        );
        $this->transactions->create()->addObject($invoice)->addObject($order)->save();
        return 'paid';
    }
}
