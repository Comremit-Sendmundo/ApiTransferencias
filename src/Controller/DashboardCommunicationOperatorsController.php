<?php

namespace App\Controller;

use App\DTO\CreateCommunicationOperatorDto;
use App\DTO\Out\CommunicationOperatorOutDto;
use App\DTO\Out\PaginatedListOutDto;
use App\DTO\UpdateCommunicationOperatorDto;
use App\Entity\CommunicationOperator;
use App\Exception\MyCurrentException;
use App\OpenApi\Attribute\DashboardEndpoint;
use App\Service\CommunicationOperatorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Administración de los operadores de DTOne (App\Entity\CommunicationOperator), cargados a mano.
 */
#[IsGranted('ROLE_ADMIN')]
class DashboardCommunicationOperatorsController extends AbstractController
{
    public function __construct(
        private readonly CommunicationOperatorService $operatorService,
    ) {
    }

    #[Route('/catalog/operators', name: 'dashboard_communication_operators_list', methods: ['GET'])]
    #[DashboardEndpoint(summary: 'Listar operadores', tag: 'Communication Operators', responseDto: PaginatedListOutDto::class, itemDto: CommunicationOperatorOutDto::class)]
    public function list(Request $request): JsonResponse
    {
        $page = max(0, (int) $request->query->get('page', 0));
        $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

        $result = $this->operatorService->list(
            $request->query->get('country'),
            $request->query->get('search'),
            $page,
            $limit,
        );

        return $this->json([
            'limit' => $limit,
            'currentPage' => $page,
            'hasNext' => $result['hasNext'],
            'hasPrevious' => $page > 0,
            'results' => array_map(fn (CommunicationOperator $o) => $this->serialize($o, false), $result['results']),
        ]);
    }

    #[Route('/catalog/operators/{id}', name: 'dashboard_communication_operators_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[DashboardEndpoint(summary: 'Detalle de un operador', tag: 'Communication Operators', responseDto: CommunicationOperatorOutDto::class)]
    public function get(int $id): JsonResponse
    {
        try {
            $operator = $this->operatorService->get($id);
        } catch (MyCurrentException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], $e->getCode());
        }

        return $this->json($this->serialize($operator, true));
    }

    #[Route('/catalog/operators', name: 'dashboard_communication_operators_create', methods: ['POST'])]
    #[DashboardEndpoint(summary: 'Crear un operador', tag: 'Communication Operators', requestDto: CreateCommunicationOperatorDto::class, responseDto: CommunicationOperatorOutDto::class, responseStatusCode: 201)]
    public function create(CreateCommunicationOperatorDto $dto): JsonResponse
    {
        try {
            $operator = $this->operatorService->create($dto);
        } catch (MyCurrentException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], $e->getCode());
        }

        return $this->json($this->serialize($operator, true), Response::HTTP_CREATED);
    }

    #[Route('/catalog/operators/{id}', name: 'dashboard_communication_operators_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[DashboardEndpoint(summary: 'Actualizar un operador', tag: 'Communication Operators', requestDto: UpdateCommunicationOperatorDto::class, responseDto: CommunicationOperatorOutDto::class)]
    public function update(int $id, UpdateCommunicationOperatorDto $dto): JsonResponse
    {
        try {
            $operator = $this->operatorService->update($id, $dto);
        } catch (MyCurrentException $e) {
            return $this->json(['error' => ['message' => $e->getMessage()]], $e->getCode());
        }

        return $this->json($this->serialize($operator, true));
    }

    private function serialize(CommunicationOperator $operator, bool $withIcon): array
    {
        $data = [
            'id' => $operator->getId(),
            'name' => $operator->getName(),
            'country' => [
                'name' => $operator->getCountryName(),
                'isoCode' => $operator->getCountryIsoCode(),
                'regions' => $operator->getCountryRegions(),
            ],
            'regions' => $operator->getRegions(),
            'hasIcon' => $operator->getIcon() !== null,
            'metadata' => $operator->getMetadata(),
            'createdAt' => $operator->getCreatedAt()?->format(\DATE_ATOM),
            'updatedAt' => $operator->getUpdatedAt()?->format(\DATE_ATOM),
        ];
        if ($withIcon) {
            $data['icon'] = $operator->getIcon();
        }

        return $data;
    }
}
