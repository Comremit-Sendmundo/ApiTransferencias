<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\NavigationItem;
use App\Repository\NavigationItemRepository;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\NavigationFixtures;

/**
 * @covers \App\Repository\NavigationItemRepository
 */
class NavigationItemRepositoryTest extends FunctionalTestCase
{
    use NavigationFixtures;

    private NavigationItemRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aislarMenu();
        $this->repository = $this->em->getRepository(NavigationItem::class);
    }

    private function guardar(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    // ---- findPermittedItemIds ----

    /**
     * Todas las combinaciones de un permiso: activo o no, rol alcanzado o no, y cliente y usuario vacíos, propios o ajenos.
     *
     * @dataProvider consultas
     *
     * @param list<string> $roles
     * @param list<string> $esperados
     */
    public function testElPermisoExigeALaVezRolClienteYUsuario(array $roles, bool $conCliente, bool $conUsuario, array $esperados): void
    {
        $propio = $this->cliente();
        $yo = $this->usuario(['ROLE_SYSTEM_USER'], $propio);
        $clientes = ['sin' => null, 'propio' => $propio, 'ajeno' => $this->cliente()];
        $usuarios = ['sin' => null, 'propio' => $yo, 'ajeno' => $this->usuario(['ROLE_SYSTEM_USER'], $propio)];
        $raiz = $this->item('Raíz');
        $items = [];
        foreach (['activo' => true, 'inactivo' => false] as $estado => $activo) {
            foreach (['alcanzado' => 'ROLE_SYSTEM_USER', 'no alcanzado' => 'ROLE_SUPER_ADMIN'] as $nombreRol => $rol) {
                foreach ($clientes as $nombreCliente => $cliente) {
                    foreach ($usuarios as $nombreUsuario => $usuario) {
                        $caso = "{$estado}, rol {$nombreRol}, cliente {$nombreCliente}, usuario {$nombreUsuario}";
                        $items[$caso] = $this->item($caso, $raiz);
                        $this->permiso($items[$caso], $rol, $cliente, $usuario, $activo);
                    }
                }
            }
        }
        $this->guardar();

        $ids = $this->repository->findPermittedItemIds(
            $roles,
            $conCliente ? $propio->getId() : null,
            $conUsuario ? $yo->getId() : null,
        );

        $casoPorId = [];
        foreach ($items as $caso => $item) {
            $casoPorId[$item->getId()] = $caso;
        }
        $this->assertTrue(array_is_list($ids));
        // Ni el padre ni ningún otro item fuera de la matriz.
        $this->assertSame([], array_values(array_diff($ids, array_keys($casoPorId))));
        $this->assertEqualsCanonicalizing($esperados, array_map(static fn (int $id): string => $casoPorId[$id], $ids));
    }

    /** @return iterable<string, array{list<string>, bool, bool, list<string>}> */
    public static function consultas(): iterable
    {
        $roles = ['ROLE_SYSTEM_USER', 'ROLE_USER'];

        yield 'con cliente y usuario' => [$roles, true, true, [
            'activo, rol alcanzado, cliente sin, usuario sin',
            'activo, rol alcanzado, cliente sin, usuario propio',
            'activo, rol alcanzado, cliente propio, usuario sin',
            'activo, rol alcanzado, cliente propio, usuario propio',
        ]];
        yield 'usuario sin cliente' => [$roles, false, true, [
            'activo, rol alcanzado, cliente sin, usuario sin',
            'activo, rol alcanzado, cliente sin, usuario propio',
        ]];
        yield 'solo cliente' => [$roles, true, false, [
            'activo, rol alcanzado, cliente sin, usuario sin',
            'activo, rol alcanzado, cliente propio, usuario sin',
        ]];
        yield 'solo roles' => [$roles, false, false, [
            'activo, rol alcanzado, cliente sin, usuario sin',
        ]];
        yield 'sin roles' => [[], true, true, []];
    }

    public function testVariosPermisosDeUnItemSonAlternativas(): void
    {
        $equis = $this->usuario(['ROLE_SYSTEM_ADMIN']);
        $otro = $this->usuario(['ROLE_SYSTEM_ADMIN']);
        $item = $this->item('Con alternativas');
        $this->permiso($item, 'ROLE_SUPER_ADMIN');
        $this->permiso($item, 'ROLE_SYSTEM_ADMIN', user: $equis);
        $this->guardar();

        $superAdmin = ['ROLE_SUPER_ADMIN', 'ROLE_SYSTEM_ADMIN'];
        $sistema = ['ROLE_SYSTEM_ADMIN'];

        $this->assertSame([$item->getId()], $this->repository->findPermittedItemIds($superAdmin, null, $otro->getId()));
        $this->assertSame([$item->getId()], $this->repository->findPermittedItemIds($sistema, null, $equis->getId()));
        $this->assertSame([], $this->repository->findPermittedItemIds($sistema, null, $otro->getId()));
        $this->assertSame([], $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], null, $equis->getId()));
    }

    public function testUnPermisoInactivoNoCuenta(): void
    {
        $this->permiso($this->item('Raíz'), 'ROLE_SYSTEM_USER', active: false);
        $this->guardar();

        $this->assertSame([], $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], null, null));
    }

    public function testUnItemConVariosPermisosSaleUnaSolaVez(): void
    {
        $client = $this->cliente();
        $primero = $this->item('Primero');
        $segundo = $this->item('Segundo');
        $this->permiso($primero, 'ROLE_SYSTEM_USER');
        $this->permiso($primero, 'ROLE_SYSTEM_USER', client: $client);
        $this->permiso($segundo, 'ROLE_SYSTEM_USER');
        $this->guardar();

        $ids = $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], $client->getId(), null);

        $this->assertTrue(array_is_list($ids));
        $this->assertEqualsCanonicalizing([$primero->getId(), $segundo->getId()], $ids);
    }

    // ---- findAllForMenu ----

    public function testDevuelveTodosLosItemsOrdenadosConElIdDeSuPadre(): void
    {
        $sinOrden = $this->item('Menú A sin orden');
        $segundo = $this->item('Menú B', order: '2');
        $empateZ = $this->item('Menú Z', order: '1');
        $empateA = $this->item('Menú A', order: '1');
        $gemeloUno = $this->item('Menú gemelo', $sinOrden, order: '3');
        $gemeloDos = $this->item('Menú gemelo', $gemeloUno, active: false, order: '3');
        $this->guardar();

        $creados = [
            $sinOrden->getId(),
            $segundo->getId(),
            $empateZ->getId(),
            $empateA->getId(),
            $gemeloUno->getId(),
            $gemeloDos->getId(),
        ];
        $filas = array_values(array_filter(
            $this->repository->findAllForMenu(),
            static fn (array $fila): bool => in_array($fila['item']->getId(), $creados, true),
        ));

        $this->assertSame(
            [
                [$empateA->getId(), null],
                [$empateZ->getId(), null],
                [$segundo->getId(), null],
                [$gemeloUno->getId(), $sinOrden->getId()],
                [$gemeloDos->getId(), $gemeloUno->getId()],
                [$sinOrden->getId(), null],
            ],
            array_map(static fn (array $fila): array => [$fila['item']->getId(), $fila['parentId']], $filas),
        );
        $this->assertContainsOnlyInstancesOf(NavigationItem::class, array_column($filas, 'item'));
    }

    // ---- accessIds: la sesión sigue recibiendo lo mismo ----

    public function testLosIdsPermitidosAnadenSoloElPadreDirecto(): void
    {
        $raiz = $this->item('Raíz');
        $hijo = $this->item('Hijo', $raiz);
        $nieto = $this->item('Nieto', $hijo);
        $this->permiso($nieto, 'ROLE_SYSTEM_USER');
        $this->guardar();

        $this->assertEqualsCanonicalizing(
            [$nieto->getId(), $hijo->getId()],
            $this->repository->getAllowedItemIds(['ROLE_SYSTEM_USER'], null, null),
        );
        $this->assertEqualsCanonicalizing(
            [
                ['parentId' => $hijo->getId(), 'childId' => $nieto->getId()],
                ['parentId' => $raiz->getId(), 'childId' => $hijo->getId()],
            ],
            $this->repository->accessIds(['ROLE_SYSTEM_USER']),
        );
    }

    public function testAccessIdsDeUnaRaizPermitidaNoArrastraSusHijosEInactivosSeMantienen(): void
    {
        $raiz = $this->item('Raíz', active: false);
        $this->item('Hijo', $raiz);
        $this->permiso($raiz, 'ROLE_SYSTEM_USER');
        $this->guardar();

        $this->assertSame(
            [['parentId' => $raiz->getId(), 'childId' => $raiz->getId()]],
            $this->repository->accessIds(['ROLE_SYSTEM_USER']),
        );
    }

    public function testSinCondicionesNoHayIdsPermitidos(): void
    {
        $this->permiso($this->item('Raíz'), 'ROLE_SYSTEM_USER');
        $this->guardar();

        $this->assertSame([], $this->repository->getAllowedItemIds([], null, null));
        $this->assertSame([], $this->repository->accessIds([]));
    }
}
