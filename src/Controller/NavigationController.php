<?php

namespace App\Controller;

use App\OpenApi\Attribute\DashboardEndpoint;
use App\Service\Navigation\NavigationMenuNode;
use App\Service\NavigationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/navigation')]
class NavigationController extends AbstractController
{
    public function __construct(
        private readonly NavigationService $navigationService,
        private readonly NormalizerInterface $serializer
    ) {

    }

    #[Route(name: 'navigation', methods: ['GET'])]
    #[DashboardEndpoint(summary: 'Obtener navegación del usuario', tag: 'Navigation')]
    public function navigation(): JsonResponse
    {
        $serializerArray = $this->normalizeNodes($this->navigationService->getNavigationForUsers());

        return $this->json([
            'compact' => $serializerArray,
            'default' => $serializerArray,
            'futuristic' => $serializerArray,
            'horizontal' => $serializerArray,
        ]);
    }

    /**
     * Los hijos salen del nodo ya filtrado, nunca de la colección de la entidad.
     *
     * @param list<NavigationMenuNode> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeNodes(array $nodes): array
    {
        return array_map(function (NavigationMenuNode $node): array {
            $item = $this->serializer->normalize($node->item, 'json', [
                'groups' => ['navigation'],
                AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
                AbstractObjectNormalizer::IGNORED_ATTRIBUTES => ['children'],
            ]);
            $item['children'] = $this->normalizeNodes($node->children);

            return $item;
        }, $nodes);
    }
}