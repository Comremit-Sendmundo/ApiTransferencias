<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\Entity\CommunicationOperator;
use App\State\CommunicationOperatorProvider;

/**
 * Operador móvil (DTOne) en la API pública: `GET /communication/operators` (filtros `country_iso_code`, ISO alfa-3, y `search`; `country` sigue valiendo como alias) y
 * `GET /communication/operators/{id}`. Es una vista de solo lectura de CommunicationOperator; la administración vive en
 * el dashboard. El listado no trae el icono (`icon` null, `has_icon` indica si existe): se pide en el detalle.
 */
#[ApiResource(
    shortName: 'CommunicationOperator',
    operations: [
        new GetCollection(
            uriTemplate: '/communication/operators',
            description: 'Operadores móviles disponibles, con su país y regiones',
            provider: CommunicationOperatorProvider::class,
            openapi: new OpenApiOperation(
                parameters: [
                    new OpenApiParameter(
                        name: 'country_iso_code',
                        in: 'query',
                        description: 'Código ISO 3166-1 alfa-3 del país (por ejemplo, CUB); sin distinguir mayúsculas. Devuelve solo los operadores de ese país.',
                        schema: ['type' => 'string', 'minLength' => 3, 'maxLength' => 3, 'example' => 'CUB'],
                    ),
                    new OpenApiParameter(
                        name: 'search',
                        in: 'query',
                        description: 'Texto a buscar en el nombre del operador o del país; si es un número, también coincide con el id del operador.',
                        schema: ['type' => 'string'],
                    ),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/communication/operators/{id}',
            requirements: ['id' => '\d+'],
            provider: CommunicationOperatorProvider::class,
        ),
    ],
    security: "is_granted('ROLE_COM_API_USER')",
    paginationMaximumItemsPerPage: 100,
)]
class CommunicationOperatorResource
{
    #[ApiProperty(identifier: true)]
    public int $id;

    public string $name;

    /** @var array<string, mixed> País: name, iso_code y regions. */
    public array $country = [];

    /** @var array<int, array{name: string, code: string}> */
    public array $regions = [];

    /** Data URI de la imagen; solo viene en el detalle. */
    public ?string $icon = null;

    public bool $hasIcon = false;

    /** @var array<string, array{title?: string, description?: string, keywords?: string[]}> */
    public array $metadata = [];

    public static function fromEntity(CommunicationOperator $operator, bool $withIcon): self
    {
        $resource = new self();
        $resource->id = (int) $operator->getId();
        $resource->name = (string) $operator->getName();
        $resource->country = [
            'name' => (string) $operator->getCountryName(),
            'iso_code' => (string) $operator->getCountryIsoCode(),
            'regions' => $operator->getCountryRegions(),
        ];
        $resource->regions = $operator->getRegions();
        $resource->icon = $withIcon ? $operator->getIcon() : null;
        $resource->hasIcon = $operator->getIcon() !== null && $operator->getIcon() !== '';
        $resource->metadata = $operator->getMetadata();

        return $resource;
    }
}
