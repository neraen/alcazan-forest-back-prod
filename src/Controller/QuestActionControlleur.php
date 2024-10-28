<?php

namespace App\Controller;

use App\Repository\ActionFieldTypeRepository;
use App\Repository\ActionTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


#[Route("/api", name:"api_")]
class QuestActionControlleur extends AbstractController{


    public function __construct(){}

    #[Route("/action/types", name:"action_types")]
    public function getAllActionTypes(ActionTypeRepository $actionTypeRepository): Response {
        $actionTypes = $actionTypeRepository->findAll();
        $actionTypesNormalized = [];

        foreach ($actionTypes as $actionType) {
            $actionTypesNormalized[] = [
                'id' => $actionType->getId(),
                'name' => $actionType->getName(),
                'isRecursive' => $actionType->getIsRecursive()
            ];
        }

        return new Response(json_encode([
            'actionTypes' => $actionTypesNormalized
        ]));
    }


    #[Route("/action/type/fields", name:"action_type_fields")]
    public function getFieldsByTypeAction(Request $request, ActionFieldTypeRepository $actionFieldTypeRepository): Response {
        $requestContent = json_decode($request->getContent(), true);
        $idTypeAction = $requestContent['actionTypeId'];
        $fields = $actionFieldTypeRepository->getFieldByType($idTypeAction);

        return new Response(json_encode([
            'fields' => $fields
        ]));
    }


}