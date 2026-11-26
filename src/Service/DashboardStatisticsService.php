<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\CommunicationSaleInfoRepository;
use App\Repository\EnvironmentRepository;
use App\Repository\SysConfigRepository;
use App\Security\ClientScope;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Serializer\SerializerInterface;

class DashboardStatisticsService extends CommonService
{
    public function __construct(
        EntityManagerInterface $em,
        Security $security,
        ParameterBagInterface $parameters,
        MailerInterface $mailer,
        LoggerInterface $logger,
        UserPasswordHasherInterface $passwordHasher,
        EnvironmentRepository $environmentRepository,
        SysConfigRepository $sysConfigRepo,
        SerializerInterface $serializer,
        private readonly CommunicationSaleInfoRepository $saleRepo,
    ) {
        parent::__construct(
            $em, $security, $parameters, $mailer, $logger,
            $passwordHasher, $environmentRepository, $sysConfigRepo, $serializer
        );
    }

    private function resolveScope(?int $requestedClientId): ClientScope
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        return ClientScope::of($user, $this->security->isGranted('ROLE_ADMIN'), $requestedClientId);
    }

    /** @return array<string, mixed>|null null si el usuario no puede ver ningún cliente: no hay que consultar nada */
    private function normalizeFilters(array $filters): ?array
    {
        $scope = $this->resolveScope($filters['clientId'] ?? null);
        if ($scope->isNone()) {
            return null;
        }

        return [
            'clientId'        => $scope->clientId(),
            'dateFrom'        => !empty($filters['dateFrom'])
                ? new \DateTimeImmutable($filters['dateFrom'])
                : new \DateTimeImmutable('-1 year'),
            'dateTo'          => !empty($filters['dateTo'])
                ? (new \DateTimeImmutable($filters['dateTo']))->modify('+1 day')
                : (new \DateTimeImmutable('now'))->modify('+1 day'),
            'environmentType' => $filters['environmentType'] ?? null,
            'type'            => $filters['type'] ?? null,
        ];
    }

    public function getSummary(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        if ($f === null) {
            return [
                'totalOperations' => 0,
                'completed'       => 0,
                'failed'          => 0,
                'pending'         => 0,
                'rejected'        => 0,
                'created'         => 0,
                'reserved'        => 0,
                'successRate'     => 0,
                'totalAmount'     => 0.0,
                'avgAmount'       => 0.0,
            ];
        }
        return $this->saleRepo->getStatsSummary(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type']
        );
    }

    public function getOperationsByClient(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        if ($f === null) {
            return [];
        }
        return $this->saleRepo->getStatsOperationsByClient(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type']
        );
    }

    public function getOperationsOverTime(array $filters, string $groupBy): array
    {
        $f = $this->normalizeFilters($filters);
        $allowed = ['day', 'week', 'month'];
        if (!in_array($groupBy, $allowed, true)) {
            $groupBy = 'day';
        }
        if ($f === null) {
            return ['groupBy' => $groupBy, 'series' => []];
        }
        return $this->saleRepo->getStatsOperationsOverTime(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type'], $groupBy
        );
    }

    public function getBusiestDays(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        if ($f === null) {
            return [];
        }
        return $this->saleRepo->getStatsBusiestDays(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type']
        );
    }

    public function getPeakHours(array $filters): array
    {
        $f = $this->normalizeFilters($filters);
        if ($f === null) {
            return [];
        }
        return $this->saleRepo->getStatsPeakHours(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type']
        );
    }

    public function getTopPackages(array $filters, int $limit): array
    {
        $f = $this->normalizeFilters($filters);
        if ($f === null) {
            return [];
        }
        return $this->saleRepo->getStatsTopPackages(
            $f['clientId'], $f['dateFrom'], $f['dateTo'], $f['environmentType'], $f['type'], $limit
        );
    }
}
