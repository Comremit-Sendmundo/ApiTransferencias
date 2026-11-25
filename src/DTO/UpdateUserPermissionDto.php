<?php

namespace App\DTO;

use App\Entity\UserPermission;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Edición parcial: un campo omitido no cambia y un null en clientId o userId lo quita. Para distinguirlos no hay
 * constructor (el serializador lo llamaría con null en lo omitido) y cada setter anota que su campo llegó.
 */
class UpdateUserPermissionDto implements IInput
{
    #[Assert\Choice(choices: UserPermission::ASSIGNABLE_ROLES)]
    protected ?string $minRoleRequired = null;

    protected ?bool $isActive = null;

    protected ?int $clientId = null;

    protected ?int $userId = null;

    /** @var array<string, true> Sin setter ni accesor: el cuerpo de la petición no puede escribirlo. */
    private array $sent = [];

    public function wasSent(string $field): bool
    {
        return isset($this->sent[$field]);
    }

    #[Assert\Callback]
    public function validateExplicitNulls(ExecutionContextInterface $context): void
    {
        foreach (['minRoleRequired', 'isActive'] as $field) {
            if ($this->wasSent($field) && $this->$field === null) {
                $context->buildViolation('Este campo no admite null')->atPath($field)->addViolation();
            }
        }
    }

    public function getMinRoleRequired(): ?string { return $this->minRoleRequired; }
    public function setMinRoleRequired(?string $v): void { $this->minRoleRequired = $v; $this->sent['minRoleRequired'] = true; }

    public function getIsActive(): ?bool { return $this->isActive; }
    public function setIsActive(?bool $v): void { $this->isActive = $v; $this->sent['isActive'] = true; }

    public function getClientId(): ?int { return $this->clientId; }
    public function setClientId(?int $v): void { $this->clientId = $v; $this->sent['clientId'] = true; }

    public function getUserId(): ?int { return $this->userId; }
    public function setUserId(?int $v): void { $this->userId = $v; $this->sent['userId'] = true; }
}
