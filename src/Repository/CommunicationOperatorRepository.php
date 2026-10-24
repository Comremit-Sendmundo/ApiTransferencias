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
}
