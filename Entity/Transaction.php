<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;

/**
 * One Blokbay payment created for an order. The webhook finds the order by the EXACT payment id (never "the latest Blokbay
 * order"). An order can have several attempts: only the current one (is_current) may cancel the order when it expires.
 *
 * @ORM\Table(name="plg_blokbay_payment_transaction", indexes={@ORM\Index(name="plg_blokbay_tx_order", columns={"order_id"})})
 * @ORM\Entity(repositoryClass="Plugin\BlokbayPayment\Repository\TransactionRepository")
 */
class Transaction extends AbstractEntity
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="payment_id", type="string", length=64, unique=true)
     */
    private $payment_id;

    /**
     * @var int
     *
     * @ORM\Column(name="order_id", type="integer", options={"unsigned":true})
     */
    private $order_id;

    /**
     * @var string amount sent to Blokbay, "125.50" (USD)
     *
     * @ORM\Column(name="amount", type="string", length=32)
     */
    private $amount;

    /**
     * @var bool
     *
     * @ORM\Column(name="is_current", type="boolean", options={"default":true})
     */
    private $is_current = true;

    /**
     * @var string|null pending, paid, expired (last status seen)
     *
     * @ORM\Column(name="status", type="string", length=16, nullable=true)
     */
    private $status;

    /**
     * @var string|null
     *
     * @ORM\Column(name="tx", type="string", length=255, nullable=true)
     */
    private $tx;

    /**
     * @var \DateTime
     *
     * @ORM\Column(name="create_date", type="datetimetz")
     */
    private $create_date;

    public function getId()
    {
        return $this->id;
    }

    public function getPaymentId()
    {
        return $this->payment_id;
    }

    public function setPaymentId($v)
    {
        $this->payment_id = $v;

        return $this;
    }

    public function getOrderId()
    {
        return (int) $this->order_id;
    }

    public function setOrderId($v)
    {
        $this->order_id = (int) $v;

        return $this;
    }

    public function getAmount()
    {
        return (string) $this->amount;
    }

    public function setAmount($v)
    {
        $this->amount = (string) $v;

        return $this;
    }

    public function isCurrent()
    {
        return (bool) $this->is_current;
    }

    public function setCurrent($v)
    {
        $this->is_current = (bool) $v;

        return $this;
    }

    public function getStatus()
    {
        return (string) $this->status;
    }

    public function setStatus($v)
    {
        $this->status = $v;

        return $this;
    }

    public function getTx()
    {
        return (string) $this->tx;
    }

    public function setTx($v)
    {
        $this->tx = $v;

        return $this;
    }

    public function getCreateDate()
    {
        return $this->create_date;
    }

    public function setCreateDate(\DateTime $v)
    {
        $this->create_date = $v;

        return $this;
    }
}
