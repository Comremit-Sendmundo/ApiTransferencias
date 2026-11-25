<?php

namespace App\Service\Navigation;

use App\Entity\NavigationItem;

/**
 * Nodo del menú ya filtrado para un usuario. Los hijos visibles van aquí, no en la entidad.
 */
final readonly class NavigationMenuNode
{
    /** @param list<NavigationMenuNode> $children */
    public function __construct(
        public NavigationItem $item,
        public array $children = [],
    ) {}
}
