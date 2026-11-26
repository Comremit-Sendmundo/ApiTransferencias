<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Client;
use App\Entity\User;
use App\Security\ClientScope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * COMR-399: qué clientes alcanza a ver un usuario del panel. Los administradores ven todo; el resto solo su cliente y, sin
 * cliente, nada.
 *
 * @covers \App\Security\ClientScope
 */
final class ClientScopeTest extends TestCase
{
    private function userOfClient(?int $clientId): User
    {
        $user = new User();
        if ($clientId !== null) {
            $client = $this->createStub(Client::class);
            $client->method('getId')->willReturn($clientId);
            $user->setCompany($client);
        }

        return $user;
    }

    public function testAnAdministratorWithoutARequestedClientSeesEverything(): void
    {
        // Aunque el administrador tenga cliente propio, sin pedir uno ve todos.
        $scope = ClientScope::of($this->userOfClient(7), true);

        $this->assertTrue($scope->isAll());
        $this->assertFalse($scope->isNone());
        $this->assertNull($scope->clientId());
        $this->assertTrue($scope->allows(7));
        $this->assertTrue($scope->allows(99));
        $this->assertTrue($scope->allows(null));
    }

    public function testAnAdministratorSeesTheClientItAsksFor(): void
    {
        $scope = ClientScope::of($this->userOfClient(null), true, 12);

        $this->assertFalse($scope->isAll());
        $this->assertFalse($scope->isNone());
        $this->assertSame(12, $scope->clientId());
        $this->assertTrue($scope->allows(12));
        $this->assertFalse($scope->allows(13));
        $this->assertFalse($scope->allows(null));
    }

    public function testAUserWithAClientSeesOnlyItsClient(): void
    {
        foreach ([null, 7] as $requested) {
            $scope = ClientScope::of($this->userOfClient(7), false, $requested);

            $this->assertFalse($scope->isAll());
            $this->assertFalse($scope->isNone());
            $this->assertSame(7, $scope->clientId());
            $this->assertTrue($scope->allows(7));
            $this->assertFalse($scope->allows(8));
            $this->assertFalse($scope->allows(null));
        }
    }

    public function testAUserCannotAskForAnotherClient(): void
    {
        $this->expectException(AccessDeniedException::class);

        ClientScope::of($this->userOfClient(7), false, 8);
    }

    public function testAUserWithoutAClientSeesNothing(): void
    {
        $scope = ClientScope::of($this->userOfClient(null), false);

        $this->assertTrue($scope->isNone());
        $this->assertFalse($scope->isAll());
        $this->assertNull($scope->clientId());
        $this->assertFalse($scope->allows(7));
        $this->assertFalse($scope->allows(null));
    }

    public function testAUserWithoutAClientCannotAskForOne(): void
    {
        $this->expectException(AccessDeniedException::class);

        ClientScope::of($this->userOfClient(null), false, 7);
    }

    public function testWithoutAUserNothingIsVisible(): void
    {
        $this->assertTrue(ClientScope::of(null, false)->isNone());
    }

    public function testAnInactiveClientStillCountsAsTheOwnClient(): void
    {
        $client = $this->createStub(Client::class);
        $client->method('getId')->willReturn(7);
        $client->method('isActive')->willReturn(false);

        $this->assertSame(7, ClientScope::of((new User())->setCompany($client), false)->clientId());
    }
}
