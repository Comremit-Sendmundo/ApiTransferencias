<?php

namespace App\Tests\Functional\Security;

use App\Controller\AdminProfileController;
use App\Controller\AdminUserController;
use App\Entity\User;
use App\Entity\UserPassword;
use App\Tests\Functional\Provider\ProviderFunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Ninguna respuesta puede llevar hashes de contraseña, material del 2FA ni tokens de las cuentas de API. Los
 * usuarios de la prueba pertenecen a una empresa con una cuenta, que es como están los reales.
 *
 * @covers \App\Controller\AdminProfileController
 * @covers \App\Controller\AdminUserController
 * @covers \App\Service\UserPresenter
 */
class UserSecretsExposureFunctionalTest extends ProviderFunctionalTestCase
{
    private const SECRETS = ['HASH-ACTUAL', 'HASH-ANTIGUO', 'SECRETO-TOTP', 'RESPALDO-2FA', 'TOKEN-PENDIENTE', '918273'];

    private string $accountToken;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $client = $this->createClient();
        $this->accountToken = $this->createAccount($client, $this->createEnvironment())->getAccessToken()->toRfc4122();
        foreach (['yo@example.test', 'companero@example.test'] as $email) {
            $user = (new User())
                ->setEmail($email)
                ->setPassword('HASH-ACTUAL')
                ->setFirstName('Nombre')
                ->setLastName('Apellido')
                ->setRoles(['ROLE_SYSTEM_ADMIN'])
                ->setIsActive(true)
                ->setIsCheckValidation(true)
                ->setCompany($client);
            $user->setTwoFactorEnabled(true);
            $user->setTwoFactorSecret('SECRETO-TOTP');
            $user->setTwoFactorBackupCodes(['RESPALDO-2FA']);
            $user->setTwoFactorPendingToken('TOKEN-PENDIENTE');
            $user->setTwoFactorEmailCode('918273');
            $this->em->persist($user);
            $this->em->persist((new UserPassword())->setUserHistoric($user)->setHistoricPassword('HASH-ANTIGUO'));
        }
        $this->em->flush();
        $this->em->clear();

        $me = $this->em->getRepository(User::class)->findOneBy(['email' => 'yo@example.test']);
        $this->userId = (int) $me->getId();
        $this->authenticateAsAdmin($me);
    }

    public function testOwnProfileHasTheSessionShapeWithoutSecrets(): void
    {
        $response = self::getContainer()->get(AdminProfileController::class)->__invoke(Request::create('/dashboard/api/admin/profile'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNoSecrets((string) $response->getContent());
        $body = json_decode((string) $response->getContent(), true);
        $this->assertSame($this->userId, $body['id']);
        $this->assertSame('yo@example.test', $body['email']);
        $this->assertContains('ROLE_SYSTEM_ADMIN', $body['roles']);
        $this->assertArrayHasKey('companyName', $body['company']);
        $this->assertArrayHasKey('accessIds', $body);
    }

    public function testUserListKeepsItsPaginationAndHasNoSecrets(): void
    {
        $response = self::getContainer()->get(AdminUserController::class)->index(Request::create('/dashboard/api/admin/user/all'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNoSecrets((string) $response->getContent());
        $page = json_decode((string) $response->getContent(), true)[0];
        $this->assertSame(['page', 'limit', 'total', 'totalPages', 'results'], array_keys($page));
        $emails = array_column($page['results'], 'email');
        sort($emails);
        $this->assertSame(['companero@example.test', 'yo@example.test'], $emails);
        $this->assertArrayHasKey('companyName', $page['results'][0]['company']);
    }

    public function testSerializingAUserNeverIncludesSecretsEvenWithoutGroups(): void
    {
        $user = (new User())->setEmail('suelto@example.test')->setPassword('HASH-ACTUAL')->setFirstName('A')->setLastName('B');
        $user->setCurrentIp(null);
        $user->setTwoFactorSecret('SECRETO-TOTP');
        $user->setTwoFactorBackupCodes(['RESPALDO-2FA']);
        $user->setTwoFactorPendingToken('TOKEN-PENDIENTE');
        $user->setTwoFactorEmailCode('918273');

        $data = self::getContainer()->get(NormalizerInterface::class)->normalize($user, 'json');

        $this->assertSame('suelto@example.test', $data['email']);
        $this->assertNoSecrets((string) json_encode($data));
    }

    private function assertNoSecrets(string $json): void
    {
        foreach ([...self::SECRETS, $this->accountToken] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
