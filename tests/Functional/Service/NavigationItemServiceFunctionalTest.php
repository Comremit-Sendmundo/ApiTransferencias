<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\DTO\CreateNavigationItemDto;
use App\DTO\CreateUserPermissionDto;
use App\DTO\UpdateNavigationItemDto;
use App\DTO\UpdateUserPermissionDto;
use App\Entity\Client;
use App\Entity\NavigationItem;
use App\Entity\User;
use App\Entity\UserPermission;
use App\Exception\MyCurrentException;
use App\OpenApi\InputValueResolver;
use App\Service\NavigationItemService;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\NavigationFixtures;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;

/**
 * Alta y edición de items de menú y de sus permisos: lo que no es válido se rechaza sin tocar la base.
 *
 * @covers \App\Service\NavigationItemService
 */
class NavigationItemServiceFunctionalTest extends FunctionalTestCase
{
    use NavigationFixtures;

    private const NO_EXISTE = 999999999;

    private NavigationItemService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = self::getContainer()->get(NavigationItemService::class);
    }

    // ---- createPermission ----

    public function testCrearUnPermisoGuardaClienteYUsuario(): void
    {
        $ids = $this->escenario();

        $id = $this->crearPermiso($ids['item'], $ids['cliente'], $ids['usuario']);

        self::assertSame(
            ['ROLE_SYSTEM_USER', true, $ids['cliente'], $ids['usuario']],
            $this->permisoGuardado($id),
        );
    }

    public function testCrearUnPermisoAdmiteUnClienteYUnUsuarioInactivos(): void
    {
        $ids = $this->escenario();

        $id = $this->crearPermiso($ids['item'], $ids['clienteInactivo'], $ids['usuarioInactivo']);

        self::assertSame(
            ['ROLE_SYSTEM_USER', true, $ids['clienteInactivo'], $ids['usuarioInactivo']],
            $this->permisoGuardado($id),
        );
    }

    public function testCrearUnPermisoAdmiteSoloClienteOSoloUsuario(): void
    {
        $ids = $this->escenario();

        $soloCliente = $this->crearPermiso($ids['item'], $ids['cliente'], null);
        $soloUsuario = $this->crearPermiso($ids['item'], null, $ids['usuarioSinCliente']);

        self::assertSame(['ROLE_SYSTEM_USER', true, $ids['cliente'], null], $this->permisoGuardado($soloCliente));
        self::assertSame(['ROLE_SYSTEM_USER', true, null, $ids['usuarioSinCliente']], $this->permisoGuardado($soloUsuario));
    }

    /** @dataProvider destinosNoValidos */
    public function testCrearUnPermisoNoValidoNoCreaNada(?string $cliente, ?string $usuario, string $codigo, int $estado): void
    {
        $ids = $this->escenario();
        $antes = $this->totales();

        $this->assertDomainError($codigo, $estado, fn () => $this->crearPermiso(
            $ids['item'],
            $cliente === null ? null : $ids[$cliente],
            $usuario === null ? null : $ids[$usuario],
        ));

        self::assertSame($antes, $this->totales());
    }

    /** @return iterable<string, array{?string, ?string, string, int}> */
    public static function destinosNoValidos(): iterable
    {
        yield 'cliente inexistente' => ['noExiste', null, 'CLIENT_NOT_FOUND', 404];
        yield 'cliente cero' => ['cero', null, 'CLIENT_NOT_FOUND', 404];
        yield 'usuario inexistente' => [null, 'noExiste', 'USER_NOT_FOUND', 404];
        yield 'usuario cero' => [null, 'cero', 'USER_NOT_FOUND', 404];
        yield 'usuario eliminado' => ['cliente', 'usuarioEliminado', 'USER_NOT_FOUND', 404];
        yield 'usuario de otro cliente' => ['cliente', 'usuarioAjeno', 'USER_NOT_IN_CLIENT', 422];
        yield 'usuario sin cliente' => ['cliente', 'usuarioSinCliente', 'USER_NOT_IN_CLIENT', 422];
        yield 'antes el cliente que el usuario' => ['noExiste', 'noExiste', 'CLIENT_NOT_FOUND', 404];
    }

    // ---- createItem ----

    public function testCrearUnItemGuardaSuPadreYElPermisoConClienteYUsuario(): void
    {
        $ids = $this->escenario();

        $itemId = (int) $this->service->createItem(new CreateNavigationItemDto(
            title: 'Nuevo',
            type: 'basic',
            parentId: $ids['item'],
            minRoleRequired: 'ROLE_SYSTEM_USER',
            clientId: $ids['cliente'],
            userId: $ids['usuario'],
        ))->getId();
        $this->em->clear();

        $item = $this->leerItem($itemId);
        self::assertSame($ids['item'], $item->getParent()?->getId());
        $permisos = $item->getUserPermissions()->toArray();
        self::assertCount(1, $permisos);
        self::assertSame(
            ['ROLE_SYSTEM_USER', true, $ids['cliente'], $ids['usuario']],
            $this->permisoGuardado((int) $permisos[0]->getId()),
        );
    }

    /** @dataProvider itemsNoValidos */
    public function testCrearUnItemNoValidoNoCreaNada(
        ?string $padre,
        ?string $cliente,
        ?string $usuario,
        string $codigo,
        int $estado,
    ): void {
        $ids = $this->escenario();
        $antes = $this->totales();

        $this->assertDomainError($codigo, $estado, fn () => $this->service->createItem(new CreateNavigationItemDto(
            title: 'Nuevo',
            type: 'basic',
            parentId: $padre === null ? null : $ids[$padre],
            minRoleRequired: 'ROLE_SYSTEM_USER',
            clientId: $cliente === null ? null : $ids[$cliente],
            userId: $usuario === null ? null : $ids[$usuario],
        )));

        self::assertSame($antes, $this->totales());
    }

    /** @return iterable<string, array{?string, ?string, ?string, string, int}> */
    public static function itemsNoValidos(): iterable
    {
        yield 'padre inexistente' => ['noExiste', null, null, 'PARENT_NOT_FOUND', 404];
        yield 'padre cero' => ['cero', null, null, 'PARENT_NOT_FOUND', 404];
        yield 'cliente inexistente' => ['item', 'noExiste', null, 'CLIENT_NOT_FOUND', 404];
        yield 'cliente cero' => [null, 'cero', null, 'CLIENT_NOT_FOUND', 404];
        yield 'usuario eliminado' => [null, null, 'usuarioEliminado', 'USER_NOT_FOUND', 404];
        yield 'usuario de otro cliente' => [null, 'cliente', 'usuarioAjeno', 'USER_NOT_IN_CLIENT', 422];
        yield 'antes el padre que el cliente' => ['noExiste', 'noExiste', 'noExiste', 'PARENT_NOT_FOUND', 404];
        yield 'antes el cliente que el usuario' => ['item', 'noExiste', 'noExiste', 'CLIENT_NOT_FOUND', 404];
    }

    // ---- updateItem ----

    /** @dataProvider padresNoValidos */
    public function testEditarUnItemConUnPadreNoValidoNoModificaNada(string $padre, string $codigo, int $estado): void
    {
        $ids = $this->arbol();
        $antes = $this->filaDeItem($ids['medio']);

        $this->assertDomainError($codigo, $estado, fn () => $this->service->updateItem(
            $this->leerItem($ids['medio']),
            new UpdateNavigationItemDto(title: 'Cambiado', parentId: $ids[$padre], active: false),
        ));

        self::assertSame($antes, $this->filaDeItem($ids['medio']));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function padresNoValidos(): iterable
    {
        yield 'inexistente' => ['noExiste', 'PARENT_NOT_FOUND', 404];
        yield 'cero' => ['cero', 'PARENT_NOT_FOUND', 404];
        yield 'el propio item' => ['medio', 'PARENT_CYCLE', 422];
        yield 'un hijo' => ['hijo', 'PARENT_CYCLE', 422];
        yield 'un descendiente lejano' => ['nieto', 'PARENT_CYCLE', 422];
    }

    public function testEditarUnItemAdmiteUnPadreQueNoDesciendeDeEl(): void
    {
        $ids = $this->arbol();

        $this->service->updateItem($this->leerItem($ids['nieto']), new UpdateNavigationItemDto(parentId: $ids['raiz']));
        $this->em->clear();

        self::assertSame($ids['raiz'], $this->leerItem($ids['nieto'])->getParent()?->getId());
    }

    /** Un ciclo que ya esté guardado más arriba no debe dejar la comprobación dando vueltas. */
    public function testUnCicloPreexistenteEntreLosAscendientesNoBloqueaLaComprobacion(): void
    {
        $uno = $this->item('Uno');
        $dos = $this->item('Dos', $uno);
        $uno->setParent($dos);
        $suelto = $this->item('Suelto');
        $this->em->flush();
        [$unoId, $sueltoId] = [(int) $uno->getId(), (int) $suelto->getId()];
        $this->em->clear();

        $this->service->updateItem($this->leerItem($sueltoId), new UpdateNavigationItemDto(parentId: $unoId));
        $this->em->clear();

        self::assertSame($unoId, $this->leerItem($sueltoId)->getParent()?->getId());
    }

    public function testEditarUnItemSinPadreSigueDejandoloEnLaRaiz(): void
    {
        $ids = $this->arbol();

        $this->service->updateItem($this->leerItem($ids['medio']), new UpdateNavigationItemDto(title: 'Cambiado'));
        $this->em->clear();

        self::assertNull($this->leerItem($ids['medio'])->getParent());
    }

    // ---- updatePermission ----

    /**
     * @dataProvider edicionesValidas
     *
     * @param array<string, mixed> $cuerpo   los textos de clientId y userId se sustituyen por el id del escenario
     * @param array{string, bool, ?string, ?string} $esperado
     */
    public function testEditarUnPermisoCambiaSoloLoEnviado(array $cuerpo, array $esperado): void
    {
        $ids = $this->escenario();
        $id = $this->crearPermiso($ids['item'], $ids['cliente'], $ids['usuario']);

        $this->service->updatePermission($this->leerPermiso($id), $this->edicion($cuerpo, $ids));
        $this->em->clear();

        [$rol, $activo, $cliente, $usuario] = $esperado;
        self::assertSame(
            [$rol, $activo, $cliente === null ? null : $ids[$cliente], $usuario === null ? null : $ids[$usuario]],
            $this->permisoGuardado($id),
        );
    }

    /** @return iterable<string, array{array<string, mixed>, array{string, bool, ?string, ?string}}> */
    public static function edicionesValidas(): iterable
    {
        $rol = 'ROLE_SYSTEM_USER';

        yield 'cuerpo vacío' => [[], [$rol, true, 'cliente', 'usuario']];
        yield 'solo el estado' => [['isActive' => false], [$rol, false, 'cliente', 'usuario']];
        yield 'solo el rol' => [['minRoleRequired' => 'ROLE_ADMIN'], ['ROLE_ADMIN', true, 'cliente', 'usuario']];
        yield 'quitar el usuario' => [['userId' => null], [$rol, true, 'cliente', null]];
        yield 'quitar el cliente' => [['clientId' => null], [$rol, true, null, 'usuario']];
        yield 'quitar los dos' => [['clientId' => null, 'userId' => null], [$rol, true, null, null]];
        yield 'cambiar el usuario' => [['userId' => 'usuarioCompanero'], [$rol, true, 'cliente', 'usuarioCompanero']];
        yield 'cambiar los dos' => [
            ['clientId' => 'otroCliente', 'userId' => 'usuarioAjeno'],
            [$rol, true, 'otroCliente', 'usuarioAjeno'],
        ];
        yield 'otro cliente quitando el usuario' => [
            ['clientId' => 'otroCliente', 'userId' => null],
            [$rol, true, 'otroCliente', null],
        ];
        yield 'quitar el cliente y poner un usuario sin cliente' => [
            ['clientId' => null, 'userId' => 'usuarioSinCliente'],
            [$rol, true, null, 'usuarioSinCliente'],
        ];
        yield 'cliente y usuario inactivos' => [
            ['clientId' => 'clienteInactivo', 'userId' => 'usuarioInactivo'],
            [$rol, true, 'clienteInactivo', 'usuarioInactivo'],
        ];
    }

    /**
     * @dataProvider edicionesNoValidasDePermiso
     *
     * @param array<string, mixed> $cuerpo
     */
    public function testUnaEdicionNoValidaDeUnPermisoNoModificaNada(array $cuerpo, string $codigo, int $estado): void
    {
        $ids = $this->escenario();
        $id = $this->crearPermiso($ids['item'], $ids['cliente'], $ids['usuario']);
        $antes = $this->permisoGuardado($id);
        $dto = $this->edicion(['minRoleRequired' => 'ROLE_ADMIN', 'isActive' => false] + $cuerpo, $ids);

        $this->assertDomainError(
            $codigo,
            $estado,
            fn () => $this->service->updatePermission($this->leerPermiso($id), $dto),
        );

        self::assertSame($antes, $this->permisoGuardado($id));
    }

    /** @return iterable<string, array{array<string, mixed>, string, int}> */
    public static function edicionesNoValidasDePermiso(): iterable
    {
        yield 'cliente inexistente' => [['clientId' => 'noExiste'], 'CLIENT_NOT_FOUND', 404];
        yield 'cliente cero' => [['clientId' => 'cero'], 'CLIENT_NOT_FOUND', 404];
        yield 'usuario inexistente' => [['userId' => 'noExiste'], 'USER_NOT_FOUND', 404];
        yield 'usuario eliminado' => [['userId' => 'usuarioEliminado'], 'USER_NOT_FOUND', 404];
        yield 'usuario ajeno al cliente guardado' => [['userId' => 'usuarioAjeno'], 'USER_NOT_IN_CLIENT', 422];
        yield 'usuario sin cliente' => [['userId' => 'usuarioSinCliente'], 'USER_NOT_IN_CLIENT', 422];
        yield 'cliente ajeno al usuario guardado' => [['clientId' => 'otroCliente'], 'USER_NOT_IN_CLIENT', 422];
        yield 'antes el cliente que el usuario' => [
            ['clientId' => 'noExiste', 'userId' => 'noExiste'],
            'CLIENT_NOT_FOUND',
            404,
        ];
    }

    // ---- apoyo ----

    /** @return array<string, int> */
    private function escenario(): array
    {
        $cliente = $this->cliente();
        $otroCliente = $this->cliente();
        $clienteInactivo = $this->cliente()->setIsActive(false);
        $usuarios = [
            'usuario' => $this->usuario(['ROLE_SYSTEM_USER'], $cliente),
            'usuarioCompanero' => $this->usuario(['ROLE_SYSTEM_USER'], $cliente),
            'usuarioAjeno' => $this->usuario(['ROLE_SYSTEM_USER'], $otroCliente),
            'usuarioSinCliente' => $this->usuario(['ROLE_SYSTEM_USER']),
            'usuarioInactivo' => $this->usuario(['ROLE_SYSTEM_USER'], $clienteInactivo)->setIsActive(false),
            'usuarioEliminado' => $this->usuario(['ROLE_SYSTEM_USER'], $cliente)
                ->setRemovedAt(new \DateTimeImmutable('2026-01-02 10:00:00')),
        ];
        $item = $this->item('Con permisos');
        $this->em->flush();

        $ids = [
            'noExiste' => self::NO_EXISTE,
            'cero' => 0,
            'item' => (int) $item->getId(),
            'cliente' => (int) $cliente->getId(),
            'otroCliente' => (int) $otroCliente->getId(),
            'clienteInactivo' => (int) $clienteInactivo->getId(),
        ] + array_map(static fn (User $user): int => (int) $user->getId(), $usuarios);
        $this->em->clear();

        return $ids;
    }

    /** @return array<string, int> raíz > medio > hijo > nieto */
    private function arbol(): array
    {
        $raiz = $this->item('Raíz');
        $medio = $this->item('Medio', $raiz);
        $hijo = $this->item('Hijo', $medio);
        $nieto = $this->item('Nieto', $hijo);
        $this->em->flush();

        $ids = [
            'noExiste' => self::NO_EXISTE,
            'cero' => 0,
            'raiz' => (int) $raiz->getId(),
            'medio' => (int) $medio->getId(),
            'hijo' => (int) $hijo->getId(),
            'nieto' => (int) $nieto->getId(),
        ];
        $this->em->clear();

        return $ids;
    }

    private function crearPermiso(int $itemId, ?int $clientId, ?int $userId): int
    {
        $permission = $this->service->createPermission(
            $this->leerItem($itemId),
            new CreateUserPermissionDto('ROLE_SYSTEM_USER', null, $clientId, $userId),
        );
        $id = (int) $permission->getId();
        $this->em->clear();

        return $id;
    }

    /**
     * El DTO tal como llega al servicio desde una petición.
     *
     * @param array<string, mixed> $cuerpo
     * @param array<string, int>   $ids
     */
    private function edicion(array $cuerpo, array $ids): UpdateUserPermissionDto
    {
        foreach (['clientId', 'userId'] as $campo) {
            if (is_string($cuerpo[$campo] ?? null)) {
                $cuerpo[$campo] = $ids[$cuerpo[$campo]];
            }
        }
        $request = Request::create(
            '/dashboard/api/navigation-admin/permissions/1',
            'PUT',
            content: $cuerpo === [] ? '{}' : json_encode($cuerpo, JSON_THROW_ON_ERROR),
        );
        $argument = new ArgumentMetadata('dto', UpdateUserPermissionDto::class, false, false, null);

        return [...self::getContainer()->get(InputValueResolver::class)->resolve($request, $argument)][0];
    }

    /** Ejecuta la acción, exige el error y confirma que un guardado posterior no arrastra cambios a medias. */
    private function assertDomainError(string $codigo, int $estado, callable $accion): void
    {
        try {
            $accion();
        } catch (MyCurrentException $e) {
            self::assertSame([$codigo, $estado], [$e->getCodeWork(), $e->getCode()]);
            $this->em->flush();
            $this->em->clear();

            return;
        }

        self::fail("Se esperaba el error {$codigo}");
    }

    private function leerItem(int $id): NavigationItem
    {
        $item = $this->em->find(NavigationItem::class, $id);
        self::assertNotNull($item);

        return $item;
    }

    private function leerPermiso(int $id): UserPermission
    {
        $permission = $this->em->find(UserPermission::class, $id);
        self::assertNotNull($permission);

        return $permission;
    }

    /** @return array{string, bool, ?int, ?int} rol, activo, cliente y usuario leídos de la base */
    private function permisoGuardado(int $id): array
    {
        $this->em->clear();
        $permission = $this->leerPermiso($id);

        return [
            (string) $permission->getMinRoleRequired(),
            (bool) $permission->isActive(),
            $permission->getClient()?->getId(),
            $permission->getUserInfo()?->getId(),
        ];
    }

    /** @return array<string, mixed> */
    private function filaDeItem(int $id): array
    {
        $fila = $this->em->getConnection()->fetchAssociative('SELECT * FROM navigation_item WHERE id = ?', [$id]);
        self::assertIsArray($fila);

        return $fila;
    }

    /** @return array{int, int} items y permisos guardados */
    private function totales(): array
    {
        $connection = $this->em->getConnection();

        return [
            (int) $connection->fetchOne('SELECT COUNT(*) FROM navigation_item'),
            (int) $connection->fetchOne('SELECT COUNT(*) FROM user_permission'),
        ];
    }
}
