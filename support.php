<?php
/**
 * Feedback & Support — a feedback/support channel between this Macktiles
 * deployment and the central Levata system. Included by api.php. Stores
 * everything in the 'tickets' KV bucket via kvGet/kvSet (data/kv_store.json
 * locally, Postgres kv_store table in production).
 *
 * Currently gated to super admins only (requireSuperAdmin()) while this is
 * being tested; intended to open up to all Macktiles staff/reps later — no
 * rework needed beyond loosening that gate + the frontend's nav visibility.
 *
 * This deployment acts as the SPOKE: when a ticket is filed here, it's saved
 * locally AND forwarded to the Levata hub (admin.json 'ticket_hub_url' +
 * 'ticket_hub_secret'). When the hub's staff reply, the hub pushes the reply
 * back to this deployment's 'ingest-reply' endpoint, and it appears in the
 * thread here. Replies posted here are also pushed onward to the hub.
 *
 * Ticket number format: TKT-0001 (sequential).
 */

/** Read the shared ticket store. Shape: ['tickets' => [...], 'seq' => ['ticket'=>n]]. */
function getTicketsStore() {
    $store = kvGet('support', 'tickets', null);
    if ($store === null) return ['tickets' => [], 'seq' => ['ticket' => 0]];
    if (!isset($store['tickets']) || !is_array($store['tickets'])) $store['tickets'] = [];
    if (!isset($store['seq']) || !is_array($store['seq'])) $store['seq'] = ['ticket' => 0];
    $store['seq']['ticket'] = (int) ($store['seq']['ticket'] ?? 0);
    return $store;
}

function saveTicketsStore($store) {
    kvSet('support', 'tickets', $store);
}

function nextTicketNo(&$store) {
    $store['seq']['ticket']++;
    return sprintf('TKT-%04d', $store['seq']['ticket']);
}

$VALID_TICKET_TYPE     = ['feedback', 'support'];
$SUPPORT_CATEGORIES    = ['bug', 'how_to', 'account', 'performance', 'other'];
$FEEDBACK_CATEGORIES   = ['feature', 'improvement', 'complaint', 'praise', 'other'];
$VALID_TICKET_CATEGORY = array_values(array_unique(array_merge($SUPPORT_CATEGORIES, $FEEDBACK_CATEGORIES)));
$VALID_TICKET_PRIORITY = ['low', 'normal', 'high'];
$VALID_TICKET_STATUS   = ['open', 'in_progress', 'resolved', 'closed'];

/** Build/validate a ticket's user-editable fields. Used by create and edit. */
function applyTicketFields($ticket, $input) {
    global $VALID_TICKET_TYPE, $VALID_TICKET_CATEGORY, $VALID_TICKET_PRIORITY;
    $type = $input['type'] ?? ($ticket['type'] ?? 'support');
    $ticket['type'] = in_array($type, $VALID_TICKET_TYPE, true) ? $type : 'support';
    $cat = $input['category'] ?? ($ticket['category'] ?? 'other');
    $ticket['category'] = in_array($cat, $VALID_TICKET_CATEGORY, true) ? $cat : 'other';
    $pri = $input['priority'] ?? ($ticket['priority'] ?? 'normal');
    $ticket['priority'] = in_array($pri, $VALID_TICKET_PRIORITY, true) ? $pri : 'normal';
    $ticket['subject'] = trim($input['subject'] ?? ($ticket['subject'] ?? ''));
    $ticket['message'] = trim($input['message'] ?? ($ticket['message'] ?? ''));
    return $ticket;
}

/** Summary across tickets, for the stat cards. */
function ticketsSummary($tickets) {
    $open = 0; $inProgress = 0; $resolved = 0; $closed = 0;
    foreach ($tickets as $t) {
        $s = $t['status'] ?? 'open';
        if ($s === 'open') $open++;
        elseif ($s === 'in_progress') $inProgress++;
        elseif ($s === 'resolved') $resolved++;
        elseif ($s === 'closed') $closed++;
    }
    return [
        'open' => $open,
        'in_progress' => $inProgress,
        'resolved' => $resolved,
        'closed' => $closed,
        'total' => count($tickets),
    ];
}

/* =====================================================================
 * Ticket sync (this deployment is always the SPOKE; the Levata hub is
 * the central system). Configured via admin.json:
 *
 *  - ticket_hub_url    : the hub's ingest endpoint (…?action=ingest-ticket)
 *  - ticket_hub_secret  : shared secret, must match the hub's ticket_ingest_secret
 *  - ticket_client_name : label shown at the hub for tickets from this deployment
 *
 * All cross-system calls are best-effort: they never block or fail the
 * developer's action.
 * ===================================================================== */

function ticketClientName() {
    $admin = getAdmin();
    return trim($admin['ticket_client_name'] ?? '') ?: 'Macktiles';
}

/** This deployment's own base URL to api.php, so the hub knows where to send replies. */
function selfApiUrl() {
    $admin = getAdmin();
    $configured = trim($admin['self_api_url'] ?? '');
    if ($configured !== '') return $configured;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $path = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    return $scheme . '://' . $host . $path;
}

/**
 * Forward a freshly-created ticket to the Levata hub. Best-effort; never throws.
 * Returns the hub's own ticket id on success (so replies can be routed back to
 * the right hub ticket), or null if not configured / unreachable.
 */
