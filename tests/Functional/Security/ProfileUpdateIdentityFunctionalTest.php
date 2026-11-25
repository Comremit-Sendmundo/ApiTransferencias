<?php

namespace App\Tests\Functional\Security;

use App\Controller\AdminProfileController;
use App\Entity\User;
use App\OpenApi\InputValueResolver;
use App\Service\UserService;
use App\Tests\Functional\Provider\ProviderFunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * La sesión que devuelve la actualización del perfil sale solo del usuario autenticado:
 * nada de lo que envíe el cliente puede cambiar su identidad ni sus roles.
 *
 * @covers \App\Controller\AdminProfileController
 * @covers \App\Service\UserService
 */
class ProfileUpdateIdentityFunctionalTest extends ProviderFunctionalTestCase
{
    private string|false $previousSecret = false;

    protected function setUp(): void
    {
        // La firma HS512 exige al menos 32 caracteres y el APP_SECRET de CI es más corto.
        $this->previousSecret = $_SERVER['APP_SECRET'] ?? false;
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = str_repeat('s', 64);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->previousSecret === false) {
            unset($_SERVER['APP_SECRET'], $_ENV['APP_SECRET']);
        } else {
            $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = $this->previousSecret;
        }
    }

    public function testTheNewTokenIgnoresTheIdentitySentByTheClient(): void
    {
        $user = $this->panelUser('limitado@example.test', 'ROLE_SYSTEM_USER');
        $this->panelUser('jefe@example.test', 'ROLE_SUPER_ADMIN');
        $this->authenticateAsAdmin($user);

        $response = $this->updateProfile($this->fullBody((int) $user->getId(), 'jefe@example.test', 'ROLE_SUPER_ADMIN'));

        $this->assertSame(200, $response['status']);
        $identity = $this->identityOf($response['body']['token']);
        $this->assertSame('limitado@example.test', $identity->getEmail());
        $this->assertSame((int) $user->getId(), $identity->getId());
        $this->assertNotContains('ROLE_SUPER_ADMIN', $identity->getRoles());
        $this->assertSame('limitado@example.test', $response['body']['user']['email']);
    }

    public function testChangingTheStatusFromTheUserMenuStillReturnsTokenAndUser(): void
    {
        $user = $this->panelUser('estado@example.test', 'ROLE_SYSTEM_ADMIN');
        $this->authenticateAsAdmin($user);

        // Lo que envía hoy el panel: el usuario en sesión completo con el estado nuevo.
        $response = $this->updateProfile(['id' => $user->getId(), 'email' => $user->getEmail(), 'name' => 'A B', 'status' => 'away']);

        $this->assertSame(200, $response['status']);
        $this->assertSame('estado@example.test', $this->identityOf($response['body']['token'])->getEmail());
        $this->assertSame((int) $user->getId(), $response['body']['user']['id']);
    }

    public function testAnswersLikeTheLoginWhenTheCompanyHasApiAccounts(): void
    {
        $user = $this->panelUser('cuentas@example.test', 'ROLE_SYSTEM_ADMIN');
        $account = $this->createAccount($user->getCompany(), $this->createEnvironment());
        $token = $account->getAccessToken()->toRfc4122();
        $userId = (int) $user->getId();
        $this->em->clear();
        $this->authenticateAsAdmin($this->em->find(User::class, $userId));

        $response = $this->updateProfile(['id' => $userId, 'status' => 'busy']);

        $this->assertSame(200, $response['status']);
        $this->assertArrayHasKey('companyName', $response['body']['user']['company']);
        $this->assertStringNotContainsString($token, (string) json_encode($response['body']['user']));
    }

    public function testRejectsABodyThatRefersToAnotherUser(): void
    {
        $user = $this->panelUser('uno@example.test', 'ROLE_SYSTEM_USER');
        $other = $this->panelUser('dos@example.test', 'ROLE_SYSTEM_USER');
        $this->authenticateAsAdmin($user);

        $this->expectException(AccessDeniedException::class);

        $this->updateProfile($this->fullBody((int) $other->getId(), 'dos@example.test', 'ROLE_SYSTEM_USER'));
    }

    private function panelUser(string $email, string $role): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setPassword('irrelevant-hash')
            ->setFirstName('Usuario')
            ->setLastName('Prueba')
            ->setRoles([$role])
            ->setIsActive(true)
            ->setIsCheckValidation(true)
            ->setCompany($this->createClient());
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function fullBody(int $id, string $email, string $role): array
    {
        return [
            'id' => $id, 'email' => $email, 'firstName' => 'A', 'lastName' => 'B', 'middleName' => '', 'currentIp' => '127.0.0.1',
            'isActive' => true, 'isCheckValidation' => true, 'roles' => [$role], 'permission' => [],
        ];
    }

    /**
     * Pasa el cuerpo por el resolver real y llama al controlador con los argumentos que declare.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function updateProfile(array $body): array
    {
        $request = Request::create('/dashboard/api/admin/profile', 'PATCH', content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->server->set('REMOTE_ADDR', '127.0.0.1');
        $arguments = [];
        foreach ((new \ReflectionMethod(AdminProfileController::class, 'update'))->getParameters() as $parameter) {
            $type = ltrim((string) $parameter->getType(), '?');
            if ($type === Request::class) {
                $arguments[] = $request;
            } elseif ($parameter->getAttributes() !== []) {
                $arguments[] = self::getContainer()->get(TokenStorageInterface::class)->getToken()?->getUser();
            } else {
                $metadata = new ArgumentMetadata($parameter->getName(), $type, false, false, null);
                $arguments[] = [...self::getContainer()->get(InputValueResolver::class)->resolve($request, $metadata)][0];
            }
        }

        $response = self::getContainer()->get(AdminProfileController::class)->update(...$arguments);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string) $response->getContent(), true)];
    }

    private function identityOf(string $token): User
    {
        $identity = self::getContainer()->get(UserService::class)->parser($token);
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['email' => $identity->getUserIdentifier()]);
    }
}
