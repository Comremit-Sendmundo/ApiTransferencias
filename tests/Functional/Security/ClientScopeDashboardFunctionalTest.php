<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Account;
use App\Entity\BalanceOperation;
use App\Entity\Client;
use App\Entity\CommunicationSaleInfo;
use App\Entity\CommunicationSalePackage;
use App\Entity\CommunicationSaleRecharge;
use App\Entity\ReportMarked;
use App\Entity\User;
use App\Enums\BalanceOperationEnum;
use App\Enums\BalanceStateEnum;
use App\Enums\CommunicationStateEnum;
use App\Repository\CommunicationSaleInfoRepository;
use App\Service\DashboardStatisticsService;
use App\Service\UserService;
use App\Tests\Functional\Provider\ProviderFunctionalTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * COMR-399: el alcance por cliente en el panel, contra el kernel real (firewall, JWT, controladores y Postgres). ROLE_ADMIN y
 * ROLE_SUPER_ADMIN ven todo; el resto de roles solo su cliente y, sin cliente, nada.
 *
 * Dos clientes con datos en cada prueba: «A» es el del usuario y «B» el ajeno.
 *
 * @coversNothing
 */
final class ClientScopeDashboardFunctionalTest extends ProviderFunctionalTestCase
{
    private const API = '/dashboard/api';

    private string|false $previousSecret = false;

    private int $clientA;
    private int $clientB;
    private int $accountA;
    private int $accountB;
    private int $operationA;
    private int $operationB;
    private int $saleA;
    private int $saleB;
    private int $providerCalls = 0;
    private int $sequence = 0;

