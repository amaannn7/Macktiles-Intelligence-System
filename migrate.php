<?php
/**
 * migrate.php — One-time JSON → PostgreSQL importer for Macktiles Sales Intelligence
 *
 * Run once on the server from the command line:
 *   php migrate.php
 *
 * Or via browser after setting $browserSecret below:
 *   https://sales.macktiles.com.au/migrate.php?secret=YOUR_SECRET
 *
 * Safe to re-run: every insert is an UPSERT, so re-running does not duplicate data.
 * The data/ JSON files are treated as the authoritative source on import.
 *
 * IMPORTANT: This reads the LEGACY Macktiles layout:
 *   - data/users.json               → users table
 *   - data/admin.json               → kv_store (config/admin)
 *   - data/user_<id>.json           → that user's leads[] (flattened into the
 *                                     leads table, owner_id = <id>) and
 *                                     settings{} (into kv_store settings/<id>)
 *
 * After a successful run, remove (or rename) the ./local-data folder on the
 * server so api.php uses PostgreSQL. Keep data/ as a backup until verified.
 */

// ── CLI / browser guard ──────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $browserSecret = 'CHANGE_ME_BEFORE_BROWSER_USE';
    if (($_GET['secret'] ?? '') !== $browserSecret) {
        http_response_code(403);
        echo "CLI only. Add ?secret=YOUR_SECRET to run via browser after setting \$browserSecret.";
        exit(1);
    }
}

set_time_limit(600);
ini_set('memory_limit', '512M');

// Force the PostgreSQL layer regardless of any local-data folder.
require_once __DIR__ . '/db.php';

$DATA = __DIR__ . '/data/';
$counts = [];

function migrateLog(string $msg): void {
    $ts = date('H:i:s');
    if (php_sapi_name() === 'cli') {
        echo "[{$ts}] {$msg}\n";
    } else {
        echo "<p>[{$ts}] " . htmlspecialchars($msg) . "</p>\n";
        @ob_flush(); @flush();
    }
}

function loadJsonFile(string $path): array {
    if (!file_exists($path)) return [];
    $d = json_decode(file_get_contents($path), true);
    return is_array($d) ? $d : [];
}

