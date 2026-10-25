<?php

namespace App\DTO\Out;

final class CommunicationOperatorOutDto
{
    public int $id;
    public string $name;
    /** @var array{name: string, isoCode: string, regions: array<int, array{name: string, code: string}>} */
    public array $country;
    /** @var array<int, array{name: string, code: string}> */
    public array $regions = [];
    /** Solo en el detalle (el data URI es pesado); en el listado va `hasIcon`. */
    public ?string $icon = null;
    public bool $hasIcon;
    /** @var array<string, array{title?: string, description?: string, keywords?: string[]}> */
    public array $metadata = [];
    public ?string $createdAt = null;
    public ?string $updatedAt = null;
}
