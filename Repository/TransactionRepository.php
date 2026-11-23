<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlokbayPayment\Entity\Transaction;

class TransactionRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /** Exact match on the Blokbay payment id. */
    public function findByPaymentId($paymentId)
    {
        if (!preg_match('/^gp_[a-z0-9]+$/', (string) $paymentId)) {
            return null;
        }

        return $this->findOneBy(['payment_id' => $paymentId]);
    }

    public function findCurrent($orderId)
    {
        return $this->findOneBy(['order_id' => (int) $orderId, 'is_current' => true], ['id' => 'DESC']);
    }

    /** @return Transaction[] */
    public function findByOrder($orderId)
    {
        return $this->findBy(['order_id' => (int) $orderId], ['id' => 'ASC']);
    }
}
