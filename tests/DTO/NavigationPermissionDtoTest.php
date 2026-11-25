<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\CreateNavigationItemDto;
use App\DTO\CreateUserPermissionDto;
use App\DTO\UpdateUserPermissionDto;
use App\Entity\UserPermission;
use App\OpenApi\InputValueResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Los cuerpos de los permisos de menú, leídos y validados por el resolver real.
 *
 * @covers \App\DTO\CreateNavigationItemDto
 * @covers \App\DTO\CreateUserPermissionDto
 * @covers \App\DTO\UpdateUserPermissionDto
 */
class NavigationPermissionDtoTest extends KernelTestCase
{
    private const ITEM = ['title' => 'Item', 'type' => 'basic'];

    private const CAMPOS = ['minRoleRequired', 'isActive', 'clientId', 'userId'];

    public function testCrearUnPermisoAdmiteTodosLosRolesAsignables(): void
    {
        foreach (UserPermission::ASSIGNABLE_ROLES as $role) {
            self::assertSame($role, $this->leer(CreateUserPermissionDto::class, ['minRoleRequired' => $role])->getMinRoleRequired());
        }
    }

    /**
     * @dataProvider rolesNoValidos
     *
     * @param array<string, mixed> $body
     */
    public function testCrearUnPermisoRechazaUnRolFueraDeLaListaOAusente(array $body): void
    {
        self::assertSame(['minRoleRequired'], $this->camposRechazados(CreateUserPermissionDto::class, $body));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rolesNoValidos(): iterable
    {
        yield 'desconocido' => [['minRoleRequired' => 'ROLE_NADIE']];
        yield 'del mercado' => [['minRoleRequired' => 'ROLE_MARKET_API_USER']];
        yield 'vacío' => [['minRoleRequired' => '']];
        yield 'nulo' => [['minRoleRequired' => null]];
        yield 'omitido' => [[]];
    }

    public function testCrearUnItemSinPermisoSigueSiendoValido(): void
    {
        self::assertNull($this->leer(CreateNavigationItemDto::class, self::ITEM)->getMinRoleRequired());
    }

    public function testCrearUnItemConPermisoCompletoEsValido(): void
    {
        $dto = $this->leer(CreateNavigationItemDto::class, self::ITEM + [
            'minRoleRequired' => 'ROLE_SYSTEM_USER', 'clientId' => 1, 'userId' => 2,
        ]);

        self::assertSame(['ROLE_SYSTEM_USER', 1, 2], [$dto->getMinRoleRequired(), $dto->getClientId(), $dto->getUserId()]);
    }

    /**
     * @dataProvider permisosDeItemNoValidos
     *
     * @param array<string, mixed> $permiso
     */
    public function testCrearUnItemRechazaUnPermisoSinRolValido(array $permiso): void
    {
        self::assertSame(['minRoleRequired'], $this->camposRechazados(CreateNavigationItemDto::class, self::ITEM + $permiso));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function permisosDeItemNoValidos(): iterable
    {
        yield 'rol desconocido' => [['minRoleRequired' => 'ROLE_NADIE']];
        yield 'rol vacío' => [['minRoleRequired' => '']];
        yield 'cliente sin rol' => [['clientId' => 1]];
        yield 'cliente cero sin rol' => [['clientId' => 0]];
        yield 'usuario sin rol' => [['userId' => 1]];
        yield 'cliente y usuario con rol nulo' => [['minRoleRequired' => null, 'clientId' => 1, 'userId' => 2]];
    }

    public function testAlEditarUnCampoOmitidoNoSeDaPorEnviado(): void
    {
        $dto = $this->leer(UpdateUserPermissionDto::class, []);

        foreach (self::CAMPOS as $campo) {
            self::assertFalse($dto->wasSent($campo), $campo);
        }
        self::assertSame(
            [null, null, null, null],
            [$dto->getMinRoleRequired(), $dto->getIsActive(), $dto->getClientId(), $dto->getUserId()],
        );
    }

    public function testAlEditarSeDistingueElNuloDeLoOmitido(): void
    {
        $dto = $this->leer(UpdateUserPermissionDto::class, ['clientId' => null, 'userId' => 7]);

        self::assertTrue($dto->wasSent('clientId'));
        self::assertNull($dto->getClientId());
        self::assertTrue($dto->wasSent('userId'));
        self::assertSame(7, $dto->getUserId());
        self::assertFalse($dto->wasSent('minRoleRequired'));
        self::assertFalse($dto->wasSent('isActive'));
    }

    public function testAlEditarSeLeenLosCuatroCampos(): void
    {
        $dto = $this->leer(UpdateUserPermissionDto::class, [
            'minRoleRequired' => 'ROLE_ADMIN', 'isActive' => false, 'clientId' => 3, 'userId' => null,
        ]);

        foreach (self::CAMPOS as $campo) {
            self::assertTrue($dto->wasSent($campo), $campo);
        }
        self::assertSame(
            ['ROLE_ADMIN', false, 3, null],
            [$dto->getMinRoleRequired(), $dto->getIsActive(), $dto->getClientId(), $dto->getUserId()],
        );
    }

    /**
     * @dataProvider marcasFalsas
     *
     * @param array<string, mixed> $body
     */
    public function testElCuerpoNoPuedeMarcarCamposComoEnviados(array $body): void
    {
        $dto = $this->leer(UpdateUserPermissionDto::class, $body);

        foreach (self::CAMPOS as $campo) {
            self::assertFalse($dto->wasSent($campo), $campo);
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function marcasFalsas(): iterable
    {
        yield 'mapa' => [['sent' => ['clientId' => true, 'userId' => true]]];
        yield 'lista' => [['sent' => self::CAMPOS]];
        yield 'nulo' => [['sent' => null]];
    }

    public function testLasCabecerasDelPanelNoCuentanComoCamposDelPermiso(): void
    {
        $dto = $this->leer(UpdateUserPermissionDto::class, [], [
            'HTTP_X_ENVIRONMENT_ID' => '1', 'HTTP_X_ACCOUNT_ID' => '2', 'HTTP_X_ENVIRONMENT_TYPE' => 'TEST',
        ]);

        foreach (self::CAMPOS as $campo) {
            self::assertFalse($dto->wasSent($campo), $campo);
        }
    }

    /**
     * @dataProvider edicionesNoValidas
     *
     * @param array<string, mixed> $body
     */
    public function testAlEditarSeRechazaUnRolOEstadoNoValido(array $body, string $campo): void
    {
        self::assertSame([$campo], $this->camposRechazados(UpdateUserPermissionDto::class, $body));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function edicionesNoValidas(): iterable
    {
        yield 'rol desconocido' => [['minRoleRequired' => 'ROLE_NADIE'], 'minRoleRequired'];
        yield 'rol vacío' => [['minRoleRequired' => ''], 'minRoleRequired'];
        yield 'rol nulo' => [['minRoleRequired' => null], 'minRoleRequired'];
        yield 'estado nulo' => [['isActive' => null], 'isActive'];
    }

    /**
     * @template T of object
     *
     * @param class-string<T>       $class
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     *
     * @return T
     */
    private function leer(string $class, array $body, array $server = []): object
    {
        $request = Request::create(
            '/dashboard/api/navigation-admin',
            'POST',
            server: $server,
            content: $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR),
        );
        $argument = new ArgumentMetadata('dto', $class, false, false, null);

        return [...self::getContainer()->get(InputValueResolver::class)->resolve($request, $argument)][0];
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function camposRechazados(string $class, array $body): array
    {
        try {
            $this->leer($class, $body);
        } catch (ValidationFailedException $e) {
            $campos = [];
            foreach ($e->getViolations() as $violation) {
                $campos[] = $violation->getPropertyPath();
            }

            return array_values(array_unique($campos));
        }

        return [];
    }
}
