<?php

namespace App\Controller;

use App\Exception\QuestException;
use App\service\ShopEditorService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin du ShopMaker. Tout le préfixe /api/shop/editor est réservé
 * ROLE_ADMIN dans security.yaml (lectures comprises).
 */
#[Route("/api/shop/editor", name: "api_shop_editor_")]
class ShopEditorController extends AbstractController
{
    public function __construct(private readonly ShopEditorService $shopEditorService){}

    #[Route("/list", name: "list", methods: ["POST"])]
    public function list(): Response
    {
        return new JsonResponse($this->shopEditorService->listShops());
    }

    #[Route("/referentiels", name: "referentiels", methods: ["POST"])]
    public function referentiels(): Response
    {
        return new JsonResponse($this->shopEditorService->getReferentiels());
    }

    #[Route("/get", name: "get", methods: ["POST"])]
    public function get(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            $shopId = (int)($this->decode($request)['shopId'] ?? 0);

            return $this->shopEditorService->getShopForEditor($shopId);
        });
    }

    #[Route("/save", name: "save", methods: ["POST"])]
    public function save(Request $request): Response
    {
        return $this->handle(fn (): array => $this->shopEditorService->saveShop($this->decode($request)));
    }

    #[Route("/delete", name: "delete", methods: ["POST"])]
    public function delete(Request $request): Response
    {
        return $this->handle(function () use ($request): array {
            $this->shopEditorService->deleteShop((int)($this->decode($request)['shopId'] ?? 0));

            return ['deleted' => true];
        });
    }

    private function decode(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : [];
    }

    private function handle(callable $callback): Response
    {
        try {
            return new JsonResponse($callback());
        } catch (QuestException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
