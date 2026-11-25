<?php

namespace App\Tests\Service;

use App\Entity\NavigationItem;
use App\Entity\User;
use App\Entity\Client;
use App\Repository\EnvironmentRepository;
use App\Repository\NavigationItemRepository;
use App\Repository\SysConfigRepository;
use App\Service\Navigation\NavigationMenuBuilder;
use App\Service\Navigation\NavigationMenuNode;
use App\Service\NavigationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @covers \App\Service\NavigationService
 */
class NavigationServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private Security&MockObject $security;
    private RoleHierarchyInterface&MockObject $roleHierarchy;
    private NavigationService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->security = $this->createMock(Security::class);
        $this->roleHierarchy = $this->createMock(RoleHierarchyInterface::class);

        $parameters = $this->createMock(ParameterBagInterface::class);
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $environmentRepository = $this->createMock(EnvironmentRepository::class);
        $sysConfigRepo = $this->createMock(SysConfigRepository::class);
        $serializer = $this->createMock(SerializerInterface::class);

        $this->service = new NavigationService(
            $this->em,
            $this->security,
            $parameters,
            $mailer,
            $logger,
            $passwordHasher,
            $environmentRepository,
            $sysConfigRepo,
            $serializer,
            $this->roleHierarchy,
            new NavigationMenuBuilder(),
        );
    }

    public function testGetNavigationItemsReturnsArrayFromRepository(): void
    {
        $items = [
            $this->createMock(NavigationItem::class),
            $this->createMock(NavigationItem::class),
        ];

        $navRepo = $this->createMock(NavigationItemRepository::class);
        $navRepo->method('getNavigationItems')->willReturn($items);

        $this->em->method('getRepository')
            ->with(NavigationItem::class)
            ->willReturn($navRepo);

        $result = $this->service->getNavigationItems();

        $this->assertSame($items, $result);
        $this->assertCount(2, $result);
    }

    public function testGetNavigationItemsReturnsEmptyArray(): void
    {
        $navRepo = $this->createMock(NavigationItemRepository::class);
        $navRepo->method('getNavigationItems')->willReturn([]);

        $this->em->method('getRepository')
            ->with(NavigationItem::class)
            ->willReturn($navRepo);

        $result = $this->service->getNavigationItems();

        $this->assertSame([], $result);
    }

    public function testGetNavigationForUsersThrowsWhenNotUser(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);

        $this->service->getNavigationForUsers();
    }

    public function testElAdministradorSoloConsultaPorSusRoles(): void
    {
        $this->usuarioAutenticado(isAdmin: true, reachableRoles: ['ROLE_ADMIN', 'ROLE_USER']);
        $this->repositorioDelMenu(['ROLE_ADMIN', 'ROLE_USER'], null, null);

        $this->assertMenuConElItemPermitido($this->service->getNavigationForUsers());
    }

    public function testElRestoDeUsuariosConsultaTambienPorSuClienteYUsuario(): void
    {
        $this->usuarioAutenticado(isAdmin: false, reachableRoles: ['ROLE_SYSTEM_ADMIN', 'ROLE_USER']);
        $this->repositorioDelMenu(['ROLE_SYSTEM_ADMIN', 'ROLE_USER'], 5, 2);

        $this->assertMenuConElItemPermitido($this->service->getNavigationForUsers());
    }

    /** @param string[] $reachableRoles */
    private function usuarioAutenticado(bool $isAdmin, array $reachableRoles): void
    {
        $company = $this->createMock(Client::class);
        $company->method('getId')->willReturn(5);

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn([$reachableRoles[0]]);
        $user->method('getCompany')->willReturn($company);
        $user->method('getId')->willReturn(2);

        $this->security->method('getUser')->willReturn($user);
        $this->security->method('isGranted')
            ->with('ROLE_ADMIN')
            ->willReturn($isAdmin);

        $this->roleHierarchy->method('getReachableRoleNames')
            ->with([$reachableRoles[0]])
            ->willReturn($reachableRoles);
    }

    /** @param string[] $roles */
    private function repositorioDelMenu(array $roles, ?int $clientId, ?int $userId): void
    {
        $item = $this->createMock(NavigationItem::class);
        $item->method('getId')->willReturn(10);
        $item->method('getLink')->willReturn('/dashboard');
        $item->method('active')->willReturn(true);

        $navRepo = $this->createMock(NavigationItemRepository::class);
        $navRepo->expects($this->once())
            ->method('findPermittedItemIds')
            ->with($roles, $clientId, $userId)
            ->willReturn([10]);
        $navRepo->expects($this->once())
            ->method('findAllForMenu')
            ->willReturn([['item' => $item, 'parentId' => null]]);

        $this->em->method('getRepository')
            ->with(NavigationItem::class)
            ->willReturn($navRepo);
    }

    /** @param NavigationMenuNode[] $menu */
    private function assertMenuConElItemPermitido(array $menu): void
    {
        $this->assertCount(1, $menu);
        $this->assertSame(10, $menu[0]->item->getId());
        $this->assertSame([], $menu[0]->children);
    }
}
