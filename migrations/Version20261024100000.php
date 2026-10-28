<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Operadores de DTOne (id, nombre, país y regiones) con icono y metadatos por idioma. Aditiva e idempotente: crea la
 * tabla y el operador por defecto 161 (Cubacel, Cuba) solo si no existe. Reversa (down): elimina la tabla.
 */
final class Version20261024100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea communication_operator (operadores de DTOne) con el operador por defecto 161 (Cubacel, Cuba)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS communication_operator (
                id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                country_name VARCHAR(255) NOT NULL,
                country_iso_code CHAR(3) NOT NULL,
                country_regions JSON NOT NULL DEFAULT '[]',
                regions JSON NOT NULL DEFAULT '[]',
                icon TEXT DEFAULT NULL,
                metadata JSON NOT NULL DEFAULT '{}',
                created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
            SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_communication_operator_country ON communication_operator (country_iso_code)');
        $this->addSql(<<<'SQL'
            INSERT INTO communication_operator (id, name, country_name, country_iso_code, created_at, updated_at)
            VALUES (161, 'Cubacel', 'Cuba', 'CUB', NOW(), NOW())
            ON CONFLICT (id) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS communication_operator');
    }
}
