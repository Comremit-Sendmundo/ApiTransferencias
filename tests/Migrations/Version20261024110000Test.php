<?php

namespace App\Tests\Migrations;

use App\Tests\Functional\Provider\ProviderFunctionalTestCase;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261024110000;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261024110000.php';

/**
 * La pantalla «Operadores» cuelga de Catálogo, con permiso de administrador. Idempotente y reversible.
 *
 * @covers \DoctrineMigrations\Version20261024110000
 */
class Version20261024110000Test extends ProviderFunctionalTestCase
{
    private const TITLE = 'menu.apps.catalog.operators.title';

    protected function setUp(): void
    {
        parent::setUp();

        // La base de pruebas solo trae el esquema: se siembra el grupo Catálogo, que en producción ya existe.
        $this->em->getConnection()->executeStatement(<<<'SQL'
            INSERT INTO navigation_item (id, parent_id, title, type, icon, link, is_active, created_at, updated_at, order_value)
            VALUES (nextval('navigation_item_id_seq'), NULL, 'menu.apps.catalog.title', 'collapsable', 'heroicons_outline:archive-box', NULL, TRUE, NOW(), NOW(), '00136')
        SQL);
    }

    private function migrate(string $direction = 'up'): void
    {
        $connection = $this->em->getConnection();
        $migration = new Version20261024110000($connection, new NullLogger());
        $migration->$direction(new Schema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    public function testTheScreenHangsFromCatalogForAdministrators(): void
    {
        $this->migrate();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT p.title AS parent, ni.link FROM navigation_item ni JOIN navigation_item p ON p.id = ni.parent_id WHERE ni.title = :t',
            ['t' => self::TITLE],
        );
        $this->assertSame(['menu.apps.catalog.title', '/apps/catalog/operators'], [$row['parent'], $row['link']]);
        $roles = $this->em->getConnection()->fetchFirstColumn(
            'SELECT up.min_role_required FROM user_permission up JOIN navigation_item ni ON ni.id = up.item_id WHERE ni.title = :t',
            ['t' => self::TITLE],
        );
        $this->assertSame(['ROLE_ADMIN'], $roles);
    }

    public function testRunningItAgainChangesNothingAndDownRemovesIt(): void
    {
        $this->migrate();
        $this->migrate();
        $this->assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM navigation_item WHERE title = :t', ['t' => self::TITLE]));
        $this->assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM user_permission up JOIN navigation_item ni ON ni.id = up.item_id WHERE ni.title = :t', ['t' => self::TITLE]));

        $this->migrate('down');
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM navigation_item WHERE title = :t', ['t' => self::TITLE]));
    }

    public function testItHasADescription(): void
    {
        $this->assertNotSame('', (new Version20261024110000($this->em->getConnection(), new NullLogger()))->getDescription());
    }
}
