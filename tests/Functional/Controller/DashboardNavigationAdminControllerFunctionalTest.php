<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\DashboardNavigationAdminController;
use App\DTO\CreateNavigationItemDto;
use App\DTO\CreateUserPermissionDto;
use App\DTO\UpdateNavigationItemDto;
use App\DTO\UpdateUserPermissionDto;
use App\Exception\MyCurrentException;
use App\OpenApi\InputValueResolver;
use App\Service\NavigationItemService;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\NavigationFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Administración del menú con el servicio y el repositorio reales.
 *
 * @covers \App\Controller\DashboardNavigationAdminController
 */
class DashboardNavigationAdminControllerFunctionalTest extends FunctionalTestCase
{
    use NavigationFixtures;

    private const NO_EXISTE = 999999999;

    public function testElPermisoCreadoSeLeeConSuClienteYSuUsuario(): void
    {
        $ids = $this->escenario();

        $creado = $this->crearPermiso($ids['item'], ['clientId' => $ids['cliente'], 'userId' => $ids['usuario']]);
        $this->em->clear();
        $listado = $this->cuerpo($this->controller()->listPermissions($ids['item']));
        $this->em->clear();
        $detalle = $this->cuerpo($this->controller()->showItem($ids['item']));

        self::assertSame(201, $creado->getStatusCode());
        foreach ([$this->cuerpo($creado), $listado[0], $detalle['permissions'][0]] as $permiso) {
            self::assertSame(['id' => $ids['cliente'], 'companyName' => $ids['nombreCliente']], $permiso['client']);
            self::assertSame(['id' => $ids['usuario'], 'email' => $ids['email']], $permiso['userInfo']);
        }
    }

    public function testUnPermisoSinClienteNiUsuarioNoLlevaEsasClaves(): void
    {
        $ids = $this->escenario();

        $creado = $this->cuerpo($this->crearPermiso($ids['item'], []));
        $this->em->clear();
        $listado = $this->cuerpo($this->controller()->listPermissions($ids['item']));

        foreach ([$creado, $listado[0]] as $permiso) {
            self::assertArrayNotHasKey('client', $permiso);
            self::assertArrayNotHasKey('userInfo', $permiso);
        }
    }

    public function testAlEditarUnPermisoUnNuloQuitaElDatoYLoOmitidoSeConserva(): void
    {
        $ids = $this->escenario();
        $id = $this->idDePermiso($ids);

        $sinCambios = $this->editarPermiso($id, []);
        self::assertSame(200, $sinCambios->getStatusCode());
        self::assertSame($ids['usuario'], $this->cuerpo($sinCambios)['userInfo']['id']);

        $this->em->clear();
        $this->editarPermiso($id, ['userId' => null]);
        $this->em->clear();
        $permiso = $this->cuerpo($this->controller()->listPermissions($ids['item']))[0];

        self::assertSame($ids['cliente'], $permiso['client']['id']);
        self::assertArrayNotHasKey('userInfo', $permiso);
    }

    /**
     * @dataProvider erroresDeDestino
     *
     * @param array<string, string> $destino
     */
    public function testCrearUnPermisoNoValidoRespondeConSuEstadoYSuCodigo(array $destino, int $estado, string $codigo): void
    {
        $ids = $this->escenario();

        $this->assertError($estado, $codigo, $this->crearPermiso($ids['item'], $this->resolver($destino, $ids)));
    }

    /**
     * @dataProvider erroresDeDestino
     *
     * @param array<string, string> $destino
     */
    public function testEditarUnPermisoNoValidoRespondeConSuEstadoYSuCodigo(array $destino, int $estado, string $codigo): void
    {
        $ids = $this->escenario();
        $id = $this->idDePermiso($ids);

        $this->assertError($estado, $codigo, $this->editarPermiso($id, $this->resolver($destino, $ids)));
    }

    /**
     * @dataProvider erroresDeDestino
     *
     * @param array<string, string> $destino
     */
    public function testCrearUnItemConUnPermisoNoValidoRespondeConSuEstadoYSuCodigo(array $destino, int $estado, string $codigo): void
    {
        $ids = $this->escenario();

        $response = $this->controller()->createItem($this->dto(CreateNavigationItemDto::class, [
            'title' => 'Nuevo', 'type' => 'basic', 'minRoleRequired' => 'ROLE_SYSTEM_USER',
        ] + $this->resolver($destino, $ids)));

        $this->assertError($estado, $codigo, $response);
    }

    /** @return iterable<string, array{array<string, string>, int, string}> */
    public static function erroresDeDestino(): iterable
    {
        yield 'cliente inexistente' => [['clientId' => 'noExiste'], 404, 'CLIENT_NOT_FOUND'];
        yield 'usuario inexistente' => [['userId' => 'noExiste'], 404, 'USER_NOT_FOUND'];
        yield 'usuario de otro cliente' => [['clientId' => 'cliente', 'userId' => 'usuarioAjeno'], 422, 'USER_NOT_IN_CLIENT'];
    }

