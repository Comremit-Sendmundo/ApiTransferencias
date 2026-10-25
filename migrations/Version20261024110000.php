<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrada de menú «Operadores» dentro de Catálogo, solo para administradores. Idempotente. Reversa (down): quita el
 * permiso y el item.
 */
final class Version20261024110000 extends AbstractMigration
{
    private const MENU = 'menu.apps.catalog.operators.title';

    public function getDescription(): string
    {
        return 'Añade el menú "Operadores" (/apps/catalog/operators) dentro de Catálogo, con permiso ROLE_ADMIN';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO navigation_item (id, parent_id, title, type, icon, link, is_active, created_at, updated_at, order_value)
            SELECT nextval('navigation_item_id_seq'), parent.id, 'menu.apps.catalog.operators.title', 'basic',
                   'heroicons_outline:signal', '/apps/catalog/operators', TRUE, NOW(), NOW(), '00139'
            FROM navigation_item parent
            WHERE parent.title = 'menu.apps.catalog.title'
              AND NOT EXISTS (SELECT 1 FROM navigation_item ni WHERE ni.title = 'menu.apps.catalog.operators.title')
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO user_permission (id, item_id, client_id, user_info_id, is_active, min_role_required, created_at, updated_at)
            SELECT nextval('user_permission_id_seq'), item.id, NULL, NULL, TRUE, 'ROLE_ADMIN', NOW(), NOW()
            FROM navigation_item item
            WHERE item.title = 'menu.apps.catalog.operators.title'
              AND NOT EXISTS (
                  SELECT 1 FROM user_permission up
                  WHERE up.item_id = item.id AND up.min_role_required = 'ROLE_ADMIN' AND up.client_id IS NULL AND up.user_info_id IS NULL
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM user_permission WHERE item_id IN (SELECT id FROM navigation_item WHERE title = '" . self::MENU . "')");
        $this->addSql("DELETE FROM navigation_item WHERE title = '" . self::MENU . "'");
    }
}
