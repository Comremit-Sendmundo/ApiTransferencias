<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vínculo opcional paquete→operador: columna nullable operator_id en communication_package (FK a communication_operator,
 * ON DELETE SET NULL) con su índice. Aditiva e idempotente; no rellena paquetes existentes. Reversa (down): quita la
 * columna.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade communication_package.operator_id (FK nullable a communication_operator, ON DELETE SET NULL)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_package ADD COLUMN IF NOT EXISTS operator_id INT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_communication_package_operator') THEN
                    ALTER TABLE communication_package
                        ADD CONSTRAINT fk_communication_package_operator
                        FOREIGN KEY (operator_id) REFERENCES communication_operator (id) ON DELETE SET NULL;
                END IF;
            END
            $$
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_communication_package_operator ON communication_package (operator_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE communication_package DROP CONSTRAINT IF EXISTS fk_communication_package_operator');
        $this->addSql('DROP INDEX IF EXISTS idx_communication_package_operator');
        $this->addSql('ALTER TABLE communication_package DROP COLUMN IF EXISTS operator_id');
    }
}
