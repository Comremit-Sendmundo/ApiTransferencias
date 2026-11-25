<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Service\UserService;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\NavigationFixtures;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Los accessIds de la sesión salen del usuario que se evalúa, haya o no alguien autenticado en la petición.
 *
 * @covers \App\Service\UserService
 */
class SessionMenuAccessFunctionalTest extends FunctionalTestCase
{
    use NavigationFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->aislarMenu();
    }

    /** En el login, la renovación y el segundo factor todavía no hay token en la petición. */
    public function testSinSesionIniciadaSeUsanElClienteYElUsuarioEvaluado(): void
    {
        [$userId, , $esperados] = $this->escenario();

        self::assertNull(self::getContainer()->get(TokenStorageInterface::class)->getToken());

        self::assertEqualsCanonicalizing($esperados, $this->accessIds($userId));
    }

    public function testUnAdministradorAutenticadoNoPrestaSusPermisosAlUsuarioEvaluado(): void
    {
        [$userId, $adminId, $esperados] = $this->escenario();
        $this->autenticar($adminId);

        self::assertEqualsCanonicalizing($esperados, $this->accessIds($userId));
    }

    public function testUnUsuarioSinPerfilDeAdministradorRecibeLoDeSuClienteYElSuyo(): void
    {
        [$userId, , $esperados] = $this->escenario();
        $this->autenticar($userId);

        self::assertEqualsCanonicalizing($esperados, $this->accessIds($userId));
    }

    /**
     * Un usuario de sistema y un administrador, cada uno de un cliente, con permisos generales, propios y ajenos.
     *
     * @return array{int, int, list<int>} usuario, administrador y accessIds que corresponden al usuario
     */
    private function escenario(): array
    {
        $suCliente = $this->cliente();
        $otroCliente = $this->cliente();
        $user = $this->usuario(['ROLE_SYSTEM_USER'], $suCliente);
        $admin = $this->usuario(['ROLE_SUPER_ADMIN'], $otroCliente);

        $raiz = $this->item('Raíz');
        $general = $this->item('General', $raiz);
        $delCliente = $this->item('Del cliente', $raiz);
        $suyo = $this->item('Suyo', $raiz);
        $this->permiso($general, 'ROLE_SYSTEM_USER');
        $this->permiso($delCliente, 'ROLE_SYSTEM_USER', client: $suCliente);
        $this->permiso($suyo, 'ROLE_SYSTEM_USER', user: $user);
        $this->permiso($this->item('Del otro cliente', $raiz), 'ROLE_SYSTEM_USER', client: $otroCliente);
        $this->permiso($this->item('Del administrador', $raiz), 'ROLE_SYSTEM_USER', user: $admin);
        $this->permiso($this->item('De otro rol', $raiz), 'ROLE_SUPER_ADMIN', client: $suCliente);

        $this->em->flush();
        $resultado = [
            (int) $user->getId(),
            (int) $admin->getId(),
            [(int) $raiz->getId(), (int) $general->getId(), (int) $delCliente->getId(), (int) $suyo->getId()],
        ];
        $this->em->clear();

        return $resultado;
    }

    private function autenticar(int $userId): void
    {
        $user = $this->em->find(User::class, $userId);
        self::assertNotNull($user);

        self::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($user, 'dashboard', $user->getRoles()));
    }

    /** @return list<int> */
    private function accessIds(int $userId): array
    {
        $user = $this->em->find(User::class, $userId);
        self::assertNotNull($user);

        return self::getContainer()->get(UserService::class)->createPayloadUser($user)['accessIds'];
    }
}
