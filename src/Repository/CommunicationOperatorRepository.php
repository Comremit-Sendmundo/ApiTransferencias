<?php

namespace App\Repository;

use App\Entity\CommunicationOperator;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CommunicationOperator>
 *
 * @method CommunicationOperator|null find($id, $lockMode = null, $lockVersion = null)
 * @method CommunicationOperator|null findOneBy(array $criteria, array $orderBy = null)
 * @method CommunicationOperator[]    findAll()
 * @method CommunicationOperator[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CommunicationOperatorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CommunicationOperator::class);
    }

    /**
     * @return CommunicationOperator[]
     */
    public function search(?string $country, ?string $search, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('o')->orderBy('o.countryName', 'ASC')->addOrderBy('o.name', 'ASC')->addOrderBy('o.id', 'ASC');

        if ($country !== null && $country !== '') {
            $qb->andWhere('o.countryIsoCode = :country')->setParameter('country', strtoupper($country));
        }
        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            // Por id exacto si es un número; si no, por nombre del operador o del país.
            $qb->andWhere(ctype_digit($term) ? '(o.id = :id OR LOWER(o.name) LIKE :q)' : '(LOWER(o.name) LIKE :q OR LOWER(o.countryName) LIKE :q)')
                ->setParameter('q', '%' . mb_strtolower($term) . '%');
            if (ctype_digit($term)) {
                $qb->setParameter('id', (int) $term);
            }
        }

        return $qb->setMaxResults($limit)->setFirstResult($offset)->getQuery()->getResult();
    }
}
