<?php

namespace App\Tests\Repository;

use App\Entity\CommunicationOperator;
use App\Repository\CommunicationOperatorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @covers \App\Repository\CommunicationOperatorRepository
 */
class CommunicationOperatorRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommunicationOperatorRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = $this->em->getRepository(CommunicationOperator::class);
        $this->em->getConnection()->beginTransaction();

        foreach ([[9001, 'Africell Gambia', 'Gambia', 'GMB'], [9002, 'Qcell', 'Gambia', 'GMB']] as [$id, $name, $country, $iso]) {
            $this->em->persist((new CommunicationOperator())->setId($id)->setName($name)->setCountryName($country)->setCountryIsoCode($iso));
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->rollBack();
        parent::tearDown();
    }

    /** @return int[] */
    private function ids(?string $country, ?string $search, int $limit = 50, int $offset = 0): array
    {
        return array_map(static fn (CommunicationOperator $o) => $o->getId(), $this->repository->search($country, $search, $limit, $offset));
    }

    public function testFiltraPorPaisSinDistinguirMayusculas(): void
    {
        $this->assertSame([9001, 9002], $this->ids('gmb', null));
    }

    public function testBuscaPorNombreDelOperadorOPais(): void
    {
        $this->assertSame([9002], $this->ids(null, 'QCEL'));
        $this->assertContains(9001, $this->ids(null, 'gambia'));
        $this->assertNotContains(161, $this->ids(null, 'gambia'));
    }

    public function testBuscaPorIdExacto(): void
    {
        $this->assertSame([161], $this->ids(null, '161'));
    }

    public function testPaginaYOrdenaPorPaisYNombre(): void
    {
        $this->assertSame([9001], $this->ids('GMB', null, 1, 0));
        $this->assertSame([9002], $this->ids('GMB', null, 1, 1));
    }
}
