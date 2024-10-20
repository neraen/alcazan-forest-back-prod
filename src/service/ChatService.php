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
$server = new App('localhost', 8080);
$server->route('/', new ChatService(), ['*']);
$server->run();