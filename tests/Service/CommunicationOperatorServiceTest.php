<?php

namespace App\Tests\Service;

use App\DTO\CreateCommunicationOperatorDto;
use App\DTO\UpdateCommunicationOperatorDto;
use App\Entity\CommunicationOperator;
use App\Exception\MyCurrentException;
use App\Repository\CommunicationOperatorRepository;
use App\Service\CommunicationOperatorService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\CommunicationOperatorService
 */
class CommunicationOperatorServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private CommunicationOperatorRepository&MockObject $repository;
    private CommunicationOperatorService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(CommunicationOperatorRepository::class);
        $this->service = new CommunicationOperatorService($this->em, $this->repository);
    }

    private function cubacel(): CommunicationOperator
    {
        return (new CommunicationOperator())->setId(161)->setName('Cubacel')->setCountryName('Cuba')->setCountryIsoCode('CUB');
    }

    public function testGetLanzaNotFoundSiNoExiste(): void
    {
        $this->repository->method('find')->willReturn(null);

        try {
            $this->service->get(999);
            $this->fail('Debía lanzar MyCurrentException');
        } catch (MyCurrentException $e) {
            $this->assertSame('OPERATOR_NOT_FOUND', $e->getCodeWork());
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testCreateGuardaElOperador(): void
    {
        $this->repository->method('find')->willReturn(null);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(CommunicationOperator::class));
        $this->em->expects($this->once())->method('flush');

        $operator = $this->service->create(new CreateCommunicationOperatorDto(
            id: 7,
            name: 'Africell Gambia',
            countryName: 'Gambia',
            countryIsoCode: 'GMB',
            regions: [],
            icon: 'data:image/png;base64,AAAA',
            metadata: ['es' => ['title' => 'Recargas']],
        ));

        $this->assertSame(7, $operator->getId());
        $this->assertSame('GMB', $operator->getCountryIsoCode());
        $this->assertSame('data:image/png;base64,AAAA', $operator->getIcon());
        $this->assertSame(['es' => ['title' => 'Recargas']], $operator->getMetadata());
    }

    public function testCreateRechazaUnIdRepetido(): void
    {
        $this->repository->method('find')->willReturn($this->cubacel());
        $this->em->expects($this->never())->method('persist');

        try {
            $this->service->create(new CreateCommunicationOperatorDto(id: 161, name: 'X', countryName: 'Cuba', countryIsoCode: 'CUB'));
            $this->fail('Debía lanzar MyCurrentException');
        } catch (MyCurrentException $e) {
            $this->assertSame('OPERATOR_ALREADY_EXISTS', $e->getCodeWork());
            $this->assertSame(409, $e->getCode());
        }
    }

    public function testUpdateSoloCambiaLoQueLlega(): void
    {
        $operator = $this->cubacel()->setIcon('data:image/png;base64,AAAA');
        $this->repository->method('find')->with(161)->willReturn($operator);
        $this->em->expects($this->once())->method('flush');

        $updated = $this->service->update(161, new UpdateCommunicationOperatorDto(name: 'Cubacel Móvil', metadata: ['es' => ['title' => 'T']]));

        $this->assertSame('Cubacel Móvil', $updated->getName());
        $this->assertSame('Cuba', $updated->getCountryName());
        $this->assertSame('data:image/png;base64,AAAA', $updated->getIcon());
        $this->assertSame(['es' => ['title' => 'T']], $updated->getMetadata());
    }

    public function testUpdateConIconoVacioLoQuita(): void
    {
        $operator = $this->cubacel()->setIcon('data:image/png;base64,AAAA');
        $this->repository->method('find')->willReturn($operator);

        $this->assertNull($this->service->update(161, new UpdateCommunicationOperatorDto(icon: ''))->getIcon());
    }

    public function testUpdateLanzaNotFoundSiNoExiste(): void
    {
        $this->repository->method('find')->willReturn(null);
        $this->expectException(MyCurrentException::class);

        $this->service->update(999, new UpdateCommunicationOperatorDto(name: 'X'));
    }
}