function forwardTicketToHub($ticket) {
    $admin = getAdmin();
    $hubUrl = trim($admin['ticket_hub_url'] ?? '');
    $secret = trim($admin['ticket_hub_secret'] ?? '');
    if ($hubUrl === '' || $secret === '') return null;

    $payload = [
        'secret' => $secret,
        'client' => ticketClientName(),
        'reply_url' => selfApiUrl() . '?action=ingest-reply',
        'ticket' => [
            'remote_id' => $ticket['id'] ?? '',
            'remote_ticket_no' => $ticket['ticket_no'] ?? '',
            'type' => $ticket['type'] ?? 'support',
            'category' => $ticket['category'] ?? 'other',
            'priority' => $ticket['priority'] ?? 'normal',
            'status' => $ticket['status'] ?? 'open',
            'subject' => $ticket['subject'] ?? '',
            'message' => $ticket['message'] ?? '',
            'created_by_name' => $ticket['created_by_name'] ?? '',
            'created_by_email' => $ticket['created_by_email'] ?? '',
            'created_at' => $ticket['created_at'] ?? date('c'),
        ],
    ];

    $ch = curl_init($hubUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) return null;
    $decoded = json_decode((string) $response, true);
    return trim($decoded['id'] ?? '') ?: null;
}

/**
 * Push a client (Macktiles-side) reply UP to the hub, so the hub sees the
 * conversation too. The hub finds its own copy of the ticket by matching this
 * spoke's ticket id against the 'remote_id' it stored when the ticket was
 * first forwarded (forwardTicketToHub()) — no extra id bookkeeping needed here.
 *
 * Posts to the hub's 'ingest-client-reply' endpoint (NOT 'ingest-reply', which
 * is the opposite direction — hub-to-spoke, handled by ingestReplyFromHub()
 * below). Derived from the configured ticket_hub_url the same way
 * forwardTicketToHub() already does, so there's nothing extra to configure.
 */
function sendReplyToHub($ticket, $reply) {
    $admin = getAdmin();
    $hubUrl = trim($admin['ticket_hub_url'] ?? '');
    $secret = trim($admin['ticket_hub_secret'] ?? '');
    if ($hubUrl === '' || $secret === '') return false;
    $localId = trim($ticket['id'] ?? '');
    if ($localId === '') return false;

    $replyUrl = str_replace('action=ingest-ticket', 'action=ingest-client-reply', $hubUrl);
    $payload = [
        'secret' => $secret,
        'remote_id' => $localId,
        'reply' => [
            // Lets the hub recognise a redelivery (timeout retry) and skip it
            // instead of appending the same message twice.
            'source_reply_id' => $reply['id'] ?? '',
            'author_name' => $reply['author_name'] ?? 'Macktiles',
            'is_staff' => false,
            'message' => $reply['message'] ?? '',
            'created_at' => $reply['created_at'] ?? date('c'),
        ],
    ];
    $ch = curl_init($replyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Receive a staff reply pushed back from the Levata hub and append it to the
 * local ticket's thread. Public endpoint, authenticated by the shared secret
 * (admin.json 'ticket_hub_secret', must match the hub's outbound secret).
 */
function ingestReplyFromHub($localTicketId, $reply) {
    if (!is_array($reply)) return false;
    $message = trim($reply['message'] ?? '');
    if ($localTicketId === '' || $message === '') return false;

    $sourceId = trim($reply['source_reply_id'] ?? '');
    $createdAt = trim($reply['created_at'] ?? '');
    $authorName = trim($reply['author_name'] ?? '') ?: 'Levata Support';

    $store = getTicketsStore();
    $found = false;
    $ticketCopy = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $localTicketId) {
            if (!isset($ticket['replies']) || !is_array($ticket['replies'])) $ticket['replies'] = [];

            // The hub retries on timeout, so the SAME reply can arrive more than
            // once. Match on the hub's reply id where available, else on
            // content+timestamp+author for older payloads. Return true (not
            // false) so the hub doesn't treat this as a failure and retry forever.
            foreach ($ticket['replies'] as $existing) {
                $sameSource = $sourceId !== '' && ($existing['source_reply_id'] ?? '') === $sourceId;
                $sameContent = $sourceId === ''
                    && ($existing['message'] ?? '') === $message
                    && ($existing['created_at'] ?? '') === $createdAt
                    && ($existing['author_name'] ?? '') === $authorName;
                if ($sameSource || $sameContent) return true;
            }

            $ticket['replies'][] = [
                'id' => 'rep_' . bin2hex(random_bytes(6)),
                'source_reply_id' => $sourceId,
                'author_id' => '',
                'author_name' => $authorName,
                'is_staff' => true,
                'message' => $message,
                'created_at' => $createdAt ?: date('c'),
            ];
            $ticket['status'] = 'in_progress';
            $ticket['updated_at'] = date('c');
            $found = true;
            $ticketCopy = $ticket;
            break;
        }
    }
    unset($ticket);
    if (!$found) return false;
    saveTicketsStore($store);
    // Notify the ticket's creator (the super admin who filed it) via the bell.
    $creatorId = trim($ticketCopy['created_by'] ?? '');
    if ($creatorId !== '') {
        pushChatNotification($creatorId, [
            'id' => 'notif_' . bin2hex(random_bytes(6)),
            'type' => 'ticket_reply',
            'title' => 'Levata replied: ' . ($ticketCopy['subject'] ?? '(no subject)'),
            'body' => mb_strimwidth($message, 0, 140, '…'),
            'ticket_id' => $localTicketId,
            'notif_key' => 'ticket_reply_' . $localTicketId . '_' . substr(md5($message), 0, 8),
            'read' => false,
            'created_at' => date('c'),
        ]);
    }
    return true;
}
