<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Service;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Repository\OrderRepository;
use Eccube\Service\MailService;
use Eccube\Service\OrderStateMachine;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\Processor\PointProcessor;
use Eccube\Service\PurchaseFlow\Processor\StockReduceProcessor;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\BlokbayPayment\Entity\Config;
use Plugin\BlokbayPayment\Entity\Transaction;
use Plugin\BlokbayPayment\Repository\ConfigRepository;
use Plugin\BlokbayPayment\Repository\TransactionRepository;

/**
 * Shared logic of the webhook and of the customer's return: finds the order of a Blokbay payment (exact payment id)
 * and moves it to paid or cancelled.
 *
 * Order states used:
 *   PROCESSING (購入処理中)  customer still in the checkout, or came back from the payment page
 *   PENDING    (決済処理中)  sent to the Blokbay payment page, stock reserved by PurchaseFlow::prepare
 *   NEW        (新規受付)    customer came back, payment not confirmed yet (like a bank transfer order)
 *   PAID       (入金済み)    payment confirmed by a signed webhook or by the API on return
 *   CANCEL     (注文取消し)  the current Blokbay payment expired
 */
class OrderUpdater
{
    /** @var EntityManagerInterface */
    private $em;
    /** @var OrderRepository */
    private $orderRepository;
    /** @var OrderStatusRepository */
    private $orderStatusRepository;
    /** @var PurchaseFlow */
    private $purchaseFlow;
    /** @var OrderStateMachine */
    private $stateMachine;
    /** @var MailService */
    private $mailService;
    /** @var TransactionRepository */
    private $transactionRepository;
    /** @var ConfigRepository */
    private $configRepository;
    /** @var EccubeConfig */
    private $eccubeConfig;
    /** @var StockReduceProcessor */
    private $stockReduceProcessor;
    /** @var PointProcessor */
    private $pointProcessor;

    public function __construct(
        EntityManagerInterface $em,
        OrderRepository $orderRepository,
        OrderStatusRepository $orderStatusRepository,
        PurchaseFlow $shoppingPurchaseFlow,
        OrderStateMachine $orderStateMachine,
        MailService $mailService,
        TransactionRepository $transactionRepository,
        ConfigRepository $configRepository,
        EccubeConfig $eccubeConfig,
        StockReduceProcessor $stockReduceProcessor,
        PointProcessor $pointProcessor
    ) {
        $this->stockReduceProcessor = $stockReduceProcessor;
        $this->pointProcessor = $pointProcessor;
        $this->em = $em;
        $this->orderRepository = $orderRepository;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->purchaseFlow = $shoppingPurchaseFlow;
        $this->stateMachine = $orderStateMachine;
        $this->mailService = $mailService;
        $this->transactionRepository = $transactionRepository;
        $this->configRepository = $configRepository;
        $this->eccubeConfig = $eccubeConfig;
    }

    /** @return Config */
    public function config()
    {
        return $this->configRepository->get();
    }

    public function api()
    {
        $c = $this->config();

        return new BlokbayApi($c->getSecretKey(), $c->getApiBase() !== '' ? $c->getApiBase() : BlokbayApi::DEFAULT_BASE);
    }

    /** Shop currency (ECCUBE_CURRENCY): the order currency sent to Blokbay (USD, EUR, GBP, CHF, JPY, CAD, AUD, SGD, HKD, AED). */
    public function shopCurrency()
    {
        return strtoupper((string) $this->eccubeConfig->get('currency'));
    }

    /**
     * Applies a Blokbay payment object (webhook "data" or GET /api/v1/payments/:id) to its order.
     *
     * @param array<string, mixed> $p
     *
     * @return string short result, also the webhook response body
     */
    public function apply(array $p)
    {
        $id = is_string(isset($p['id']) ? $p['id'] : null) ? $p['id'] : '';
        $tx = $this->transactionRepository->findByPaymentId($id);
        if (!$tx instanceof Transaction) {
            return 'unknown payment';
        }
        $Order = $this->orderRepository->find($tx->getOrderId());
        if (!$Order instanceof Order) {
            return 'unknown order';
        }
        $status = (string) (isset($p['status']) ? $p['status'] : '');
        if ($status === 'paid') {
            $amount = (string) (isset($p['amount']) ? $p['amount'] : '');
            $currency = (string) (isset($p['currency']) ? $p['currency'] : '');
            $expected = strtoupper((string) $Order->getCurrencyCode()) ?: $this->shopCurrency();
            if ($tx->getAmount() === '' || $amount !== $tx->getAmount() || $currency !== $expected) {
                $this->note($Order, 'Blokbay: payment '.$tx->getPaymentId().' reported '.$amount.' '.$currency.', expected '.$tx->getAmount().' '.$expected.'. Not marked as paid.');
                $this->em->flush();

                return 'amount mismatch';
            }
            $tx->setStatus('paid');
            $hash = isset($p['tx']) && is_string($p['tx']) ? substr($p['tx'], 0, 250) : '';
            if ($hash !== '') {
                $tx->setTx($hash);
            }
            $r = $this->markPaid($Order, $tx, $p);
            $this->em->flush();

            return $r;
        }
        if ($status === 'expired') {
            if ($tx->getStatus() !== 'paid') {
                $tx->setStatus('expired');
            }
            $r = 'ok';
            // Only the current payment of an unpaid order: an old attempt never cancels a reopened order.
            if ($tx->isCurrent()) {
                $sid = $this->statusId($Order);
                if ($sid === OrderStatus::PENDING) {
                    $this->rollback($Order, OrderStatus::CANCEL);
                    $this->note($Order, 'Blokbay: payment '.$tx->getPaymentId().' expired, order cancelled.');
                    $r = 'expired';
                } elseif ($sid === OrderStatus::NEW) {
                    $Cancel = $this->orderStatusRepository->find(OrderStatus::CANCEL);
                    if ($this->stateMachine->can($Order, $Cancel)) {
                        $this->stateMachine->apply($Order, $Cancel);
                        $this->note($Order, 'Blokbay: payment '.$tx->getPaymentId().' expired, order cancelled.');
                        $r = 'expired';
                    }
                }
            }
            $this->em->flush();

            return $r;
        }

        return 'ok';
    }

