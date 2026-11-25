<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Client;
use App\Entity\NavigationItem;
use App\Entity\User;
use App\Entity\UserPermission;
use App\Enums\NavigationTypeEnum;

/**
 * Datos de menú para los tests contra Postgres real.
 *
 * Debe usarse en una clase que extienda FunctionalTestCase.
 */
trait NavigationFixtures
{
    /** Rol que no tiene ningún usuario: el permiso solo vale por cliente o por usuario. */
    private const ROL_SIN_USUARIOS = 'ROLE_NADIE';

    /** Desactiva las semillas de la base de test; el rollback del test las deja como estaban. */
    private function aislarMenu(): void
    {
        $this->em->getConnection()->executeStatement('UPDATE navigation_item SET is_active = false');
        $this->em->getConnection()->executeStatement('UPDATE user_permission SET is_active = false');
    }

    private function item(
        string $title,
        ?NavigationItem $parent = null,
        ?string $link = null,
        ?NavigationTypeEnum $type = null,
        bool $active = true,
        ?string $order = null,
    ): NavigationItem {
        $item = (new NavigationItem())
            ->setTitle($title)
            ->setParent($parent)
            ->setLink($link)
            ->setType($type)
            ->setActive($active)
            ->setOrderValue($order)
            ->setCreatedAt(new \DateTimeImmutable('2026-01-01 10:00:00'))
            ->setUpdatedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $this->em->persist($item);

        return $item;
    }

    private function permiso(
        NavigationItem $item,
        string $role = self::ROL_SIN_USUARIOS,
        ?Client $client = null,
        ?User $user = null,
        bool $active = true,
    ): UserPermission {
        $permission = (new UserPermission())
            ->setItem($item)
            ->setMinRoleRequired($role)
            ->setClient($client)
            ->setUserInfo($user)
            ->setActive($active);

        $this->em->persist($permission);

        return $permission;
    }

    private function cliente(): Client
    {
        $unico = uniqid('', true);
        $client = (new Client())
            ->setCompanyName("Cliente menú {$unico}")
            ->setCompanyCountry('CUB')
            ->setCompanyEmail("menu-{$unico}@example.test")
            ->setCompanyPhoneNumber('5550000000')
            ->setCompanyIdentification("MENU-{$unico}")
            ->setCompanyIdentificationType('NIT')
            ->setDiscountOfClient(0.0)
            ->setIsActive(true);

        $this->em->persist($client);

        return $client;
    }

    /** @param string[] $roles */
    private function usuario(array $roles, ?Client $company = null): User
    {
        $user = (new User())
            ->setEmail('menu-'.uniqid('', true).'@example.test')
            ->setPassword('irrelevant-hash')
            ->setFirstName('Usuario')
            ->setLastName('Menú')
            ->setRoles($roles)
            ->setCompany($company)
            ->setIsActive(true)
            ->setIsCheckValidation(true);

        $this->em->persist($user);

        return $user;
    }
}