function upsertRecord(string $table, string $id, array $data): void {
    db()->prepare("
        INSERT INTO {$table} (id, data, updated_at)
        VALUES (:id, :data::jsonb, now())
        ON CONFLICT (id) DO UPDATE
            SET data = EXCLUDED.data, updated_at = now()
    ")->execute([':id' => $id, ':data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function upsertLead(string $id, string $ownerId, array $lead): void {
    db()->prepare("
        INSERT INTO leads (id, owner_id, data, updated_at)
        VALUES (:id, :o, :data::jsonb, now())
        ON CONFLICT (id) DO UPDATE
            SET owner_id = EXCLUDED.owner_id, data = EXCLUDED.data, updated_at = now()
    ")->execute([':id' => $id, ':o' => $ownerId, ':data' => json_encode($lead, JSON_UNESCAPED_UNICODE)]);
}

// Ensure schema exists.
db();

// ── 1. USERS ─────────────────────────────────────────────────────────────────
migrateLog("Migrating users...");
$users = loadJsonFile($DATA . 'users.json');
$userCount = 0;
foreach ($users as $u) {
    $id = $u['id'] ?? null;
    if (!$id) continue;
    upsertRecord('users', $id, $u);
    $userCount++;
}
$counts['users'] = $userCount;
migrateLog("  → {$userCount} users migrated.");

// ── 2. ADMIN CONFIG ──────────────────────────────────────────────────────────
migrateLog("Migrating admin config...");
$admin = loadJsonFile($DATA . 'admin.json');
if (!empty($admin)) {
    kvSet('config', 'admin', $admin);
    $counts['admin'] = 1;
    migrateLog("  → admin config migrated to kv_store.");
} else {
    $counts['admin'] = 0;
    migrateLog("  → admin.json empty or missing, skipped.");
}

// ── 3. PER-USER LEADS + SETTINGS ─────────────────────────────────────────────
// A user with id "user_ABC" has a data file named "user_user_ABC.json" (the
// helper prefixes "user_" onto the id). So the owner id is the filename with a
// SINGLE leading "user_" stripped — which yields the exact stored user id.
migrateLog("Migrating per-user leads and settings...");
$knownUserIds = array_column($users, 'id');   // for orphan detection
$userFiles = glob($DATA . 'user_*.json');
$totalLeads = 0;
$settingsCount = 0;
$orphanWarnings = [];
foreach ($userFiles as $uf) {
    $base = basename($uf, '.json');            // e.g. user_user_ABC
    $ownerId = preg_replace('/^user_/', '', $base);   // → user_ABC (the real id)
    $data = loadJsonFile($uf);

    // Warn (but still import) if this file's owner has no matching user record.
    $leadCountForWarn = count($data['leads'] ?? []);
    if ($leadCountForWarn > 0 && !in_array($ownerId, $knownUserIds, true)) {
        $orphanWarnings[] = "{$base} → owner '{$ownerId}' ({$leadCountForWarn} leads) has NO matching user in users.json";
    }

    // Leads → flat leads table tagged with this owner.
    $leads = $data['leads'] ?? [];
    $fileLeadCount = 0;
    foreach ($leads as $lead) {
        $id = $lead['id'] ?? null;
        if (!$id) { $id = 'lead_' . substr(md5(json_encode($lead)), 0, 16); $lead['id'] = $id; }
        upsertLead($id, $ownerId, $lead);
        $totalLeads++;
        $fileLeadCount++;
    }

    // Settings → kv_store settings/<ownerId>
    if (isset($data['settings']) && is_array($data['settings'])) {
        kvSet('settings', $ownerId, $data['settings']);
        $settingsCount++;
    }

    migrateLog("  {$base}: {$fileLeadCount} leads" . (isset($data['settings']) ? " + settings" : ""));
}
$counts['leads'] = $totalLeads;
$counts['settings'] = $settingsCount;
migrateLog("  → {$totalLeads} total leads, {$settingsCount} settings blocks migrated.");
if (!empty($orphanWarnings)) {
    migrateLog("  ⚠ ORPHAN LEAD FILES (imported, but owner missing from users.json —");
    migrateLog("    verify these users exist on the LIVE server before trusting this run):");
    foreach ($orphanWarnings as $w) migrateLog("      - {$w}");
}

// ── 4. CHAT (optional — only if chat data files are present) ──────────────────
// These are populated once the chat branch is deployed. Harmless if absent.
migrateLog("Migrating chat channels (if present)...");
$channels = loadJsonFile($DATA . 'channels.json');
$chanCount = 0;
foreach ($channels as $ch) {
    $id = $ch['id'] ?? null;
    if (!$id) continue;
    upsertRecord('chat_channels', $id, $ch);
    $chanCount++;
}
$counts['chat_channels'] = $chanCount;
migrateLog("  → {$chanCount} channels migrated.");

migrateLog("Migrating chat messages (if present)...");
$msgFiles = glob($DATA . 'messages_*.json');
$totalMsgs = 0;
foreach ($msgFiles as $mf) {
    $threadId = preg_replace('/^messages_/', '', basename($mf, '.json'));
    foreach (loadJsonFile($mf) as $msg) {
        $id = $msg['id'] ?? null;
        if (!$id) continue;
        db()->prepare("
            INSERT INTO chat_messages (id, thread_id, data, updated_at)
            VALUES (:id, :t, :data::jsonb, now())
            ON CONFLICT (id) DO UPDATE
                SET data = EXCLUDED.data, thread_id = EXCLUDED.thread_id, updated_at = now()
        ")->execute([':id' => $id, ':t' => $threadId, ':data' => json_encode($msg, JSON_UNESCAPED_UNICODE)]);
        $totalMsgs++;
    }
}
$counts['chat_messages'] = $totalMsgs;
migrateLog("  → {$totalMsgs} chat messages migrated.");

migrateLog("Migrating chat last-read markers (if present)...");
$lrFiles = glob($DATA . 'chat_last_read_*.json');
$lrCount = 0;
foreach ($lrFiles as $lrf) {
    $userId = preg_replace('/^chat_last_read_/', '', basename($lrf, '.json'));
    foreach (loadJsonFile($lrf) as $threadId => $lastMsgId) {
        db()->prepare("
            INSERT INTO chat_last_read (user_id, thread_id, last_read)
            VALUES (:u, :t, :lr)
            ON CONFLICT (user_id, thread_id) DO UPDATE SET last_read = EXCLUDED.last_read
        ")->execute([':u' => $userId, ':t' => $threadId, ':lr' => (string)$lastMsgId]);
        $lrCount++;
    }
}
$counts['chat_last_read'] = $lrCount;
migrateLog("  → {$lrCount} last-read markers migrated.");

// ── Summary ──────────────────────────────────────────────────────────────────
migrateLog("");
migrateLog("=== MIGRATION COMPLETE ===");
foreach ($counts as $table => $n) migrateLog("  {$table}: {$n} records");
migrateLog("");
migrateLog("Next steps:");
migrateLog("  1. Ensure db.php has the real DB password (DB_PASS).");
migrateLog("  2. Remove/rename ./local-data on the server so api.php uses PostgreSQL.");
migrateLog("  3. Verify the app (login, leads, settings) before deleting data/.");
