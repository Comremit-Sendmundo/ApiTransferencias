<?php

namespace App\Tests\DTO;

use App\DTO\CreateCommunicationOperatorDto;
use App\DTO\UpdateCommunicationOperatorDto;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @covers \App\DTO\CreateCommunicationOperatorDto
 * @covers \App\DTO\UpdateCommunicationOperatorDto
 */
class CreateCommunicationOperatorDtoTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    private function dto(array $override = []): CreateCommunicationOperatorDto
    {
        $args = array_merge([
            'id' => 161,
            'name' => 'Cubacel',
            'countryName' => 'Cuba',
            'countryIsoCode' => 'CUB',
            'countryRegions' => [],
            'regions' => [['name' => 'La Habana', 'code' => 'CU-03']],
            'icon' => 'data:image/png;base64,AAAA',
            'metadata' => ['es' => ['title' => 'Recargas Cubacel', 'description' => 'Recarga', 'keywords' => ['cubacel']]],
        ], $override);

        return new CreateCommunicationOperatorDto(...$args);
    }

    public function testUnOperadorCompletoEsValido(): void
    {
        $this->assertCount(0, $this->validator->validate($this->dto()));
    }

    /** @dataProvider invalidos */
    public function testRechazaDatosInvalidos(array $override, string $property): void
    {
        $paths = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($this->validator->validate($this->dto($override))));

        $this->assertContains($property, $paths);
    }

    public static function invalidos(): iterable
    {
        yield 'id nulo' => [['id' => null], 'id'];
        yield 'id no positivo' => [['id' => 0], 'id'];
        yield 'nombre vacío' => [['name' => ''], 'name'];
        yield 'país sin nombre' => [['countryName' => ''], 'countryName'];
        yield 'iso en minúsculas' => [['countryIsoCode' => 'cub'], 'countryIsoCode'];
        yield 'iso de dos letras' => [['countryIsoCode' => 'CU'], 'countryIsoCode'];
        yield 'región sin código' => [['regions' => [['name' => 'Oriente']]], 'regions[0][code]'];
        yield 'código de región inválido' => [['regions' => [['name' => 'Oriente', 'code' => 'x']]], 'regions[0][code]'];
        yield 'icono que no es imagen' => [['icon' => 'http://evil/x.png'], 'icon'];
        yield 'metadatos con keywords no lista de texto' => [['metadata' => ['es' => ['keywords' => [1]]]], 'metadata[es][keywords][0]'];
        yield 'metadatos con campo desconocido' => [['metadata' => ['es' => ['foo' => 'x']]], 'metadata[es][foo]'];
    }

    public function testLaActualizacionSoloValidaLoQueLlega(): void
    {
        $this->assertCount(0, $this->validator->validate(new UpdateCommunicationOperatorDto(name: 'Cubacel 2')));

        $paths = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($this->validator->validate(new UpdateCommunicationOperatorDto(countryIsoCode: 'cub'))));
        $this->assertContains('countryIsoCode', $paths);
    }
}
