<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * COMR-399: qué clientes alcanza a ver un usuario del panel. Tres casos: todos (administradores), un solo cliente, o ninguno
 * (usuario sin cliente). Con «ninguno» no hay que consultar el repositorio: en las consultas un cliente null significa «sin filtro».
 */
final class ClientScope
{
    private function __construct(
        private readonly bool $all,
        private readonly ?int $clientId,
    ) {
    }

    /**
     * @param bool     $isAdmin   resultado de isGranted('ROLE_ADMIN')
     * @param int|null $requested cliente pedido en la petición, si lo hay
     *
     * @throws AccessDeniedException si quien no es administrador pide un cliente que no es el suyo
     */
    public static function of(?User $user, bool $isAdmin, ?int $requested = null): self
    {
        if ($isAdmin) {
            return new self($requested === null, $requested);
        }

        $own = $user?->getCompany()?->getId();
        if ($requested !== null && $requested !== $own) {
            throw new AccessDeniedException();
        }

        return new self(false, $own);
    }

    public function isAll(): bool
    {
        return $this->all;
    }

    public function isNone(): bool
    {
        return !$this->all && $this->clientId === null;
    }

    /** El único cliente visible; null tanto si se ven todos como si no se ve ninguno. */
    public function clientId(): ?int
    {
        return $this->clientId;
    }

    public function allows(?int $clientId): bool
    {
        return $this->all || ($this->clientId !== null && $this->clientId === $clientId);
    }
}
