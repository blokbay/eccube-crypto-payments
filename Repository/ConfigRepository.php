<?php

/*
 * Blokbay crypto payments for EC-CUBE 4.2 / 4.3
 * (c) Blokbay, MIT License. https://blokbay.com/docs/api
 */

namespace Plugin\BlokbayPayment\Repository;

use Doctrine\Persistence\ManagerRegistry;
use Eccube\Repository\AbstractRepository;
use Plugin\BlokbayPayment\Entity\Config;

class ConfigRepository extends AbstractRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Config::class);
    }

    /** @return Config */
    public function get($id = 1)
    {
        $c = $this->find($id);

        return $c instanceof Config ? $c : new Config();
    }
}
