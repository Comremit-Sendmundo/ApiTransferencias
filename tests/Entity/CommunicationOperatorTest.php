<?php

namespace App\Tests\Entity;

use App\Entity\CommunicationOperator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Entity\CommunicationOperator
 */
class CommunicationOperatorTest extends TestCase
{
    public function testGuardaLosCamposDelOperadorDeDTOne(): void
    {
        $operator = (new CommunicationOperator())
            ->setId(161)
            ->setName('Cubacel')
            ->setCountryName('Cuba')
            ->setCountryIsoCode('CUB')
            ->setCountryRegions([['name' => 'La Habana', 'code' => 'CU-03']])
            ->setRegions([['name' => 'Oriente', 'code' => 'CU-14']]);

        $this->assertSame(161, $operator->getId());
        $this->assertSame('Cubacel', $operator->getName());
        $this->assertSame('Cuba', $operator->getCountryName());
        $this->assertSame('CUB', $operator->getCountryIsoCode());
        $this->assertSame([['name' => 'La Habana', 'code' => 'CU-03']], $operator->getCountryRegions());
        $this->assertSame([['name' => 'Oriente', 'code' => 'CU-14']], $operator->getRegions());
    }

    public function testLosMetadatosSonOpcionalesYPorIdioma(): void
    {
        $operator = new CommunicationOperator();

        $this->assertSame([], $operator->getRegions());
        $this->assertSame([], $operator->getCountryRegions());
        $this->assertNull($operator->getIcon());
        $this->assertSame([], $operator->getMetadata());

        $metadata = ['es' => ['title' => 'Recargas Cubacel', 'description' => 'Recarga Cubacel', 'keywords' => ['cubacel']]];
        $operator->setIcon('data:image/png;base64,AAAA')->setMetadata($metadata);

        $this->assertSame('data:image/png;base64,AAAA', $operator->getIcon());
        $this->assertSame($metadata, $operator->getMetadata());
    }

    public function testMarcaLasFechasDeCreacionYActualizacion(): void
    {
        $operator = new CommunicationOperator();
        $this->assertNull($operator->getCreatedAt());

        $operator->onCreated();
        $operator->onUpdated();

        $this->assertInstanceOf(\DateTimeImmutable::class, $operator->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $operator->getUpdatedAt());
    }
}