    /**
     * Customer is back, Blokbay says "pending" (wallet transaction waiting for confirmations, or webhook late):
     * the order is placed as NEW (like a bank transfer order) and the webhook marks it paid later.
     */
    public function awaiting(Order $Order)
    {
        if ($this->statusId($Order) !== OrderStatus::PENDING) {
            return false;
        }
        $this->purchaseFlow->commit($Order, new PurchaseContext());
        $this->note($Order, 'Blokbay: waiting for the payment confirmation.');
        $this->em->flush();
        $this->mailService->sendOrderMail($Order);
        $this->em->flush();

        return true;
    }

    /**
     * Customer cancelled on the payment page (or the payment expired before the return): release the stock and give the
     * order back to the checkout, so the cart is not lost.
     */
    public function backToCart(Order $Order)
    {
        if ($this->statusId($Order) !== OrderStatus::PENDING) {
            return false;
        }
        $this->rollback($Order, OrderStatus::PROCESSING, true);
        $this->em->flush();

        return true;
    }

    /**
     * Marks the order paid. Handles the late payment: an order cancelled because the payment expired is reopened, since the
     * funds are already in the merchant's wallet.
     *
     * @param array<string, mixed> $p
     */
    private function markPaid(Order $Order, Transaction $tx, array $p)
    {
        $sid = $this->statusId($Order);
        if (in_array($sid, [OrderStatus::PAID, OrderStatus::IN_PROGRESS, OrderStatus::DELIVERED, OrderStatus::RETURNED], true)) {
            return 'already paid';
        }
        $info = 'Blokbay: paid '.$tx->getAmount().' '.(strtoupper((string) $Order->getCurrencyCode()) ?: $this->shopCurrency());
        if (!empty($p['coin']) && is_string($p['coin'])) {
            $info .= ' in '.$p['coin'];
        }
        if (!empty($p['network']) && is_string($p['network'])) {
            $info .= ' ('.$p['network'].')';
        }
        if ($tx->getTx() !== '') {
            $info .= ', tx '.$tx->getTx();
        }
        $info .= ', payment '.$tx->getPaymentId().'.';

        if ($sid === OrderStatus::NEW) {
            $Paid = $this->orderStatusRepository->find(OrderStatus::PAID);
            if ($this->stateMachine->can($Order, $Paid)) {
                $this->stateMachine->apply($Order, $Paid);
            } else {
                $this->setPaid($Order);
            }
            $this->note($Order, $info);

            return 'paid';
        }

        $firstCommit = $Order->getOrderDate() === null;
        if ($sid === OrderStatus::PROCESSING || $sid === OrderStatus::CANCEL) {
            // Stock and points were given back (customer back in the cart, or payment expired): take them again.
            try {
                $this->stockReduceProcessor->prepare($Order, new PurchaseContext());
                $this->pointProcessor->prepare($Order, new PurchaseContext());
            } catch (\Exception $e) {
                log_error('[Blokbay] prepare failed', [$Order->getId(), $e->getMessage()]);
                $info .= ' Stock could not be reserved again: check the stock.';
            }
        }
        if ($sid === OrderStatus::CANCEL) {
            $info .= ' Paid after the order was cancelled (late payment): order reopened, check the stock.';
        }
        $this->purchaseFlow->commit($Order, new PurchaseContext());
        $this->setPaid($Order);
        $this->note($Order, $info);
        $this->em->flush();
        if ($firstCommit) {
            $this->mailService->sendOrderMail($Order);
        }

        return 'paid';
    }

    private function setPaid(Order $Order)
    {
        $Order->setOrderStatus($this->orderStatusRepository->find(OrderStatus::PAID));
        $Order->setPaymentDate(new \DateTime());
    }

    /**
     * Gives stock and used points back. From the customer's browser (cancel, return) the shopping PurchaseFlow is used, as in
     * EC-CUBE's own link payments; from the webhook there is no cart session (PreOrderIdValidator would refuse), so the same
     * stock and point processors are called directly, exactly like OrderStateMachine does when an order is cancelled.
     */
    private function rollback(Order $Order, $statusId, $fromCustomer = false)
    {
        try {
            if ($fromCustomer) {
                $this->purchaseFlow->rollback($Order, new PurchaseContext());
            } else {
                $this->stockReduceProcessor->rollback($Order, new PurchaseContext());
                $this->pointProcessor->rollback($Order, new PurchaseContext());
            }
        } catch (\Exception $e) {
            log_error('[Blokbay] rollback failed', [$Order->getId(), get_class($e), $e->getMessage()]);
            $this->note($Order, 'Blokbay: stock could not be released automatically, check the stock.');
        }
        $Order->setOrderStatus($this->orderStatusRepository->find($statusId));
    }

    private function statusId(Order $Order)
    {
        $s = $Order->getOrderStatus();

        return $s ? (int) $s->getId() : 0;
    }

    /** Shop memo (ショップ用メモ欄), visible only in the admin. */
    private function note(Order $Order, $line)
    {
        $note = trim((string) $Order->getNote());
        if (strpos($note, $line) !== false) {
            return;
        }
        $Order->setNote(mb_substr(ltrim($note."\n".'['.date('Y-m-d H:i').'] '.$line), -4000));
    }
}
