<?php
// server.php
require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use SplObjectStorage;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;

class Signaling implements MessageComponentInterface {
    /** @var array<string, SplObjectStorage> room => set(connections) */
    private $rooms = [];
    /** @var array<int, string> connId => room */
    private $connRoom = [];

    public function onOpen(ConnectionInterface $conn) {
        $conn->send(json_encode(["type" => "hello", "msg" => "connected"]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        if (!is_array($data) || !isset($data['type'])) return;

        switch ($data['type']) {
            case 'join': {
                $room = (string)($data['room'] ?? 'default');
                $this->connRoom[$from->resourceId] = $room;
                if (!isset($this->rooms[$room])) $this->rooms[$room] = new SplObjectStorage();
                $this->rooms[$room]->attach($from);
                $this->broadcast($room, ["type"=>"peers","count"=>$this->countRoom($room)]);
                break;
            }
            case 'offer':
            case 'answer':
            case 'ice': {
                $room = $this->connRoom[$from->resourceId] ?? null;
                if (!$room) return;
                $payload = $data + ['sender' => $from->resourceId];
                foreach ($this->rooms[$room] ?? [] as $client) {
                    if ($from !== $client) $client->send(json_encode($payload));
                }
                break;
            }
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $room = $this->connRoom[$conn->resourceId] ?? null;
        unset($this->connRoom[$conn->resourceId]);
        if ($room && isset($this->rooms[$room])) {
            if ($this->rooms[$room]->contains($conn)) $this->rooms[$room]->detach($conn);
            if (count($this->rooms[$room]) === 0) unset($this->rooms[$room]);
            else $this->broadcast($room, ["type"=>"peers","count"=>$this->countRoom($room)]);
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        $conn->close();
    }

    private function broadcast(string $room, array $data): void {
        foreach ($this->rooms[$room] ?? [] as $client) {
            $client->send(json_encode($data));
        }
    }

    private function countRoom(string $room): int {
        $n = 0; foreach ($this->rooms[$room] ?? [] as $_) $n++; return $n;
    }
}

$server = IoServer::factory(
    new HttpServer(new WsServer(new Signaling())),
    8080 // ws://host:8080
);

echo "Signaling WebSocket chạy tại ws://0.0.0.0:8080\n";
$server->run();
