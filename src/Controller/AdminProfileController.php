<?php

namespace App\Controller;

use App\DTO\Out\ProfileUpdateOutDto;
use App\DTO\Out\UserOutDto;
use App\DTO\UpdateProfileDto;
use App\Entity\User;
use App\OpenApi\Attribute\DashboardEndpoint;
use App\Service\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[Route('/admin/profile')]
class AdminProfileController extends AbstractController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly NormalizerInterface $serializer,
    ) {
    }

    #[Route('', name: 'admin_profile', methods: ['GET'])]
    #[DashboardEndpoint(summary: 'Obtener perfil', tag: 'Admin Profile', responseDto: UserOutDto::class)]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => ['message' => 'Unauthorized']], Response::HTTP_UNAUTHORIZED);
        }
        $user->setCurrentIp($request->getClientIp());

        return $this->json($this->sessionUser($user));
    }

    #[Route('', name: 'admin_profile_update', methods: ['PUT', 'PATCH'])]
    #[DashboardEndpoint(summary: 'Actualizar perfil', tag: 'Admin Profile', requestDto: UpdateProfileDto::class, responseDto: ProfileUpdateOutDto::class)]
    public function update(UpdateProfileDto $dto, Request $request): JsonResponse
    {
        $currentUser = $this->userService->update($dto);
        $currentUser->setCurrentIp($request->getClientIp());
        $newToken = $this->userService->createToken($currentUser, null);
        return $this->json([
            'token' => $newToken,
            'user' => $this->sessionUser($currentUser),
        ]);
    }

    /** El usuario con la misma forma y los mismos campos que devuelve el inicio de sesión. */
    private function sessionUser(User $user): array
    {
        return $this->serializer->normalize($this->userService->createPayloadUser($user), 'json', ['groups' => ['profile']]);
    }
}