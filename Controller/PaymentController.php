<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Controller;

use Eccube\Controller\AbstractController;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\OrderRepository;
use Eccube\Service\CartService;
use Eccube\Service\OrderHelper;
use Plugin\BlokbayPayment\Entity\Transaction;
use Plugin\BlokbayPayment\Repository\TransactionRepository;
use Plugin\BlokbayPayment\Service\BlokbayApi;
use Plugin\BlokbayPayment\Service\Method\BlokbayCrypto;
use Plugin\BlokbayPayment\Service\OrderUpdater;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Customer return from the Blokbay payment page, cancel link, and the signed webhook.
 */
class PaymentController extends AbstractController
{
    /** @var OrderRepository */
    private $orderRepository;
    /** @var TransactionRepository */
    private $transactionRepository;
    /** @var OrderUpdater */
    private $updater;
    /** @var CartService */
    private $cartService;

    public function __construct(OrderRepository $orderRepository, TransactionRepository $transactionRepository, OrderUpdater $updater, CartService $cartService)
    {
        $this->orderRepository = $orderRepository;
        $this->transactionRepository = $transactionRepository;
        $this->updater = $updater;
        $this->cartService = $cartService;
    }

    /**
     * url_success: the customer comes back. The real status is asked to the Blokbay API (the webhook may not have arrived yet);
     * nothing in the URL is trusted.
     *
     * @Route("/blokbay_payment/return", name="blokbay_payment_return", methods={"GET"})
     */
    public function back(Request $request)
    {
        $Order = $this->order($request);
        if (!$Order) {
            return $this->redirectToRoute('homepage');
        }
        // Anyone can open this address with an order number: only the API answer is trusted, and only the customer's own
        // browser moves the order out of the payment step or sees the confirmation page.
        $mine = $this->isMine($Order);
        $tx = $this->transactionRepository->findCurrent($Order->getId());
        $status = '';
        if ($tx instanceof Transaction) {
            $p = $this->updater->api()->getPayment($tx->getPaymentId());
            if (isset($p['id'], $p['status']) && $p['id'] === $tx->getPaymentId() && is_string($p['status'])) {
                $status = $p['status'];
                if ($status === 'paid') {
                    $this->updater->apply($p);
                } elseif ($status === 'expired' && !($mine && $this->updater->backToCart($Order))) {
                    // Not in the payment step any more (already placed as NEW), or not the customer: normal expiry rules.
                    $this->updater->apply($p);
                }
            }
        }
        if (!$mine) {
            return $this->redirectToRoute('homepage');
        }
        $sid = $Order->getOrderStatus() ? (int) $Order->getOrderStatus()->getId() : 0;
        if ($status === 'expired' || $sid === OrderStatus::CANCEL || $sid === OrderStatus::PROCESSING) {
            $this->addError('blokbay_payment.front.expired');

            return $this->redirectToRoute('shopping');
        }
        if ($status !== 'paid' && $sid === OrderStatus::PENDING) {
            // Still pending (confirmations) or Blokbay not reachable: place the order, the webhook will mark it paid.
            $this->updater->awaiting($Order);
        }

        return $this->complete($Order);
    }

    /**
     * url_cancel: the customer left the payment page. Stock is released and the checkout opens again with the same cart.
     *
     * @Route("/blokbay_payment/cancel", name="blokbay_payment_cancel", methods={"GET"})
     */
    public function cancel(Request $request)
    {
        $Order = $this->order($request);
        if (!$Order || !$this->isMine($Order)) {
            return $this->redirectToRoute('homepage');
        }
        $tx = $this->transactionRepository->findCurrent($Order->getId());
        if ($tx instanceof Transaction) {
            // Paid in the meantime (another tab, late confirmation): never send a paying customer back to the cart.
            $p = $this->updater->api()->getPayment($tx->getPaymentId());
            if (isset($p['id'], $p['status']) && $p['id'] === $tx->getPaymentId() && $p['status'] === 'paid') {
                $this->updater->apply($p);
                $sid = $Order->getOrderStatus() ? (int) $Order->getOrderStatus()->getId() : 0;
                if ($sid === OrderStatus::PAID) {
                    return $this->complete($Order);
                }
            }
        }
        if ($this->updater->backToCart($Order)) {
            $this->addWarning('blokbay_payment.front.cancelled');
        }

        return $this->redirectToRoute('shopping');
    }

    /**
     * Webhook: POST https://your-shop/blokbay_payment/webhook
     * Header Blokbay-Signature "t=<unix>,v1=<HMAC-SHA256 of t.body>". No login: the signature is the authentication.
     *
     * @Route("/blokbay_payment/webhook", name="blokbay_payment_webhook", methods={"POST"})
     */
    public function webhook(Request $request)
    {
        $raw = (string) $request->getContent();
        $config = $this->updater->config();
        if (!BlokbayApi::verifySignature($raw, (string) $request->headers->get('Blokbay-Signature', ''), $config->getWebhookSecret())) {
            return $this->reply(400, 'bad signature');
        }
        $ev = json_decode($raw, true);
        if (!is_array($ev) || !isset($ev['type']) || !is_string($ev['type'])) {
            return $this->reply(400, 'bad payload');
        }
        if ($config->getPublicKey() !== '' && (isset($ev['account']) ? $ev['account'] : null) !== $config->getPublicKey()) {
            return $this->reply(400, 'other account');
        }
        if ($ev['type'] === 'ping') {
            return $this->reply(200, 'pong');
        }
        $data = isset($ev['data']) && is_array($ev['data']) ? $ev['data'] : [];
        if ($ev['type'] !== 'payment.paid' && $ev['type'] !== 'payment.expired') {
            return $this->reply(200, 'ignored');
        }

        return $this->reply(200, $this->updater->apply($data));
    }

    private function order(Request $request)
    {
        $no = (string) $request->query->get('no', '');
        if ($no === '' || strlen($no) > 255) {
            return null;
        }
        $Order = $this->orderRepository->findOneBy(['order_no' => $no]);

        return $Order instanceof Order ? $Order : null;
    }

    /** The order belongs to this browser: the one sent to Blokbay from this session, or the cart's pre-order. */
    private function isMine(Order $Order)
    {
        $id = $this->session->get(BlokbayCrypto::SESSION_ORDER);
        if ($id && (int) $id === (int) $Order->getId()) {
            return true;
        }
        $pre = $this->cartService->getPreOrderId();

        return $pre && $pre === $Order->getPreOrderId();
    }

    private function complete(Order $Order)
    {
        $this->cartService->clear();
        $this->session->set(OrderHelper::SESSION_ORDER_ID, $Order->getId());
        $this->session->remove(BlokbayCrypto::SESSION_ORDER);
        $this->entityManager->flush();

        return $this->redirectToRoute('shopping_complete');
    }

    private function reply($code, $msg)
    {
        return new Response($msg, $code, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
