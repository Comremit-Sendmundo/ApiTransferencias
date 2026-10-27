<?php

namespace App\Tests\Migrations;

use App\Entity\CommunicationOperator;
use App\Repository\CommunicationOperatorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Operadores de DTOne: la tabla communication_operator nace con el operador por defecto 161 (Cubacel, Cuba).
 *
 * @covers \DoctrineMigrations\Version20261024100000
 */
class Version20261024100000Test extends KernelTestCase
{
    public function testExisteElOperadorPorDefecto161(): void
    {
        self::bootKernel();
        /** @var CommunicationOperatorRepository $repository */
        $repository = self::getContainer()->get(EntityManagerInterface::class)->getRepository(CommunicationOperator::class);

        $operator = $repository->find(CommunicationOperator::DEFAULT_ID);

        $this->assertNotNull($operator);
        $this->assertSame(161, $operator->getId());
        $this->assertSame('Cubacel', $operator->getName());
        $this->assertSame('CUB', $operator->getCountryIsoCode());
        $this->assertSame('Cuba', $operator->getCountryName());
    }
}
