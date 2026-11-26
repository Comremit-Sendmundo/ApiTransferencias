<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Exception\MyCurrentException;
use App\Repository\EnvironmentRepository;
use App\Repository\SysConfigRepository;
use App\Service\ReportService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * El acceso denegado de los informes debe lanzar la excepción de Security (la
 * que el firewall convierte en 403), no la de Finder, que es de ficheros.
 *
 * @covers \App\Service\ReportService
 */
class ReportServiceAccessTest extends TestCase
{
    private Security $security;
    private EntityManagerInterface $em;
    private ReportService $service;

    protected function setUp(): void
    {
        $this->security = $this->createMock(Security::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->service = new ReportService(
            $this->em,
            $this->security,
            $this->createMock(ParameterBagInterface::class),
            $this->createMock(MailerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(EnvironmentRepository::class),
            $this->createMock(SysConfigRepository::class),
            $this->createMock(SerializerInterface::class),
        );
    }

    public function testGetAllReportsWithoutAUserThrowsSecurityAccessDenied(): void
    {
        $this->security->method('getUser')->willReturn(null);

        $this->expectException(AccessDeniedException::class);

        $this->service->getAllReports();
    }

    public function testGetAllReportsWithoutThePermissionThrowsSecurityAccessDenied(): void
    {
        $this->security->method('getUser')->willReturn($this->createMock(User::class));
        $this->security->method('isGranted')->willReturn(false);

        $this->expectException(AccessDeniedException::class);

        $this->service->getAllReports();
    }

    // ---- COMR-399: basta ROLE_SYSTEM_USER y el alcance es el del cliente propio ----

    private function authenticateSystemUserWithoutClient(): void
    {
        $this->security->method('getUser')->willReturn(new User());
        $this->security->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_SYSTEM_USER');
    }

    public function testASystemUserWithoutAClientGetsAnEmptyPageWithoutQuerying(): void
    {
        $this->authenticateSystemUserWithoutClient();
        $this->em->expects($this->never())->method('getRepository');

        $page = $this->service->getAllReports(null, 1, 25);

        $this->assertSame([0, 1, 25, []], [$page->getTotal(), $page->getPage(), $page->getPerPage(), $page->getResults()]);
    }

    public function testAReportThatDoesNotExistIsNotFound(): void
    {
        $this->authenticateSystemUserWithoutClient();
        $this->em->method('getRepository')->willReturn($this->createStub(EntityRepository::class));

        try {
            $this->service->getReport(5);
            $this->fail('Debía lanzar REPORT_NOT_FOUND');
        } catch (MyCurrentException $e) {
            $this->assertSame(['REPORT_NOT_FOUND', 404], [$e->getCodeWork(), $e->getCode()]);
        }
    }
}
