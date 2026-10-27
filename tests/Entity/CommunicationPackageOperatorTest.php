<?php

namespace App\Tests\Entity;

use App\Entity\CommunicationOperator;
use App\Entity\CommunicationPackage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \App\Entity\CommunicationPackage
 */
class CommunicationPackageOperatorTest extends TestCase
{
    public function testSummaryIsNullWithoutOperator(): void
    {
        $this->assertNull((new CommunicationPackage())->getOperatorSummary());
    }

    public function testSummaryHasIdNameAndCountryButNoIcon(): void
    {
        $operator = (new CommunicationOperator())
            ->setId(161)
            ->setName('Cubacel')
            ->setCountryName('Cuba')
            ->setCountryIsoCode('CUB')
            ->setIcon('data:image/png;base64,AAAA');
        $package = (new CommunicationPackage())->setOperator($operator);

        $this->assertSame(
            ['id' => 161, 'name' => 'Cubacel', 'country' => ['name' => 'Cuba', 'iso_code' => 'CUB']],
            $package->getOperatorSummary(),
        );
    }
}
