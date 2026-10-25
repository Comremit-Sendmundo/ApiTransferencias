<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CommunicationOperatorResource;
use App\Repository\CommunicationOperatorRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lectura pública de operadores (GET /communication/operators[/{id}]). Los operadores son pocos (se gestionan a mano), así
 * que el listado se filtra en base de datos y se pagina en memoria con ListPaginator.
 *
 * @implements ProviderInterface<CommunicationOperatorResource>
 */
final class CommunicationOperatorProvider implements ProviderInterface
{
    private const LOAD_LIMIT = 1000;

    public function __construct(private readonly CommunicationOperatorRepository $repository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|CommunicationOperatorResource|null
    {
        if (!$operation instanceof CollectionOperationInterface) {
            $operator = $this->repository->find((int) ($uriVariables['id'] ?? 0));

            return $operator === null ? null : CommunicationOperatorResource::fromEntity($operator, true);
        }

        /** @var Request|null $request */
        $request = $context['request'] ?? null;
        $country = $request?->query->get('country');
        $search = $request?->query->get('search');

        $operators = $this->repository->search(
            is_string($country) ? $country : null,
            is_string($search) ? $search : null,
            self::LOAD_LIMIT,
            0,
        );
        $items = array_map(static fn ($o) => CommunicationOperatorResource::fromEntity($o, false), $operators);

        if ($request === null) {
            return $items;
        }

        $itemsPerPage = $operation->getPaginationItemsPerPage() ?? 20;
        if (is_numeric($request->query->get('itemsPerPage'))) {
            $itemsPerPage = (int) $request->query->get('itemsPerPage');
        }
        $itemsPerPage = max(1, min($itemsPerPage, $operation->getPaginationMaximumItemsPerPage() ?? 100));
        $page = max(1, (int) $request->query->get('page', 1));

        return new ListPaginator($items, ($page - 1) * $itemsPerPage, $itemsPerPage);
    }
}
