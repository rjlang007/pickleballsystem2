<?php
// ============================================================
//  FILE: includes/websocket.php
//
//  WebSocket server for real-time updates.
//
//  Broadcasts court status changes, queue updates, game events.
//
//  Requires: ReactPHP WebSocket or similar server
// ============================================================

class WebSocketBroadcaster {
    private $clients = [];
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function addClient($conn) {
        $this->clients[] = $conn;
    }

    public function removeClient($conn) {
        $this->clients = array_filter($this->clients, fn($c) => $c !== $conn);
    }

    public function broadcastCourtUpdate(int $courtId, array $data) {
        $message = json_encode([
            'type' => 'court_update',
            'court_id' => $courtId,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $this->broadcast($message);
    }

    public function broadcastQueueUpdate(int $courtId, array $queueData) {
        $message = json_encode([
            'type' => 'queue_update',
            'court_id' => $courtId,
            'queue' => $queueData,
            'timestamp' => time(),
        ]);

        $this->broadcast($message);
    }

    public function broadcastGameEvent(int $courtId, string $event, array $data) {
        $message = json_encode([
            'type' => 'game_event',
            'court_id' => $courtId,
            'event' => $event,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $this->broadcast($message);
    }

    private function broadcast(string $message) {
        foreach ($this->clients as $client) {
            try {
                $client->send($message);
            } catch (Exception $e) {
                // Remove broken connection
                $this->removeClient($client);
            }
        }
    }

    public function getConnectedClientsCount(): int {
        return count($this->clients);
    }
}

// Global broadcaster instance
$websocketBroadcaster = null;

function getWebSocketBroadcaster(): WebSocketBroadcaster {
    global $websocketBroadcaster;
    if ($websocketBroadcaster === null) {
        $db = getDB();
        $websocketBroadcaster = new WebSocketBroadcaster($db);
    }
    return $websocketBroadcaster;
}

// Helper to broadcast from game operations
function broadcastCourtStatus(int $courtId) {
    $broadcaster = getWebSocketBroadcaster();

    // Get current court status
    $db = getDB();
    $stmt = $db->prepare("
        SELECT live_status, players_on_court, queue_count
        FROM falcon.v_court_status
        WHERE id = ?
    ");
    $stmt->execute([$courtId]);
    $status = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($status) {
        $broadcaster->broadcastCourtUpdate($courtId, $status);
    }
}

function broadcastQueueStatus(int $courtId) {
    $broadcaster = getWebSocketBroadcaster();

    // Get current queue
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.username, gq.joined_at
        FROM falcon.game_queue gq
        JOIN falcon.users u ON u.id = gq.user_id
        WHERE gq.court_id = ? AND gq.session_id IS NULL
        ORDER BY gq.joined_at ASC
        LIMIT 10
    ");
    $stmt->execute([$courtId]);
    $queue = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $broadcaster->broadcastQueueUpdate($courtId, $queue);
}