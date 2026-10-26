<?php

namespace App\Tests\Controller;

use App\Controller\DashboardCommunicationOperatorsController;
use App\DTO\CreateCommunicationOperatorDto;
use App\DTO\UpdateCommunicationOperatorDto;
use App\Entity\CommunicationOperator;
use App\Exception\MyCurrentException;
use App\Service\CommunicationOperatorService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @covers \App\Controller\DashboardCommunicationOperatorsController
 */
class DashboardCommunicationOperatorsControllerTest extends TestCase
{
    private CommunicationOperatorService&MockObject $service;
    private DashboardCommunicationOperatorsController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(CommunicationOperatorService::class);
        $this->controller = new DashboardCommunicationOperatorsController($this->service);
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(false);
        $this->controller->setContainer($container);
    }

    private function cubacel(): CommunicationOperator
    {
        return (new CommunicationOperator())->setId(161)->setName('Cubacel')->setCountryName('Cuba')->setCountryIsoCode('CUB')
            ->setRegions([['name' => 'La Habana', 'code' => 'CU-03']])->setMetadata(['es' => ['title' => 'T']]);
    }

    private function body(Response $response): array
    {
        return json_decode((string) $response->getContent(), true);
    }

    public function testListaPaginadaConFiltros(): void
    {
        $this->service->expects($this->once())->method('list')->with('CUB', 'cuba', 1, 20)
            ->willReturn(['results' => [$this->cubacel()], 'hasNext' => true]);

        $response = $this->controller->list(new Request(['country' => 'CUB', 'search' => 'cuba', 'page' => 1]));
        $body = $this->body($response);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(20, $body['limit']);
        $this->assertSame(1, $body['currentPage']);
        $this->assertTrue($body['hasNext']);
        $this->assertTrue($body['hasPrevious']);
        $this->assertSame(161, $body['results'][0]['id']);
        $this->assertSame('CUB', $body['results'][0]['country']['isoCode']);
        $this->assertSame([['name' => 'La Habana', 'code' => 'CU-03']], $body['results'][0]['regions']);
    }

    public function testFiltraPorCountryIsoCode(): void
    {
        $this->service->expects($this->once())->method('list')->with('CUB', null, 0, 20)
            ->willReturn(['results' => [$this->cubacel()], 'hasNext' => false]);

        $response = $this->controller->list(new Request(['country_iso_code' => 'CUB']));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCountryIsoCodeGanaSobreCountry(): void
    {
        $this->service->expects($this->once())->method('list')->with('CUB', null, 0, 20)
            ->willReturn(['results' => [], 'hasNext' => false]);

        $this->controller->list(new Request(['country_iso_code' => 'CUB', 'country' => 'GMB']));
    }

    public function testLaListaNoIncluyeElIconoCompleto(): void
    {
        $operator = $this->cubacel()->setIcon('data:image/png;base64,AAAA');
        $this->service->method('list')->willReturn(['results' => [$operator], 'hasNext' => false]);

        $row = $this->body($this->controller->list(new Request()))['results'][0];

        $this->assertArrayNotHasKey('icon', $row);
        $this->assertTrue($row['hasIcon']);
    }

    public function testDetalleIncluyeIconoYMetadatos(): void
    {
        $this->service->method('get')->with(161)->willReturn($this->cubacel()->setIcon('data:image/png;base64,AAAA'));

        $body = $this->body($this->controller->get(161));

        $this->assertSame('data:image/png;base64,AAAA', $body['icon']);
        $this->assertSame(['es' => ['title' => 'T']], $body['metadata']);
    }

    public function testDetalleNoEncontradoDevuelve404(): void
    {
        $this->service->method('get')->willThrowException(new MyCurrentException('OPERATOR_NOT_FOUND', 'Operator not found', 404));

        $response = $this->controller->get(999);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Operator not found', $this->body($response)['error']['message']);
    }

    public function testCrearDevuelve201(): void
    {
        $dto = new CreateCommunicationOperatorDto(id: 161, name: 'Cubacel', countryName: 'Cuba', countryIsoCode: 'CUB');
        $this->service->expects($this->once())->method('create')->with($dto)->willReturn($this->cubacel());

        $this->assertSame(201, $this->controller->create($dto)->getStatusCode());
    }

    public function testCrearRepetidoDevuelve409(): void
    {
        $this->service->method('create')->willThrowException(new MyCurrentException('OPERATOR_ALREADY_EXISTS', 'Operator already exists', 409));

        $response = $this->controller->create(new CreateCommunicationOperatorDto(id: 161, name: 'X', countryName: 'Cuba', countryIsoCode: 'CUB'));

        $this->assertSame(409, $response->getStatusCode());
    }

    public function testActualizarDevuelve200(): void
    {
        $dto = new UpdateCommunicationOperatorDto(name: 'Cubacel Móvil');
        $this->service->expects($this->once())->method('update')->with(161, $dto)->willReturn($this->cubacel());

        $this->assertSame(200, $this->controller->update(161, $dto)->getStatusCode());
    }

    public function testActualizarNoEncontradoDevuelve404(): void
    {
        $this->service->method('update')->willThrowException(new MyCurrentException('OPERATOR_NOT_FOUND', 'Operator not found', 404));

        $this->assertSame(404, $this->controller->update(999, new UpdateCommunicationOperatorDto(name: 'X'))->getStatusCode());
    }
}
