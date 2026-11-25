<?php

declare(strict_types=1);

namespace App\Tests\Service\Navigation;

use App\Entity\NavigationItem;
use App\Enums\NavigationTypeEnum;
use App\Service\Navigation\NavigationMenuBuilder;
use App\Service\Navigation\NavigationMenuNode;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Service\Navigation\NavigationMenuBuilder
 * @covers \App\Service\Navigation\NavigationMenuNode
 */
class NavigationMenuBuilderTest extends TestCase
{
    /** @var list<array{item: NavigationItem, parentId: ?int}> */
    private array $rows = [];

    private int $ultimoId = 0;

    private function item(
        string $title,
        ?NavigationItem $parent = null,
        ?string $link = null,
        ?NavigationTypeEnum $type = null,
        bool $active = true,
    ): NavigationItem {
        $item = (new NavigationItem())->setTitle($title)->setLink($link)->setType($type)->setActive($active);
        (new \ReflectionProperty(NavigationItem::class, 'id'))->setValue($item, ++$this->ultimoId);
        $parent?->addChild($item);
        $this->rows[] = ['item' => $item, 'parentId' => $parent?->getId()];

        return $item;
    }

    /** @return list<array<string, mixed>> */
    private function menu(NavigationItem ...$permitidos): array
    {
        $ids = array_values(array_map(static fn (NavigationItem $item): int => (int) $item->getId(), $permitidos));

        return $this->titulos((new NavigationMenuBuilder())->build($this->rows, $ids));
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<array<string, mixed>>
     */
    private function titulos(array $nodes): array
    {
        self::assertTrue(array_is_list($nodes), 'Los hijos deben ser una lista');

        return array_map(
            fn (NavigationMenuNode $node): array => [(string) $node->item->getTitle() => $this->titulos($node->children)],
            $nodes,
        );
    }

    public function testSinFilasElMenuEstaVacio(): void
    {
        self::assertSame([], $this->menu());
    }

    public function testLosHijosQuedanEnListaAunqueFalteUnoIntermedio(): void
    {
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $h1 = $this->item('H1', $grupo, '/h1');
        $this->item('H2', $grupo, '/h2');
        $h3 = $this->item('H3', $grupo, '/h3');

        self::assertSame([['Grupo' => [['H1' => []], ['H3' => []]]]], $this->menu($h1, $h3));
    }

    public function testUnGrupoConPermisoSoloEnElTercerHijoLoDevuelveEnUnaLista(): void
    {
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $this->item('H1', $grupo, '/h1');
        $this->item('H2', $grupo, '/h2');
        $h3 = $this->item('H3', $grupo, '/h3');

        self::assertSame([['Grupo' => [['H3' => []]]]], $this->menu($h3));
    }

    public function testUnNietoSinPermisoOInactivoNoApareceNiHaceVisiblesASusAntecesores(): void
    {
        $raiz = $this->item('Raíz', type: NavigationTypeEnum::GROUP);
        $hijo = $this->item('Hijo', $raiz, type: NavigationTypeEnum::COLLAPSABLE);
        $this->item('Sin permiso', $hijo, '/sin-permiso');
        $inactivo = $this->item('Inactivo', $hijo, '/inactivo', active: false);

        self::assertSame([], $this->menu($inactivo));
    }

    public function testElPermisoEnElPadreNoConcedeLosHijos(): void
    {
        $raiz = $this->item('Raíz', link: '/raiz');
        $hijo = $this->item('Hijo', $raiz, '/hijo');
        $this->item('Nieto', $hijo, '/nieto');

        self::assertSame([['Raíz' => [['Hijo' => []]]]], $this->menu($raiz, $hijo));
    }

    public function testElPermisoSoloEnUnNietoMuestraSuCadenaSinHermanos(): void
    {
        $raiz = $this->item('Raíz', type: NavigationTypeEnum::GROUP);
        $hijo = $this->item('Hijo', $raiz, type: NavigationTypeEnum::COLLAPSABLE);
        $this->item('Tío', $raiz, '/tio');
        $nieto = $this->item('Nieto', $hijo, '/nieto');
        $this->item('Hermano', $hijo, '/hermano');

        self::assertSame([['Raíz' => [['Hijo' => [['Nieto' => []]]]]]], $this->menu($nieto));
    }

    public function testUnaRaizInactivaOcultaTodaSuRama(): void
    {
        $raiz = $this->item('Raíz', link: '/raiz', active: false);
        $hijo = $this->item('Hijo', $raiz, '/hijo');

        self::assertSame([], $this->menu($raiz, $hijo));
    }

    public function testUnIntermedioInactivoOcultaSuRama(): void
    {
        $raiz = $this->item('Raíz', type: NavigationTypeEnum::GROUP);
        $medio = $this->item('Medio', $raiz, '/medio', active: false);
        $nieto = $this->item('Nieto', $medio, '/nieto');

        self::assertSame([], $this->menu($medio, $nieto));
    }

    /** @dataProvider tiposContenedor */
    public function testUnContenedorSinEnlaceNiHijosVisiblesSePodaEnCascada(NavigationTypeEnum $tipo): void
    {
        $raiz = $this->item('Raíz', type: NavigationTypeEnum::GROUP);
        $medio = $this->item('Medio', $raiz, type: NavigationTypeEnum::GROUP);
        $vacio = $this->item('Vacío', $medio, type: $tipo);
        $visible = $this->item('Visible', link: '/visible');

        self::assertSame([['Visible' => []]], $this->menu($raiz, $medio, $vacio, $visible));
    }

    /** @return iterable<string, array{NavigationTypeEnum}> */
    public static function tiposContenedor(): iterable
    {
        yield 'group' => [NavigationTypeEnum::GROUP];
        yield 'collapsable' => [NavigationTypeEnum::COLLAPSABLE];
        yield 'aside' => [NavigationTypeEnum::ASIDE];
    }

    public function testUnItemSinEnlaceConHijosEnLaBasePeroNingunoVisibleEsUnContenedorVacio(): void
    {
        $raiz = $this->item('Raíz', link: '/raiz');
        $sinTipo = $this->item('Sin tipo', $raiz);
        $this->item('Inactivo', $sinTipo, '/inactivo', active: false);

        self::assertSame([['Raíz' => []]], $this->menu($raiz, $sinTipo));
    }

    public function testUnaRaizConEnlaceYPermisoPropioSeVeAunqueSusHijosEstenInactivos(): void
    {
        $raiz = $this->item('Raíz', link: '/raiz', type: NavigationTypeEnum::COLLAPSABLE);
        $hijo = $this->item('Hijo', $raiz, '/hijo', active: false);

        self::assertSame([['Raíz' => []]], $this->menu($raiz, $hijo));
    }

    public function testLasHojasEspecialesPermitidasSeConservanDentroDelArbol(): void
    {
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $divisor = $this->item('Divisor', $grupo, type: NavigationTypeEnum::DIVIDER);
        $espacio = $this->item('Espacio', $grupo, type: NavigationTypeEnum::SPACER);
        $funcion = $this->item('Función', $grupo, type: NavigationTypeEnum::BASIC);
        $funcion->setFunction('abrirAyuda');

        self::assertSame(
            [['Grupo' => [['Divisor' => []], ['Espacio' => []], ['Función' => []]]]],
            $this->menu($divisor, $espacio, $funcion),
        );
    }

    public function testUnaRaizSinEnlaceNiHijosQuedaFueraAunqueSeaDivisorOEspacio(): void
    {
        $divisor = $this->item('Divisor', type: NavigationTypeEnum::DIVIDER);
        $espacio = $this->item('Espacio', type: NavigationTypeEnum::SPACER);
        $suelto = $this->item('Suelto');

        self::assertSame([], $this->menu($divisor, $espacio, $suelto));
    }

    public function testUnItemConEnlaceSeMuestraSiSoloEsVisibleUnDescendiente(): void
    {
        $raiz = $this->item('Raíz', link: '/raiz');
        $hijo = $this->item('Hijo', $raiz, '/hijo');

        self::assertSame([['Raíz' => [['Hijo' => []]]]], $this->menu($hijo));
    }

    public function testRespetaElOrdenDeLasFilas(): void
    {
        $segunda = $this->item('Zeta', link: '/z');
        $primera = $this->item('Alfa', link: '/a');
        $hijoB = $this->item('B', $segunda, '/b');
        $hijoA = $this->item('A', $segunda, '/a2');
        $segunda->setOrderValue('2');
        $primera->setOrderValue('1');
        $hijoB->setOrderValue('9');
        $hijoA->setOrderValue('1');

        self::assertSame(
            [['Zeta' => [['B' => []], ['A' => []]]], ['Alfa' => []]],
            $this->menu($primera, $segunda, $hijoA, $hijoB),
        );
    }

    public function testElPadrePuedeLlegarDespuesDeSusHijos(): void
    {
        $padre = $this->item('Padre', type: NavigationTypeEnum::GROUP);
        $hijo = $this->item('Hijo', $padre, '/hijo');
        $this->rows = array_reverse($this->rows);

        self::assertSame([['Padre' => [['Hijo' => []]]]], $this->menu($hijo));
    }

    public function testUnCicloSinRaizNoApareceNiBloquea(): void
    {
        $uno = $this->item('Uno', link: '/uno');
        $dos = $this->item('Dos', $uno, '/dos');
        // El primero pasa a colgar del segundo: la rama se queda sin raíz.
        $this->rows[0]['parentId'] = $dos->getId();
        $suelto = $this->item('Suelto', link: '/suelto');

        self::assertSame([['Suelto' => []]], $this->menu($uno, $dos, $suelto));
    }

    public function testUnItemQueReapareceComoDescendienteSuyoSeCorta(): void
    {
        $uno = $this->item('Uno', link: '/uno');
        $dos = $this->item('Dos', $uno, '/dos');
        $this->rows[] = ['item' => $uno, 'parentId' => $dos->getId()];

        self::assertSame([['Uno' => [['Dos' => []]]]], $this->menu($uno, $dos));
    }

    public function testNoModificaLasEntidades(): void
    {
        $grupo = $this->item('Grupo', type: NavigationTypeEnum::GROUP);
        $oculto = $this->item('Oculto', $grupo, '/oculto');
        $visible = $this->item('Visible', $grupo, '/visible');
        $inactivo = $this->item('Inactivo', $grupo, '/inactivo', active: false);

        $this->menu($visible);

        self::assertSame([$oculto, $visible, $inactivo], $grupo->getChildren()->toArray());
        foreach ([$oculto, $visible, $inactivo] as $hijo) {
            self::assertSame($grupo, $hijo->getParent());
        }
    }
}
