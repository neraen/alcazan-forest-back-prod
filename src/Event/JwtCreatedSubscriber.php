<?php


namespace App\Event;


use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;

class JwtCreatedSubscriber
{
    public function __construct()
    {

    }

    public function updateJwtData(JWTCreatedEvent $event)
    {
        $user = $event->getUser();
        $data = $event->getData();
        $data['pseudo'] = $user->getPseudo();
        $data['is_active'] = $user->getIsActive();
        $data['description'] = $user->getDescription();
        $data['id'] = $user->getId();

        $event->setData($data);
    }
}