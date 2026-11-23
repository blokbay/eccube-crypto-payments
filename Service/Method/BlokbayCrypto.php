<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Service\Method;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Entity\Master\OrderStatus;
use Eccube\Entity\Order;
use Eccube\Repository\Master\OrderStatusRepository;
use Eccube\Service\Payment\PaymentDispatcher;
use Eccube\Service\Payment\PaymentMethodInterface;
use Eccube\Service\Payment\PaymentResult;
use Eccube\Service\PurchaseFlow\PurchaseContext;
use Eccube\Service\PurchaseFlow\PurchaseFlow;
use Plugin\BlokbayPayment\Entity\Transaction;
use Plugin\BlokbayPayment\Repository\TransactionRepository;
use Plugin\BlokbayPayment\Service\BlokbayApi;
use Plugin\BlokbayPayment\Service\OrderUpdater;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Link type payment: apply() creates the Blokbay payment and redirects the customer to the Blokbay payment page.
 * The order stays PENDING (決済処理中) until the customer comes back (status checked with the API) or a signed webhook arrives.
 */
class BlokbayCrypto implements PaymentMethodInterface
{
    const SESSION_ORDER = 'blokbay_payment.order_id';

    /** @var Order */
    private $Order;
    /** @var FormInterface */
    private $form;
    /** @var PurchaseFlow */
    private $purchaseFlow;
    /** @var OrderStatusRepository */
    private $orderStatusRepository;
    /** @var TransactionRepository */
    private $transactionRepository;
    /** @var OrderUpdater */
    private $updater;
    /** @var UrlGeneratorInterface */
    private $urls;
    /** @var RequestStack */
    private $requestStack;
    /** @var EntityManagerInterface */
    private $em;

    public function __construct(
        PurchaseFlow $shoppingPurchaseFlow,
        OrderStatusRepository $orderStatusRepository,
        TransactionRepository $transactionRepository,
        OrderUpdater $updater,
        UrlGeneratorInterface $urls,
        RequestStack $requestStack,
        EntityManagerInterface $em
    ) {
        $this->purchaseFlow = $shoppingPurchaseFlow;
        $this->orderStatusRepository = $orderStatusRepository;
        $this->transactionRepository = $transactionRepository;
        $this->updater = $updater;
        $this->urls = $urls;
        $this->requestStack = $requestStack;
        $this->em = $em;
    }

    /** Errors that stop the checkout before the order is placed. */
    private function problems()
    {
        $errors = [];
        if (!$this->updater->config()->isReady()) {
            $errors[] = 'blokbay_payment.front.not_configured';
        } elseif (!BlokbayApi::supported($this->Order->getCurrencyCode()) || strtoupper((string) $this->Order->getCurrencyCode()) !== $this->updater->shopCurrency()) {
            $errors[] = 'blokbay_payment.front.currency_only';
        } elseif ($this->updater->shopCurrency() === 'USD' && (float) $this->Order->getPaymentTotal() < (float) BlokbayApi::MIN_AMOUNT) {
            $errors[] = 'blokbay_payment.front.min_amount';
        }

        return $errors;
    }

    public function verify()
    {
        $errors = $this->problems();
        $result = new PaymentResult();
        $result->setSuccess(!$errors);
        if ($errors) {
            $result->setErrors($errors);
        }

        return $result;
    }

    public function apply()
    {
        $errors = $this->problems();
        if ($errors) {
            return $this->backToShopping($errors[0]);
        }
        $Order = $this->Order;
        $currency = $this->updater->shopCurrency();
        $amount = BlokbayApi::amount($Order->getPaymentTotal(), $currency);
        $previous = $this->transactionRepository->findByOrder($Order->getId());
        $no = (string) $Order->getOrderNo();
        $res = $this->updater->api()->createPayment([
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $no,
            'description' => 'Order '.$no,
            'customer_email' => (string) $Order->getEmail(),
            'url_success' => $this->urls->generate('blokbay_payment_return', ['no' => $no], UrlGeneratorInterface::ABSOLUTE_URL),
            'url_cancel' => $this->urls->generate('blokbay_payment_cancel', ['no' => $no], UrlGeneratorInterface::ABSOLUTE_URL),
        ], 'eccube-'.$Order->getId().'-'.$amount.($currency === 'USD' ? '' : '-'.$currency).'-'.count($previous));
        if (empty($res['id']) || empty($res['url']) || !is_string($res['id']) || !is_string($res['url'])
            || !preg_match('/^gp_[a-z0-9]+$/', $res['id']) || !preg_match('#^https?://#', $res['url'])) {
            $err = isset($res['error']) && is_string($res['error']) ? $res['error'] : 'error';
            log_error('[Blokbay] payment not created', [$no, $err]);

            // Order stays PROCESSING (購入処理中): the cart is kept and the customer can choose again.
            return $this->backToShopping('blokbay_payment.front.create_failed');
        }
        // Same Idempotency-Key = same payment: do not store it twice.
        $tx = $this->transactionRepository->findByPaymentId($res['id']);
        foreach ($previous as $old) {
            if (!$tx || $old->getId() !== $tx->getId()) {
                $old->setCurrent(false);
            }
        }
        if (!$tx instanceof Transaction) {
            $tx = new Transaction();
            $tx->setPaymentId($res['id'])->setOrderId($Order->getId())->setAmount($amount)->setStatus('pending')->setCreateDate(new \DateTime());
            $this->em->persist($tx);
        }
        $tx->setCurrent(true);

        $Order->setOrderStatus($this->orderStatusRepository->find(OrderStatus::PENDING));
        $this->purchaseFlow->prepare($Order, new PurchaseContext());
        $this->em->flush();
        $session = $this->session();
        if ($session) {
            $session->set(self::SESSION_ORDER, $Order->getId());
        }

        $dispatcher = new PaymentDispatcher();
        $dispatcher->setResponse(new RedirectResponse($res['url']));

        return $dispatcher;
    }

    /** Not called: apply() always answers with a redirect. Kept for the interface. */
    public function checkout()
    {
        $result = new PaymentResult();
        $result->setSuccess(false);
        $result->setErrors(['blokbay_payment.front.create_failed']);

        return $result;
    }

    public function setFormType(FormInterface $form)
    {
        $this->form = $form;

        return $this;
    }

    public function setOrder(Order $Order)
    {
        $this->Order = $Order;

        return $this;
    }

    private function backToShopping($message)
    {
        $session = $this->session();
        if ($session && method_exists($session, 'getFlashBag')) {
            $session->getFlashBag()->add('eccube.front.error', $message);
        }
        $dispatcher = new PaymentDispatcher();
        $dispatcher->setResponse(new RedirectResponse($this->urls->generate('shopping')));

        return $dispatcher;
    }

    private function session()
    {
        $req = $this->requestStack->getCurrentRequest();

        return $req && $req->hasSession() ? $req->getSession() : null;
    }
}
