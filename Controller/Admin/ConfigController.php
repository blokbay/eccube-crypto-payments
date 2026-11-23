<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Controller\Admin;

use Eccube\Controller\AbstractController;
use Eccube\Repository\PaymentRepository;
use Plugin\BlokbayPayment\Form\Type\Admin\ConfigType;
use Plugin\BlokbayPayment\Repository\ConfigRepository;
use Plugin\BlokbayPayment\Service\Method\BlokbayCrypto;
use Plugin\BlokbayPayment\Service\OrderUpdater;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ConfigController extends AbstractController
{
    /** @var ConfigRepository */
    private $configRepository;
    /** @var PaymentRepository */
    private $paymentRepository;
    /** @var OrderUpdater */
    private $updater;

    public function __construct(ConfigRepository $configRepository, PaymentRepository $paymentRepository, OrderUpdater $updater)
    {
        $this->configRepository = $configRepository;
        $this->paymentRepository = $paymentRepository;
        $this->updater = $updater;
    }

    /**
     * @Route("/%eccube_admin_route%/blokbay_payment/config", name="blokbay_payment_admin_config")
     * @Template("@BlokbayPayment/admin/config.twig")
     */
    public function index(Request $request)
    {
        $Config = $this->configRepository->get();
        $form = $this->createForm(ConfigType::class);
        $form->get('public_key')->setData($Config->getPublicKey());
        $form->get('api_base')->setData($Config->getApiBase());
        $form->handleRequest($request);
        $usd = $this->updater->shopCurrency() === 'USD';

        if ($form->isSubmitted() && $form->isValid()) {
            $sk = trim((string) $form->get('secret_key')->getData());
            $wh = trim((string) $form->get('webhook_secret')->getData());
            if ($sk !== '') {
                $Config->setSecretKey($sk);
            }
            if ($wh !== '') {
                $Config->setWebhookSecret($wh);
            }
            $Config->setPublicKey(trim((string) $form->get('public_key')->getData()));
            $Config->setApiBase(rtrim(trim((string) $form->get('api_base')->getData()), '/'));
            $this->entityManager->persist($Config);
            // The payment method is shown at checkout only when the shop works in USD and the keys are saved.
            $Payment = $this->paymentRepository->findOneBy(['method_class' => BlokbayCrypto::class]);
            if ($Payment) {
                $Payment->setVisible($usd && $Config->isReady());
            }
            $this->entityManager->flush();
            $this->addSuccess($usd && $Config->isReady() ? 'blokbay_payment.admin.saved' : 'blokbay_payment.admin.saved_hidden', 'admin');

            return $this->redirectToRoute('blokbay_payment_admin_config');
        }

        return [
            'form' => $form->createView(),
            'Config' => $Config,
            'usd' => $usd,
            'currency' => $this->updater->shopCurrency(),
            'has_secret' => $Config->getSecretKey() !== '',
            'has_webhook' => $Config->getWebhookSecret() !== '',
            'webhook_url' => $this->generateUrl('blokbay_payment_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];
    }
}
