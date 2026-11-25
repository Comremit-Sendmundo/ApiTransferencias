<?php

namespace App\Service\Navigation;

use App\Entity\NavigationItem;
use App\Enums\NavigationTypeEnum;

/**
 * Arma el árbol del menú sin tocar las entidades.
 *
 * Un item se ve si está activo y tiene permiso propio o algún descendiente visible. El permiso de
 * un padre no se hereda y un item inactivo oculta toda su rama.
 */
final class NavigationMenuBuilder
{
    private const CONTAINER_TYPES = [
        NavigationTypeEnum::GROUP,
        NavigationTypeEnum::COLLAPSABLE,
        NavigationTypeEnum::ASIDE,
    ];

    /**
     * @param list<array{item: NavigationItem, parentId: ?int}> $rows         Todos los items, ya ordenados
     * @param list<int>                                         $permittedIds Items con permiso propio
     *
     * @return list<NavigationMenuNode>
     */
    public function build(array $rows, array $permittedIds): array
    {
        $items = [];
        $childrenOf = [];
        $rootIds = [];

        foreach ($rows as ['item' => $item, 'parentId' => $parentId]) {
            $id = $item->getId();
            $items[$id] = $item;

            if ($parentId === null) {
                $rootIds[] = $id;
            } else {
                $childrenOf[$parentId][] = $id;
            }
        }

        return $this->nodes($rootIds, $items, $childrenOf, array_flip($permittedIds), []);
    }

    /**
     * @param list<int>                  $ids
     * @param array<int, NavigationItem> $items
     * @param array<int, list<int>>      $childrenOf
     * @param array<int, int>            $permitted
     * @param array<int, true>           $ancestors
     *
     * @return list<NavigationMenuNode>
     */
    private function nodes(array $ids, array $items, array $childrenOf, array $permitted, array $ancestors): array
    {
        $nodes = [];
        foreach ($ids as $id) {
            $item = $items[$id];
            // Un item que ya está en la rama es un ciclo en los datos: se corta ahí.
            if (isset($ancestors[$id]) || $item->active() !== true) {
                continue;
            }

            $children = $this->nodes(
                $childrenOf[$id] ?? [],
                $items,
                $childrenOf,
                $permitted,
                $ancestors + [$id => true],
            );

            if ($children === []) {
                if (!isset($permitted[$id])) {
                    continue;
                }

                // Sin enlace ni hijos visibles: en la raíz no se muestra nada; más abajo solo las hojas.
                $isRoot = $ancestors === [];
                $isContainer = isset($childrenOf[$id]) || in_array($item->getType(), self::CONTAINER_TYPES, true);
                if ($item->getLink() === null && ($isRoot || $isContainer)) {
                    continue;
                }
            }

            $nodes[] = new NavigationMenuNode($item, $children);
        }

        return $nodes;
    }
}
