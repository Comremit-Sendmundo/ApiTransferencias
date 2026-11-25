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
     * Devuelve los IDs de parent y child a los que el usuario tiene acceso.
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
     * Hay permiso si existe uno activo que coincida por:
     * - Rol: su minRoleRequired está en los roles del usuario
     * - Client: el permiso está asignado al client del usuario
     * - Usuario: el permiso está asignado directamente al usuario
     *
     * @return list<int>
     */
    public function findPermittedItemIds(array $roles, ?int $clientId, ?int $userId): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(up.item) as itemId')
            ->from(UserPermission::class, 'up')
            ->where('up.isActive = :is_active')
            ->setParameter('is_active', true);

        $conditions = [];

        // Condición por rol: el minRoleRequired debe estar en los roles del usuario
        if (!empty($roles)) {
            $conditions[] = 'up.minRoleRequired IN (:roles)';
            $qb->setParameter('roles', $roles);
        }

        // Condición por client
        if ($clientId !== null) {
            $conditions[] = 'up.client = :clientId';
            $qb->setParameter('clientId', $clientId);
        }

        // Condición por usuario específico
        if ($userId !== null) {
            $conditions[] = 'up.userInfo = :userId';
            $qb->setParameter('userId', $userId);
        }

        if (empty($conditions)) {
            return [];
        }

        $qb->andWhere(implode(' OR ', $conditions));

        $rows = $qb->getQuery()->getScalarResult();

        return array_values(array_unique(array_map('intval', array_column($rows, 'itemId'))));
    }

    /**
     * IDs con permiso propio más el padre directo de cada uno (los usa accessIds).
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
