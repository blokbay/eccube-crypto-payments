<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment;

use Doctrine\ORM\EntityManagerInterface;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\Delivery;
use Eccube\Entity\Payment;
use Eccube\Entity\PaymentOption;
use Eccube\Plugin\AbstractPluginManager;
use Plugin\BlokbayPayment\Entity\Config;
use Plugin\BlokbayPayment\Service\Method\BlokbayCrypto;
use Psr\Container\ContainerInterface;

class PluginManager extends AbstractPluginManager
{
    public function enable(array $meta, ContainerInterface $container)
    {
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        // EntityManager::find, not getRepository(): the plugin repositories are not services yet while it is being enabled.
        $Config = $em->find(Config::class, 1);
        if (!$Config) {
            $Config = new Config();
            $em->persist($Config);
            $em->flush();
        }
        $currency = 'JPY';
        $locale = 'ja';
        if ($container->has(EccubeConfig::class)) {
            $c = $container->get(EccubeConfig::class);
            $currency = strtoupper((string) $c->get('currency'));
            $locale = (string) $c->get('locale');
        }
        $repo = $em->getRepository(Payment::class);
        $Payment = $repo->findOneBy(['method_class' => BlokbayCrypto::class]);
        if (!$Payment) {
            $Last = $repo->findOneBy([], ['sort_no' => 'DESC']);
            $Payment = new Payment();
            $Payment->setMethod($locale === 'ja' ? '暗号資産決済 (Blokbay)' : 'Crypto (Blokbay)')
                ->setMethodClass(BlokbayCrypto::class)
                ->setCharge(0)
                ->setRuleMin(1)
                ->setSortNo($Last ? $Last->getSortNo() + 1 : 1)
                ->setFixed(true)
                ->setVisible(false);
            $em->persist($Payment);
            $em->flush();
            // Offered with every existing delivery method (the merchant can remove it in 配送方法設定).
            foreach ($em->getRepository(Delivery::class)->findAll() as $Delivery) {
                $Option = new PaymentOption();
                $Option->setPaymentId($Payment->getId())->setPayment($Payment)
                    ->setDeliveryId($Delivery->getId())->setDelivery($Delivery);
                $em->persist($Option);
            }
        }
        // Visible only with the keys saved and a USD shop: the Blokbay API works in USD only.
        $Payment->setVisible($currency === 'USD' && $Config->isReady());
        $em->flush();
    }

    public function disable(array $meta, ContainerInterface $container)
    {
        $em = $container->get('doctrine.orm.entity_manager');
        $Payment = $em->getRepository(Payment::class)->findOneBy(['method_class' => BlokbayCrypto::class]);
        if ($Payment) {
            $Payment->setVisible(false);
            $em->flush();
        }
    }

    public function uninstall(array $meta, ContainerInterface $container)
    {
        // The payment row and the Blokbay transactions are kept: past orders still reference them.
        $this->disable($meta, $container);
    }
}
