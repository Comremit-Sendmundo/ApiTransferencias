<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Alta de un operador. El id es el del operador en DTOne (no se genera). Hereda las restricciones de formato de la
 * actualización y exige id, nombre y país.
 */
class CreateCommunicationOperatorDto extends UpdateCommunicationOperatorDto
{
    #[Assert\NotNull]
    #[Assert\Positive]
    protected ?int $id;

    #[Assert\NotNull]
    protected ?string $name;

    #[Assert\NotNull]
    protected ?string $countryName;

    #[Assert\NotNull]
    protected ?string $countryIsoCode;

    public function __construct(
        ?int $id = null,
        ?string $name = null,
        ?string $countryName = null,
        ?string $countryIsoCode = null,
        ?array $countryRegions = null,
        ?array $regions = null,
        ?string $icon = null,
        ?array $metadata = null,
    ) {
        parent::__construct($name, $countryName, $countryIsoCode, $countryRegions, $regions, $icon, $metadata);
        $this->id = $id;
    }

    public function getId(): ?int { return $this->id; }
    public function setId(?int $v): void { $this->id = $v; }
}
