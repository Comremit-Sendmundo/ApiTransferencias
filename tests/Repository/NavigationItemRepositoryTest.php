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

    public function testElPermisoValePorRolClienteOUsuarioSinAnadirPadres(): void
    {
        $client = $this->cliente();
        $user = $this->usuario(['ROLE_SYSTEM_USER'], $client);
        $raiz = $this->item('Raíz');
        $porRol = $this->item('Por rol', $raiz);
        $porCliente = $this->item('Por cliente', $raiz);
        $porUsuario = $this->item('Por usuario', $raiz);
        $ajeno = $this->item('Ajeno', $raiz);
        $this->permiso($porRol, 'ROLE_SYSTEM_USER');
        $this->permiso($porCliente, client: $client);
        $this->permiso($porUsuario, user: $user);
        $this->permiso($ajeno, client: $this->cliente());
        $this->guardar();

        $ids = $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], $client->getId(), $user->getId());

        $this->assertTrue(array_is_list($ids));
        $this->assertEqualsCanonicalizing(
            [$porRol->getId(), $porCliente->getId(), $porUsuario->getId()],
            $ids,
        );
        $this->assertSame([$porRol->getId()], $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], null, null));
        $this->assertSame([$porCliente->getId()], $this->repository->findPermittedItemIds([], $client->getId(), null));
        $this->assertSame([$porUsuario->getId()], $this->repository->findPermittedItemIds([], null, $user->getId()));
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
        $this->permiso($primero, client: $client);
        $this->permiso($segundo, 'ROLE_SYSTEM_USER');
        $this->guardar();

        $ids = $this->repository->findPermittedItemIds(['ROLE_SYSTEM_USER'], $client->getId(), null);

        $this->assertTrue(array_is_list($ids));
        $this->assertEqualsCanonicalizing([$primero->getId(), $segundo->getId()], $ids);
    }

    public function testSinCondicionesNoHayPermisosPropios(): void
    {
        $this->permiso($this->item('Raíz'), 'ROLE_SYSTEM_USER');
        $this->guardar();

        $this->assertSame([], $this->repository->findPermittedItemIds([], null, null));
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
