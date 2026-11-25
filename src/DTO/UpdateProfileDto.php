<?php

namespace App\DTO;

/**
 * Cuerpo de PUT/PATCH /admin/profile. El panel envía el usuario completo; solo se lee el id, para rechazar una
 * petición que se refiera a otra persona. Los datos de la sesión salen siempre del usuario autenticado.
 */
class UpdateProfileDto implements IInput
{
    public function __construct(
        protected ?int $id = null,
    ) {}

    public function getId(): ?int { return $this->id; }
    public function setId(?int $v): void { $this->id = $v; }
}