    protected function setUp(): void
    {
        // La firma HS512 exige un secreto largo y el APP_SECRET de CI es corto.
        $this->previousSecret = $_SERVER['APP_SECRET'] ?? false;
        $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = str_repeat('s', 64);

        parent::setUp();

        // Cualquier llamada HTTP al proveedor queda contada y no sale del proceso.
        self::getContainer()->set('http_client', new MockHttpClient(function (): MockResponse {
            $this->providerCalls++;

            return new MockResponse('{}');
        }));

        $environment = $this->createEnvironment();
        $clientA = $this->createClient();
        $clientB = $this->createClient();
        $accountA = $this->createAccount($clientA, $environment);
        $accountB = $this->createAccount($clientB, $environment);
        $this->clientA = (int) $clientA->getId();
        $this->clientB = (int) $clientB->getId();
        $this->accountA = (int) $accountA->getId();
        $this->accountB = (int) $accountB->getId();
        $this->operationA = $this->createOperation($accountA);
        $this->operationB = $this->createOperation($accountB);
        $this->saleA = $this->createRecharge($this->accountA);
        $this->saleB = $this->createRecharge($this->accountB);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->previousSecret === false) {
            unset($_SERVER['APP_SECRET'], $_ENV['APP_SECRET']);
        } else {
            $_SERVER['APP_SECRET'] = $_ENV['APP_SECRET'] = $this->previousSecret;
        }
    }

    // ── Perfiles ────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function nonAdminRoles(): iterable
    {
        foreach (['ROLE_SYSTEM_USER', 'ROLE_SYSTEM_SHOW', 'ROLE_SYSTEM_EDITOR', 'ROLE_API_ADMIN'] as $role) {
            yield $role => [$role];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function adminRoles(): iterable
    {
        yield 'ROLE_ADMIN' => ['ROLE_ADMIN'];
        yield 'ROLE_SUPER_ADMIN' => ['ROLE_SUPER_ADMIN'];
    }

    // ── Finanzas ────────────────────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testFinancesShowOnlyTheOwnClient(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        foreach (['', '&clientId=' . $this->clientA] as $query) {
            [$status, $body] = $this->call('GET', '/admin/dashboard/financial?limit=1000' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame([$this->accountA], array_column($body['balances'], 'id'));
            $this->assertSame([$this->operationA], array_column($body['recentTransactions'], 'id'));
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientTheFinancesAreEmpty(string $role): void
    {
        [$status, $body] = $this->call('GET', '/admin/dashboard/financial?limit=1000', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([], $body['balances']);
        $this->assertSame([], $body['recentTransactions']);
        $this->assertArrayHasKey('accountBalance', $body);
    }

    /** @dataProvider nonAdminRoles */
    public function testAskingForTheFinancesOfAnotherClientIsForbidden(string $role): void
    {
        foreach ([$this->clientA, null] as $own) {
            [$status] = $this->call('GET', '/admin/dashboard/financial?clientId=' . $this->clientB, $this->panelUser($role, $own));

            $this->assertSame(403, $status);
        }
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorSeesTheFinancesOfEveryClient(string $role): void
    {
        $admin = $this->panelUser($role, null);

        [$status, $body] = $this->call('GET', '/admin/dashboard/financial?limit=1000', $admin);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertContainsBoth($this->accountA, $this->accountB, array_column($body['balances'], 'id'));
        $this->assertContainsBoth($this->operationA, $this->operationB, array_column($body['recentTransactions'], 'id'));

        [, $filtered] = $this->call('GET', '/admin/dashboard/financial?limit=1000&clientId=' . $this->clientB, $admin);

        $this->assertSame([$this->accountB], array_column($filtered['balances'], 'id'));
        $this->assertSame([$this->operationB], array_column($filtered['recentTransactions'], 'id'));
    }

    public function testAnAdministratorWithAClientStillSeesItsOwnFinancesByDefault(): void
    {
        [, $body] = $this->call('GET', '/admin/dashboard/financial?limit=1000', $this->panelUser('ROLE_ADMIN', $this->clientA));

        $this->assertSame([$this->accountA], array_column($body['balances'], 'id'));
    }

    // ── Operaciones de balance ──────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testBalanceOperationsShowOnlyTheOwnClient(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        [$status, $body] = $this->call('GET', '/admin/balance-operation?limit=1000', $user);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$this->operationA], array_column($body['results'], 'id'));
        $this->assertSame(1, $body['total']);

        [$status, $foreign] = $this->call('GET', '/admin/balance-operation?filter[accountId]=' . $this->accountB, $user);

        $this->assertSame(200, $status, json_encode($foreign));
        $this->assertSame([], $foreign['results']);
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientThereAreNoBalanceOperations(string $role): void
    {
        $user = $this->panelUser($role, null);

        foreach (['', '&filter[accountId]=' . $this->accountB] as $query) {
            [$status, $body] = $this->call('GET', '/admin/balance-operation?limit=25&page=0' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertEquals(['total' => 0, 'page' => 0, 'perPage' => 25, 'results' => []], $body);
        }
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorSeesTheBalanceOperationsOfEveryClient(string $role): void
    {
        [$status, $body] = $this->call('GET', '/admin/balance-operation?limit=1000', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertContainsBoth($this->operationA, $this->operationB, array_column($body['results'], 'id'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function editorsOutsideTheOperation(): iterable
    {
        foreach (['ROLE_SYSTEM_EDITOR', 'ROLE_API_ADMIN'] as $role) {
            yield $role . ' de otro cliente' => [$role, true];
            yield $role . ' sin cliente' => [$role, false];
        }
    }

    /** @dataProvider editorsOutsideTheOperation */
    public function testAForeignBalanceOperationIsNotFoundAndStaysUntouched(string $role, bool $withClient): void
    {
        $user = $this->panelUser($role, $withClient ? $this->clientA : null);
        $before = $this->operationRow($this->operationB);

        foreach ([['isRequired' => false], ['isRequired' => true], ['amountApproved' => 55.0, 'currencyApproved' => 'USD']] as $payload) {
            [$status, $body] = $this->call('PATCH', '/admin/balance-operation/' . $this->operationB, $user, $payload);

            $this->assertSame(404, $status, json_encode($body));
            $this->assertSame('BALANCE_OPERATION_NOT_FOUND', $body['error']['code']);
            $this->assertArrayNotHasKey('totalAmount', $body);
            $this->assertNoSideEffects();
        }
        $this->assertSame($before, $this->operationRow($this->operationB));
    }

    public function testAnUnknownBalanceOperationIsNotFound(): void
    {
        [$status, $body] = $this->call('PATCH', '/admin/balance-operation/999999999', $this->panelUser('ROLE_SUPER_ADMIN', null), ['isRequired' => false]);

        $this->assertSame(404, $status, json_encode($body));
        $this->assertSame('BALANCE_OPERATION_NOT_FOUND', $body['error']['code']);
    }

    /** @dataProvider nonAdminRoles */
    public function testUpdatingBalanceOperationsKeepsItsRoleRequirement(string $role): void
    {
        [$status, $body] = $this->call('PUT', '/admin/balance-operation/' . $this->operationA, $this->panelUser($role, $this->clientA), ['isRequired' => false]);

        $canEdit = in_array($role, ['ROLE_SYSTEM_EDITOR', 'ROLE_API_ADMIN'], true);
        $this->assertSame($canEdit ? 200 : 403, $status, json_encode($body));
    }

    public function testADomainErrorOnTheOwnOperationIsStillABadRequest(): void
    {
        $pending = $this->createOperation($this->em->find(Account::class, $this->accountA), BalanceStateEnum::PENDING);

        [$status, $body] = $this->call('PATCH', '/admin/balance-operation/' . $pending, $this->panelUser('ROLE_SYSTEM_EDITOR', $this->clientA), ['isRequired' => true]);

        $this->assertSame(400, $status, json_encode($body));
        $this->assertSame('BAL001', $body['error']['code']);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorStillApprovesTheOperationOfAnyClient(string $role): void
    {
        [$status, $body] = $this->call('PATCH', '/admin/balance-operation/' . $this->operationB, $this->panelUser($role, null), ['amountApproved' => 55.0, 'currencyApproved' => 'USD']);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertEquals(55.0, $this->operationRow($this->operationB)['total_amount']);
    }

    // ── Ventas ──────────────────────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testSalesShowOnlyTheOwnClient(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        foreach (['', '&clientId=' . $this->clientA, '&accountId=' . $this->accountB] as $query) {
            [$status, $body] = $this->call('GET', '/packages/sales?limit=100' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame([$this->saleA], array_column($body['results'], 'id'), $query);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientThereAreNoSales(string $role): void
    {
        $user = $this->panelUser($role, null);

        foreach (['', '&accountId=' . $this->accountB] as $query) {
            [$status, $body] = $this->call('GET', '/packages/sales?limit=100' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame(['limit' => 100, 'currentPage' => 0, 'hasNext' => false, 'hasPrevious' => false, 'results' => []], $body);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testAskingForTheSalesOfAnotherClientIsForbidden(string $role): void
    {
        foreach ([$this->clientA, null] as $own) {
            [$status] = $this->call('GET', '/packages/sales?clientId=' . $this->clientB, $this->panelUser($role, $own));

            $this->assertSame(403, $status);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testOnlyTheOwnSaleHasADetail(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        [$status, $body] = $this->call('GET', '/packages/sales/' . $this->saleA, $user);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame($this->saleA, $body['id']);

        foreach ([[$user, $this->saleB], [$this->panelUser($role, null), $this->saleA], [$user, 999999999]] as [$who, $sale]) {
            [$status, $body] = $this->call('GET', '/packages/sales/' . $sale, $who);

            $this->assertSame(404, $status, json_encode($body));
            $this->assertSame(['error' => ['message' => 'Sale not found']], $body);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testTheStatusOfAForeignSaleCannotBeChecked(string $role): void
    {
        // Completada, pendiente sin enviar y pendiente ya enviada: ninguna de las tres ramas revela nada ni llama al proveedor.
        $foreign = [
            $this->createRecharge($this->accountB, CommunicationStateEnum::COMPLETED, 'Completed'),
            $this->createRecharge($this->accountB, CommunicationStateEnum::PENDING, 'Created'),
            $this->saleB,
        ];

        foreach ([$this->clientA, null] as $own) {
            $user = $this->panelUser($role, $own);
            foreach ($foreign as $sale) {
                $before = $this->saleRow($sale);

                [$status, $body] = $this->call('POST', "/packages/sales/{$sale}/check-status", $user);

                $this->assertSame(404, $status, json_encode($body));
                $this->assertSame(['error' => ['message' => 'Sale not found']], $body);
                $this->assertNoSideEffects();
                $this->assertSame($before, $this->saleRow($sale));
            }
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testTheStatusOfTheOwnSaleCanBeChecked(string $role): void
    {
        [$status, $body] = $this->call('POST', "/packages/sales/{$this->saleA}/check-status", $this->panelUser($role, $this->clientA));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertGreaterThan(0, $this->providerCalls, 'La consulta propia sí llega al proveedor.');
    }

    /** @return iterable<string, array{bool}> */
    public static function withAndWithoutClient(): iterable
    {
        yield 'de otro cliente' => [true];
        yield 'sin cliente' => [false];
    }

    /** @dataProvider withAndWithoutClient */
    public function testAForeignSaleCannotBeRetried(bool $withClient): void
    {
        $user = $this->panelUser('ROLE_API_ADMIN', $withClient ? $this->clientA : null);
        $foreign = [
            'paquete fallido' => $this->createPackageSale($this->accountB, CommunicationStateEnum::FAILED),
            'recarga ETECSA fallida' => $this->createRecharge($this->accountB, CommunicationStateEnum::FAILED, 'Failed'),
            'recarga de otro proveedor' => $this->createRecharge($this->accountB, CommunicationStateEnum::FAILED, 'Failed', 'CSQ'),
            'completada' => $this->createRecharge($this->accountB, CommunicationStateEnum::COMPLETED, 'Completed'),
            'sin proveedor' => $this->createRecharge($this->accountB, CommunicationStateEnum::PENDING, CommunicationSaleInfo::STATE_PROCESS_AWAITING_PROVIDER),
        ];

        foreach ($foreign as $case => $sale) {
            $before = $this->saleRow($sale);

            [$status, $body] = $this->call('POST', "/packages/sales/{$sale}/retry", $user, ['reason' => 'Motivo suficiente']);

            $this->assertSame(404, $status, $case . ' ' . json_encode($body));
            $this->assertSame(['error' => ['message' => 'Sale not found']], $body);
            $this->assertNoSideEffects();
            $this->assertSame($before, $this->saleRow($sale), $case);
        }
    }

    public function testTheOwnSaleCanStillBeRetried(): void
    {
        $user = $this->panelUser('ROLE_API_ADMIN', $this->clientA);
        $package = $this->createPackageSale($this->accountA, CommunicationStateEnum::FAILED);

        [$status, $body] = $this->call('POST', "/packages/sales/{$package}/retry", $user, []);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(1, $this->sentMessages());

        $recharge = $this->createRecharge($this->accountA, CommunicationStateEnum::FAILED, 'Failed');
        $this->call('POST', "/packages/sales/{$recharge}/retry", $user, ['reason' => 'Motivo suficiente']);

        $this->assertGreaterThan(0, $this->providerCalls, 'El reintento propio de ETECSA sí llega al proveedor.');
    }

    /** @dataProvider nonAdminRoles */
    public function testRetryingKeepsItsRoleRequirement(string $role): void
    {
        $package = $this->createPackageSale($this->accountA, CommunicationStateEnum::FAILED);

        [$status] = $this->call('POST', "/packages/sales/{$package}/retry", $this->panelUser($role, $this->clientA), []);

        $this->assertSame($role === 'ROLE_API_ADMIN' ? 200 : 403, $status);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorWorksWithTheSalesOfEveryClient(string $role): void
    {
        $admin = $this->panelUser($role, null);

        [, $all] = $this->call('GET', '/packages/sales?limit=100', $admin);
        $this->assertContainsBoth($this->saleA, $this->saleB, array_column($all['results'], 'id'));
        foreach (['clientId=' . $this->clientB, 'accountId=' . $this->accountB] as $query) {
            [, $filtered] = $this->call('GET', '/packages/sales?limit=100&' . $query, $admin);
            $this->assertSame([$this->saleB], array_column($filtered['results'], 'id'), $query);
        }

        [$status] = $this->call('GET', '/packages/sales/' . $this->saleB, $admin);
        $this->assertSame(200, $status);

        [$status] = $this->call('POST', "/packages/sales/{$this->saleB}/check-status", $admin);
        $this->assertSame(200, $status);
        $this->assertGreaterThan(0, $this->providerCalls);

        $package = $this->createPackageSale($this->accountB, CommunicationStateEnum::FAILED);
        [$status, $body] = $this->call('POST', "/packages/sales/{$package}/retry", $admin, []);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertCount(1, $this->sentMessages());
    }

    // ── Ventas inciertas ────────────────────────────────────────

    private function createUncertain(int $accountId): int
    {
        return $this->createRecharge($accountId, CommunicationStateEnum::PENDING, CommunicationSaleInfo::STATE_PROCESS_UNCERTAIN);
    }

    public function testUncertainSalesShowOnlyTheOwnClient(): void
    {
        $mine = $this->createUncertain($this->accountA);
        $this->createUncertain($this->accountB);

        [$status, $body] = $this->call('GET', '/packages/sales/uncertain?limit=100', $this->panelUser('ROLE_API_ADMIN', $this->clientA));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$mine], array_column($body['results'], 'id'));
    }

    public function testWithoutAClientThereAreNoUncertainSales(): void
    {
        $this->createUncertain($this->accountA);

        [$status, $body] = $this->call('GET', '/packages/sales/uncertain?limit=100', $this->panelUser('ROLE_API_ADMIN', null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame(['limit' => 100, 'currentPage' => 0, 'hasNext' => false, 'hasPrevious' => false, 'results' => []], $body);
    }

    /** @dataProvider withAndWithoutClient */
    public function testAForeignUncertainSaleCannotBeTouched(bool $withClient): void
    {
        $user = $this->panelUser('ROLE_API_ADMIN', $withClient ? $this->clientA : null);
        // Una incierta y otra que no lo es: la ajena nunca llega a revelar su estado con un 409.
        foreach ([$this->createUncertain($this->accountB), $this->saleB, 999999999] as $sale) {
            $before = $this->saleRow($sale);
            foreach ([['recheck', null], ['resolve', ['resolution' => 'completed', 'reason' => 'Intento sobre una venta ajena']]] as [$action, $payload]) {
                [$status, $body] = $this->call('POST', "/packages/sales/uncertain/{$sale}/{$action}", $user, $payload);

                $this->assertSame(404, $status, "{$action} {$sale} " . json_encode($body));
                $this->assertSame('SALE_NOT_FOUND', $body['error']['code']);
                $this->assertNoSideEffects();
            }
            $this->assertSame($before, $this->saleRow($sale));
        }
        $this->assertSame(2, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM balance_operation WHERE tenant_id IN (:a, :b)',
            ['a' => $this->accountA, 'b' => $this->accountB],
        ), 'No se apunta ningún cargo.');
    }

    public function testTheOwnUncertainSaleCanBeRecheckedAndResolved(): void
    {
        $user = $this->panelUser('ROLE_API_ADMIN', $this->clientA);
        $sale = $this->createUncertain($this->accountA);

        [$status] = $this->call('POST', "/packages/sales/uncertain/{$sale}/recheck", $user);
        $this->assertSame(202, $status);
        $this->assertCount(1, $this->sentMessages());

        [$status, $body] = $this->call('POST', "/packages/sales/uncertain/{$sale}/resolve", $user, ['resolution' => 'rejected', 'reason' => 'El proveedor no la procesó']);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame('Rejected', $body['state']);
    }

    /** @return iterable<string, array{string}> */
    public static function rolesBelowApiAdmin(): iterable
    {
        foreach (['ROLE_SYSTEM_USER', 'ROLE_SYSTEM_SHOW', 'ROLE_SYSTEM_EDITOR'] as $role) {
            yield $role => [$role];
        }
    }

    /** @dataProvider rolesBelowApiAdmin */
    public function testUncertainSalesKeepTheirRoleRequirement(string $role): void
    {
        $sale = $this->createUncertain($this->accountA);
        $user = $this->panelUser($role, $this->clientA);

        [$list] = $this->call('GET', '/packages/sales/uncertain', $user);
        [$recheck] = $this->call('POST', "/packages/sales/uncertain/{$sale}/recheck", $user);
        [$resolve] = $this->call('POST', "/packages/sales/uncertain/{$sale}/resolve", $user, ['resolution' => 'rejected', 'reason' => 'Motivo suficiente']);

        $this->assertSame([403, 403, 403], [$list, $recheck, $resolve]);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorResolvesTheUncertainSalesOfEveryClient(string $role): void
    {
        $admin = $this->panelUser($role, null);
        $first = $this->createUncertain($this->accountA);
        $second = $this->createUncertain($this->accountB);

        [, $body] = $this->call('GET', '/packages/sales/uncertain?limit=100', $admin);
        $this->assertContainsBoth($first, $second, array_column($body['results'], 'id'));

        [$status] = $this->call('POST', "/packages/sales/uncertain/{$second}/recheck", $admin);
        $this->assertSame(202, $status);
        [$status] = $this->call('POST', "/packages/sales/uncertain/{$second}/resolve", $admin, ['resolution' => 'completed', 'reason' => 'Confirmada por el proveedor']);
        $this->assertSame(200, $status);
    }

    // ── Reportes ────────────────────────────────────────────────

    private function createReport(int $accountId): int
    {
        $account = $this->em->find(Account::class, $accountId);
        $report = (new ReportMarked())
            ->setName('Reporte de la cuenta ' . $accountId)
            ->setCreatedAt(new \DateTimeImmutable('now'))
            ->setLastOperationMarked(1)
            ->setClient($account->getClient())
            ->setAccount($account);
        $this->em->persist($report);
        $this->em->flush();

        return (int) $report->getId();
    }

    /** @dataProvider nonAdminRoles */
    public function testReportsShowOnlyTheOwnClient(string $role): void
    {
        $mine = $this->createReport($this->accountA);
        $this->createReport($this->accountB);
        $user = $this->panelUser($role, $this->clientA);

        foreach (['', '?clientId=' . $this->clientA] as $query) {
            [$status, $body] = $this->call('GET', '/admin/report' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame([$mine], array_column($body['results'], 'id'));
            $this->assertSame(1, $body['total']);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientThereAreNoReports(string $role): void
    {
        $this->createReport($this->accountA);

        [$status, $body] = $this->call('GET', '/admin/report', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertEquals(['total' => 0, 'page' => 0, 'perPage' => 40, 'results' => []], $body);
    }

    /** @dataProvider nonAdminRoles */
    public function testAskingForTheReportsOfAnotherClientIsForbidden(string $role): void
    {
        foreach ([$this->clientA, null] as $own) {
            [$status] = $this->call('GET', '/admin/report?clientId=' . $this->clientB, $this->panelUser($role, $own));

            $this->assertSame(403, $status);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testOnlyTheOwnReportCanBeRead(string $role): void
    {
        $mine = $this->createReport($this->accountA);
        $foreign = $this->createReport($this->accountB);
        $user = $this->panelUser($role, $this->clientA);

        [$status, $body] = $this->call('GET', '/admin/report/' . $mine, $user);
        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame($mine, $body['id']);

        foreach ([[$user, $foreign], [$this->panelUser($role, null), $mine], [$user, 999999999]] as [$who, $report]) {
            [$status, $body] = $this->call('GET', '/admin/report/' . $report, $who);

            $this->assertSame(404, $status, json_encode($body));
            $this->assertSame('REPORT_NOT_FOUND', $body['error']['code']);
        }
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorReadsTheReportsOfEveryClient(string $role): void
    {
        $first = $this->createReport($this->accountA);
        $second = $this->createReport($this->accountB);
        $admin = $this->panelUser($role, null);

        [, $all] = $this->call('GET', '/admin/report?limit=1000', $admin);
        $this->assertContainsBoth($first, $second, array_column($all['results'], 'id'));
        [, $filtered] = $this->call('GET', '/admin/report?clientId=' . $this->clientB, $admin);
        $this->assertSame([$second], array_column($filtered['results'], 'id'));

        [$status] = $this->call('GET', '/admin/report/' . $second, $admin);
        $this->assertSame(200, $status);
        [$status, $body] = $this->call('GET', '/admin/report/999999999', $admin);
        $this->assertSame(404, $status, json_encode($body));
    }

    // ── Estadísticas ────────────────────────────────────────────

    private const STATISTICS = ['summary', 'operations-by-client', 'operations-over-time', 'busiest-days', 'peak-hours', 'top-packages'];

    /** @return iterable<string, array{string}> */
    public static function statisticsRoles(): iterable
    {
        foreach (['ROLE_SYSTEM_SHOW', 'ROLE_SYSTEM_EDITOR', 'ROLE_API_ADMIN'] as $role) {
            yield $role => [$role];
        }
    }

    /** @dataProvider statisticsRoles */
    public function testStatisticsCountOnlyTheOwnClient(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        [$status, $summary] = $this->call('GET', '/information/summary', $user);
        $this->assertSame(200, $status, json_encode($summary));
        $this->assertSame(1, $summary['totalOperations']);

        [, $byClient] = $this->call('GET', '/information/operations-by-client', $user);
        $this->assertSame([$this->clientA], array_column($byClient, 'clientId'));
    }

    /** @dataProvider statisticsRoles */
    public function testWithoutAClientTheStatisticsAreEmpty(string $role): void
    {
        $user = $this->panelUser($role, null);

        foreach (self::STATISTICS as $report) {
            [$status, $body] = $this->call('GET', '/information/' . $report, $user);

            $this->assertSame(200, $status, $report . ' ' . json_encode($body));
            match ($report) {
                'summary' => $this->assertEquals(array_fill_keys([
                    'totalOperations', 'completed', 'failed', 'pending', 'rejected', 'created', 'reserved', 'successRate', 'totalAmount', 'avgAmount',
                ], 0), $body),
                'operations-over-time' => $this->assertSame(['groupBy' => 'day', 'series' => []], $body),
                default => $this->assertSame([], $body, $report),
            };
        }
    }

    public function testTheEmptyStatisticsHaveTheShapeOfAClientWithoutData(): void
    {
        $user = $this->panelUser('ROLE_SYSTEM_SHOW', null);
        $this->authenticateAsAdmin($user);
        $service = self::getContainer()->get(DashboardStatisticsService::class);
        $repository = $this->em->getRepository(CommunicationSaleInfo::class);
        \assert($repository instanceof CommunicationSaleInfoRepository);
        // La referencia son las consultas reales sobre un cliente que no tiene ninguna venta.
        $idle = [(int) $this->createClient()->getId(), new \DateTimeImmutable('-1 year'), new \DateTimeImmutable('+1 day'), null, null];

        $this->assertSame($repository->getStatsSummary(...$idle), $service->getSummary([]));
        $this->assertSame($repository->getStatsOperationsByClient(...$idle), $service->getOperationsByClient([]));
        foreach (['day', 'week', 'month'] as $groupBy) {
            $this->assertSame($repository->getStatsOperationsOverTime(...[...$idle, $groupBy]), $service->getOperationsOverTime([], $groupBy));
        }
        $this->assertSame($repository->getStatsBusiestDays(...$idle), $service->getBusiestDays([]));
        $this->assertSame($repository->getStatsPeakHours(...$idle), $service->getPeakHours([]));
        $this->assertSame($repository->getStatsTopPackages(...[...$idle, 10]), $service->getTopPackages([], 10));
    }

    /** @dataProvider statisticsRoles */
    public function testAskingForTheStatisticsOfAnotherClientIsForbidden(string $role): void
    {
        foreach ([$this->clientA, null] as $own) {
            foreach (self::STATISTICS as $report) {
                [$status] = $this->call('GET', "/information/{$report}?clientId=" . $this->clientB, $this->panelUser($role, $own));

                $this->assertSame(403, $status, $report);
            }
        }
    }

    public function testStatisticsKeepTheirRoleRequirement(): void
    {
        [$status] = $this->call('GET', '/information/summary', $this->panelUser('ROLE_SYSTEM_USER', $this->clientA));

        $this->assertSame(403, $status);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorSeesTheStatisticsOfEveryClient(string $role): void
    {
        $admin = $this->panelUser($role, null);

        [, $byClient] = $this->call('GET', '/information/operations-by-client', $admin);
        $this->assertContainsBoth($this->clientA, $this->clientB, array_column($byClient, 'clientId'));

        [, $summary] = $this->call('GET', '/information/summary?clientId=' . $this->clientB, $admin);
        $this->assertSame(1, $summary['totalOperations']);
    }

    // ── Listado de clientes ─────────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testTheClientListShowsOnlyTheOwnClient(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);

        [$status, $body] = $this->call('GET', '/admin/client/all?limit=1000', $user);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$this->clientA], array_column($body['results'], 'id'));
        $this->assertSame(1, $body['total']);

        [, $second] = $this->call('GET', '/admin/client/all?limit=1&page=1', $user);

        $this->assertSame([], $second['results']);
        $this->assertSame(1, $second['total']);
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientTheClientListIsEmpty(string $role): void
    {
        [$status, $body] = $this->call('GET', '/admin/client/all?limit=10', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertEquals(['page' => 0, 'limit' => 10, 'total' => 0, 'totalPages' => 0, 'results' => []], $body);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorListsEveryClient(string $role): void
    {
        // También con cliente propio: el listado del administrador no se filtra.
        [$status, $body] = $this->call('GET', '/admin/client/all?limit=1000', $this->panelUser($role, $this->clientA));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertContainsBoth($this->clientA, $this->clientB, array_column($body['results'], 'id'));
        $this->assertGreaterThanOrEqual(2, $body['total']);
    }

    // ── Permisos de cuenta ──────────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testThePermissionsShowOnlyTheOwnClient(string $role): void
    {
        [$status, $body] = $this->call('GET', '/admin/permission', $this->panelUser($role, $this->clientA));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([$this->accountA], array_column($body['accounts'], 'id'));
        $this->assertCount(1, $body['clients']);
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientThePermissionsAreEmpty(string $role): void
    {
        [$status, $body] = $this->call('GET', '/admin/permission', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertSame([], $body['accounts']);
        $this->assertSame([], $body['environments']);
        $this->assertSame([], $body['clients']);
        $this->assertNull($body['environment'] ?? null);
        $this->assertNull($body['account'] ?? null);
    }

    // ── Usuarios ────────────────────────────────────────────────

    /** @dataProvider nonAdminRoles */
    public function testEveryUserCanReadItself(string $role): void
    {
        foreach ([$this->clientA, null] as $client) {
            $user = $this->panelUser($role, $client);
            $id = (int) $user->getId();

            [$status, $body] = $this->call('GET', '/users/' . $id, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame($id, $body['id']);
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testReadingAnotherUserKeepsItsRules(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);
        $colleague = (int) $this->panelUser('ROLE_SYSTEM_USER', $this->clientA)->getId();
        $foreign = (int) $this->panelUser('ROLE_SYSTEM_USER', $this->clientB)->getId();

        [$own] = $this->call('GET', '/users/' . $colleague, $user);
        [$other] = $this->call('GET', '/users/' . $foreign, $user);

        $this->assertSame([$role === 'ROLE_API_ADMIN' ? 200 : 403, 403], [$own, $other]);
    }

    /** @dataProvider rolesBelowApiAdmin */
    public function testReadingItselfDoesNotAllowDisablingOrDeletingItself(string $role): void
    {
        $user = $this->panelUser($role, $this->clientA);
        $id = (int) $user->getId();

        [$toggle] = $this->call('PATCH', "/users/{$id}/toggle", $user);
        [$delete] = $this->call('DELETE', '/users/' . $id, $user);

        $this->assertSame([403, 403], [$toggle, $delete]);
        $this->assertTrue($this->em->find(User::class, $id)->isActive());
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientNoOtherUserCanBeReadDisabledOrDeleted(string $role): void
    {
        $actor = $this->panelUser($role, null);
        // «Sin cliente» en ambos lados no es compartir cliente.
        $targets = [
            'usuario sin cliente' => $this->panelUser('ROLE_SYSTEM_USER', null),
            'superadministrador sin cliente' => $this->panelUser('ROLE_SUPER_ADMIN', null),
            'administrador sin cliente' => $this->panelUser('ROLE_ADMIN', null),
            'usuario de un cliente' => $this->panelUser('ROLE_SYSTEM_USER', $this->clientB),
        ];

        foreach ($targets as $case => $target) {
            $id = (int) $target->getId();

            $this->assertSame([403, 403, 403], $this->readToggleAndDelete($actor, $id), $case);
            $this->assertUserUntouched($id, $case);
        }
    }

    public function testAnApiAdminManagesOnlyTheUsersOfItsClient(): void
    {
        $actor = $this->panelUser('ROLE_API_ADMIN', $this->clientA);
        $own = (int) $this->panelUser('ROLE_SYSTEM_USER', $this->clientA)->getId();

        foreach ([$this->clientB, null] as $client) {
            $id = (int) $this->panelUser('ROLE_SYSTEM_USER', $client)->getId();

            $this->assertSame([403, 403, 403], $this->readToggleAndDelete($actor, $id));
            $this->assertUserUntouched($id, 'ajeno');
        }
        $this->assertSame([200, 200, 200], $this->readToggleAndDelete($actor, $own));
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorManagesTheUsersOfEveryClientAndWithoutOne(string $role): void
    {
        $admin = $this->panelUser($role, null);

        foreach ([$this->clientB, null] as $client) {
            $id = (int) $this->panelUser('ROLE_SYSTEM_USER', $client)->getId();

            $this->assertSame([200, 200, 200], $this->readToggleAndDelete($admin, $id));
        }
    }

    /** @dataProvider nonAdminRoles */
    public function testWithoutAClientTheUserListIsEmpty(string $role): void
    {
        $this->panelUser('ROLE_SYSTEM_USER', null);
        $user = $this->panelUser($role, null);

        foreach (['', '?search=alcance', '?clientId=' . $this->clientB] as $query) {
            [$status, $body] = $this->call('GET', '/users' . $query, $user);

            $this->assertSame(200, $status, json_encode($body));
            $this->assertSame([], $body, $query);
        }
    }

    public function testAnApiAdminWithoutAClientCannotCreateUsers(): void
    {
        $actor = $this->panelUser('ROLE_API_ADMIN', null);

        foreach ([[], ['companyId' => $this->clientB]] as $company) {
            [$status, $body] = $this->call('POST', '/users', $actor, $this->newUser('alta-sin-cliente@example.test') + $company);

            $this->assertSame(403, $status, json_encode($body));
            $this->assertSame('Cannot create users for another client.', $body['error']['message']);
        }
        $this->assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'alta-sin-cliente@example.test']));
    }

    public function testUsersCanStillBeCreatedInTheOwnClientAndByAdministrators(): void
    {
        $apiAdmin = $this->panelUser('ROLE_API_ADMIN', $this->clientA);
        $superAdmin = $this->panelUser('ROLE_SUPER_ADMIN', null);

        [$own, $body] = $this->call('POST', '/users', $apiAdmin, $this->newUser('alta-propia@example.test'));
        $this->assertSame(201, $own, json_encode($body));
        [$foreign] = $this->call('POST', '/users', $apiAdmin, $this->newUser('alta-ajena@example.test') + ['companyId' => $this->clientB]);
        $this->assertSame(403, $foreign);
        [$byAdmin, $body] = $this->call('POST', '/users', $superAdmin, $this->newUser('alta-admin@example.test') + ['companyId' => $this->clientB]);
        $this->assertSame(201, $byAdmin, json_encode($body));
    }

    // ── Listado de usuarios de administración ───────────────────

    /** @return iterable<string, array{string}> */
    public static function userAdminRoles(): iterable
    {
        yield 'ROLE_SYSTEM_ADMIN' => ['ROLE_SYSTEM_ADMIN'];
        yield 'ROLE_API_ADMIN' => ['ROLE_API_ADMIN'];
    }

    /** @dataProvider userAdminRoles */
    public function testWithoutAClientTheAdminUserListIsEmpty(string $role): void
    {
        $this->panelUser('ROLE_SYSTEM_USER', $this->clientB);
        $this->panelUser('ROLE_SUPER_ADMIN', null);

        [$status, $body] = $this->call('GET', '/admin/user/all?limit=25', $this->panelUser($role, null));

        $this->assertSame(200, $status, json_encode($body));
        $this->assertEquals([['page' => 0, 'limit' => 25, 'total' => 0, 'totalPages' => 0, 'results' => []]], $body);
    }

    /** @dataProvider userAdminRoles */
    public function testTheAdminUserListShowsOnlyTheActiveUsersOfTheOwnClient(string $role): void
    {
        $actor = $this->panelUser($role, $this->clientA);
        $colleague = $this->panelUser('ROLE_SYSTEM_USER', $this->clientA);
        $this->panelUser('ROLE_SYSTEM_USER', $this->clientA, false);
        $this->panelUser('ROLE_SYSTEM_USER', $this->clientB);
        $this->panelUser('ROLE_SYSTEM_USER', null);

        [$status, $body] = $this->call('GET', '/admin/user/all?limit=1000', $actor);

        $this->assertSame(200, $status, json_encode($body));
        $this->assertEqualsCanonicalizing([$actor->getId(), $colleague->getId()], array_column($body[0]['results'], 'id'));
        $this->assertSame(2, $body[0]['total']);
    }

    /** @dataProvider adminRoles */
    public function testAnAdministratorListsTheUsersOfEveryClientEvenWithOneOfItsOwn(string $role): void
    {
        $withClient = $this->panelUser($role, $this->clientA);
        $withoutClient = $this->panelUser($role, null);
        $foreign = (int) $this->panelUser('ROLE_SYSTEM_USER', $this->clientB)->getId();
        $orphan = (int) $this->panelUser('ROLE_SYSTEM_USER', null)->getId();
        $inactive = (int) $this->panelUser('ROLE_SYSTEM_USER', $this->clientB, false)->getId();

        // Un administrador ve todo, tenga o no cliente propio: también los usuarios inactivos de cualquiera.
        foreach ([$withClient, $withoutClient] as $admin) {
            [$status, $body] = $this->call('GET', '/admin/user/all?limit=1000', $admin);

            $this->assertSame(200, $status, json_encode($body));
            $ids = array_column($body[0]['results'], 'id');
            $this->assertContainsBoth($foreign, $orphan, $ids);
            $this->assertContains($inactive, $ids);
        }
    }

    // ── Ayudas ──────────────────────────────────────────────────

    /** @return array{int, int, int} estados de leer, activar/desactivar y eliminar, en ese orden */
    private function readToggleAndDelete(User $actor, int $id): array
    {
        return [
            $this->call('GET', '/users/' . $id, $actor)[0],
            $this->call('PATCH', "/users/{$id}/toggle", $actor)[0],
            $this->call('DELETE', '/users/' . $id, $actor)[0],
        ];
    }

    private function assertUserUntouched(int $id, string $case): void
    {
        $this->em->clear();
        $user = $this->em->find(User::class, $id);

        $this->assertTrue($user->isActive(), $case);
        $this->assertNull($user->getRemovedAt(), $case);
    }

    /** @return array<string, string> */
    private function newUser(string $email): array
    {
        return ['email' => $email, 'firstName' => 'Alta', 'lastName' => 'Prueba', 'role' => 'ROLE_SYSTEM_USER'];
    }

    private function createRecharge(
        int $accountId,
        CommunicationStateEnum $state = CommunicationStateEnum::PENDING,
        string $stateProcess = 'Pending',
        string $provider = 'ETECSA',
    ): int {
        $account = $this->em->find(Account::class, $accountId);
        // El importe de destino distinto evita dos contratos por defecto para el mismo importe.
        $package = $this->createSellableV2Package($account->getEnvironment(), $provider, destinationAmount: 500.0 + $this->sequence);
        $sale = (new CommunicationSaleRecharge())
            ->setCatalogPackage($package)
            ->setPackageId($package->getId())
            ->setPhoneNumber('5550000000')
            ->setDestinationAmount($package->getDestinationAmount())
            ->setDestinationCurrency($package->getDestinationCurrency());

        return $this->persistSale($sale, $account, $state, $stateProcess, $provider);
    }

    private function createPackageSale(int $accountId, CommunicationStateEnum $state): int
    {
        $sale = (new CommunicationSalePackage())
            ->setIdentificationNumber('12345')
            ->setName('Cliente de prueba');

        return $this->persistSale($sale, $this->em->find(Account::class, $accountId), $state, $state->value, 'CSQ');
    }

    private function persistSale(
        CommunicationSaleInfo $sale,
        Account $account,
        CommunicationStateEnum $state,
        string $stateProcess,
        string $provider,
    ): int {
        ++$this->sequence;
        $sale
            ->setTenant($account)
            ->setAmount(6.5)
            ->setTotalPrice(6.5)
            ->setCurrency('USD')
            ->setProvider($provider)
            ->setClientTransactionId('scope-' . $this->sequence)
            ->setTransactionId('2611250' . str_pad((string) $this->sequence, 6, '0', STR_PAD_LEFT))
            ->setState($state)
            ->setStateProcess($stateProcess);
        $this->em->persist($sale);
        $this->em->flush();

        return (int) $sale->getId();
    }

    /** @return array<string, mixed> */
    private function saleRow(int $id): array
    {
        return (array) $this->em->getConnection()->fetchAssociative('SELECT * FROM communication_sale_info WHERE id = :id', ['id' => $id]);
    }

    /**
     * Mensajes encolados por la última petición (el kernel vacía los transportes en memoria al empezar la siguiente).
     *
     * @return list<object>
     */
    private function sentMessages(): array
    {
        $messages = [];
        foreach (['async_notifications_high', 'async_clients_high', 'async_send_sale_package', 'async_check_status', 'async_send_notification'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.' . $name);
            \assert($transport instanceof InMemoryTransport);
            foreach ($transport->getSent() as $envelope) {
                $messages[] = $envelope->getMessage();
            }
        }

        return $messages;
    }

    private function assertNoSideEffects(): void
    {
        $this->assertSame([], $this->sentMessages(), 'No se encola nada.');
        $this->assertSame(0, $this->providerCalls, 'No se llama al proveedor.');
    }

    private function panelUser(string $role, ?int $clientId, bool $isActive = true): User
    {
        static $counter = 0;
        $counter++;

        $user = (new User())
            ->setEmail("alcance-{$counter}@example.test")
            ->setPassword('irrelevant-hash')
            ->setFirstName('Alcance')
            ->setLastName("Prueba {$counter}")
            ->setRoles([$role])
            ->setIsActive($isActive)
            ->setIsCheckValidation(true);
        if ($clientId !== null) {
            $user->setCompany($this->em->find(Client::class, $clientId));
        }
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{int, mixed}
     */
    private function call(string $method, string $uri, User $as, ?array $payload = null): array
    {
        // clear() antes de abrir la sesión: su flush no debe tocar (updatedAt) los datos ya sembrados. El usuario se recarga por id.
        $this->em->clear();
        $as = $this->em->find(User::class, $as->getId());
        $as->setCurrentIp('127.0.0.1');
        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(UserService::class)->createSession($as, '127.0.0.1'),
        ];
        $this->em->clear();

        $content = $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR);
        $response = self::$kernel->handle(Request::create(self::API . $uri, $method, [], [], [], $server, $content));

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true)];
    }

    private function createOperation(Account $account, BalanceStateEnum $state = BalanceStateEnum::COMPLETED): int
    {
        $operation = (new BalanceOperation())
            ->setTenant($account)
            ->setAmount(100.0)
            ->setCurrency('USD')
            ->setTotalAmount(100.0)
            ->setTotalCurrency('USD')
            ->setState($state->value)
            ->setOperationType(BalanceOperationEnum::CREDIT->value);
        $this->em->persist($operation);
        $this->em->flush();

        return (int) $operation->getId();
    }

    /** @return array<string, mixed> */
    private function operationRow(int $id): array
    {
        return (array) $this->em->getConnection()->fetchAssociative('SELECT * FROM balance_operation WHERE id = :id', ['id' => $id]);
    }

    /** @param list<int> $ids */
    private function assertContainsBoth(int $first, int $second, array $ids): void
    {
        $this->assertContains($first, $ids);
        $this->assertContains($second, $ids);
    }
}
