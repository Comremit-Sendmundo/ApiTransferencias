<?php

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Vínculo paquete→operador: communication_package.operator_id existe, admite NULL y su FK es ON DELETE SET NULL.
 *
 * @covers \DoctrineMigrations\Version20261025120000
 */
class Version20261025120000Test extends KernelTestCase
{
    public function testLaColumnaOperatorIdAdmiteNull(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $nullable = $connection->fetchOne(
            "SELECT is_nullable FROM information_schema.columns WHERE table_name = 'communication_package' AND column_name = 'operator_id'",
        );

        $this->assertSame('YES', $nullable);
    }

    public function testLaFkBorraConSetNull(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        $rule = $connection->fetchOne(
            "SELECT delete_rule FROM information_schema.referential_constraints WHERE constraint_name = 'fk_communication_package_operator'",
        );

        $this->assertSame('SET NULL', $rule);
    }
}
