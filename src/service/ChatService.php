<?php


namespace App\service;


use JetBrains\PhpStorm\Pure;
use Ratchet\App;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class ChatService implements MessageComponentInterface {
    protected $clients;
    // protected $users;

    #[Pure] public function __construct() {
        $this->clients = new \SplObjectStorage;
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        // $this->users[$conn->resourceId] = $conn;
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        // unset($this->users[$conn->resourceId]);
    }

    public function onMessage(ConnectionInterface $from,  $data) {
        $from_id = $from->resourceId;
        $data = json_decode($data);
        $type = $data->type;
        switch ($type) {
            case 'chat':
                $user_id = $data->user_id;
                $chat_msg = $data->chat_msg;
                $response_from = $chat_msg;
                $response_to = $chat_msg;
                // Output
                $from->send(json_encode([
                    "type"=>$type,"msg"=>$response_from
                ]));
                foreach($this->clients as $client) {
                    if($from!=$client) {
                        $client->send(json_encode([
                            "type"=>$type,"msg"=>$response_to
                        ]));
                    }
                }
                break;
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }
}

// NOTE : ce service dépend de Ratchet, qui n'est PAS dans composer.json — la classe
// n'est pas utilisable en l'état. Le code de démarrage du serveur qui vivait ici a
// été retiré : il instanciait un serveur WebSocket au premier autoload de la classe.
// Pour le temps réel, voir la proposition Mercure dans DOCUMENTATION.md.