<?php

namespace App\Service;

use App\Entity\User;

/**
 * Datos de un usuario que pueden salir en una respuesta del panel. Es una lista explícita: nunca incluye
 * hashes de contraseña, material del 2FA ni las cuentas de API de su empresa.
 */
class UserPresenter
{
    /** @return array<string, mixed> */
    public function present(User $user): array
    {
        $jp = $user->getJobPosition();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'middleName' => $user->getMiddleName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'jobTitle' => $user->getJobTitle(),
            'jobPosition' => $jp ? [
                'id'   => $jp->getId(),
                'code' => $jp->getCode(),
                'name' => $jp->getName(),
                'area' => $jp->getArea()?->value,
            ] : null,
            'phoneNumber' => $user->getPhoneNumber(),
            'langPreference' => $user->getLangPreference(),
            'isActive' => $user->isActive(),
            'isCheckValidation' => $user->isCheckValidation(),
            'twoFactorEnabled' => $user->isTwoFactorEnabled(),
            'company' => $user->getCompany() ? [
                'id' => $user->getCompany()->getId(),
                'companyName' => $user->getCompany()->getCompanyName(),
            ] : null,
            'createdAt' => $user->getCreatedAt()?->format('c'),
            'removedAt' => $user->getRemovedAt()?->format('c'),
        ];
    }
}
