<?php

namespace App\Tests\Architecture;

use App\DTO\IInput;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * InputValueResolver rellena con el cuerpo de la petición cualquier argumento que implemente IInput: si una
 * entidad lo hiciera, el cliente podría fijar cualquiera de sus campos.
 *
 * @coversNothing
 */
class RequestBodyNeverHydratesEntitiesTest extends KernelTestCase
{
    public function testNoDoctrineEntityIsAnInputDto(): void
    {
        self::bootKernel();
        $entities = array_map(
            static fn ($metadata): string => $metadata->getName(),
            self::getContainer()->get(EntityManagerInterface::class)->getMetadataFactory()->getAllMetadata(),
        );

        $this->assertGreaterThan(20, \count($entities), 'La prueba debe estar recorriendo las entidades.');
        $this->assertSame([], array_values(array_filter($entities, static fn (string $c): bool => is_subclass_of($c, IInput::class))));
    }
}
