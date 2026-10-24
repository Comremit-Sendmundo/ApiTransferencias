<?php

namespace App\Service;

use App\DTO\CreateCommunicationOperatorDto;
use App\DTO\UpdateCommunicationOperatorDto;
use App\Entity\CommunicationOperator;
use App\Exception\MyCurrentException;
use App\Repository\CommunicationOperatorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alta manual y mantenimiento de los operadores de DTOne (sin sincronización con su API).
 */
class CommunicationOperatorService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommunicationOperatorRepository $repository,
    ) {
    }

    /**
     * @return array{results: CommunicationOperator[], hasNext: bool}
     */
    public function list(?string $country, ?string $search, int $page, int $limit): array
    {
        $results = $this->repository->search($country, $search, $limit + 1, $page * $limit);

        $hasNext = count($results) > $limit;
        if ($hasNext) {
            array_pop($results);
        }

        return ['results' => $results, 'hasNext' => $hasNext];
    }

    /**
     * @throws MyCurrentException
     */
    public function get(int $id): CommunicationOperator
    {
        return $this->repository->find($id)
            ?? throw new MyCurrentException('OPERATOR_NOT_FOUND', 'Operator not found', 404);
    }

    /**
     * @throws MyCurrentException
     */
    public function create(CreateCommunicationOperatorDto $dto): CommunicationOperator
    {
        if ($this->repository->find($dto->getId()) !== null) {
            throw new MyCurrentException('OPERATOR_ALREADY_EXISTS', 'Operator already exists', 409);
        }

        $operator = (new CommunicationOperator())->setId($dto->getId());
        $this->apply($operator, $dto);

        $this->em->persist($operator);
        $this->em->flush();

        return $operator;
    }

    /**
     * @throws MyCurrentException
     */
    public function update(int $id, UpdateCommunicationOperatorDto $dto): CommunicationOperator
    {
        $operator = $this->get($id);
        $this->apply($operator, $dto);
        $this->em->flush();

        return $operator;
    }

    private function apply(CommunicationOperator $operator, UpdateCommunicationOperatorDto $dto): void
    {
        if ($dto->getName() !== null) {
            $operator->setName($dto->getName());
        }
        if ($dto->getCountryName() !== null) {
            $operator->setCountryName($dto->getCountryName());
        }
        if ($dto->getCountryIsoCode() !== null) {
            $operator->setCountryIsoCode($dto->getCountryIsoCode());
        }
        if ($dto->getCountryRegions() !== null) {
            $operator->setCountryRegions(array_values($dto->getCountryRegions()));
        }
        if ($dto->getRegions() !== null) {
            $operator->setRegions(array_values($dto->getRegions()));
        }
        if ($dto->getIcon() !== null) {
            $operator->setIcon($dto->getIcon() === '' ? null : $dto->getIcon());
        }
        if ($dto->getMetadata() !== null) {
            $operator->setMetadata($dto->getMetadata());
        }
    }
}
