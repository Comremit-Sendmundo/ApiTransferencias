<?php

namespace App\Tests\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\ApiResource\CommunicationOperatorResource;
use App\Entity\CommunicationOperator;
use App\Repository\CommunicationOperatorRepository;
use App\State\CommunicationOperatorProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \App\State\CommunicationOperatorProvider
 * @covers \App\ApiResource\CommunicationOperatorResource
 */
class CommunicationOperatorProviderTest extends TestCase
{
    private CommunicationOperatorRepository&MockObject $repository;
    private CommunicationOperatorProvider $provider;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(CommunicationOperatorRepository::class);
        $this->provider = new CommunicationOperatorProvider($this->repository);
    }

    private function operator(int $id, string $name = 'Cubacel'): CommunicationOperator
    {
        return (new CommunicationOperator())
            ->setId($id)
            ->setName($name)
            ->setCountryName('Cuba')
            ->setCountryIsoCode('CUB')
            ->setRegions([['name' => 'La Habana', 'code' => 'CU-03']])
            ->setIcon('data:image/png;base64,AAAA')
            ->setMetadata(['es' => ['title' => 'Cubacel']]);
    }

    public function testItemIncludesIconAndMetadata(): void
    {
        $this->repository->method('find')->with(161)->willReturn($this->operator(161));

        $resource = $this->provider->provide(new Get(), ['id' => 161]);

        $this->assertInstanceOf(CommunicationOperatorResource::class, $resource);
        $this->assertSame(161, $resource->id);
        $this->assertSame('Cubacel', $resource->name);
        $this->assertSame(['name' => 'Cuba', 'iso_code' => 'CUB', 'regions' => []], $resource->country);
        $this->assertSame([['name' => 'La Habana', 'code' => 'CU-03']], $resource->regions);
        $this->assertSame('data:image/png;base64,AAAA', $resource->icon);
        $this->assertTrue($resource->hasIcon);
        $this->assertSame(['es' => ['title' => 'Cubacel']], $resource->metadata);
    }

    public function testUnknownItemReturnsNull(): void
    {
        $this->repository->method('find')->willReturn(null);

        $this->assertNull($this->provider->provide(new Get(), ['id' => 999]));
    }

    public function testCollectionOmitsIconButFlagsIt(): void
    {
        $this->repository->method('search')->willReturn([$this->operator(161)]);

        $items = iterator_to_array($this->provider->provide(new GetCollection(), [], []), false);

        $this->assertCount(1, $items);
        $this->assertNull($items[0]->icon);
        $this->assertTrue($items[0]->hasIcon);
    }

    public function testCollectionPassesCountryAndSearchFilters(): void
    {
        $request = new Request(['country' => 'cub', 'search' => 'cuba']);
        $this->repository->expects($this->once())
            ->method('search')
            ->with('cub', 'cuba', $this->anything(), 0)
            ->willReturn([]);

        $this->provider->provide(new GetCollection(), [], ['request' => $request]);
    }
}
