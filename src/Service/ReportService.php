<?php

namespace App\Service;

use App\DTO\PaginationResult;
use App\Entity\ReportMarked;
use App\Entity\User;
use App\Exception\MyCurrentException;
use App\Security\ClientScope;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ReportService extends CommonService
{
    /**
     * @param int|null $clientId para ROLE_ADMIN, filtra por un cliente concreto (null = todos);
     *     el resto solo puede pedir el suyo (otro responde 403) y sin cliente no ve ninguno.
     * @param int $page
     * @param int $limit
     * @return \App\DTO\PaginationResult
     */
    public function getAllReports(?int $clientId = null, int $page = 0, int $limit = 40): PaginationResult
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        if (!$this->security->isGranted('ROLE_SYSTEM_USER')) {
            throw new AccessDeniedException();
        }

        $scope = ClientScope::of($user, $this->security->isGranted('ROLE_ADMIN'), $clientId);
        if ($scope->isNone()) {
            return new PaginationResult(0, $page, $limit, []);
        }

        /** @var \App\Repository\ReportMarkedRepository $repo */
        $repo = $this->em->getRepository(ReportMarked::class);
        return $repo->list($scope->clientId(), $page, $limit);
    }

    /**
     * @throws MyCurrentException si el informe no existe o es de un cliente que el usuario no puede ver
     */
    public function getReport(int $id): ReportMarked
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }
        if (!$this->security->isGranted('ROLE_SYSTEM_USER')) {
            throw new AccessDeniedException();
        }
        $report = $this->em->getRepository(ReportMarked::class)->find($id);
        $scope = ClientScope::of($user, $this->security->isGranted('ROLE_ADMIN'));
        if ($report === null || !$scope->allows($report->getClient()?->getId())) {
            throw new MyCurrentException('REPORT_NOT_FOUND', 'Report not found', 404);
        }
        return $report;
    }
}