<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cambios sobre un operador. Todo es opcional: lo que no llega no se toca. `icon` vacío ('') quita el icono. El id no
 * se cambia. Las restricciones son las mismas de la creación, que añade la obligatoriedad (CreateCommunicationOperatorDto).
 */
class UpdateCommunicationOperatorDto implements IInput
{
    #[Assert\Length(min: 1, max: 255)]
    protected ?string $name;

    #[Assert\Length(min: 1, max: 255)]
    protected ?string $countryName;

    #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Debe ser un código ISO 3166-1 alfa-3 en mayúsculas')]
    protected ?string $countryIsoCode;

    /** @var array<int, array{name: string, code: string}>|null */
    #[Assert\All([new Assert\Collection(fields: [
        'name' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
        'code' => [new Assert\NotBlank(), new Assert\Regex(pattern: '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', message: 'Debe ser un código ISO 3166-2')],
    ])])]
    protected ?array $countryRegions;

    /** @var array<int, array{name: string, code: string}>|null */
    #[Assert\All([new Assert\Collection(fields: [
        'name' => [new Assert\NotBlank(), new Assert\Length(max: 255)],
        'code' => [new Assert\NotBlank(), new Assert\Regex(pattern: '/^[A-Z]{2}-[A-Z0-9]{1,3}$/', message: 'Debe ser un código ISO 3166-2')],
    ])])]
    protected ?array $regions;

    #[Assert\Length(max: 500000)]
    #[Assert\Regex(pattern: '/^(data:image\/(png|jpeg|webp|gif|svg\+xml);base64,[A-Za-z0-9+\/=]+)?$/', message: 'Debe ser una imagen en data URI base64')]
    protected ?string $icon;

    /** @var array<string, array{title?: string, description?: string, keywords?: string[]}>|null */
    #[Assert\Count(max: 10)]
    #[Assert\All([new Assert\Collection(fields: [
        'title' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 255)]),
        'description' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 1000)]),
        'keywords' => new Assert\Optional([
            new Assert\Type('array'),
            new Assert\Count(max: 30),
            new Assert\All([new Assert\Type('string'), new Assert\Length(max: 100)]),
        ]),
    ])])]
    protected ?array $metadata;

    public function __construct(
        ?string $name = null,
        ?string $countryName = null,
        ?string $countryIsoCode = null,
        ?array $countryRegions = null,
        ?array $regions = null,
        ?string $icon = null,
        ?array $metadata = null,
    ) {
        $this->name = $name;
        $this->countryName = $countryName;
        $this->countryIsoCode = $countryIsoCode;
        $this->countryRegions = $countryRegions;
        $this->regions = $regions;
        $this->icon = $icon;
        $this->metadata = $metadata;
    }

    public function getName(): ?string { return $this->name; }
    public function setName(?string $v): void { $this->name = $v; }

    public function getCountryName(): ?string { return $this->countryName; }
    public function setCountryName(?string $v): void { $this->countryName = $v; }

    public function getCountryIsoCode(): ?string { return $this->countryIsoCode; }
    public function setCountryIsoCode(?string $v): void { $this->countryIsoCode = $v; }

    public function getCountryRegions(): ?array { return $this->countryRegions; }
    public function setCountryRegions(?array $v): void { $this->countryRegions = $v; }

    public function getRegions(): ?array { return $this->regions; }
    public function setRegions(?array $v): void { $this->regions = $v; }

    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $v): void { $this->icon = $v; }

    public function getMetadata(): ?array { return $this->metadata; }
    public function setMetadata(?array $v): void { $this->metadata = $v; }
}
