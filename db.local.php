<?php
/**
 * db.local.php — JSON flat-file storage layer for LOCAL TESTING only.
 *
 * Drop-in replacement for db.php with identical function signatures. api.php
 * auto-selects this when a ./local-data directory exists. All data is stored
 * in ./local-data/*.json so it never collides with the production data/ folder.
 */

define('LOCAL_DATA_DIR', __DIR__ . '/local-data');

function _ensureDir(): void {
    if (!is_dir(LOCAL_DATA_DIR)) mkdir(LOCAL_DATA_DIR, 0777, true);
}

function _jsonFile(string $name): string {
    return LOCAL_DATA_DIR . '/' . $name . '.json';
}

function _readJson(string $name): array {
    $f = _jsonFile($name);
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function _writeJson(string $name, $data): void {
    _ensureDir();
    file_put_contents(_jsonFile($name), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Stub db() so any code that touches db() directly won't crash locally ──────
function db(): object {
    return new class {
        public function prepare(string $sql): object {
            return new class {
                public function execute(array $p = []): void {}
                public function fetch(): bool { return false; }
                public function fetchAll(): array { return []; }
                public function bindValue($a, $b): void {}
            };
        }
        public function exec(string $sql): void {}
        public function query(string $sql): object {
            return new class { public function fetchAll(): array { return []; } };
        }
        public function beginTransaction(): void {}
        public function commit(): void {}
        public function rollBack(): void {}
    };
}

// ── Generic id/data tables ───────────────────────────────────────────────────
function dbLoadAll(string $table): array {
    return array_values(_readJson($table));
}

function dbSaveAll(string $table, array $records, string $idKey = 'id'): void {
    $indexed = [];
    foreach ($records as $r) {
        $id = $r[$idKey] ?? null;
        if ($id !== null) $indexed[$id] = $r;
    }
    _writeJson($table, array_values($indexed));
}

// ── Leads (flat store with owner_id) ─────────────────────────────────────────
function dbLoadLeadsByOwner(string $ownerId): array {
    $all = _readJson('leads');
    $out = [];
    foreach ($all as $row) {
        if (($row['owner_id'] ?? '') === $ownerId) $out[] = $row['data'] ?? $row;
    }
    return $out;
}

function dbSaveLeadsForOwner(string $ownerId, array $leads): void {
    $all = _readJson('leads');
    // Drop this owner's existing leads, then re-add.
    $all = array_values(array_filter($all, fn($r) => ($r['owner_id'] ?? '') !== $ownerId));
    foreach ($leads as $lead) {
        $id = $lead['id'] ?? null;
        if ($id === null) continue;
        $all[] = ['id' => $id, 'owner_id' => $ownerId, 'data' => $lead];
    }
    _writeJson('leads', $all);
}

function dbDeleteUserData(string $userId): void {
    $all = _readJson('leads');
    $all = array_values(array_filter($all, fn($r) => ($r['owner_id'] ?? '') !== $userId));
    _writeJson('leads', $all);
    $store = _readJson('kv_store');
    unset($store['settings'][$userId]);
    unset($store['usermeta'][$userId]);
    unset($store['notif_dismissed_at'][$userId]);
    _writeJson('kv_store', $store);
    $notifs = _readJson('notifications');
    $notifs = array_values(array_filter($notifs, fn($n) => ($n['_user_id'] ?? '') !== $userId));
    _writeJson('notifications', $notifs);
}

// Moves a single lead to a new owner without touching any of the old or new
// owner's other leads — mirrors dbReassignLead() in db.php (Postgres mode).
function dbReassignLead(string $leadId, string $newOwnerId): bool {
    $all = _readJson('leads');
    $found = false;
    foreach ($all as &$row) {
        if (($row['id'] ?? null) === $leadId) {
            $row['owner_id'] = $newOwnerId;
            $found = true;
            break;
        }
    }
    unset($row);
    if ($found) _writeJson('leads', $all);
    return $found;
}

// ── KV store ─────────────────────────────────────────────────────────────────
function kvGet(string $bucket, string $key, $default = null) {
    $store = _readJson('kv_store');
    return $store[$bucket][$key] ?? $default;
}

function kvSet(string $bucket, string $key, $value): void {
    $store = _readJson('kv_store');
    $store[$bucket][$key] = $value;
    _writeJson('kv_store', $store);
}

// ── Chat messages ────────────────────────────────────────────────────────────
function dbLoadMessages(string $threadId): array {
    $all = _readJson('chat_messages');
    return array_values(array_filter($all, fn($m) => ($m['thread_id'] ?? '') === $threadId));
}

// Full-replace of a thread's message set — kept for any bulk-rewrite caller,
// but NOT safe for concurrent senders (see the matching warning in db.php).
// Every single-message operation must use dbInsertMessage()/dbUpdateMessage()/
// dbDeleteMessage() instead.
function dbSaveMessages(string $threadId, array $messages): void {
    $all = _readJson('chat_messages');
    $all = array_values(array_filter($all, fn($m) => ($m['thread_id'] ?? '') !== $threadId));
    foreach ($messages as $m) {
        $m['thread_id'] = $threadId;
        $all[] = $m;
    }
    _writeJson('chat_messages', $all);
}

function dbInsertMessage(string $threadId, array $msg): void {
    $all = _readJson('chat_messages');
    $msg['thread_id'] = $threadId;
    $found = false;
    foreach ($all as &$m) {
        if (($m['id'] ?? null) === ($msg['id'] ?? null)) { $m = $msg; $found = true; break; }
    }
    unset($m);
    if (!$found) $all[] = $msg;
    _writeJson('chat_messages', $all);
}

function dbUpdateMessage(string $threadId, string $messageId, array $msg): void {
    $all = _readJson('chat_messages');
    foreach ($all as &$m) {
        if (($m['id'] ?? null) === $messageId && ($m['thread_id'] ?? '') === $threadId) { $msg['thread_id'] = $threadId; $m = $msg; break; }
    }
    unset($m);
    _writeJson('chat_messages', $all);
}

function dbDeleteMessage(string $threadId, string $messageId): void {
    $all = _readJson('chat_messages');
    $all = array_values(array_filter($all, fn($m) => !(($m['id'] ?? null) === $messageId && ($m['thread_id'] ?? '') === $threadId)));
    _writeJson('chat_messages', $all);
}

// ── Chat last-read markers ───────────────────────────────────────────────────
function dbGetLastRead(string $userId, string $threadId): string {
    $store = _readJson('chat_last_read');
    return $store[$userId][$threadId] ?? '';
}

function dbSetLastRead(string $userId, string $threadId, string $lastRead): void {
    $store = _readJson('chat_last_read');
    $store[$userId][$threadId] = $lastRead;
    _writeJson('chat_last_read', $store);
}

// ── Notifications (own file-level store, matching db.php's own table) ───────
function dbLoadNotifications(string $userId, int $limit = 50): array {
    $all = _readJson('notifications');
    $mine = array_values(array_filter($all, fn($n) => ($n['_user_id'] ?? '') === $userId));
    usort($mine, fn($a, $b) => strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0'));
    return array_map(fn($n) => array_diff_key($n, ['_user_id' => true]), array_slice($mine, 0, $limit));
}

function dbInsertNotification(string $userId, array $notif): void {
    $all = _readJson('notifications');
    foreach ($all as $n) { if (($n['id'] ?? null) === ($notif['id'] ?? null)) return; }
    $notif['_user_id'] = $userId;
    $all[] = $notif;
    _writeJson('notifications', $all);
}

function dbNotificationKeyExists(string $userId, string $notifKey): bool {
    if ($notifKey === '') return false;
    foreach (_readJson('notifications') as $n) {
        if (($n['_user_id'] ?? '') === $userId && ($n['notif_key'] ?? '') === $notifKey) return true;
    }
    return false;
}

function dbMarkNotificationRead(string $userId, string $notifId): void {
    $all = _readJson('notifications');
    foreach ($all as &$n) {
        if (($n['_user_id'] ?? '') === $userId && ($n['id'] ?? null) === $notifId) { $n['read'] = true; break; }
    }
    unset($n);
    _writeJson('notifications', $all);
}

function dbMarkAllNotificationsRead(string $userId): void {
    $all = _readJson('notifications');
    foreach ($all as &$n) {
        if (($n['_user_id'] ?? '') === $userId) $n['read'] = true;
    }
    unset($n);
    _writeJson('notifications', $all);
}

function dbDeleteNotification(string $userId, string $notifId): void {
    $all = _readJson('notifications');
    $all = array_values(array_filter($all, fn($n) => !(($n['_user_id'] ?? '') === $userId && ($n['id'] ?? null) === $notifId)));
    _writeJson('notifications', $all);
}

function dbDeleteAllNotifications(string $userId): void {
    $all = _readJson('notifications');
    $all = array_values(array_filter($all, fn($n) => ($n['_user_id'] ?? '') !== $userId));
    _writeJson('notifications', $all);
}

// ── Activity pings (user session analytics) ──────────────────────────────────
function dbRecordPing(string $userId, string $userName, string $userRole, string $page): void {
    $all = _readJson('activity_pings');
    $all[] = [
        'user_id'   => $userId,
        'user_name' => $userName,
        'user_role' => $userRole,
        'page'      => $page,
        'pinged_at' => date('c'),
    ];
    // Keep the file bounded in local mode.
    if (count($all) > 5000) $all = array_slice($all, -5000);
    _writeJson('activity_pings', $all);
}

function dbLoadPings(int $days): array {
    $days = max(1, min(90, $days));
    $cutoff = time() - ($days * 86400);
    $all = _readJson('activity_pings');
    $out = array_values(array_filter($all, fn($p) => strtotime($p['pinged_at'] ?? '') >= $cutoff));
    usort($out, fn($a, $b) => strcmp($a['user_id'] . $a['pinged_at'], $b['user_id'] . $b['pinged_at']));
    return $out;
}

function dbLoadLastSeen(): array {
    $all = _readJson('activity_pings');
    $byUser = [];
    foreach ($all as $p) {
        $uid = $p['user_id'] ?? '';
        if (!isset($byUser[$uid]) || strcmp($p['pinged_at'] ?? '', $byUser[$uid]['pinged_at'] ?? '') > 0) {
            $byUser[$uid] = $p;
        }
    }
    return array_values($byUser);
}
