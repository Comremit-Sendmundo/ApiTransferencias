<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Market\Organizacion\Application\LinkBuyerToOrgUnit;
use App\Repository\EnvironmentRepository;
use App\Repository\SysConfigRepository;
use App\Service\ClientService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * COMR-399: sin cliente, el listado de clientes va vacío y no llega a consultar (un cliente null en la consulta significaría «todos»).
 *
 * @covers \App\Service\ClientService
 */
class ClientServiceScopeTest extends FunctionalTestCase
{
    public function testAUserWithoutAClientGetsAnEmptyListWithoutQuerying(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $security->method('isGranted')->willReturn(false);

        $service = new ClientService(
            $em,
            $security,
            $this->createStub(ParameterBagInterface::class),
            $this->createStub(MailerInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(EnvironmentRepository::class),
            $this->createStub(SysConfigRepository::class),
            $this->createStub(SerializerInterface::class),
            $this->createStub(MessageBusInterface::class),
            self::getContainer()->get(LinkBuyerToOrgUnit::class),
        );

        $response = $service->getAllClients(['page' => 2, 'limit' => 15]);

        $this->assertSame([2, 15, 0, []], [$response->getPage(), $response->getLimit(), $response->getTotal(), $response->getResults()]);
    }
}
