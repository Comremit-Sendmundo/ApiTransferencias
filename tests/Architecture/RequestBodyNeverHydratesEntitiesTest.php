<?php

namespace App\Tests\Architecture;

use App\DTO\IInput;
use PHPUnit\Framework\TestCase;

/**
 * InputValueResolver rellena con el cuerpo de la petición cualquier argumento que implemente IInput: si una
 * entidad lo hiciera, el cliente podría fijar cualquiera de sus campos.
 *
 * @coversNothing
 */
class RequestBodyNeverHydratesEntitiesTest extends TestCase
{
    public function testNoDoctrineEntityIsAnInputDto(): void
    {
        $root = \dirname(__DIR__, 2) . '/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $offenders = [];
        $entities = 0;

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
            if (!class_exists($class) || (new \ReflectionClass($class))->getAttributes(\Doctrine\ORM\Mapping\Entity::class) === []) {
                continue;
            }
            ++$entities;
            if (is_subclass_of($class, IInput::class)) {
                $offenders[] = $class;
            }
        }

        $this->assertGreaterThan(20, $entities, 'La prueba debe estar recorriendo las entidades.');
        $this->assertSame([], $offenders);
    }
}
