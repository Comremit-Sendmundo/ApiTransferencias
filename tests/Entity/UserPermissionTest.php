<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\UserPermission;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * @covers \App\Entity\UserPermission
 */
class UserPermissionTest extends KernelTestCase
{
    public function testLosRolesAsignablesSonLosDelPanelYLaApiEnSuOrden(): void
    {
        self::assertSame(
            [
                'ROLE_USER',
                'ROLE_SYSTEM_USER',
                'ROLE_SYSTEM_SHOW',
                'ROLE_SYSTEM_EDITOR',
                'ROLE_SYSTEM_ADMIN',
                'ROLE_API_USER',
                'ROLE_COM_API_USER',
                'ROLE_REM_API_USER',
                'ROLE_API_EDITOR',
                'ROLE_COM_API_ADMIN',
                'ROLE_REM_API_ADMIN',
                'ROLE_API_ADMIN',
                'ROLE_ADMIN',
                'ROLE_SUPER_ADMIN',
            ],
            UserPermission::ASSIGNABLE_ROLES,
        );
    }

    public function testTodosLosRolesAsignablesSeAlcanzanDesdeElSuperAdministrador(): void
    {
        $alcanzables = self::getContainer()->get(RoleHierarchyInterface::class)
            ->getReachableRoleNames(['ROLE_SUPER_ADMIN']);

        self::assertSame([], array_values(array_diff(UserPermission::ASSIGNABLE_ROLES, $alcanzables)));
    }

    public function testNingunRolDelMercadoEsAsignable(): void
    {
        self::assertSame([], preg_grep('/^ROLE_MARKET_/', UserPermission::ASSIGNABLE_ROLES));
    }
}