    public function testCrearUnItemConUnPadreInexistenteResponde404(): void
    {
        $response = $this->controller()->createItem($this->dto(CreateNavigationItemDto::class, [
            'title' => 'Nuevo', 'type' => 'basic', 'parentId' => self::NO_EXISTE,
        ]));

        $this->assertError(404, 'PARENT_NOT_FOUND', $response);
    }

    public function testEditarUnItemConUnPadreNoValidoRespondeConSuEstadoYSuCodigo(): void
    {
        $ids = $this->escenario();

        $inexistente = $this->controller()->updateItem(
            $ids['item'],
            $this->dto(UpdateNavigationItemDto::class, ['parentId' => self::NO_EXISTE]),
        );
        $ciclo = $this->controller()->updateItem(
            $ids['item'],
            $this->dto(UpdateNavigationItemDto::class, ['parentId' => $ids['item']]),
        );

        $this->assertError(404, 'PARENT_NOT_FOUND', $inexistente);
        $this->assertError(422, 'PARENT_CYCLE', $ciclo);
    }

    /** @dataProvider codigosSinEstado */
    public function testUnErrorDeDominioSinEstadoHttpValidoResponde400(?int $codigo): void
    {
        $service = $this->createMock(NavigationItemService::class);
        $service->method('createItem')->willThrowException(new MyCurrentException('OTRO_ERROR', 'Otro error', $codigo));
        $controller = new DashboardNavigationAdminController(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(NormalizerInterface::class),
            $service,
        );
        $controller->setContainer(new Container());

        $response = $controller->createItem(new CreateNavigationItemDto('Nuevo', 'basic'));

        $this->assertError(400, 'OTRO_ERROR', $response);
    }

    /** @return iterable<string, array{?int}> */
    public static function codigosSinEstado(): iterable
    {
        yield 'sin código' => [null];
        yield 'por debajo del rango' => [99];
        yield 'por encima del rango' => [600];
    }

    private function controller(): DashboardNavigationAdminController
    {
        return self::getContainer()->get(DashboardNavigationAdminController::class);
    }

    /** @param array<string, mixed> $cuerpo */
    private function crearPermiso(int $itemId, array $cuerpo): JsonResponse
    {
        return $this->controller()->createPermission(
            $itemId,
            $this->dto(CreateUserPermissionDto::class, ['minRoleRequired' => 'ROLE_SYSTEM_USER'] + $cuerpo),
        );
    }

    /** @param array<string, mixed> $cuerpo */
    private function editarPermiso(int $id, array $cuerpo): JsonResponse
    {
        return $this->controller()->updatePermission($id, $this->dto(UpdateUserPermissionDto::class, $cuerpo));
    }

    /** @param array<string, mixed> $ids */
    private function idDePermiso(array $ids): int
    {
        $response = $this->crearPermiso($ids['item'], ['clientId' => $ids['cliente'], 'userId' => $ids['usuario']]);
        $this->em->clear();

        return $this->cuerpo($response)['id'];
    }

    /**
     * Pasa el cuerpo por el resolver real.
     *
     * @template T of object
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $cuerpo
     *
     * @return T
     */
    private function dto(string $class, array $cuerpo): object
    {
        $request = Request::create(
            '/dashboard/api/navigation-admin',
            'POST',
            content: $cuerpo === [] ? '{}' : json_encode($cuerpo, JSON_THROW_ON_ERROR),
        );
        $argument = new ArgumentMetadata('dto', $class, false, false, null);

        return [...self::getContainer()->get(InputValueResolver::class)->resolve($request, $argument)][0];
    }

    /**
     * @param array<string, string> $destino
     * @param array<string, mixed>  $ids
     *
     * @return array<string, int>
     */
    private function resolver(array $destino, array $ids): array
    {
        return array_map(static fn (string $nombre): int => $ids[$nombre], $destino);
    }

    /** @return array<array-key, mixed> */
    private function cuerpo(JsonResponse $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertError(int $estado, string $codigo, JsonResponse $response): void
    {
        self::assertSame($estado, $response->getStatusCode());
        $error = $this->cuerpo($response)['error'];
        self::assertSame(['message', 'code'], array_keys($error));
        self::assertSame($codigo, $error['code']);
        self::assertNotSame('', $error['message']);
    }

    /** @return array{item: int, cliente: int, nombreCliente: string, usuario: int, email: string, usuarioAjeno: int, noExiste: int} */
    private function escenario(): array
    {
        $cliente = $this->cliente();
        $usuario = $this->usuario(['ROLE_SYSTEM_USER'], $cliente);
        $ajeno = $this->usuario(['ROLE_SYSTEM_USER'], $this->cliente());
        $item = $this->item('Con permisos');
        $this->em->flush();

        $ids = [
            'item' => (int) $item->getId(),
            'cliente' => (int) $cliente->getId(),
            'nombreCliente' => (string) $cliente->getCompanyName(),
            'usuario' => (int) $usuario->getId(),
            'email' => (string) $usuario->getEmail(),
            'usuarioAjeno' => (int) $ajeno->getId(),
            'noExiste' => self::NO_EXISTE,
        ];
        $this->em->clear();

        return $ids;
    }
}
