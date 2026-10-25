<?php

namespace App\Entity;

use App\Repository\CommunicationOperatorRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Operador móvil de DTOne (GET /operators). El id es el del proveedor, no se genera aquí. `country` y `regions` van
 * aplanados: nombre e ISO alfa-3 del país en columnas propias, y las regiones ([{name, code}], ISO 3166-2) como JSON.
 * Además guarda el icono (data URI) y los metadatos de SEO por idioma ({es: {title, description, keywords[]}}).
 * Hay un registro por defecto, el 161 (Cubacel, Cuba), que crea la migración.
 */
#[ORM\Entity(repositoryClass: CommunicationOperatorRepository::class)]
#[ORM\Table(name: 'communication_operator')]
#[ORM\Index(name: 'idx_communication_operator_country', columns: ['country_iso_code'])]
#[ORM\HasLifecycleCallbacks]
class CommunicationOperator
{
    public const DEFAULT_ID = 161;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255)]
    private ?string $countryName = null;

    #[ORM\Column(length: 3, options: ['fixed' => true])]
    private ?string $countryIsoCode = null;

    /** @var array<int, array{name: string, code: string}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $countryRegions = [];

    /** @var array<int, array{name: string, code: string}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $regions = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $icon = null;

    /** @var array<string, array{title?: string, description?: string, keywords?: string[]}> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\PrePersist]
    public function onCreated(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onUpdated(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCountryName(): ?string
    {
        return $this->countryName;
    }

    public function setCountryName(string $countryName): static
    {
        $this->countryName = $countryName;

        return $this;
    }

    public function getCountryIsoCode(): ?string
    {
        return $this->countryIsoCode;
    }

    public function setCountryIsoCode(string $countryIsoCode): static
    {
        $this->countryIsoCode = $countryIsoCode;

        return $this;
    }

    /** @return array<int, array{name: string, code: string}> */
    public function getCountryRegions(): array
    {
        return $this->countryRegions;
    }

    /** @param array<int, array{name: string, code: string}> $countryRegions */
    public function setCountryRegions(array $countryRegions): static
    {
        $this->countryRegions = $countryRegions;

        return $this;
    }

    /** @return array<int, array{name: string, code: string}> */
    public function getRegions(): array
    {
        return $this->regions;
    }

    /** @param array<int, array{name: string, code: string}> $regions */
    public function setRegions(array $regions): static
    {
        $this->regions = $regions;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** @return array<string, array{title?: string, description?: string, keywords?: string[]}> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** @param array<string, array{title?: string, description?: string, keywords?: string[]}> $metadata */
    public function setMetadata(array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
