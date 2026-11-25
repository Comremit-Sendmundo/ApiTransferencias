<?php

namespace App\Repository;

use App\Entity\NavigationItem;
use App\Entity\UserPermission;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NavigationItem>
 */
class NavigationItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NavigationItem::class);
    }

    /**
     * @return NavigationItem[]
     */
    public function getNavigationItems(): array
    {
        return $this->createQueryBuilder('ni')
            ->select('ni')
            ->leftJoin('ni.children', 'c')
            ->where('ni.parent IS NULL')
            ->andWhere('ni.active = :is_active')
            ->setParameter('is_active', true)
            ->orderBy('ni.orderValue', 'ASC')
            ->addOrderBy('ni.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Devuelve los IDs de parent y child a los que el usuario tiene acceso, según los permisos que le aplican
     * (ver findPermittedItemIds).
     */
    public function accessIds(array $roles, ?int $clientId = null, ?int $userId = null): array
    {
        $allowedIds = $this->getAllowedItemIds($roles, $clientId, $userId);

        $items = $this->findBy(['id' => $allowedIds]);
        $itemMap = [];
        foreach ($items as $item) {
            $itemMap[$item->getId()] = $item;
        }

        $result = [];
        foreach ($allowedIds as $id) {
            $item = $itemMap[$id] ?? null;
            if ($item === null) {
                continue;
            }
            $parent = $item->getParent();
            if ($parent !== null) {
                $result[] = ['parentId' => $parent->getId(), 'childId' => $id];
            } else {
                $result[] = ['parentId' => $id, 'childId' => $id];
            }
        }

        return $result;
    }

    /**
     * Todos los items, activos o no, con el id de su padre y en el orden en que se muestran.
     *
     * @return list<array{item: NavigationItem, parentId: ?int}>
     */
    public function findAllForMenu(): array
    {
        $rows = $this->createQueryBuilder('ni')
            ->select('ni', 'IDENTITY(ni.parent) AS parentId')
            ->orderBy('ni.orderValue', 'ASC')
            ->addOrderBy('ni.title', 'ASC')
            ->addOrderBy('ni.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => [
                'item' => $row[0],
                'parentId' => $row['parentId'] === null ? null : (int) $row['parentId'],
            ],
            $rows,
        );
    }

    /**
     * IDs de los items con permiso propio, sin añadir padres ni hijos.
     *
     * Un permiso activo aplica si cumple a la vez:
     * - Rol: su minRoleRequired está en los roles alcanzables del usuario
     * - Client: no tiene cliente o es el del usuario
     * - Usuario: no tiene usuario o es él
     *
     * Los permisos de un item son alternativas: basta con que aplique uno. No hay excepción para administradores.
     *
     * @param string[] $roles roles alcanzables, ya resueltos con la jerarquía
     *
     * @return list<int>
     */
    public function findPermittedItemIds(array $roles, ?int $clientId, ?int $userId): array
    {
        if ($roles === []) {
            return [];
        }

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(up.item) as itemId')
            ->from(UserPermission::class, 'up')
            ->where('up.isActive = :is_active')
            ->andWhere('up.minRoleRequired IN (:roles)')
            ->setParameter('is_active', true)
            ->setParameter('roles', $roles);

        if ($clientId === null) {
            $qb->andWhere('up.client IS NULL');
        } else {
            $qb->andWhere('up.client IS NULL OR up.client = :clientId')
                ->setParameter('clientId', $clientId);
        }

        if ($userId === null) {
            $qb->andWhere('up.userInfo IS NULL');
        } else {
            $qb->andWhere('up.userInfo IS NULL OR up.userInfo = :userId')
                ->setParameter('userId', $userId);
        }

        $rows = $qb->getQuery()->getScalarResult();

        return array_values(array_unique(array_map('intval', array_column($rows, 'itemId'))));
    }

    /**
     * IDs con permiso propio (rol, cliente y usuario a la vez, ver findPermittedItemIds) más el padre directo
     * de cada uno; los usa accessIds.
     *
     * @return int[]
     */
    public function getAllowedItemIds(array $roles, ?int $clientId, ?int $userId): array
    {
        $intIds = $this->findPermittedItemIds($roles, $clientId, $userId);

        // Para cada hijo permitido, incluir también su padre
        // Para cada padre permitido, no incluir hijos automáticamente
        $items = $this->findBy(['id' => $intIds]);

        $allIds = $intIds;
        foreach ($items as $item) {
            if ($item->getParent() !== null) {
                $allIds[] = $item->getParent()->getId();
            }
        }

        return array_unique($allIds);
    }
}
