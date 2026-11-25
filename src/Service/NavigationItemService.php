<?php

namespace App\Service;

use App\DTO\CreateNavigationItemDto;
use App\DTO\CreateUserPermissionDto;
use App\DTO\UpdateNavigationItemDto;
use App\DTO\UpdateUserPermissionDto;
use App\Entity\Client;
use App\Entity\NavigationItem;
use App\Entity\User;
use App\Entity\UserPermission;
use App\Enums\NavigationTypeEnum;
use App\Exception\MyCurrentException;
use Doctrine\ORM\EntityManagerInterface;

class NavigationItemService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /** @throws MyCurrentException */
    public function createItem(CreateNavigationItemDto $dto): NavigationItem
    {
        $item = new NavigationItem();
        // Todo se resuelve antes de crear o modificar nada.
        $parent = $this->resolveParent($dto->getParentId(), $item);
        $client = $this->resolveClient($dto->getClientId());
        $user = $this->resolveUser($dto->getUserId());
        $this->assertUserInClient($client, $user);

        $this->applyItemDto($item, $dto->getTitle(), $dto->getType(), $dto->getSubtitle(), $dto->getIcon(), $dto->getLink(), $parent, $dto->getActive(), $dto->getOrderValue(), $dto->getBadge(), $dto->getData());
        $item->setCreatedAt(new \DateTimeImmutable('now'));
        $item->setUpdatedAt(new \DateTimeImmutable('now'));

        $this->em->persist($item);

        if ($dto->getMinRoleRequired() !== null) {
            $permission = new UserPermission();
            $permission->setItem($item);
            $permission->setMinRoleRequired($dto->getMinRoleRequired());
            $permission->setActive(true);
            $permission->setClient($client);
            $permission->setUserInfo($user);
            $this->em->persist($permission);
        }

        $this->em->flush();

        return $item;
    }

    /** @throws MyCurrentException */
    public function updateItem(NavigationItem $item, UpdateNavigationItemDto $dto): NavigationItem
    {
        $parent = $this->resolveParent($dto->getParentId(), $item);

        $this->applyItemDto($item, $dto->getTitle(), $dto->getType(), $dto->getSubtitle(), $dto->getIcon(), $dto->getLink(), $parent, $dto->getActive(), $dto->getOrderValue(), $dto->getBadge(), $dto->getData());
        $item->setUpdatedAt(new \DateTimeImmutable('now'));

        $this->em->flush();

        return $item;
    }

    public function deleteItem(NavigationItem $item): void
    {
        foreach ($item->getUserPermissions() as $permission) {
            $this->em->remove($permission);
        }
        $this->em->remove($item);
        $this->em->flush();
    }

    /** @throws MyCurrentException */
    public function createPermission(NavigationItem $item, CreateUserPermissionDto $dto): UserPermission
    {
        $client = $this->resolveClient($dto->getClientId());
        $user = $this->resolveUser($dto->getUserId());
        $this->assertUserInClient($client, $user);

        $permission = new UserPermission();
        $permission->setItem($item);
        $permission->setMinRoleRequired($dto->getMinRoleRequired());
        $permission->setActive($dto->getIsActive() ?? true);
        $permission->setClient($client);
        $permission->setUserInfo($user);

        $this->em->persist($permission);
        $this->em->flush();

        return $permission;
    }

    /**
     * Cambia solo lo enviado; un null en clientId o userId lo quita.
     *
     * @throws MyCurrentException
     */
    public function updatePermission(UserPermission $permission, UpdateUserPermissionDto $dto): UserPermission
    {
        // La pertenencia se comprueba sobre cómo quedará el permiso: lo enviado o, si no, lo guardado.
        $client = $dto->wasSent('clientId') ? $this->resolveClient($dto->getClientId()) : $permission->getClient();
        $user = $dto->wasSent('userId') ? $this->resolveUser($dto->getUserId()) : $permission->getUserInfo();
        $this->assertUserInClient($client, $user);

        if ($dto->getMinRoleRequired() !== null) {
            $permission->setMinRoleRequired($dto->getMinRoleRequired());
        }
        if ($dto->getIsActive() !== null) {
            $permission->setActive($dto->getIsActive());
        }
        $permission->setClient($client);
        $permission->setUserInfo($user);

        $this->em->flush();

        return $permission;
    }

    public function deletePermission(UserPermission $permission): void
    {
        $this->em->remove($permission);
        $this->em->flush();
    }

    private function applyItemDto(
        NavigationItem $item,
        ?string $title,
        ?string $type,
        ?string $subtitle,
        ?string $icon,
        ?string $link,
        ?NavigationItem $parent,
        ?bool $active,
        ?string $orderValue,
        ?array $badge,
        ?array $data,
    ): void {
        if ($title !== null) {
            $item->setTitle(mb_substr($title, 0, 255));
        }
        if ($subtitle !== null) {
            $item->setSubtitle(mb_substr($subtitle, 0, 255));
        }
        if ($type !== null) {
            $item->setType(NavigationTypeEnum::tryFrom($type));
        }
        if ($icon !== null) {
            $item->setIcon(mb_substr($icon, 0, 80));
        }
        if ($link !== null) {
            $item->setLink(mb_substr($link, 0, 255));
        }
        // Al editar, no enviar padre deja el item en la raíz.
        if ($parent !== null || $item->getId() !== null) {
            $item->setParent($parent);
        }
        if ($active !== null) {
            $item->setActive($active);
        } elseif ($item->active() === null) {
            $item->setActive(true);
        }
        if ($orderValue !== null) {
            $item->setOrderValue(mb_substr($orderValue, 0, 5));
        }
        if ($badge !== null) {
            $item->setBadge($badge);
        }
        if ($data !== null) {
            $item->setData($data);
        }
    }

    /**
     * @throws MyCurrentException si el padre no existe o es el propio item o uno de sus descendientes
     */
    private function resolveParent(?int $parentId, NavigationItem $item): ?NavigationItem
    {
        if ($parentId === null) {
            return null;
        }

        $parent = $this->em->getRepository(NavigationItem::class)->find($parentId)
            ?? throw new MyCurrentException('PARENT_NOT_FOUND', 'Parent item not found', 404);

        // Se sube desde el padre: si aparece el item, quedaría colgando de sí mismo. Un ciclo ya guardado corta la subida.
        $visited = [];
        for ($ancestor = $parent; $ancestor !== null; $ancestor = $ancestor->getParent()) {
            if ($ancestor->getId() === $item->getId()) {
                throw new MyCurrentException('PARENT_CYCLE', 'Parent item cannot be the item itself or one of its descendants', 422);
            }
            if (isset($visited[$ancestor->getId()])) {
                break;
            }
            $visited[$ancestor->getId()] = true;
        }

        return $parent;
    }

    /** @throws MyCurrentException */
    private function resolveClient(?int $clientId): ?Client
    {
        if ($clientId === null) {
            return null;
        }

        return $this->em->getRepository(Client::class)->find($clientId)
            ?? throw new MyCurrentException('CLIENT_NOT_FOUND', 'Client not found', 404);
    }

    /** @throws MyCurrentException si el usuario no existe o está eliminado */
    private function resolveUser(?int $userId): ?User
    {
        if ($userId === null) {
            return null;
        }

        $user = $this->em->getRepository(User::class)->find($userId);
        if ($user === null || $user->getRemovedAt() !== null) {
            throw new MyCurrentException('USER_NOT_FOUND', 'User not found', 404);
        }

        return $user;
    }

    /**
     * Un permiso con cliente y usuario de otro cliente no aplicaría nunca.
     *
     * @throws MyCurrentException
     */
    private function assertUserInClient(?Client $client, ?User $user): void
    {
        if ($client !== null && $user !== null && $user->getCompany()?->getId() !== $client->getId()) {
            throw new MyCurrentException('USER_NOT_IN_CLIENT', 'User does not belong to the client', 422);
        }
    }
}
