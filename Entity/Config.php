<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Entity;

use Doctrine\ORM\Mapping as ORM;
use Eccube\Entity\AbstractEntity;

/**
 * Plugin settings (one row, id 1).
 *
 * @ORM\Table(name="plg_blokbay_payment_config")
 * @ORM\Entity(repositoryClass="Plugin\BlokbayPayment\Repository\ConfigRepository")
 */
class Config extends AbstractEntity
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
     * @var string|null
     *
     * @ORM\Column(name="secret_key", type="string", length=255, nullable=true)
     */
    private $secret_key;

    /**
     * @var string|null
     *
     * @ORM\Column(name="public_key", type="string", length=255, nullable=true)
     */
    private $public_key;

    /**
     * @var string|null
     *
     * @ORM\Column(name="webhook_secret", type="string", length=255, nullable=true)
     */
    private $webhook_secret;

    /**
     * @var string|null
     *
     * @ORM\Column(name="api_base", type="string", length=255, nullable=true)
     */
    private $api_base;

    public function getId()
    {
        return $this->id;
    }

    public function getSecretKey()
    {
        return (string) $this->secret_key;
    }

    public function setSecretKey($v)
    {
        $this->secret_key = $v;

        return $this;
    }

    public function getPublicKey()
    {
        return (string) $this->public_key;
    }

    public function setPublicKey($v)
    {
        $this->public_key = $v;

        return $this;
    }

    public function getWebhookSecret()
    {
        return (string) $this->webhook_secret;
    }

    public function setWebhookSecret($v)
    {
        $this->webhook_secret = $v;

        return $this;
    }

    public function getApiBase()
    {
        return (string) $this->api_base;
    }

    public function setApiBase($v)
    {
        $this->api_base = $v;

        return $this;
    }

    public function isReady()
    {
        return $this->getSecretKey() !== '' && $this->getWebhookSecret() !== '';
    }
}
