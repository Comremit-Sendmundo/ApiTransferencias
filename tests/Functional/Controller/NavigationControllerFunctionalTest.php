<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller;

use App\Controller\NavigationController;
use App\Entity\User;
use App\Enums\NavigationTypeEnum;
use App\Tests\Functional\FunctionalTestCase;
use App\Tests\Support\NavigationFixtures;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * GET /dashboard/api/navigation con el servicio y el repositorio reales.
 *
 * @covers \App\Controller\NavigationController
 */
class NavigationControllerFunctionalTest extends FunctionalTestCase
{
    use NavigationFixtures;

    private const CLAVES = ['compact', 'default', 'futuristic', 'horizontal'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->aislarMenu();
    }

    /** Monta el menú como lo vería el usuario, leyendo todo de la base. */
    private function pedirMenu(User $user): Response
    {
        $this->em->flush();
        $userId = $user->getId();
        $this->em->clear();

        $reloaded = $this->em->find(User::class, $userId);
        self::assertNotNull($reloaded);

        self::getContainer()->get(TokenStorageInterface::class)
            ->setToken(new UsernamePasswordToken($reloaded, 'dashboard', $reloaded->getRoles()));

        return self::getContainer()->get(NavigationController::class)->navigation();
    }

    private function decodificar(Response $response): \stdClass
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        return json_decode((string) $response->getContent(), false, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * Reduce el árbol a títulos y comprueba de paso que cada nivel es una lista JSON.
     *
     * @return list<array<string, mixed>>
     */
    private function titulos(mixed $nodos): array
    {
        self::assertIsArray($nodos, 'children debe ser una lista JSON, no un objeto');

        return array_map(
            fn (\stdClass $nodo): array => [$nodo->title => $this->titulos($nodo->children)],
            $nodos,
        );
    }

    public function testChildrenEsListaEnTodosLosNivelesAunqueFaltenHermanosAnteriores(): void
    {
        $user = $this->usuario(['ROLE_SYSTEM_USER']);
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP, order: '1');
        $this->item('H1', $grupo, '/h1', order: '1');
        $h2 = $this->item('H2', $grupo, '/h2', order: '2');
        $h3 = $this->item('H3', $grupo, type: NavigationTypeEnum::COLLAPSABLE, order: '3');
        $this->item('N1', $h3, '/n1', order: '1');
        $n2 = $this->item('N2', $h3, '/n2', order: '2');
        $this->item('N3', $h3, '/n3', active: false, order: '3');
        $this->permiso($h2, 'ROLE_SYSTEM_USER');
        $this->permiso($n2, 'ROLE_SYSTEM_USER');

        $response = $this->pedirMenu($user);
        $body = $this->decodificar($response);

        self::assertStringNotContainsString('"children":{', (string) $response->getContent());
        self::assertSame(
            [['Grupo' => [
                ['H2' => []],
                ['H3' => [['N2' => []]]],
            ]]],
            $this->titulos($body->default),
        );
    }

    public function testUnPermisoEnElPadreNoConcedeLosHijosEnNingunNivel(): void
    {
        $user = $this->usuario(['ROLE_SYSTEM_USER']);
        $raiz = $this->item('Raíz', link: '/raiz');
        $hijo = $this->item('Hijo', $raiz, '/hijo');
        $this->item('Nieto', $hijo, '/nieto');
        $this->permiso($raiz, 'ROLE_SYSTEM_USER');
        $this->permiso($hijo, 'ROLE_SYSTEM_USER');

        $body = $this->decodificar($this->pedirMenu($user));

        self::assertSame([['Raíz' => [['Hijo' => []]]]], $this->titulos($body->default));
    }

    public function testLasCuatroClavesLlevanElMismoArbolSinNulosYConHojasVacias(): void
    {
        $user = $this->usuario(['ROLE_SYSTEM_USER']);
        // Por título el hijo se lee antes que su padre: el orden de carga no debe afectar a la salida.
        $padre = $this->item('Z padre', type: NavigationTypeEnum::GROUP);
        $hoja = $this->item('A hoja', $padre, '/hoja');
        $this->permiso($hoja, 'ROLE_SYSTEM_USER');

        $response = $this->pedirMenu($user);
        $body = $this->decodificar($response);

        self::assertSame(self::CLAVES, array_keys(get_object_vars($body)));
        foreach (self::CLAVES as $clave) {
            self::assertEquals($body->default, $body->{$clave});
        }

        $nodoPadre = $body->default[0];
        self::assertSame(['id', 'title', 'type', 'children'], array_keys(get_object_vars($nodoPadre)));
        self::assertSame('group', $nodoPadre->type);

        $nodoHoja = $nodoPadre->children[0];
        self::assertSame(['id', 'title', 'link', 'children'], array_keys(get_object_vars($nodoHoja)));
        self::assertSame('A hoja', $nodoHoja->title);
        self::assertSame('/hoja', $nodoHoja->link);
        self::assertSame([], $nodoHoja->children);
    }

    public function testUnAdministradorDeSistemaVeLoConcedidoASuCliente(): void
    {
        $client = $this->cliente();
        $user = $this->usuario(['ROLE_SYSTEM_ADMIN'], $client);
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $this->item('Sin permiso', $grupo, '/no', order: '1');
        $delCliente = $this->item('Del cliente', $grupo, '/si', order: '2');
        $this->permiso($delCliente, client: $client);

        $body = $this->decodificar($this->pedirMenu($user));

        self::assertSame([['Grupo' => [['Del cliente' => []]]]], $this->titulos($body->default));
    }

    public function testSinPermisosDevuelveLasCuatroClavesVacias(): void
    {
        $user = $this->usuario(['ROLE_SYSTEM_USER']);
        $this->item('Sin permiso', link: '/no');

        $response = $this->pedirMenu($user);
        $this->decodificar($response);

        self::assertSame(
            '{"compact":[],"default":[],"futuristic":[],"horizontal":[]}',
            $response->getContent(),
        );
    }

    public function testMontarElMenuNoModificaLaBase(): void
    {
        $user = $this->usuario(['ROLE_SYSTEM_USER']);
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $oculto = $this->item('Sin permiso', $grupo, '/no', order: '1');
        $visible = $this->item('Con permiso', $grupo, '/si', order: '2');
        $inactivo = $this->item('Inactivo', $grupo, '/inactivo', active: false, order: '3');
        $this->permiso($visible, 'ROLE_SYSTEM_USER');

        $this->em->flush();
        $ids = [$grupo->getId(), $oculto->getId(), $visible->getId(), $inactivo->getId()];
        $antes = $this->filas($ids);

        $this->decodificar($this->pedirMenu($user));
        // Un flush posterior en la misma petición no debe arrastrar cambios del menú.
        $this->em->flush();
        $this->em->clear();

        self::assertSame($antes, $this->filas($ids));
    }

    /**
     * @param list<int|null> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function filas(array $ids): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT id, parent_id, is_active, updated_at FROM navigation_item WHERE id IN (%s) ORDER BY id',
                implode(',', array_map('intval', $ids)),
            ),
        );
    }
}
