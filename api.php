<?php
/**
 * Macktiles Sales Intelligence API
 * B2B sales outreach platform for Macktiles Australia
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// All storage and internal timestamps stay in UTC — reps aren't all in one
// place, so the server can't assume a single "local" timezone. Every
// "today"/date-range computation (Reports, daily targets/streaks, Session
// Activity) instead uses viewerToday()/viewerDateRange() below, which shift
// by the offset the browser sends on every request (see X-Timezone-Offset).
date_default_timezone_set('UTC');

header('Content-Type: application/json');

// CORS: restrict to known origins
$allowedOrigins = ['https://sales.macktiles.com.au', 'http://sales.macktiles.com.au', 'https://macktiles.levataos.com', 'http://macktiles.levataos.com', 'https://macktiles.levatahq.com', 'http://macktiles.levatahq.com', 'http://localhost:8000', 'http://localhost:8080'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://sales.macktiles.com.au');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Token, X-Timezone-Offset');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

// ── Viewer timezone ──────────────────────────────────────────────────────────
// JS's Date.getTimezoneOffset() returns minutes WEST of UTC (positive for
// zones behind UTC, negative for zones ahead — e.g. Sydney in AEST/+10 sends
// -600). Clamped to a sane +/-14h range so a missing/garbled header can't
// send date math off into the weeds; falls back to 0 (UTC) if absent.
function viewerOffsetMinutes(): int {
    $raw = $_SERVER['HTTP_X_TIMEZONE_OFFSET'] ?? '';
    if ($raw === '' || !preg_match('/^-?\d+$/', $raw)) return 0;
    return max(-840, min(840, (int)$raw));
}

// "Today" as a Y-m-d string in the viewer's own timezone, not the server's.
function viewerToday(): string {
    return (new DateTime('now', new DateTimeZone('UTC')))
        ->modify('-' . viewerOffsetMinutes() . ' minutes')
        ->format('Y-m-d');
}

// Same shift, but returns the full DateTime (viewer-local wall-clock, still
// UTC-backed) — useful when callers need more than just the date string,
// e.g. streak day-stepping or "is this still today" instant comparisons.
function viewerNow(): DateTime {
    return (new DateTime('now', new DateTimeZone('UTC')))->modify('-' . viewerOffsetMinutes() . ' minutes');
}

// Converts a stored UTC ISO timestamp into the viewer's local Y-m-d, for
// bucketing a specific record (a call, a ping, an email) into "today" from
// the viewer's point of view — not the server's, and not whatever offset
// the value happened to be written with.
function viewerDateOf(?string $utcIso): string {
    if (!$utcIso) return '';
    try {
        $dt = new DateTime($utcIso);
        $dt->setTimezone(new DateTimeZone('UTC'));
        $dt->modify('-' . viewerOffsetMinutes() . ' minutes');
        return $dt->format('Y-m-d');
    } catch (Throwable $e) {
        return substr($utcIso, 0, 10);
    }
}

// ── Storage layer ─────────────────────────────────────────────────────────────
// Auto-select: if a ./local-data directory exists, use the JSON flat-file layer
// (db.local.php) for local testing; otherwise use PostgreSQL (db.php).
// Both expose identical helper signatures (dbLoadAll, kvGet, dbLoadLeadsByOwner…).
define('LOCAL_MODE', is_dir(__DIR__ . '/local-data'));
require_once __DIR__ . (LOCAL_MODE ? '/db.local.php' : '/db.php');
require_once __DIR__ . '/support.php';
// ──────────────────────────────────────────────────────────────────────────────

define('DATA_DIR', __DIR__ . '/data');
define('USERS_FILE', DATA_DIR . '/users.json');
define('ADMIN_FILE', DATA_DIR . '/admin.json');

// Feature-disabled: flip this to true to re-enable click-to-dial, the
// webhook receiver, live "on a call" status, and the admin test-connection
// endpoint. Every Aircall case checks this first. No other code needs to change.
define('AIRCALL_ENABLED', true);

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// Rate limiting
function checkRateLimit($identifier, $maxRequests = 120, $windowSeconds = 60) {
    $rateFile = DATA_DIR . '/.rate_limits.json';
    $limits = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
    $now = time();
    $windowStart = $now - $windowSeconds;

    // Clean old entries for this identifier
    $limits[$identifier] = array_values(array_filter($limits[$identifier] ?? [], function($ts) use ($windowStart) {
        return $ts > $windowStart;
    }));

    if (count($limits[$identifier]) >= $maxRequests) {
        header('HTTP/1.1 429 Too Many Requests');
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please try again later.']);
        exit;
    }

    $limits[$identifier][] = $now;

    // Clean stale identifiers older than 2 minutes
    foreach ($limits as $key => $timestamps) {
        $limits[$key] = array_values(array_filter($timestamps, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        }));
        if (empty($limits[$key])) unset($limits[$key]);
    }

    file_put_contents($rateFile, json_encode($limits));
}

$rateLimitKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . ($_SERVER['HTTP_X_USER_TOKEN'] ?? 'anon');
checkRateLimit($rateLimitKey);

// Input sanitization utility
function sanitizeInput($value) {
    if (is_array($value)) return array_map('sanitizeInput', $value);
    if (!is_string($value)) return $value;
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

$defaultRequisitions = [
    ['id' => 'business_type', 'title' => 'Business Type', 'subtitle' => 'What kind of customer or channel are they?', 'type' => 'single', 'options' => ['Builder', 'Architect / Designer', 'Tiler', 'Handyman / Tradie', 'Property Developer', 'Retailer / Distributor', 'Other'], 'enabled' => true],
    ['id' => 'project_context', 'title' => 'Project Context', 'subtitle' => 'What type of work are they discussing?', 'type' => 'single', 'options' => ['Residential', 'Commercial', 'Multi-residential', 'Hospitality', 'Renovation', 'Retail / Showroom', 'Other'], 'enabled' => true],
    ['id' => 'current_tile_sourcing', 'title' => 'Current Tile Sourcing', 'subtitle' => 'Where do they currently source tiles?', 'type' => 'single', 'options' => ['Local Supplier', 'Importer', 'Retailer', 'Direct from Manufacturer', 'Multiple Suppliers', 'Unclear'], 'enabled' => true],
    ['id' => 'primary_need', 'title' => 'Primary Need', 'subtitle' => 'What is the strongest Macktiles angle?', 'type' => 'single', 'options' => ['Range', 'Price / Margin', 'Quality Consistency', 'Delivery Speed', 'Design Support', 'Sample / Showroom Support', 'Visualiser / Demo'], 'enabled' => true],
    ['id' => 'timeline', 'title' => 'Timeline', 'subtitle' => 'When could this move?', 'type' => 'single', 'options' => ['Immediate', '1-3 Months', '3-6 Months', 'Future / Nurture', 'Unknown'], 'enabled' => true],
    ['id' => 'decision_role', 'title' => 'Decision Role', 'subtitle' => 'What role do they play in supplier choice?', 'type' => 'single', 'options' => ['Owner / Founder', 'Director', 'Designer / Specifier', 'Procurement', 'Site / Project Manager', 'Influencer', 'Not Decision-maker'], 'enabled' => true],
    ['id' => 'project_size_volume', 'title' => 'Project Size / Volume', 'subtitle' => 'How meaningful is the opportunity?', 'type' => 'single', 'options' => ['One-off', 'Small Recurring', 'Medium Recurring', 'High Volume', 'Unknown'], 'enabled' => true],
    ['id' => 'next_step_agreed', 'title' => 'Next Step Agreed', 'subtitle' => 'What did they agree to after the conversation?', 'type' => 'single', 'options' => ['Send Range', 'Book Showroom / Consultation', 'Send Samples', 'Call Back', 'Nurture', 'Not Interested'], 'enabled' => true],
    ['id' => 'notes_objections', 'title' => 'Notes / Objections', 'subtitle' => 'Capture objections, buying signals, or specific project details.', 'type' => 'text', 'options' => [], 'enabled' => true]
];

// Default ICP configuration
$defaultIcpConfig = [
    'enabled' => true,
    // Weights total 100 across the factors calculateLeadGrade() can actually
    // score from real requisitions data (business_type, decision_role,
    // project_context, project_size_volume, timeline) — location,
    // active_project_evidence, and company_stability were removed since no
    // requisitions field ever captured them, so they always scored 0 anyway.
    'scoring_weights' => [
        'segment_match' => 30,
        'decision_authority' => 20,
        'project_type' => 20,
        'project_size' => 15,
        'timeline_urgency' => 15
    ],
    'ideal_segments' => ['Builder', 'Architect / Designer', 'Handyman / Tradie', 'Tiler', 'Property Developer'],
    'later_segments' => ['Retailer / Distributor'],
    'target_geographies' => ['Melbourne Metro', 'Victoria Regional'],
    'brand_positioning' => [
        'company_description' => 'Australian architectural tile brand with a curated range for modern Australian homes',
        'proof_points' => ['Melbourne showroom', '14 contemporary designs', 'Macktiles Visualiser tool', 'Italian robotics and digital printing technology', 'Batik Designer Collection pre-order range']
    ]
];

// Default stage validation rules
$defaultStageRules = [
    'stage_1_min_score' => 60,
    'stage_2_min_score' => 60,
    'stage_3_min_score' => 60,
    'grade_a_threshold' => 80,
    'grade_b_threshold' => 60,
    'grade_c_threshold' => 40
];

// Default outreach rules
$defaultOutreachRules = [
    'grade_a_channels' => ['email', 'linkedin', 'call'],
    'grade_b_channels' => ['email'],
    'grade_c_action' => 'nurture',
    'max_sequence_steps' => 4,
    'email_cadence_days' => ['initial' => 1, 'followup1' => 4, 'followup2' => 9, 'breakup' => 15],
    'call_outcomes' => ['no_answer_retry', 'left_voicemail', 'gatekeeper', 'wrong_number', 'not_interested', 'interested_followup', 'consultation_booked', 'callback_requested', 'not_right_time_park_90']
];

if (kvGet('config', 'admin') === null) {
    kvSet('config', 'admin', [
        'groq_key' => '',
        'gemini_key' => '',
        'anthropic_key' => '',
        'cerebras_key' => '',
        'default_provider' => 'groq',
        'requisitions' => $defaultRequisitions,
        'icp_config' => $defaultIcpConfig,
        'stage_validation_rules' => $defaultStageRules,
        'outreach_rules' => $defaultOutreachRules
    ]);
}

if (empty(dbLoadAll('users'))) {
    $adminId = 'user_' . bin2hex(random_bytes(8));
    $defaultAdmin = [
        'id' => $adminId,
        'name' => 'Admin',
        'email' => 'admin@macktiles.com.au',
        'password' => password_hash('password', PASSWORD_DEFAULT),
        'token' => bin2hex(random_bytes(32)),
        'created_at' => date('c'),
        'is_admin' => true
    ];
    dbSaveAll('users', [$defaultAdmin]);
    kvSet('settings', $adminId, ['sender_name' => 'Admin', 'sender_company' => 'Macktiles Australia', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => '']);
}

// Internal/test accounts — hidden from user-facing lists (Users page, chat,
// sessions, notifications) but must still be able to authenticate normally.
function internalAccountEmails(): array {
    return ['admin@macktiles.com.au', 'amaan@levatahq.com'];
}
function getUsers(): array {
    $hidden = internalAccountEmails();
    return array_values(array_filter(dbLoadAll('users'), fn($u) => !in_array($u['email'] ?? '', $hidden, true)));
}
// Unfiltered lookup — use ONLY for authentication (login/token checks), never
// for anything that renders a user list to other users.
function getAllUsersIncludingInternal(): array {
    return dbLoadAll('users');
}
function saveUsers($users) { dbSaveAll('users', array_values($users)); }

function getMacktilesStages() {
    return [
        'new_lead' => ['label' => 'New Lead', 'legacy' => 'new'],
        'research' => ['label' => 'Research', 'legacy' => 'researched'],
        'email_sent' => ['label' => 'Email Sent', 'legacy' => 'email_sent'],
        'call_attempted' => ['label' => 'Call Attempted', 'legacy' => 'call_due'],
        'engaged' => ['label' => 'Engaged', 'legacy' => 'outcome_logged'],
        'consultation_booked' => ['label' => 'Consultation Booked', 'legacy' => 'qualified'],
        'nurture_parked' => ['label' => 'Nurture / Parked', 'legacy' => 'disqualified'],
        'won' => ['label' => 'Won', 'legacy' => 'won'],
        'lost' => ['label' => 'Lost', 'legacy' => 'lost']
    ];
}

function legacyStatusToStage($status) {
    $map = [
        'new' => 'new_lead',
        'researched' => 'research',
        'email_sent' => 'email_sent',
        'call_due' => 'call_attempted',
        'outcome_logged' => 'engaged',
        'qualified' => 'consultation_booked',
        'meeting_booked' => 'consultation_booked',
        'replied' => 'engaged',
        'contacted' => 'engaged',
        'not_interested' => 'nurture_parked',
        'disqualified' => 'nurture_parked',
        'won' => 'won',
        'lost' => 'lost'
    ];
    return $map[$status ?: 'new'] ?? (isset(getMacktilesStages()[$status]) ? $status : 'new_lead');
}

function stageToLegacyStatus($stage) {
    $stages = getMacktilesStages();
    return $stages[$stage]['legacy'] ?? 'new';
}

// Aircall (and most telephony APIs) require strict E.164: "+" + country
// code + subscriber number, no domestic trunk prefix. A lead's phone is
// often entered as "+61 0423 373 442" (country code AND the local "0"
// prefix both present) which Aircall rejects as BAD_REQUEST — this strips
// formatting characters and, for the country codes reps actually use here,
// drops that redundant leading 0 so the number is valid to dial.
function normalizePhoneE164($phone) {
    $phone = trim((string)$phone);
    if ($phone === '') return $phone;
    $digits = preg_replace('/[^\d+]/', '', $phone);
    if (strpos($digits, '+') !== 0) return $digits; // no country code — leave as-is, can't safely guess one
    $trunkZeroCountryCodes = ['61', '94', '64', '44', '91']; // AU, LK, NZ, UK, IN
    foreach ($trunkZeroCountryCodes as $cc) {
        if (strpos($digits, '+' . $cc . '0') === 0) {
            return '+' . $cc . substr($digits, strlen('+' . $cc . '0'));
        }
    }
    return $digits;
}

function normalizeLeadSource($source) {
    $source = strtolower(trim($source ?: 'firmable'));
    $map = [
        'csv' => 'firmable',
        'firmable database' => 'firmable',
        'booking' => 'inbound',
        'google_ads' => 'inbound',
        'meta_ads' => 'inbound',
        'offline' => 'manual',
        'trade_show' => 'manual',
        'showroom' => 'manual',
        'referral' => 'manual'
    ];
    $source = $map[$source] ?? $source;
    return in_array($source, ['firmable', 'inbound', 'manual', 'zoho', 'other']) ? $source : 'manual';
}

function initialStageForSource($source, $warm = false) {
    $source = normalizeLeadSource($source);
    if ($source === 'inbound') return 'call_attempted';
    if ($warm) return 'engaged';
    return 'new_lead';
}

function getLeadStage($lead) {
    return legacyStatusToStage($lead['stage'] ?? ($lead['status'] ?? 'new'));
}

function setLeadStage(&$lead, $stage, $reason = '', $actor = null) {
    $stage = legacyStatusToStage($stage);
    $oldStage = getLeadStage($lead);
    if (!isset($lead['stage_history']) || !is_array($lead['stage_history'])) $lead['stage_history'] = [];
    if ($oldStage !== $stage || empty($lead['stage_entered_at'])) {
        $lead['stage_history'][] = [
            'from' => $oldStage,
            'to' => $stage,
            'reason' => $reason,
            'actor' => $actor,
            'timestamp' => date('c')
        ];
        $lead['stage_entered_at'] = date('c');
    }
    $lead['stage'] = $stage;
    $lead['status'] = stageToLegacyStatus($stage);
    $lead['updated_at'] = date('c');
}

function normalizeLeadForMapping($lead) {
    $source = normalizeLeadSource($lead['source'] ?? $lead['import_source'] ?? $lead['lead_source'] ?? 'firmable');
    $stage = getLeadStage($lead);
    $lead['source'] = $source;
    $lead['source_detail'] = $lead['source_detail'] ?? ($lead['lead_source'] ?? '');
    $lead['assigned_to'] = $lead['assigned_to'] ?? null;
    $lead['stage'] = $stage;
    $lead['status'] = stageToLegacyStatus($stage);
    $lead['stage_entered_at'] = $lead['stage_entered_at'] ?? ($lead['last_action_at'] ?? $lead['created_at'] ?? date('c'));
    $lead['stage_history'] = $lead['stage_history'] ?? [];
    $lead['urgency_flag'] = $lead['urgency_flag'] ?? ($source === 'inbound' ? 'high' : 'normal');
    $lead['call_history'] = $lead['call_history'] ?? [];
    $lead['rejection_reason'] = $lead['rejection_reason'] ?? ($lead['disqualified_reason'] ?? '');
    $lead['consultation_type'] = $lead['consultation_type'] ?? '';
    return $lead;
}

function macktilesCallOutcomeConfig($outcome) {
    $config = [
        'no_answer_retry' => ['label' => 'No answer, will retry', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'left_voicemail' => ['label' => 'No answer, left voicemail', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'gatekeeper' => ['label' => 'Gatekeeper, could not reach decision-maker', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'wrong_number' => ['label' => 'Wrong number / person no longer at company', 'stage' => 'nurture_parked', 'next_action' => 'disqualify'],
        'not_interested' => ['label' => 'Not interested, asked not to be contacted', 'stage' => 'nurture_parked', 'next_action' => 'disqualify'],
        'interested_followup' => ['label' => 'Interested, follow-up scheduled', 'stage' => 'engaged', 'next_action' => 'followup_date'],
        'consultation_booked' => ['label' => 'Consultation booked', 'stage' => 'consultation_booked', 'next_action' => 'qualify'],
        'callback_requested' => ['label' => 'Call back requested, date/time noted', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'not_right_time_park_90' => ['label' => 'Spoke, not the right time; park for 90 days', 'stage' => 'nurture_parked', 'next_action' => 'park']
    ];
    return $config[$outcome] ?? null;
}
function getAdmin() {
    global $defaultRequisitions, $defaultIcpConfig, $defaultStageRules, $defaultOutreachRules;
    $admin = kvGet('config', 'admin') ?: [];
    if (!isset($admin['requisitions'])) $admin['requisitions'] = $defaultRequisitions;
    $reqIds = array_map(fn($r) => $r['id'] ?? '', $admin['requisitions'] ?? []);
    if (!in_array('project_context', $reqIds, true) || !in_array('next_step_agreed', $reqIds, true)) {
        if (!isset($admin['legacy_requisitions_backup'])) {
            $admin['legacy_requisitions_backup'] = $admin['requisitions'] ?? [];
        }
        $admin['requisitions'] = $defaultRequisitions;
        $admin['requisitions_version'] = 'sales_discovery_v1';
        saveAdmin($admin);
    }
    if (!isset($admin['icp_config'])) $admin['icp_config'] = $defaultIcpConfig;
    if (!isset($admin['stage_validation_rules'])) $admin['stage_validation_rules'] = $defaultStageRules;
    if (!isset($admin['outreach_rules'])) $admin['outreach_rules'] = $defaultOutreachRules;
    return $admin;
}
function saveAdmin($admin) { kvSet('config', 'admin', $admin); }

// A rep's own daily target (set on Today's Commitments) always wins if
// they've set one. Otherwise fall back to the admin-configured team default
// (Admin Settings > Team Daily Targets), and only fall back to the hardcoded
// 40/40/25/40 if neither has ever been set. Without this, a rep who never
// customized their own target kept seeing the old hardcoded default even
// after an admin changed the team-wide number — the admin default only fed
// the Command Center's team-aggregate view, not each rep's own pages.
function effectiveDailyTargets($userSettings) {
    if (!empty($userSettings['daily_targets'])) return $userSettings['daily_targets'];
    $admin = getAdmin();
    if (!empty($admin['daily_targets'])) return $admin['daily_targets'];
    return ['calls' => 40, 'emails' => 40, 'followups' => 25, 'research' => 25, 'weekly_imports' => 75, 'outcomes' => 40];
}

function defaultUserSettings() {
    return ['sender_name' => '', 'sender_company' => 'Macktiles Australia', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => ''];
}

function getUserData($userId) {
    $settings = kvGet('settings', $userId);
    $leads = dbLoadLeadsByOwner($userId);
    if ($settings === null) {
        // First access for this user: seed default settings.
        $settings = defaultUserSettings();
        kvSet('settings', $userId, $settings);
    }
    // Everything that isn't leads/settings (notifications, onboarding_completed,
    // etc.) is round-tripped through the usermeta kv bucket.
    $meta = kvGet('usermeta', $userId) ?: [];
    $data = array_merge($meta, ['leads' => $leads, 'settings' => $settings]);

    // Migrate existing leads with default values for new fields
    if (!empty($data['leads'])) {
        $changed = false;
        foreach ($data['leads'] as &$lead) {
            // Original fields
            $lead['fit_grade'] = $lead['fit_grade'] ?? '';
            $lead['fit_score'] = $lead['fit_score'] ?? 0;
            $lead['grade_override'] = $lead['grade_override'] ?? null;
            $lead['calls_made'] = $lead['calls_made'] ?? 0;
            $lead['email_history'] = $lead['email_history'] ?? [];
            $lead['emails_sent'] = $lead['emails_sent'] ?? 0;

            // Proactive Intelligence fields (Phase 1)
            $lead['engagement_score'] = $lead['engagement_score'] ?? 0;
            $lead['temperature'] = $lead['temperature'] ?? 'cold';
            $lead['velocity'] = $lead['velocity'] ?? 'stalled';
            $lead['last_score_update'] = $lead['last_score_update'] ?? null;
            $lead['activities'] = $lead['activities'] ?? [];
            $lead['skipped_until'] = $lead['skipped_until'] ?? null;

            // Notification tracking
            $lead['last_notification_at'] = $lead['last_notification_at'] ?? null;

            $before = json_encode($lead);
            $lead = normalizeLeadForMapping($lead);
            $changed = $changed || ($before !== json_encode($lead));
        }
        if ($changed) {
            saveUserData($userId, $data);
        }
    }
    return $data;
}

function saveUserData($userId, $data) {
    // Leads → flat leads table (by owner); settings → its own kv bucket;
    // everything else (notifications, onboarding_completed, …) → usermeta bucket.
    dbSaveLeadsForOwner($userId, array_values($data['leads'] ?? []));
    if (array_key_exists('settings', $data)) {
        kvSet('settings', $userId, $data['settings']);
    }
    $meta = $data;
    unset($meta['leads'], $meta['settings']);
    kvSet('usermeta', $userId, $meta);
}
function generateId($prefix = '') { return $prefix . bin2hex(random_bytes(8)); }
function generateToken() { return bin2hex(random_bytes(32)); }
function logActivity(&$lead, $type, $detail, $extra = []) {
    if (!isset($lead['activity_log'])) $lead['activity_log'] = [];
    $lead['activity_log'][] = array_merge([
        'type' => $type,
        'detail' => $detail,
        'timestamp' => date('c')
    ], $extra);
}

// Default stage fields for new leads (used by CSV import)
function getDefaultStageFields() {
    return [
        'fit_grade' => '',
        'fit_score' => 0,
        'grade_override' => null,
        'calls_made' => 0,
        'email_history' => [],
        'emails_sent' => 0,
        // Proactive Intelligence fields
        'engagement_score' => 0,
        'temperature' => 'cold',
        'velocity' => 'stalled',
        'last_score_update' => null,
        'activities' => [],
        'skipped_until' => null,
        'last_notification_at' => null
    ];
}

// Calculate ICP grade based on lead data and admin config
function calculateLeadGrade($lead, $admin, $enrichmentData = null) {
    $score = 0;
    $factors = [];
    $disqualifiers = [];
    $icpConfig = $admin['icp_config'] ?? [];
    $weights = $icpConfig['scoring_weights'] ?? [];
    $req = $lead['requisitions'] ?? [];
    if (is_string($req)) $req = json_decode($req, true) ?: [];

    // Parse enrichment if provided as string
    if ($enrichmentData === null && !empty($lead['enrichment'])) {
        $enrichmentData = json_decode($lead['enrichment'], true);
    }

    // Field keys and option values below match the real requisitions IDs
    // ($defaultRequisitions) and their actual dropdown options exactly — a
    // prior version read different key names (decision_authority,
    // project_type, project_size, timeline_urgency, location,
    // active_project_evidence, company_stability) that don't exist anywhere
    // in the UI, so those factors never scored anything a rep actually
    // entered. location/active_project_evidence/company_stability have no
    // requisitions equivalent at all and are dropped; their weight is folded
    // into the remaining factors so the 0-100 scale still means something.
    $segment = $req['business_type'] ?? $lead['industry'] ?? '';
    $idealSegments = $icpConfig['ideal_segments'] ?? ['Builder', 'Architect / Designer', 'Handyman / Tradie', 'Tiler', 'Property Developer'];
    $laterSegments = $icpConfig['later_segments'] ?? ['Retailer / Distributor'];
    if (in_array($segment, $idealSegments, true) || stripos($segment, 'Builder') !== false) {
        $score += $weights['segment_match'] ?? 30;
        $factors[] = 'Primary Macktiles segment';
    } elseif (in_array($segment, $laterSegments, true)) {
        $score += round(($weights['segment_match'] ?? 30) * 0.4);
        $factors[] = 'Later-priority segment';
    }

    $decisionRole = $req['decision_role'] ?? '';
    $authority = strtolower($decisionRole ?: ($lead['title'] ?? ''));
    if (in_array($decisionRole, ['Owner / Founder', 'Director'], true) || preg_match('/owner|director|sole|founder|managing/', $authority)) {
        $score += $weights['decision_authority'] ?? 20;
        $factors[] = 'Decision-maker signal';
    } elseif (in_array($decisionRole, ['Designer / Specifier', 'Procurement', 'Site / Project Manager', 'Influencer'], true) || preg_match('/purchasing|procurement|manager|specifier|architect|designer|influencer/', $authority)) {
        $score += round(($weights['decision_authority'] ?? 20) * 0.7);
        $factors[] = 'Influencer or purchasing signal';
    } elseif ($decisionRole === '' || strpos($authority, 'unknown') !== false) {
        $score += round(($weights['decision_authority'] ?? 20) * 0.35);
        $factors[] = 'Authority unknown';
    }
    // "Not Decision-maker" — no score, no factor.

    $projectContext = $req['project_context'] ?? '';
    if (in_array($projectContext, ['Renovation', 'Commercial', 'Multi-residential'], true)) {
        $score += $weights['project_type'] ?? 20;
        $factors[] = 'Qualified project type';
    } elseif ($projectContext !== '' && $projectContext !== 'Other') {
        $score += round(($weights['project_type'] ?? 20) * 0.5);
        $factors[] = 'Standard project type';
    }

    $projectSize = $req['project_size_volume'] ?? '';
    if (in_array($projectSize, ['Medium Recurring', 'High Volume'], true)) {
        $score += $weights['project_size'] ?? 15;
        $factors[] = 'Serviceable project size';
    } elseif ($projectSize === 'Small Recurring') {
        $score += round(($weights['project_size'] ?? 15) * 0.5);
        $factors[] = 'Smaller recurring project size';
    }

    $timeline = $req['timeline'] ?? '';
    if ($timeline === 'Immediate') {
        $score += round(($weights['timeline_urgency'] ?? 15) * 0.6);
        $factors[] = 'Urgent timeline needs stock check';
    } elseif (in_array($timeline, ['1-3 Months', '3-6 Months'], true)) {
        $score += $weights['timeline_urgency'] ?? 15;
        $factors[] = 'Workable project timeline';
    }

    if ($timeline === 'Immediate' && $projectSize === 'High Volume') {
        $disqualifiers[] = 'Immediate larger supply need may exceed current stock capacity';
    }
    if ($segment === 'Other') {
        $disqualifiers[] = 'Wrong or unclear segment';
    }
    if ($decisionRole === 'Not Decision-maker') {
        $disqualifiers[] = 'Contact is not the decision-maker';
    }

    // Determine grade based on thresholds
    $stageRules = $admin['stage_validation_rules'] ?? [];
    $gradeAThreshold = $stageRules['grade_a_threshold'] ?? 80;
    $gradeBThreshold = $stageRules['grade_b_threshold'] ?? 60;
    $gradeCThreshold = $stageRules['grade_c_threshold'] ?? 40;

    // Distinguish "actively disqualified" (a real red flag was found) from
    // "not enough data yet to grade" (no requisitions/research at all) — the
    // latter must never look like a rejection, or leads silently disappear
    // from the Focus Queue and pipeline views that filter out Disqualified.
    $hasQualificationData = !empty(array_filter($req)) || !empty($enrichmentData);

    if ($disqualifiers && $score < $gradeBThreshold) {
        $grade = 'Disqualified';
    } elseif ($score >= $gradeAThreshold) {
        $grade = 'A';
    } elseif ($score >= $gradeBThreshold) {
        $grade = 'B';
    } elseif ($score >= $gradeCThreshold) {
        $grade = 'C';
    } elseif (!$hasQualificationData) {
        $grade = 'Unscored';
    } else {
        $grade = 'Disqualified';
    }

    return ['grade' => $grade, 'score' => max(0, min(100, $score)), 'factors' => $factors, 'disqualifiers' => $disqualifiers];
}

// ============ PROACTIVE INTELLIGENCE SCORING ============

// Calculate engagement score based on lead activities and status
function calculateEngagementScore($lead) {
    $score = 0;
    $breakdown = [];

    // Points for research completed
    if (!empty($lead['enrichment'])) {
        $score += 10;
        $breakdown['research'] = 10;
    }

    // Points for emails sent
    $emailsSent = intval($lead['emails_sent'] ?? 0);
    if ($emailsSent > 0) {
        $emailPoints = min(20, $emailsSent * 10); // Max 20 points for emails
        $score += $emailPoints;
        $breakdown['emails'] = $emailPoints;
    }

    // Points for calls made
    $callsMade = intval($lead['calls_made'] ?? 0);
    if ($callsMade > 0) {
        $callPoints = min(25, $callsMade * 15); // Max 25 points for calls
        $score += $callPoints;
        $breakdown['calls'] = $callPoints;
    }

    // Points for positive call outcomes
    $positiveOutcomes = ['interested_followup', 'callback_requested', 'consultation_booked', 'meeting_booked', 'qualified'];
    if (in_array($lead['call_outcome'] ?? '', $positiveOutcomes)) {
        $score += 25;
        $breakdown['positive_outcome'] = 25;
    }

    // Points for qualified status
        if (in_array(getLeadStage($lead), ['consultation_booked', 'won'])) {
            $score += 30;
            $breakdown['qualified'] = 30;
        }

    // Decay for inactivity
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);
    if ($daysSinceActivity > 7) {
        $weeksInactive = floor($daysSinceActivity / 7);
        $decay = min(30, $weeksInactive * 5); // Max -30 decay
        $score -= $decay;
        $breakdown['decay'] = -$decay;
    }

    return [
        'score' => max(0, min(100, $score)),
        'breakdown' => $breakdown
    ];
}

// Calculate days since last activity on a lead
function calculateDaysSinceLastActivity($lead) {
    $lastActivityDate = null;

    // Check last_action_at
    if (!empty($lead['last_action_at'])) {
        $lastActivityDate = strtotime($lead['last_action_at']);
    }

    // Check activities array for most recent
    if (!empty($lead['activities']) && is_array($lead['activities'])) {
        foreach ($lead['activities'] as $activity) {
            $actTime = strtotime($activity['timestamp'] ?? '');
            if ($actTime && (!$lastActivityDate || $actTime > $lastActivityDate)) {
                $lastActivityDate = $actTime;
            }
        }
    }

    // Check email history
    if (!empty($lead['email_history']) && is_array($lead['email_history'])) {
        foreach ($lead['email_history'] as $email) {
            $emailTime = strtotime($email['sent_at'] ?? '');
            if ($emailTime && (!$lastActivityDate || $emailTime > $lastActivityDate)) {
                $lastActivityDate = $emailTime;
            }
        }
    }

    // Check followup_date if it's in the past (means we should have done something)
    if (!empty($lead['followup_date'])) {
        $followupTime = strtotime($lead['followup_date']);
        if ($followupTime && $followupTime < time() && (!$lastActivityDate || $followupTime > $lastActivityDate)) {
            $lastActivityDate = $followupTime;
        }
    }

    if (!$lastActivityDate) {
        // If no activity found, use created_at or default to 30 days
        $lastActivityDate = strtotime($lead['created_at'] ?? '-30 days');
    }

    return max(0, floor((time() - $lastActivityDate) / 86400));
}

// Calculate SLA status for a lead based on current stage
function calculateSLAStatus($lead) {
    $slaRules = [
        'new_lead' => ['max_days' => 1, 'next_action' => 'Verify contact details and begin research', 'action_type' => 'research'],
        'research' => ['max_days' => 1, 'next_action' => 'Complete AI research and send initial email', 'action_type' => 'email'],
        'email_sent' => ['max_days' => 5, 'next_action' => 'Make follow-up call', 'action_type' => 'call'],
        'call_attempted' => ['max_days' => 3, 'next_action' => 'Log outcome or complete scheduled follow-up', 'action_type' => 'outcome'],
        'engaged' => ['max_days' => 7, 'next_action' => 'Book consultation or schedule next conversation', 'action_type' => 'consultation'],
        'consultation_booked' => ['max_days' => 14, 'next_action' => 'Complete consultation and mark won/lost/nurture', 'action_type' => 'consultation'],
        'nurture_parked' => ['max_days' => 90, 'next_action' => 'Light-touch re-engagement when cooling-off period ends', 'action_type' => 'nurture']
    ];

    $status = getLeadStage($lead);
    $daysSinceAction = calculateDaysSinceLastActivity($lead);

    if (($lead['source'] ?? '') === 'inbound' && !empty($lead['created_at']) && $status === 'call_attempted') {
        $hoursSinceCreated = floor((time() - strtotime($lead['created_at'])) / 3600);
        return [
            'stage' => $status,
            'days_in_stage' => $daysSinceAction,
            'sla_days' => 0,
            'is_overdue' => $hoursSinceCreated > 2,
            'urgency' => $hoursSinceCreated > 2 ? 'critical' : 'high',
            'next_action' => 'Call inbound booking lead within 2 hours',
            'action_type' => 'call',
            'hours_remaining' => max(0, 2 - $hoursSinceCreated)
        ];
    }

    if (in_array($status, ['won', 'lost'])) {
        return [
            'stage' => $status,
            'days_in_stage' => $daysSinceAction,
            'sla_days' => null,
            'is_overdue' => false,
            'urgency' => 'complete',
            'next_action' => null,
            'action_type' => null
        ];
    }

    $rule = $slaRules[$status] ?? $slaRules['new_lead'];
    $maxDays = $rule['max_days'];
    $isOverdue = $daysSinceAction > $maxDays;

    // Determine urgency
    $urgency = 'on_track';
    if ($isOverdue) {
        $urgency = $daysSinceAction > ($maxDays * 2) ? 'critical' : 'overdue';
    } elseif ($daysSinceAction >= $maxDays - 1) {
        $urgency = 'due_soon';
    }

    return [
        'stage' => $status,
        'days_in_stage' => $daysSinceAction,
        'sla_days' => $maxDays,
        'is_overdue' => $isOverdue,
        'urgency' => $urgency,
        'next_action' => $rule['next_action'],
        'action_type' => $rule['action_type'],
        'days_remaining' => max(0, $maxDays - $daysSinceAction)
    ];
}

// Calculate temperature based on fit + engagement + recency
function calculateTemperature($fitScore, $engagementScore, $daysSinceActivity, $lead = null) {
    $combined = ($fitScore * 0.4) + ($engagementScore * 0.6);

    // Apply time decay: reduce combined score based on inactivity
    if ($daysSinceActivity > 7) {
        $decayFactor = max(0.3, 1 - (($daysSinceActivity - 7) * 0.05));
        $combined *= $decayFactor;
    }

    // Boost/penalize based on email outcomes if available
    if ($lead && !empty($lead['email_history'])) {
        $lastEmail = end($lead['email_history']);
        $outcome = $lastEmail['outcome'] ?? '';
        if ($outcome === 'replied' || $outcome === 'meeting_booked') {
            $combined = min(100, $combined + 20);
        } elseif ($outcome === 'bounced') {
            $combined = max(0, $combined - 30);
        }
        // Count consecutive no-responses
        $noResponseStreak = 0;
        foreach (array_reverse($lead['email_history']) as $eh) {
            if (($eh['outcome'] ?? '') === 'no_response') $noResponseStreak++;
            else break;
        }
        if ($noResponseStreak >= 3) {
            $combined = max(0, $combined - 20);
        }
    }

    if ($combined >= 80 && $daysSinceActivity <= 3) {
        return 'on_fire';
    } elseif ($combined >= 60 && $daysSinceActivity <= 7) {
        return 'hot';
    } elseif ($combined >= 40 || $daysSinceActivity <= 14) {
        return 'warm';
    } else {
        return 'cold';
    }
}

// Calculate velocity (activity trend)
function calculateVelocity($lead) {
    $activities = $lead['activities'] ?? [];
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);

    // If no activity in 14+ days, it's stalled
    if ($daysSinceActivity >= 14) {
        return 'stalled';
    }

    // Count activities in last 7 days vs previous 7 days
    $now = time();
    $sevenDaysAgo = $now - (7 * 86400);
    $fourteenDaysAgo = $now - (14 * 86400);

    $recentCount = 0;
    $previousCount = 0;

    // Count from activities array
    foreach ($activities as $activity) {
        $actTime = strtotime($activity['timestamp'] ?? '');
        if ($actTime >= $sevenDaysAgo) {
            $recentCount++;
        } elseif ($actTime >= $fourteenDaysAgo) {
            $previousCount++;
        }
    }

    // Also count emails as activities
    $emailHistory = $lead['email_history'] ?? [];
    foreach ($emailHistory as $email) {
        $emailTime = strtotime($email['sent_at'] ?? '');
        if ($emailTime >= $sevenDaysAgo) {
            $recentCount++;
        } elseif ($emailTime >= $fourteenDaysAgo) {
            $previousCount++;
        }
    }

    // If no previous activity to compare, check if there's recent activity
    if ($previousCount == 0) {
        return $recentCount > 0 ? 'accelerating' : 'stalled';
    }

    // Calculate change percentage
    $changeRatio = $recentCount / max(1, $previousCount);

    if ($changeRatio >= 1.5) {
        return 'accelerating';
    } elseif ($changeRatio >= 0.75) {
        return 'stable';
    } elseif ($changeRatio >= 0.5) {
        return 'slowing';
    } else {
        return 'stalled';
    }
}

// Master function to recalculate all scores for a lead
function recalculateAllLeadScores($lead, $admin) {
    // Calculate fit score using existing function
    $fitResult = calculateLeadGrade($lead, $admin);

    // Calculate engagement score
    $engagementResult = calculateEngagementScore($lead);

    // Calculate days since last activity
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);

    // Calculate temperature
    $temperature = calculateTemperature($fitResult['score'], $engagementResult['score'], $daysSinceActivity, $lead);

    // Calculate velocity
    $velocity = calculateVelocity($lead);

    return [
        'fit_grade' => $fitResult['grade'],
        'fit_score' => $fitResult['score'],
        'engagement_score' => $engagementResult['score'],
        'temperature' => $temperature,
        'velocity' => $velocity,
        'last_score_update' => date('c'),
        'scoring_factors' => [
            'fit_breakdown' => ['score' => $fitResult['score'], 'grade' => $fitResult['grade']],
            'engagement_breakdown' => $engagementResult['breakdown'],
            'last_activity_days' => $daysSinceActivity,
            'activity_trend' => $velocity
        ]
    ];
}

// Log an activity for a lead
function logLeadActivity($lead, $type, $details = null) {
    $activities = $lead['activities'] ?? [];
    $activities[] = [
        'id' => 'act_' . bin2hex(random_bytes(8)),
        'type' => $type,
        'timestamp' => date('c'),
        'details' => $details
    ];
    return $activities;
}

// ============================================================================
// PROACTIVE INTELLIGENCE HELPER FUNCTIONS
// ============================================================================

/**
 * Generate a prioritized focus queue of leads requiring action
 * Priority scoring considers temperature, overdue status, velocity, and recency
 */
function generateFocusQueue($leads, $admin, $limit = 10) {
    $now = time();
    $today = viewerToday();
    $queue = [];

    foreach ($leads as $lead) {
        $stage = getLeadStage($lead);
        // Parked/won/lost leads are done — they don't belong in "call this
        // next," not even at a deprioritized rank. Previously only won/lost
        // were excluded here, so a dropped lead could still surface in the
        // queue on a sparse account with nothing else competing for a slot.
        if (in_array($stage, ['won', 'lost', 'nurture_parked'])) continue;
        // A poor ICP score (fit_grade Disqualified) no longer hides a lead from
        // the Focus Queue — matches lead-batches; a rep should still see and
        // decide on a researched-but-low-scoring lead, not have it vanish.

        // Skip leads that are snoozed (skipped_until)
        if (!empty($lead['skipped_until']) && strtotime($lead['skipped_until']) > $now) continue;

        // Calculate priority score
        $priorityScore = 0;
        $reason = '';
        $suggestedAction = '';
        $urgency = 'normal';

        // Source/SLA priority from the Levata mapping.
        if (($lead['source'] ?? '') === 'inbound' && $stage === 'call_attempted') {
            $priorityScore += 250;
            $reason = 'Inbound booking lead - call within 2 hours';
            $suggestedAction = 'call';
            $urgency = 'critical';
        }

        // Stage/source now drive priority; temperature remains a light secondary signal only.
        // (nurture_parked is excluded above, never reaches this scoring.)
        $stageScores = [
            'call_attempted' => 60,
            'email_sent' => 45,
            'engaged' => 40,
            'consultation_booked' => 35,
            'research' => 25,
            'new_lead' => 20
        ];
        $priorityScore += $stageScores[$stage] ?? 0;
        $tempScores = ['on_fire' => 20, 'hot' => 12, 'warm' => 6, 'cold' => 0];
        $temp = $lead['temperature'] ?? 'cold';
        $priorityScore += $tempScores[$temp] ?? 0;

        // Overdue bonus (+50) - followup date has passed. Compared by calendar
        // day (string), not by converting to a timestamp against $now — a
        // timestamp comparison resolves "today" to midnight, which reads as
        // already-past the instant any time ticks by, so a same-day follow-up
        // always fell into the "overdue" branch below instead of "due today"
        // (and always showed "overdue by 0 days", never actually reachable
        // as a distinct due-today message).
        $isOverdue = false;
        if (!empty($lead['followup_date'])) {
            $followupDay = substr($lead['followup_date'], 0, 10);
            if ($followupDay < $today) {
                $followupTime = strtotime($lead['followup_date']);
                $priorityScore += 50;
                $isOverdue = true;
                $urgency = 'high';
                $daysOverdue = floor(($now - $followupTime) / 86400);
                $reason = "Callback overdue by {$daysOverdue} day(s)";
                $suggestedAction = 'call';
            } elseif ($followupDay === $today) {
                $priorityScore += 30;
                $reason = "Callback due today";
                $suggestedAction = 'call';
                $urgency = 'high';
            }
        }

        // Velocity penalty (stalled=-30, slowing=-15)
        $velocity = $lead['velocity'] ?? 'stalled';
        if ($velocity === 'stalled') {
            $priorityScore -= 15; // Less severe penalty - stalled leads may still be worth pursuing
        } elseif ($velocity === 'slowing') {
            $priorityScore -= 10;
        } elseif ($velocity === 'accelerating') {
            $priorityScore += 20;
        }

        // Recency bonus (+20 if activity in 3 days)
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);
        if ($daysSinceActivity <= 3) {
            $priorityScore += 20;
        }

        // Determine suggested action based on canonical stage
        $status = $stage;
        if (empty($reason)) {
            switch ($status) {
                case 'new_lead':
                    $reason = "New lead - needs research";
                    $suggestedAction = 'research';
                    break;
                case 'research':
                    $reason = "Research complete - ready for outreach";
                    $suggestedAction = 'email';
                    $priorityScore += 15; // Boost researched leads
                    break;
                case 'email_sent':
                    // No grace-period wait state — matches lead-batches'
                    // needs_calling bucket (Queue Summary's "Calls Due"),
                    // which counts every email_sent lead as call-ready
                    // immediately. Previously this suggested "Waiting" for
                    // the first 3 days, which disagreed with Calls Due
                    // counting the same lead as due right now.
                    if ($daysSinceActivity >= 3) {
                        $reason = "Email sent {$daysSinceActivity} days ago - follow up";
                        $suggestedAction = 'followup';
                    } else {
                        $reason = "Email sent - ready to call";
                        $suggestedAction = 'call';
                    }
                    break;
                case 'call_attempted':
                    $reason = "Call scheduled";
                    $suggestedAction = 'call';
                    $priorityScore += 25;
                    $urgency = 'high';
                    break;
                case 'engaged':
                    $outcome = $lead['call_outcome'] ?? '';
                    if (in_array($outcome, ['no_answer_retry', 'callback_requested', 'left_voicemail'])) {
                        $reason = "Last call: {$outcome} - retry";
                        $suggestedAction = 'call';
                    } else {
                        $reason = "Engaged - book consultation or next step";
                        $suggestedAction = 'consultation';
                    }
                    break;
                case 'consultation_booked':
                    $reason = "Consultation booked - prepare and confirm";
                    $suggestedAction = 'consultation';
                    $priorityScore += 40;
                    $urgency = 'critical';
                    break;
                case 'nurture_parked':
                    $reason = "Parked lead - review cooling-off timing";
                    $suggestedAction = 'nurture';
                    $priorityScore -= 40;
                    break;
                default:
                    $reason = "Review needed";
                    $suggestedAction = 'review';
            }
        }

        // Calculate SLA status
        $slaStatus = calculateSLAStatus($lead);

        // Override urgency if SLA is critical
        if ($slaStatus['urgency'] === 'critical') {
            $urgency = 'critical';
        } elseif ($slaStatus['urgency'] === 'overdue' && $urgency === 'normal') {
            $urgency = 'high';
        }

        $queue[] = [
            'lead_id' => $lead['id'],
            'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
            'company' => $lead['company'] ?? '',
            'title' => $lead['title'] ?? '',
            'source' => $lead['source'] ?? '',
            'temperature' => $temp,
            'velocity' => $velocity,
            'fit_grade' => $lead['fit_grade'] ?? '',
            'status' => stageToLegacyStatus($stage),
            'stage' => $stage,
            'priority_score' => $priorityScore,
            'suggested_action' => $suggestedAction,
            'reason' => $reason,
            'urgency' => $urgency,
            'days_since_activity' => $daysSinceActivity,
            'followup_date' => $lead['followup_date'] ?? null,
            'is_overdue' => $isOverdue,
            'sla' => $slaStatus,
            // Present only when the caller merged in leads from multiple reps
            // (admin team-wide view) — absent for a rep's own single-owner queue.
            'owner_id' => $lead['_owner_id'] ?? null,
            'owner_name' => $lead['_owner_name'] ?? null
        ];
    }

    // Sort by priority score descending
    usort($queue, function($a, $b) {
        return $b['priority_score'] - $a['priority_score'];
    });

    // Return top N alongside the true total so callers can show an accurate
    // count badge instead of the capped list length.
    return ['items' => array_slice($queue, 0, $limit), 'total' => count($queue)];
}

/**
 * Find leads that need immediate attention (going cold, overdue, stalled)
 */
function findAttentionNeeded($leads) {
    $now = time();
    $today = viewerToday();
    $attention = [];

    foreach ($leads as $lead) {
        if (in_array(getLeadStage($lead), ['nurture_parked', 'won', 'lost'])) continue;

        $issues = [];
        $temp = $lead['temperature'] ?? 'cold';
        $velocity = $lead['velocity'] ?? 'stalled';
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);

        // Going cold: was hot/warm, no activity 7+ days
        if (in_array($temp, ['hot', 'warm']) && $daysSinceActivity >= 7) {
            $issues[] = [
                'type' => 'going_cold',
                'message' => "Going cold - no activity in {$daysSinceActivity} days",
                'severity' => 'warning'
            ];
        }

        // On fire but stalled: high temp but no momentum
        if ($temp === 'on_fire' && $velocity === 'stalled') {
            $issues[] = [
                'type' => 'stalled_hot',
                'message' => "Hot lead losing momentum",
                'severity' => 'critical'
            ];
        }

        // Callback overdue — compared by calendar day (string), not a raw
        // timestamp: a same-day follow-up resolves to midnight, so a
        // timestamp comparison reads it as already-past the instant any
        // time ticks by, wrongly flagging "overdue by 0 day(s)" for a
        // callback that's due later today, not actually overdue yet.
        if (!empty($lead['followup_date'])) {
            $followupDay = substr($lead['followup_date'], 0, 10);
            if ($followupDay < $today) {
                $followupTime = strtotime($lead['followup_date']);
                $daysOverdue = floor(($now - $followupTime) / 86400);
                $issues[] = [
                    'type' => 'callback_overdue',
                    'message' => "Callback overdue by {$daysOverdue} day(s)",
                    'severity' => $daysOverdue > 3 ? 'critical' : 'warning'
                ];
            }
        }

        // Multiple attempts, no success. call_outcome is only ever set by the
        // MANUAL outcome-logging flow — an Aircall call updates calls_made
        // and call_history directly and never touches call_outcome, so for
        // an Aircall-only lead call_outcome stays permanently blank even
        // after several genuinely ANSWERED calls. Checking call_outcome
        // alone (previously: blank/no_answer_retry/left_voicemail) wrongly
        // read "no manual outcome logged yet" as "no answer", flagging a
        // lead with 5 real answered Aircall calls as "8 attempts, no
        // answer". Recent call_history entries (if any exist) are now the
        // source of truth for whether calls actually went unanswered.
        $callsMade = intval($lead['calls_made'] ?? 0);
        $callHistory = $lead['call_history'] ?? [];
        if (!empty($callHistory)) {
            $recentCalls = array_slice($callHistory, -3);
            $noneAnswered = true;
            foreach ($recentCalls as $call) {
                $callOutcome = $call['outcome'] ?? '';
                if ($callOutcome === 'answered' || $callOutcome === 'consultation_booked' || $callOutcome === 'interested_followup') {
                    $noneAnswered = false;
                    break;
                }
            }
        } else {
            // No call_history at all — fall back to the legacy call_outcome
            // field for leads whose calls were all logged manually before
            // call_history existed.
            $legacyOutcome = $lead['call_outcome'] ?? '';
            $noneAnswered = in_array($legacyOutcome, ['no_answer_retry', 'left_voicemail', '']);
        }
        if ($callsMade >= 3 && $noneAnswered) {
            $issues[] = [
                'type' => 'multiple_attempts',
                'message' => "{$callsMade} call attempts with no answer",
                'severity' => 'warning'
            ];
        }

        // Research complete but no outreach (stale research)
        $status = getLeadStage($lead);
        if ($status === 'research' && $daysSinceActivity >= 5) {
            $issues[] = [
                'type' => 'stale_research',
                'message' => "Research done {$daysSinceActivity} days ago - no outreach",
                'severity' => 'warning'
            ];
        }

        if (!empty($issues)) {
            $attention[] = [
                'lead_id' => $lead['id'],
                'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
                'company' => $lead['company'] ?? '',
                'temperature' => $temp,
                'velocity' => $velocity,
                'fit_grade' => $lead['fit_grade'] ?? '',
                'status' => $status,
                'issues' => $issues,
                'days_since_activity' => $daysSinceActivity,
                'owner_id' => $lead['_owner_id'] ?? null,
                'owner_name' => $lead['_owner_name'] ?? null
            ];
        }
    }

    // Sort by most critical first
    usort($attention, function($a, $b) {
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $aMax = 2;
        $bMax = 2;
        foreach ($a['issues'] as $issue) {
            $aMax = min($aMax, $severityOrder[$issue['severity']] ?? 2);
        }
        foreach ($b['issues'] as $issue) {
            $bMax = min($bMax, $severityOrder[$issue['severity']] ?? 2);
        }
        return $aMax - $bMax;
    });

    return $attention;
}

/**
 * Generate AI-powered next-best-action recommendation for a lead
 */
function generateNextBestAction($lead, $admin) {
    // Get API key for LLM call
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';

    // Build context about the lead
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? 'Unknown Company';
    $title = $lead['title'] ?? '';
    $status = $lead['status'] ?? 'new';
    $temperature = $lead['temperature'] ?? 'cold';
    $velocity = $lead['velocity'] ?? 'stalled';
    $fitGrade = $lead['fit_grade'] ?? '';
    $engagementScore = $lead['engagement_score'] ?? 0;
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);
    $callsMade = intval($lead['calls_made'] ?? 0);
    $emailsSent = intval($lead['emails_sent'] ?? 0);
    $lastOutcome = $lead['call_outcome'] ?? '';
    $followupDate = $lead['followup_date'] ?? '';

    // Get research data if available
    $researchSummary = '';
    if (!empty($lead['enrichment'])) {
        $enrichment = json_decode($lead['enrichment'], true);
        if ($enrichment) {
            $painPoints = $enrichment['prospect_analysis']['pain_points'] ?? [];
            $openingHooks = $enrichment['sales_strategy']['opening_hooks'] ?? [];
            if (!empty($painPoints)) {
                $painTexts = array_map(function($p) {
                    return is_array($p) ? ($p['pain'] ?? $p['point'] ?? reset($p) ?? '') : (string)$p;
                }, array_slice($painPoints, 0, 3));
                $researchSummary .= "Pain points: " . implode(', ', $painTexts) . ". ";
            }
            if (!empty($openingHooks)) {
                $hookTexts = array_map(function($h) {
                    return is_array($h) ? ($h['hook'] ?? $h['text'] ?? reset($h) ?? '') : (string)$h;
                }, array_slice($openingHooks, 0, 2));
                $researchSummary .= "Hooks: " . implode(', ', $hookTexts) . ". ";
            }
        }
    }

    // Build email history summary
    $emailHistory = '';
    if (!empty($lead['email_history'])) {
        $emails = $lead['email_history'];
        $emailHistory = count($emails) . " email(s) sent. Last type: " . ($emails[count($emails)-1]['type'] ?? 'unknown');
    }

    // If no API key, return rule-based recommendation
    if (empty($apiKey)) {
        return generateRuleBasedNBA($lead, $daysSinceActivity);
    }

    $prompt = <<<PROMPT
You are a sales intelligence AI. Based on the lead data below, recommend the SINGLE best next action.

LEAD DATA:
- Name: {$name}
- Company: {$company}
- Title: {$title}
- Status: {$status}
- Temperature: {$temperature} (cold/warm/hot/on_fire)
- Velocity: {$velocity} (stalled/slowing/stable/accelerating)
- ICP Grade: {$fitGrade}
- Engagement Score: {$engagementScore}/100
- Days since last activity: {$daysSinceActivity}
- Calls made: {$callsMade}
- Emails sent: {$emailsSent}
- Last call outcome: {$lastOutcome}
- Scheduled followup: {$followupDate}
- Research: {$researchSummary}
- Email history: {$emailHistory}

Return a JSON object with exactly these fields:
{
  "action": "call|email|followup|research|wait|drop",
  "urgency": "critical|high|medium|low",
  "reason": "One sentence explaining why this action now",
  "talking_point": "A specific thing to mention based on their situation",
  "risk_if_delayed": "What happens if you don't act today"
}

Only return valid JSON, no other text.
PROMPT;

    try {
        $result = callLLM($provider, $apiKey, $prompt, $admin);

        if (!$result['success'] || empty($result['content'])) {
            return generateRuleBasedNBA($lead, $daysSinceActivity);
        }

        // Clean the response - remove markdown code blocks if present
        $response = trim($result['content']);
        $response = preg_replace('/^```json?\s*/', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        $nba = json_decode($response, true);

        if ($nba && isset($nba['action'])) {
            return [
                'action' => $nba['action'],
                'urgency' => $nba['urgency'] ?? 'medium',
                'reason' => $nba['reason'] ?? 'AI recommendation',
                'talking_point' => $nba['talking_point'] ?? '',
                'risk_if_delayed' => $nba['risk_if_delayed'] ?? '',
                'source' => 'ai'
            ];
        }
    } catch (Exception $e) {
        // Fall through to rule-based
    }

    // Fallback to rule-based
    return generateRuleBasedNBA($lead, $daysSinceActivity);
}

/**
 * Rule-based next-best-action (fallback when AI unavailable)
 */
function generateRuleBasedNBA($lead, $daysSinceActivity) {
    $status = $lead['status'] ?? 'new';
    $temp = $lead['temperature'] ?? 'cold';
    $velocity = $lead['velocity'] ?? 'stalled';
    $callsMade = intval($lead['calls_made'] ?? 0);
    $outcome = $lead['call_outcome'] ?? '';

    // Decision tree
    $status = legacyStatusToStage($status);
    if ($status === 'new_lead') {
        return [
            'action' => 'research',
            'urgency' => 'medium',
            'reason' => 'New lead needs research before outreach',
            'talking_point' => 'Run AI research to understand their business',
            'risk_if_delayed' => 'Lead may go to competitors',
            'source' => 'rules'
        ];
    }

    if ($status === 'research') {
        return [
            'action' => 'email',
            'urgency' => $temp === 'hot' ? 'high' : 'medium',
            'reason' => 'Research complete - time to reach out',
            'talking_point' => 'Use research insights for personalized email',
            'risk_if_delayed' => 'Research becomes stale after 5 days',
            'source' => 'rules'
        ];
    }

    if ($status === 'email_sent' && $daysSinceActivity >= 3) {
        return [
            'action' => 'followup',
            'urgency' => $daysSinceActivity >= 7 ? 'high' : 'medium',
            'reason' => "No response after {$daysSinceActivity} days",
            'talking_point' => 'Reference previous email, add new value',
            'risk_if_delayed' => 'Lead forgets about you',
            'source' => 'rules'
        ];
    }

    if ($status === 'call_attempted' || ($outcome === 'callback_requested')) {
        return [
            'action' => 'call',
            'urgency' => 'high',
            'reason' => 'Callback is scheduled or requested',
            'talking_point' => $lead['call_anchor'] ?? 'Follow up on previous conversation',
            'risk_if_delayed' => 'Breaking promise damages trust',
            'source' => 'rules'
        ];
    }

    if (in_array($outcome, ['no_answer_retry', 'left_voicemail']) && $callsMade < 5) {
        return [
            'action' => 'call',
            'urgency' => 'medium',
            'reason' => "Try again - only {$callsMade} attempts so far",
            'talking_point' => 'Try different time of day',
            'risk_if_delayed' => 'May lose timing window',
            'source' => 'rules'
        ];
    }

    if ($temp === 'on_fire' || $temp === 'hot') {
        return [
            'action' => 'call',
            'urgency' => 'high',
            'reason' => 'Hot lead - strike while iron is hot',
            'talking_point' => 'They are showing buying signals',
            'risk_if_delayed' => 'Hot leads cool down fast',
            'source' => 'rules'
        ];
    }

    // Default
    return [
        'action' => 'review',
        'urgency' => 'low',
        'reason' => 'Review lead and plan next steps',
        'talking_point' => 'Check for any missed opportunities',
        'risk_if_delayed' => 'Lead may become stale',
        'source' => 'rules'
    ];
}

/**
 * Generate notifications for leads that need attention
 */
function generateNotifications($leads, $existingNotifications) {
    $now = time();
    $today = viewerToday();
    $newNotifications = [];

    // Build set of existing unread notification keys to avoid duplicates
    // Key = notif_key field if set, otherwise type + lead_id
    $existingKeys = [];
    foreach ($existingNotifications as $notif) {
        if (!($notif['read'] ?? false)) {
            $key = $notif['notif_key'] ?? (($notif['type'] ?? '') . '_' . ($notif['lead_id'] ?? ''));
            $existingKeys[$key] = true;
        }
    }

    foreach ($leads as $lead) {
        if (in_array(getLeadStage($lead), ['nurture_parked', 'won', 'lost'])) continue;

        $leadId = $lead['id'];
        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $company = $lead['company'] ?? '';
        $temp = $lead['temperature'] ?? 'cold';
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);

        $status = getLeadStage($lead);

        $checks = [
            ['cond' => in_array($temp, ['on_fire','hot']) && $daysSinceActivity >= 1,
             'key'  => "hot_lead_{$leadId}", 'type' => 'hot_lead',
             'title'=> "🔥 Hot lead needs attention",
             'body' => "{$name} from {$company} hasn't been contacted in {$daysSinceActivity} day(s)"],

            ['cond' => !empty($lead['followup_date']) && date('Y-m-d', strtotime($lead['followup_date'])) === $today,
             'key'  => "callback_due_{$leadId}", 'type' => 'callback_due',
             'title'=> "📞 Callback due today",
             'body' => "{$name} from {$company}"],

            ['cond' => !empty($lead['followup_date']) && strtotime($lead['followup_date']) < strtotime('today'),
             'key'  => "callback_overdue_{$leadId}", 'type' => 'callback_overdue',
             'title'=> "⚠️ Callback overdue",
             'body' => "{$name} from {$company} — " . floor(($now - strtotime($lead['followup_date'])) / 86400) . " day(s) overdue"],

            ['cond' => in_array($temp, ['hot','warm']) && $daysSinceActivity >= 7 && $daysSinceActivity < 14,
             'key'  => "going_cold_{$leadId}", 'type' => 'going_cold',
             'title'=> "❄️ Lead going cold",
             'body' => "{$name} from {$company} — {$daysSinceActivity} days inactive"],

            ['cond' => $status === 'research' && $daysSinceActivity >= 3,
             'key'  => "stale_research_{$leadId}", 'type' => 'stale_research',
             'title'=> "🔍 Send outreach to {$name}",
             'body' => "Research done {$daysSinceActivity} days ago — {$company}"],

            ['cond' => $status === 'email_sent' && $daysSinceActivity >= 3,
             'key'  => "followup_call_{$leadId}", 'type' => 'callback_due',
             'title'=> "📞 Follow-up call needed",
             'body' => "{$name} from {$company} — email sent {$daysSinceActivity} days ago"],

            ['cond' => $status === 'new_lead' && $daysSinceActivity >= 2,
             'key'  => "new_lead_idle_{$leadId}", 'type' => 'stale_research',
             'title'=> "⏰ New lead needs attention",
             'body' => "{$name} from {$company} — added {$daysSinceActivity} days ago"],
        ];

        foreach ($checks as $check) {
            if ($check['cond'] && !isset($existingKeys[$check['key']])) {
                $newNotifications[] = [
                    'id'        => 'notif_' . bin2hex(random_bytes(8)),
                    'notif_key' => $check['key'],
                    'type'      => $check['type'],
                    'lead_id'   => $leadId,
                    'title'     => $check['title'],
                    'body'      => $check['body'],
                    'message'   => $check['title'],
                    'created_at'=> date('c'),
                    'read'      => false
                ];
                $existingKeys[$check['key']] = true;
            }
        }
    }

    return $newNotifications;
}

// ============================================================================
// END PROACTIVE INTELLIGENCE HELPER FUNCTIONS
// ============================================================================

function generatePassword() { $c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; $p = ''; for ($i = 0; $i < 10; $i++) $p .= $c[random_int(0, strlen($c) - 1)]; return $p; }
function respond($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

// Sends the response to the browser right now, but keeps the PHP process
// running afterward — for the handful of call sites (chat send) where the
// essential work (saving the message) is done and everything left is
// best-effort background push (Pusher, per-recipient notification writes)
// that must never make the sender wait. Without this, a slow-but-working
// Pusher round-trip (real-world: ~1-2s, worse under any network hiccup) sat
// directly in the response path, delaying the sender's own optimistic UI
// update by that same amount on every single message. Falls back to a
// normal blocking respond() if fastcgi_finish_request() isn't available
// (some hosting setups don't run PHP under FPM) — same correctness, just
// without the latency win in that case.
function respondThenContinue($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        // No way to detach — flush what we can and keep going; the client
        // still waits for the connection to close, but this is strictly no
        // worse than the old behavior on hosts without FPM.
        if (ob_get_level() > 0) ob_end_flush();
        flush();
    }
}

function getCurrentUser() {
    // Normal API calls send the token via the X-User-Token header. A plain
    // HTML <form> submission (used for file-download endpoints like
    // export-csv, since a real file download needs a real navigation/new-tab,
    // not a fetch() call) has no way to set a custom header at all, so it
    // falls back to a token field/param instead — without this, every
    // export-csv request 401'd with a JSON error page instead of a CSV file.
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? $_POST['token'] ?? $_GET['token'] ?? '';
    if (empty($token)) return null;
    foreach (getAllUsersIncludingInternal() as $user) {
        // Legacy single token (backward compatible) OR any active token in the tokens[] list
        if (($user['token'] ?? '') === $token) return $user;
        if (!empty($user['tokens']) && is_array($user['tokens']) && in_array($token, $user['tokens'], true)) return $user;
    }
    return null;
}

function requireAuth() { $user = getCurrentUser(); if (!$user) respond(['success' => false, 'error' => 'Authentication required'], 401); return $user; }
function requireAdmin() { $user = requireAuth(); if (!($user['is_admin'] ?? false) && !($user['is_super_admin'] ?? false)) respond(['success' => false, 'error' => 'Admin access required'], 403); return $user; }
function requireSuperAdmin() { $user = requireAuth(); if (!($user['is_super_admin'] ?? false)) respond(['success' => false, 'error' => 'Super admin access required'], 403); return $user; }

// Resolves which account's leads to read/write for a lead-scoped endpoint. A
// plain rep always operates on their own account. An admin can pass an
// owner_id (write endpoints: currentLeadOwnerId from the frontend, set when
// they opened a lead they don't personally own — read endpoints: view_as,
// the Pipeline page's rep filter) to act on that account directly — verified
// against the caller's admin status here, not just trusted, so a non-admin
// can never use this to reach into someone else's account.
function resolveLeadOwnerId(array $user, ?string $requestedOwnerId): string {
    if ($requestedOwnerId && $requestedOwnerId !== $user['id']) {
        if (empty($user['is_admin']) && empty($user['is_super_admin'])) {
            respond(['success' => false, 'error' => 'Admin access required to view or edit another user\'s leads'], 403);
        }
        return $requestedOwnerId;
    }
    return $user['id'];
}

// ===== REAL-TIME (Pusher Channels) =====
// Thin wrapper around Pusher's REST API using raw cURL + HMAC-SHA256 signing
// (same pattern as callGroq/callAnthropic/notifySupportEmail — no SDK needed).
// Configured via admin.json 'pusher_app_id'/'pusher_key'/'pusher_secret'/'pusher_cluster'
// (Settings, super-admin-only). Every call is best-effort: a Pusher outage or
// missing config must never block the underlying save (message/notification/ticket).
function pusherConfig() {
    $admin = getAdmin();
    $appId = trim($admin['pusher_app_id'] ?? '');
    $key = trim($admin['pusher_key'] ?? '');
    $secret = trim($admin['pusher_secret'] ?? '');
    $cluster = trim($admin['pusher_cluster'] ?? '') ?: 'mt1';
    if ($appId === '' || $key === '' || $secret === '') return null;
    return ['app_id' => $appId, 'key' => $key, 'secret' => $secret, 'cluster' => $cluster];
}

/** Push an event to a Pusher channel. Best-effort — never throws, returns bool. */
function pusherTrigger($channel, $event, $data) {
    $cfg = pusherConfig();
    if ($cfg === null) return false;

    $body = json_encode(['name' => $event, 'channel' => $channel, 'data' => json_encode($data)]);
    $path = "/apps/{$cfg['app_id']}/events";
    $authTs = time();
    $authVersion = '1.0';
    $bodyMd5 = md5($body);
    $params = [
        'auth_key' => $cfg['key'],
        'auth_timestamp' => $authTs,
        'auth_version' => $authVersion,
        'body_md5' => $bodyMd5,
    ];
    ksort($params);
    $query = http_build_query($params);
    $stringToSign = "POST\n{$path}\n{$query}";
    $signature = hash_hmac('sha256', $stringToSign, $cfg['secret']);
    $url = "https://api-{$cfg['cluster']}.pusher.com{$path}?{$query}&auth_signature={$signature}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 5,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Sign a private-channel subscription request from the Pusher JS client.
 * Returns ['auth' => "key:signature"] on success, or null if Pusher isn't configured.
 */
function pusherAuthenticate($channelName, $socketId) {
    $cfg = pusherConfig();
    if ($cfg === null) return null;
    $stringToSign = "{$socketId}:{$channelName}";
    $signature = hash_hmac('sha256', $stringToSign, $cfg['secret']);
    return ['auth' => "{$cfg['key']}:{$signature}"];
}

// ===== TEAM CHAT HELPERS =====
function isChatAdmin(array $user): bool {
    return !empty($user['is_admin']) || !empty($user['is_super_admin']);
}

$_channelsCache = null;
function getChatChannels(): array {
    global $_channelsCache;
    if ($_channelsCache !== null) return $_channelsCache;
    $channels = dbLoadAll('chat_channels');
    if (empty($channels)) {
        $channels = [
            ['id'=>'channel_general','name'=>'general','description'=>'Company-wide chat','members'=>[],'created_by'=>'system','created_at'=>date('c')],
            ['id'=>'channel_deals','name'=>'deals','description'=>'Deal updates','members'=>[],'created_by'=>'system','created_at'=>date('c')]
        ];
        dbSaveAll('chat_channels', $channels);
    }
    $_channelsCache = $channels;
    return $_channelsCache;
}
function saveChatChannels(array $channels): void {
    global $_channelsCache;
    dbSaveAll('chat_channels', array_values($channels));
    $_channelsCache = array_values($channels);
}
function getChannelMessages(string $channelId): array { return dbLoadMessages($channelId); }
// Not concurrency-safe (see db.php's dbSaveMessages() for why) — do not use
// for send/react/delete/pin. Only for a genuine bulk rewrite of a thread.
function saveChannelMessages(string $channelId, array $messages): void { dbSaveMessages($channelId, array_values($messages)); }
function addChannelMessage(string $channelId, array $msg): void { dbInsertMessage($channelId, $msg); }
function updateChannelMessage(string $channelId, string $messageId, array $msg): void { dbUpdateMessage($channelId, $messageId, $msg); }
function removeChannelMessage(string $channelId, string $messageId): void { dbDeleteMessage($channelId, $messageId); }
function getDmThreadId(string $a, string $b): string { $ids = [$a, $b]; sort($ids); return 'dm_' . md5($ids[0] . '_' . $ids[1]); }

// Verify the current user is allowed to read/post in a given thread —
// a DM participant, a member of a private channel, or an admin.
function canAccessChatThread(array $user, string $threadId): bool {
    if (strpos($threadId, 'dm_') === 0) {
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $user['id']) continue;
            if (getDmThreadId($user['id'], $u['id']) === $threadId) return true;
        }
        return false;
    }
    foreach (getChatChannels() as $ch) {
        if ($ch['id'] !== $threadId) continue;
        $members = $ch['members'] ?? [];
        return empty($members) || isChatAdmin($user) || in_array($user['id'], $members, true);
    }
    return false; // unknown channel id
}

function getChatUnreadCounts(string $userId): array {
    $counts = [];
    foreach (getChatChannels() as $ch) {
        $messages = getChannelMessages($ch['id']);
        $last = dbGetLastRead($userId, $ch['id']) ?: '1970-01-01T00:00:00+00:00';
        $counts[$ch['id']] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    foreach (getUsers() as $u) {
        if ($u['id'] === $userId) continue;
        $threadId = getDmThreadId($userId, $u['id']);
        $messages = getChannelMessages($threadId);
        $last = dbGetLastRead($userId, $threadId) ?: '1970-01-01T00:00:00+00:00';
        $counts[$threadId] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    return $counts;
}

// Push a chat notification. Own table (notifications), not the per-user
// usermeta JSON blob — that shared-blob pattern is a read-modify-write of the
// user's ENTIRE data on every single notification, so two notifications
// landing for the same person close together (a mention and a DM arriving in
// the same request burst, or a public-channel message notifying many members
// in a loop while one of them also gets mentioned elsewhere) could silently
// drop one. dbInsertNotification() is a single-row insert and can't race like
// that. De-dupes on notif_key, same as before.
function pushChatNotification(string $userId, array $notif): void {
    // Don't notify for messages the user has already read in that thread
    $threadId = $notif['thread_id'] ?? '';
    if ($threadId !== '') {
        $lastRead = dbGetLastRead($userId, $threadId);
        $msgTime  = $notif['created_at'] ?? '';
        if ($lastRead && $msgTime && $msgTime <= $lastRead) return;
    }

    // Don't notify if the user dismissed all notifications after this message.
    // Strict "<" only — date('c') truncates to whole seconds, so a message sent
    // in the same second as the dismiss must still notify, not be swallowed by it.
    $dismissedAt = kvGet('notif_dismissed_at', $userId);
    if ($dismissedAt && ($notif['created_at'] ?? '') < $dismissedAt) return;

    // Deduplicate by notif_key
    $key = $notif['notif_key'] ?? '';
    if ($key !== '' && dbNotificationKeyExists($userId, $key)) return;

    dbInsertNotification($userId, $notif);
    // Push instantly to the bell if the user's browser is connected — covers chat
    // DMs/mentions/channel messages AND Feedback & Support ticket replies, since
    // all of them funnel through this one function.
    pusherTrigger('private-user-' . $userId, 'new-notification', $notif);
}

// Notify @mentioned users in a message (matches full name or first name, case-insensitive).
function notifyChatMentions(array $sender, string $text, string $threadId, string $threadLabel, string $sentAt): void {
    if (strpos($text, '@') === false) return;
    $senderName = $sender['name'] ?? $sender['email'] ?? 'Someone';
    foreach (getUsers() as $u) {
        if (($u['id'] ?? '') === ($sender['id'] ?? '')) continue;
        $fullName  = trim($u['name'] ?? '');
        $firstName = $fullName !== '' ? strtok($fullName, ' ') : '';
        $matched = false;
        foreach (array_filter([$fullName, $firstName]) as $cand) {
            if (preg_match('/@' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_])/i', $text)) { $matched = true; break; }
        }
        if (!$matched) continue;
        pushChatNotification($u['id'], [
            'id'         => 'notif_' . bin2hex(random_bytes(6)),
            'notif_key'  => "mention_{$threadId}_{$u['id']}_" . substr(md5($text), 0, 8),
            'type'       => 'mention',
            'title'      => "{$senderName} mentioned you",
            'body'       => "In {$threadLabel}: " . substr($text, 0, 80),
            'thread_id'  => $threadId,
            // The message's own timestamp — see the DM/channel notifications
            // in the chat-messages POST handler for why this can't be a fresh
            // date('c') call.
            'created_at' => $sentAt,
            'read'       => false,
        ]);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';
// DELETE requests can carry a JSON body too (e.g. chat-channels, daily-progress
// commitment deletion both read $input['...'] on DELETE) — excluding it here
// silently discarded the body and made every DELETE handler that expects one
// fail with a false "required field missing" error.
//
// Pusher's JS client posts its auth callback (action=pusher-auth) as a plain
// form-encoded body (socket_id=...&channel_name=...), not JSON — pusher-js
// does not offer a way to make it send JSON instead. json_decode() on a
// form-encoded string returns null, so without this fallback every channel
// subscribe (chat threads, the admin on-call channel — anything other than
// the one channel a caller happens to test first) 400s with "Missing
// socket_id/channel_name" and silently falls back to whatever polling exists
// for that specific feature, which is not all of them.
$rawBody = in_array($method, ['POST', 'PUT', 'DELETE']) ? file_get_contents('php://input') : '';
$input = $rawBody !== '' ? (json_decode($rawBody, true) ?? []) : [];
if (empty($input) && !empty($_POST)) $input = $_POST;


switch ($path) {

case 'request-password-reset':
    if ($method !== 'POST') break;
    $email = trim(strtolower($input['email'] ?? ''));
    $users = getAllUsersIncludingInternal();
    foreach ($users as &$u) {
        if ($u['email'] === $email) {
            $resetToken = bin2hex(random_bytes(32));
            $u['reset_token'] = $resetToken;
            $u['reset_expires'] = date('c', time() + 3600);
            saveUsers($users);
            // Return token to admin. In production, email the link.
            respond(['success' => true, 'message' => 'If this email exists, a reset link has been generated.', 'reset_token' => $resetToken]);
        }
    }
    // Same message for non-existent emails (security)
    respond(['success' => true, 'message' => 'If this email exists, a reset link has been generated.']);
    break;

case 'reset-password':
    if ($method !== 'POST') break;
    $resetToken = $input['reset_token'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    if (strlen($newPassword) < 6) respond(['success' => false, 'error' => 'Password must be at least 6 characters'], 400);

    $users = getAllUsersIncludingInternal();
    foreach ($users as &$u) {
        if (($u['reset_token'] ?? '') === $resetToken && !empty($u['reset_expires']) && strtotime($u['reset_expires']) > time()) {
            $u['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            unset($u['reset_token']);
            unset($u['reset_expires']);
            // Invalidate all existing sessions on password change
            $u['token'] = '';
            $u['tokens'] = [];
            saveUsers($users);
            respond(['success' => true, 'message' => 'Password reset successful. Please login with your new password.']);
        }
    }
    respond(['success' => false, 'error' => 'Invalid or expired reset token'], 400);
    break;

case 'login':
    if ($method !== 'POST') break;
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $users = getAllUsersIncludingInternal();
    foreach ($users as &$user) {
        if ($user['email'] === $email && password_verify($password, $user['password'])) {
            $token = generateToken();
            // Support multiple concurrent sessions: keep a list of active tokens
            // instead of overwriting a single one (so logins don't kick each other out).
            $tokens = (!empty($user['tokens']) && is_array($user['tokens'])) ? $user['tokens'] : [];
            if (!empty($user['token'])) { $tokens[] = $user['token']; }   // migrate any legacy token in
            $tokens[] = $token;
            $tokens = array_values(array_unique($tokens));
            if (count($tokens) > 10) { $tokens = array_slice($tokens, -10); }  // cap to last 10 sessions
            $user['tokens'] = $tokens;
            $user['token'] = $token;   // keep legacy field as the most recent, for compatibility
            $user['last_login_at'] = date('c');
            $user['session_start'] = date('c');
            $user['last_active_at'] = date('c');
            saveUsers($users);
            respond(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'is_admin' => ($user['is_admin'] ?? false) || ($user['is_super_admin'] ?? false), 'is_super_admin' => $user['is_super_admin'] ?? false], 'token' => $token]);
        }
    }
    respond(['success' => false, 'error' => 'Invalid email or password'], 401);
    break;

case 'me':
    if ($method !== 'GET') break;
    $user = getCurrentUser();
    if ($user) {
        $users = getAllUsersIncludingInternal();
        foreach ($users as &$u) {
            if ($u['id'] === $user['id']) {
                $u['last_active_at'] = date('c');
                break;
            }
        }
        saveUsers($users);
        $userData = getUserData($user['id']);
        respond(['success' => true, 'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'is_admin' => ($user['is_admin'] ?? false) || ($user['is_super_admin'] ?? false),
            'is_super_admin' => $user['is_super_admin'] ?? false,
            'onboarding_completed' => $userData['onboarding_completed'] ?? true
        ]]);
    }
    respond(['success' => false, 'error' => 'Not authenticated'], 401);
    break;

case 'complete-onboarding':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $userData['onboarding_completed'] = true;
    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;

case 'admin-settings':
    if ($method === 'GET') {
        $reqUser = requireAdmin();
        $admin = getAdmin();
        $masked = [];
        foreach ($admin as $k => $v) {
            // Aircall is super-admin-only (same tier as Hub Sync / Real-Time) —
            // strip it out entirely for a plain admin, not just mask it.
            if (strpos($k, 'aircall_') === 0 && empty($reqUser['is_super_admin'])) continue;
            // Mask API keys and secrets
            if ((strpos($k, '_key') !== false || strpos($k, '_secret') !== false || strpos($k, '_token') !== false) && $v && is_string($v)) {
                $masked[$k] = '********' . substr($v, -4);
            } else {
                $masked[$k] = $v;
            }
        }
        respond(['success' => true, 'settings' => $masked]);
    }
    if ($method === 'POST') {
        requireAdmin();
        $admin = getAdmin();

        // LLM API keys
        foreach (['groq_key', 'gemini_key', 'anthropic_key', 'cerebras_key'] as $k) {
            if (isset($input[$k]) && strpos($input[$k], '****') === false) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['default_provider'])) $admin['default_provider'] = $input['default_provider'];
        if (isset($input['requisitions'])) $admin['requisitions'] = $input['requisitions'];

        // Team-wide default daily activity targets (Admin Dashboard's Team
        // Activity widget). A rep can still override their own via Today's
        // Commitments — this is only the org-wide default shown there and
        // used to divide the team-aggregated counts on the Admin Dashboard.
        if (isset($input['daily_targets']) && is_array($input['daily_targets'])) {
            $admin['daily_targets'] = [
                'calls' => max(0, (int)($input['daily_targets']['calls'] ?? 40)),
                'emails' => max(0, (int)($input['daily_targets']['emails'] ?? 40)),
                'research' => max(0, (int)($input['daily_targets']['research'] ?? 25)),
                'outcomes' => max(0, (int)($input['daily_targets']['outcomes'] ?? 40)),
            ];
        }

        // Aircall settings — super-admin only, same tier as Hub Sync / Real-Time.
        // Checked here (not just hidden in the UI) so a plain admin can't set
        // these via a direct API call either.
        if (isset($input['aircall_api_id']) || isset($input['aircall_api_token']) || isset($input['aircall_webhook_token'])) {
            requireSuperAdmin();
        }
        if (isset($input['aircall_api_id']) && strpos($input['aircall_api_id'], '****') === false) {
            $admin['aircall_api_id'] = trim($input['aircall_api_id']);
        }
        if (isset($input['aircall_api_token']) && strpos($input['aircall_api_token'], '****') === false) {
            $admin['aircall_api_token'] = trim($input['aircall_api_token']);
        }
        if (isset($input['aircall_webhook_token']) && strpos($input['aircall_webhook_token'], '****') === false) {
            $admin['aircall_webhook_token'] = trim($input['aircall_webhook_token']);
        }

        saveAdmin($admin);
        respond(['success' => true]);
    }
    break;

case 'ticket-sync-settings':
    // Super-admin-only: hub URL/secret for the Feedback & Support -> Levata sync.
    // Kept out of 'admin-settings' so regular admins never see it, even masked.
    // Stays super-admin-only even after Feedback & Support itself opens up to
    // everyone — this is infra config (webhook secret), not a support feature.
    if ($method === 'GET') {
        requireSuperAdmin();
        $admin = getAdmin();
        $mask = function ($v) { return ($v && is_string($v)) ? ('********' . substr($v, -4)) : ''; };
        respond(['success' => true, 'settings' => [
            'ticket_hub_url' => $admin['ticket_hub_url'] ?? '',
            'ticket_hub_secret' => $mask($admin['ticket_hub_secret'] ?? ''),
            'ticket_client_name' => $admin['ticket_client_name'] ?? '',
        ]]);
    }
    if ($method === 'POST') {
        requireSuperAdmin();
        $admin = getAdmin();
        if (isset($input['ticket_hub_url'])) $admin['ticket_hub_url'] = trim($input['ticket_hub_url']);
        if (isset($input['ticket_hub_secret']) && strpos($input['ticket_hub_secret'], '****') === false) {
            $admin['ticket_hub_secret'] = trim($input['ticket_hub_secret']);
        }
        if (isset($input['ticket_client_name'])) $admin['ticket_client_name'] = trim($input['ticket_client_name']);
        saveAdmin($admin);
        respond(['success' => true]);
    }
    break;

case 'realtime-settings':
    // Super-admin-only: Pusher app credentials for real-time chat/notifications/tickets.
    // Kept out of 'admin-settings' so regular admins never see it, even masked.
    if ($method === 'GET') {
        requireSuperAdmin();
        $admin = getAdmin();
        $mask = function ($v) { return ($v && is_string($v)) ? ('********' . substr($v, -4)) : ''; };
        respond(['success' => true, 'settings' => [
            'pusher_app_id' => $admin['pusher_app_id'] ?? '',
            'pusher_key' => $admin['pusher_key'] ?? '',
            'pusher_secret' => $mask($admin['pusher_secret'] ?? ''),
            'pusher_cluster' => $admin['pusher_cluster'] ?? '',
        ]]);
    }
    if ($method === 'POST') {
        requireSuperAdmin();
        $admin = getAdmin();
        if (isset($input['pusher_app_id'])) $admin['pusher_app_id'] = trim($input['pusher_app_id']);
        if (isset($input['pusher_key'])) $admin['pusher_key'] = trim($input['pusher_key']);
        if (isset($input['pusher_secret']) && strpos($input['pusher_secret'], '****') === false) {
            $admin['pusher_secret'] = trim($input['pusher_secret']);
        }
        if (isset($input['pusher_cluster'])) $admin['pusher_cluster'] = trim($input['pusher_cluster']);
        saveAdmin($admin);
        respond(['success' => true]);
    }
    break;

case 'realtime-config':
    // Public (any logged-in user): just the Pusher key + cluster needed to open a
    // client-side connection. Never includes the secret — that's what pusher-auth
    // is for. Returns 'enabled'=>false if not configured, so the frontend can fall
    // back to polling silently.
    if ($method !== 'GET') break;
    requireAuth();
    $cfg = pusherConfig();
    if ($cfg === null) respond(['success' => true, 'enabled' => false]);
    respond(['success' => true, 'enabled' => true, 'key' => $cfg['key'], 'cluster' => $cfg['cluster']]);
    break;

case 'pusher-auth':
    // Authenticates a logged-in user's subscription to their own private channel
    // (private-user-{id}) or a chat thread they're allowed to see
    // (private-thread-{id}). Called by the Pusher JS client automatically.
    if ($method !== 'POST') break;
    $user = requireAuth();
    $socketId = $input['socket_id'] ?? '';
    $channelName = $input['channel_name'] ?? '';
    if ($socketId === '' || $channelName === '') respond(['success' => false, 'error' => 'Missing socket_id/channel_name'], 400);

    if ($channelName === 'private-user-' . $user['id']) {
        // always allowed: a user may subscribe to their own notification channel
    } elseif (strpos($channelName, 'private-thread-') === 0) {
        $threadId = substr($channelName, strlen('private-thread-'));
        if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    } elseif ($channelName === 'private-admins') {
        // Team-wide broadcast channel (e.g. live Aircall on-call state on the
        // Command Center) — same audience as aircall-status's requireAdmin().
        if (empty($user['is_admin']) && empty($user['is_super_admin'])) respond(['success' => false, 'error' => 'Admin access required'], 403);
    } else {
        respond(['success' => false, 'error' => 'Not authorized for this channel'], 403);
    }

    $auth = pusherAuthenticate($channelName, $socketId);
    if ($auth === null) respond(['success' => false, 'error' => 'Realtime not configured'], 404);
    respond($auth);
    break;

case 'get-requisitions':
    if ($method !== 'GET') break;
    requireAuth();
    $admin = getAdmin();
    respond(['success' => true, 'requisitions' => $admin['requisitions'] ?? []]);
    break;

case 'test-api':
    if ($method !== 'POST') break;
    $provider = $input['provider'] ?? 'groq';
    $apiKey = $input['api_key'] ?? '';
    if (!$apiKey || strpos($apiKey, '****') !== false) { $admin = getAdmin(); $apiKey = $admin[$provider . '_key'] ?? ''; }
    if (!$apiKey) respond(['success' => false, 'error' => 'No API key for ' . $provider]);
    $res = callLLM($provider, $apiKey, 'Say "Connection successful!" exactly.');
    respond($res['success'] ? ['success' => true, 'message' => 'Connection successful!'] : ['success' => false, 'error' => $res['error']]);
    break;

case 'users':
    if ($method !== 'GET') break;
    requireAdmin();
    $requestingUser = requireAdmin();
    $isSuperAdmin = $requestingUser['is_super_admin'] ?? false;
    $allUsers = getUsers();
    if (!$isSuperAdmin) {
        $allUsers = array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
    }
    $users = array_map(function($u) use ($isSuperAdmin) {
        $row = ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'is_admin' => $u['is_admin'] ?? false, 'is_super_admin' => $u['is_super_admin'] ?? false, 'created_at' => $u['created_at'] ?? '', 'last_login_at' => $u['last_login_at'] ?? null, 'session_start' => $u['session_start'] ?? null, 'last_active_at' => $u['last_active_at'] ?? null];
        // Aircall linkage is super-admin-only, same tier as the Aircall connection settings.
        if ($isSuperAdmin) {
            $row['aircall_user_id'] = $u['aircall_user_id'] ?? '';
            $row['aircall_number_id'] = $u['aircall_number_id'] ?? '';
        }
        return $row;
    }, $allUsers);
    respond(['success' => true, 'users' => $users]);
    break;

case 'create-user':
    if ($method !== 'POST') break;
    requireAdmin();
    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $isAdmin = (bool)($input['is_admin'] ?? false);
    $isSuperAdmin = (bool)($input['is_super_admin'] ?? false);
    if ($isSuperAdmin) requireSuperAdmin();
    if (!$name || !$email) respond(['success' => false, 'error' => 'Name and email required'], 400);
    $customPassword = trim((string)($input['password'] ?? ''));
    if ($customPassword !== '' && strlen($customPassword) < 8) {
        respond(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
    }
    $users = getAllUsersIncludingInternal();
    foreach ($users as $u) { if ($u['email'] === $email) respond(['success' => false, 'error' => 'Email exists'], 400); }
    $password = $customPassword !== '' ? $customPassword : generatePassword();
    $userId = generateId('user_');
    $users[] = ['id' => $userId, 'name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'token' => '', 'created_at' => date('c'), 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin];
    saveUsers($users);
    saveUserData($userId, ['leads' => [], 'settings' => ['sender_name' => $name, 'sender_company' => 'Macktiles Australia', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => ''], 'onboarding_completed' => false]);
    respond(['success' => true, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin], 'password' => $password]);
    break;

case 'update-user':
    if ($method !== 'POST') break;
    $admin = requireAdmin();
    $userId = $input['id'] ?? '';
    $users = getAllUsersIncludingInternal();
    $newPass = null;
    foreach ($users as &$u) {
        if ($u['id'] === $userId) {
            if (!empty($u['is_super_admin']) && empty($admin['is_super_admin'])) respond(['success' => false, 'error' => 'Cannot edit a super admin'], 403);
            if (!empty($input['name'])) $u['name'] = trim($input['name']);
            if (!empty($input['email'])) $u['email'] = trim(strtolower($input['email']));
            if (isset($input['is_admin'])) $u['is_admin'] = (bool)$input['is_admin'];
            if (isset($input['is_super_admin'])) { requireSuperAdmin(); $u['is_super_admin'] = (bool)$input['is_super_admin']; }
            // Aircall linking — super-admin only, same tier as the Aircall
            // connection settings (checked here too, not just hidden in the UI).
            if (isset($input['aircall_user_id']) || isset($input['aircall_number_id'])) requireSuperAdmin();
            if (isset($input['aircall_user_id'])) $u['aircall_user_id'] = trim((string)$input['aircall_user_id']);
            if (isset($input['aircall_number_id'])) $u['aircall_number_id'] = trim((string)$input['aircall_number_id']);
            if (!empty($input['reset_password'])) {
                $customPassword = trim((string)($input['password'] ?? ''));
                if ($customPassword !== '' && strlen($customPassword) < 8) {
                    respond(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
                }
                $newPass = $customPassword !== '' ? $customPassword : generatePassword();
                $u['password'] = password_hash($newPass, PASSWORD_DEFAULT);
            }
            saveUsers($users);
            $res = ['success' => true];
            if ($newPass) $res['new_password'] = $newPass;
            respond($res);
        }
    }
    respond(['success' => false, 'error' => 'User not found'], 404);
    break;

case 'delete-user':
    if ($method !== 'POST') break;
    $admin = requireAdmin();
    $userId = $input['id'] ?? '';
    if ($userId === $admin['id']) respond(['success' => false, 'error' => 'Cannot delete yourself'], 400);
    $target = null;
    foreach (getUsers() as $u) { if ($u['id'] === $userId) { $target = $u; break; } }
    if (!$target) respond(['success' => false, 'error' => 'User not found'], 404);
    if (!empty($target['is_super_admin']) && empty($admin['is_super_admin'])) respond(['success' => false, 'error' => 'Cannot delete a super admin'], 403);
    $users = array_values(array_filter(getAllUsersIncludingInternal(), function($u) use ($userId) { return $u['id'] !== $userId; }));
    saveUsers($users);
    dbDeleteUserData($userId);
    respond(['success' => true]);
    break;

case 'impersonate':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    $targetId = $input['user_id'] ?? '';
    $users = getAllUsersIncludingInternal();
    foreach ($users as &$u) {
        if ($u['id'] === $targetId) {
            $token = bin2hex(random_bytes(32));
            // Add to the target's active tokens (don't evict their real sessions)
            $tokens = (!empty($u['tokens']) && is_array($u['tokens'])) ? $u['tokens'] : [];
            if (!empty($u['token'])) { $tokens[] = $u['token']; }
            $tokens[] = $token;
            $tokens = array_values(array_unique($tokens));
            if (count($tokens) > 10) { $tokens = array_slice($tokens, -10); }
            $u['tokens'] = $tokens;
            $u['token'] = $token;
            saveUsers($users);
            respond(['success' => true, 'token' => $token, 'user' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'is_admin' => $u['is_admin'] ?? false, 'is_super_admin' => $u['is_super_admin'] ?? false]]);
        }
    }
    respond(['success' => false, 'error' => 'User not found'], 404);
    break;

case 'user-leads':
    if ($method !== 'GET') break;
    requireSuperAdmin();
    $targetId = $_GET['user_id'] ?? '';
    if (!$targetId) respond(['success' => false, 'error' => 'user_id required'], 400);
    $targetData = getUserData($targetId);
    respond(['success' => true, 'leads' => $targetData['leads'] ?? []]);
    break;

case 'reset-user-data':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    $targetId = $input['user_id'] ?? '';
    if (!$targetId) respond(['success' => false, 'error' => 'user_id required'], 400);
    $existing = getUserData($targetId);
    $existing['leads'] = [];
    saveUserData($targetId, $existing);
    respond(['success' => true]);
    break;

// Wipes all test data for a full UAT reset: every lead (all owners), all chat
// messages/channels (re-seeds to just #general/#deals), tickets, activity
// pings, per-user meta (notifications, onboarding flag — including orphaned
// entries for deleted users, since they're safe to drop), and Aircall's
// live "who's on a call" state. User accounts, their My Context settings,
// and admin config (API keys, ICP, requisitions) are intentionally left
// untouched so testers can log straight back in.
case 'reset-all-data':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    if (LOCAL_MODE) respond(['success' => false, 'error' => 'reset-all-data only runs against the Postgres backend, not local JSON mode'], 400);
    if (($input['confirm'] ?? '') !== 'WIPE ALL DATA') {
        respond(['success' => false, 'error' => 'Confirmation phrase required: send {"confirm":"WIPE ALL DATA"}'], 400);
    }
    $pdo = db();
    $pdo->exec('TRUNCATE leads, chat_messages, chat_channels, chat_last_read, activity_pings, notifications');
    $pdo->prepare("DELETE FROM kv_store WHERE bucket = 'usermeta'")->execute();
    $pdo->prepare("DELETE FROM kv_store WHERE bucket = 'notif_dismissed_at'")->execute();
    kvSet('support', 'tickets', []);
    kvSet('aircall', 'oncall', []);
    respond(['success' => true, 'message' => 'All leads, chat, tickets, and activity data cleared. User accounts and settings were kept.']);
    break;

case 'leads':
    if ($method !== 'GET') break;
    $user = requireAuth();
    // Admins can pass view_as=<user_id> to browse another rep's Pipeline
    // directly (e.g. from the rep filter on the Leads/Pipeline page) — same
    // admin-gated pattern as resolveLeadOwnerId(), just for a whole-list
    // read instead of a single-lead write.
    $viewAsId = resolveLeadOwnerId($user, $_GET['view_as'] ?? null);
    $userData = getUserData($viewAsId);
    $admin = getAdmin();
    $leads = $userData['leads'] ?? [];

    // Filter out soft-deleted leads (unless requesting trash)
    $showTrash = ($_GET['trash'] ?? '') === 'true';
    if ($showTrash) {
        $leads = array_filter($leads, function($l) { return !empty($l['deleted_at']); });
    } else {
        $leads = array_filter($leads, function($l) { return empty($l['deleted_at']); });
    }

    $status = $_GET['status'] ?? $_GET['stage'] ?? null;
    if ($status && $status !== 'all') {
        $targetStage = legacyStatusToStage($status);
        $leads = array_filter($leads, function($l) use ($targetStage) { return getLeadStage($l) === $targetStage; });
    }

    // Search filter
    $search = $_GET['search'] ?? '';
    if ($search) {
        $search = strtolower($search);
        $leads = array_filter($leads, function($l) use ($search) {
            return stripos(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''), $search) !== false
                || stripos($l['company'] ?? '', $search) !== false
                || stripos($l['email'] ?? '', $search) !== false;
        });
    }

    usort($leads, function($a, $b) { return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'); });

    // Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(10, intval($_GET['per_page'] ?? 50)));
    $total = count($leads);
    $totalPages = max(1, ceil($total / $perPage));
    $paginatedLeads = array_slice(array_values($leads), ($page - 1) * $perPage, $perPage);

    respond([
        'success' => true,
        'leads' => $paginatedLeads,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $totalPages],
        'requisitions' => $admin['requisitions'] ?? []
    ]);
    break;

case 'lead':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    
    if ($method === 'GET' && isset($_GET['id'])) {
        foreach ($userData['leads'] as $l) { if ($l['id'] === $_GET['id']) respond(['success' => true, 'lead' => $l]); }
        // Admins can be notified about (and need to open) a lead owned by another
        // rep — e.g. the Aircall missed-call notification. Fall back to a
        // cross-user lookup for admins only.
        if (!empty($user['is_admin']) || !empty($user['is_super_admin'])) {
            foreach (getUsers() as $u) {
                if ($u['id'] === $user['id']) continue;
                foreach (dbLoadLeadsByOwner($u['id']) as $l) {
                    if ($l['id'] === $_GET['id']) respond(['success' => true, 'lead' => $l, 'owner_id' => $u['id'], 'owner_name' => $u['name'] ?? $u['email']]);
                }
            }
        }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'POST') {
        // Validate fields before creating lead
        $email = trim($input['email'] ?? '');
        $linkedin = trim($input['linkedin'] ?? '');
        $website = trim($input['website'] ?? '');

        // Email validation (if provided)
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'error' => 'Invalid email format. Please enter a valid email address.'], 400);
        }

        // LinkedIn URL validation (if provided)
        if (!empty($linkedin) && stripos($linkedin, 'linkedin.com') === false) {
            respond(['success' => false, 'error' => 'Invalid LinkedIn URL. Please enter a valid LinkedIn profile URL.'], 400);
        }

        // Website URL validation (if provided)
        if (!empty($website) && !preg_match('/^https?:\/\/|^www\./i', $website)) {
            // Auto-prepend https:// if missing
            $website = 'https://' . $website;
        }

        $source = normalizeLeadSource($input['source'] ?? 'manual');
        $isWarm = !empty($input['warm']) || (($input['urgency_flag'] ?? '') === 'warm');
        $requestedStage = trim($input['stage'] ?? '');
        $initialStage = legacyStatusToStage($requestedStage ?: initialStageForSource($source, $isWarm));

        $lead = [
            'id' => generateId('lead_'),
            'first_name' => sanitizeInput($input['first_name'] ?? ''),
            'last_name' => sanitizeInput($input['last_name'] ?? ''),
            'email' => $email,
            'phone' => sanitizeInput($input['phone'] ?? ''),
            'company' => sanitizeInput($input['company'] ?? ''),
            'title' => sanitizeInput($input['title'] ?? ''),
            'industry' => sanitizeInput($input['industry'] ?? ''),
            'country' => sanitizeInput($input['country'] ?? ''),
            'website' => $website,
            'linkedin' => $linkedin,
            'company_size' => sanitizeInput($input['company_size'] ?? ''),
            'notes' => sanitizeInput($input['notes'] ?? ''),
            'enrichment' => '',
            'requisitions' => null,
            'source' => $source,
            'source_detail' => sanitizeInput($input['source_detail'] ?? ''),
            'assigned_to' => sanitizeInput($input['assigned_to'] ?? ''),
            'stage' => $initialStage,
            'status' => stageToLegacyStatus($initialStage),
            'stage_entered_at' => date('c'),
            'stage_history' => [],
            'urgency_flag' => $source === 'inbound' ? 'high' : sanitizeInput($input['urgency_flag'] ?? 'normal'),
            'call_history' => [],
            'rejection_reason' => '',
            'consultation_type' => sanitizeInput($input['consultation_type'] ?? ''),
            'emails_sent' => 0,
            'last_email_type' => null,
            'last_action' => 'created',
            'last_action_at' => date('c'),
            'email_history' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),

            // === INTELLIGENCE STAGE TRACKING ===
            'intelligence_stage' => 1,
            'stage_history' => [],

            // === STAGE 1: ACCOUNT INTELLIGENCE ===
            'account_intelligence' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'company_profile' => [
                    'description' => '',
                    'key_products_services' => [],
                    'market_position' => '',
                    'growth_stage' => '',
                    'business_model' => '',
                    'revenue_model' => ''
                ],
                'operating_environment' => [
                    'tech_stack_assumptions' => [],
                    'system_constraints' => [],
                    'operational_complexity' => '',
                    'geographic_footprint' => ''
                ],
                'trigger_signals' => [
                    'growth_signals' => [],
                    'cost_pressure_signals' => [],
                    'regulatory_signals' => [],
                    'competitive_signals' => [],
                    'technology_signals' => []
                ],
                'industry_context' => [
                    'sector_challenges' => [],
                    'market_trends' => [],
                    'competitive_landscape' => ''
                ],
                'sources' => []
            ],

            // === STAGE 2: PERSONA INTELLIGENCE ===
            'persona_intelligence' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'persona_profile' => [
                    'role_category' => '',
                    'function' => '',
                    'seniority_level' => ''
                ],
                'kpis' => [
                    'primary_metrics' => [],
                    'secondary_metrics' => [],
                    'time_horizons' => ''
                ],
                'decision_profile' => [
                    'authority_level' => '',
                    'budget_influence' => '',
                    'buying_stage' => ''
                ],
                'objection_profile' => [
                    'likely_objections' => [],
                    'risk_tolerance' => ''
                ],
                'success_drivers' => [
                    'career_motivations' => [],
                    'professional_goals' => [],
                    'personal_wins' => []
                ]
            ],

            // === STAGE 3: PAIN HYPOTHESIS ===
            'pain_hypothesis' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'hypotheses' => [],
                'commercial_impact' => [
                    'quantified_impact' => '',
                    'impact_timeframe' => '',
                    'impact_confidence' => ''
                ],
                'value_levers' => [],
                'alignment_check' => [
                    'company_pain_fit' => 0,
                    'persona_pain_fit' => 0,
                    'solution_relevance' => 0
                ]
            ],

            // === STAGE 4: FIT SCORING ===
            'fit_score' => [
                'completed' => false,
                'completed_at' => null,
                'icp_fit' => ['score' => 0, 'factors' => [], 'disqualifiers' => []],
                'authority_score' => ['score' => 0, 'rationale' => ''],
                'urgency_score' => ['score' => 0, 'signals' => []],
                'commercial_potential' => ['score' => 0, 'deal_size_estimate' => ''],
                'complexity_score' => ['score' => 0, 'factors' => []],
                'overall_score' => 0,
                'overall_grade' => '',
                'outreach_eligibility' => '',
                'recommended_action' => ''
            ],

            // === STAGE 5: OUTREACH DATA ===
            'outreach_data' => [
                'unlocked' => false,
                'unlocked_at' => null,
                'unlock_grade' => '',
                'channels_enabled' => [],
                'sequence_stage' => 0,
                'email_history' => [],
                'call_history' => [],
                'linkedin_history' => []
            ]
        ];
        if (!$lead['email']) respond(['success' => false, 'error' => 'Email required'], 400);
        $lead = normalizeLeadForMapping($lead);
        logActivity($lead, 'created', 'Lead added');
        $userData['leads'][] = $lead;
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'lead' => $lead], 201);
    }
    
    if ($method === 'PUT' && isset($_GET['id'])) {
        // Validate fields before updating
        if (isset($input['email']) && !empty($input['email'])) {
            if (!filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL)) {
                respond(['success' => false, 'error' => 'Invalid email format. Please enter a valid email address.'], 400);
            }
        }
        if (isset($input['linkedin']) && !empty($input['linkedin'])) {
            if (stripos($input['linkedin'], 'linkedin.com') === false) {
                respond(['success' => false, 'error' => 'Invalid LinkedIn URL. Please enter a valid LinkedIn profile URL.'], 400);
            }
        }
        // Auto-prepend https:// to website if missing
        if (isset($input['website']) && !empty($input['website'])) {
            if (!preg_match('/^https?:\/\/|^www\./i', $input['website'])) {
                $input['website'] = 'https://' . $input['website'];
            }
        }

        foreach ($userData['leads'] as &$lead) {
            if ($lead['id'] === $_GET['id']) {
                foreach (['first_name', 'last_name', 'email', 'phone', 'company', 'title', 'industry', 'country', 'website', 'linkedin', 'company_size', 'notes', 'enrichment', 'requisitions', 'source', 'source_detail', 'assigned_to', 'urgency_flag', 'rejection_reason', 'consultation_type'] as $f) {
                    // requisitions is normally an array/object, not a string —
                    // trim() on it fatals with an uncaught TypeError in PHP 8,
                    // silently aborting the whole request with an empty
                    // response (matches a prior production incident in
                    // error_log: "trim(): ... string given, array given").
                    if (isset($input[$f])) $lead[$f] = is_string($input[$f]) ? trim($input[$f]) : $input[$f];
                }
                $requestedStage = trim($input['stage'] ?? $input['status'] ?? '');
                if ($requestedStage !== '') {
                    $prevStage = getLeadStage($lead);
                    setLeadStage($lead, $requestedStage, 'manual_update', $user['id']);
                    if ($prevStage !== $requestedStage) {
                        logActivity($lead, 'stage_change', 'Stage changed to ' . $requestedStage);
                    }
                }
                $lead = normalizeLeadForMapping($lead);
                $lead['updated_at'] = date('c');
                saveUserData($user['id'], $userData);
                respond(['success' => true, 'lead' => $lead]);
            }
        }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = $_GET['id'];
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $id) {
                $l['deleted_at'] = date('c');
                $l['deleted_by'] = $user['id'];
                logActivity($l, 'deleted', 'Moved to trash');
                break;
            }
        }
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'message' => 'Lead moved to trash']);
    }
    break;

case 'permanent-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['id'] ?? '';
    // Only allow permanent delete on trashed leads
    $found = false;
    foreach ($userData['leads'] as $l) {
        if ($l['id'] === $leadId) {
            if (empty($l['deleted_at'])) respond(['success' => false, 'error' => 'Lead must be in trash first'], 400);
            $found = true;
            break;
        }
    }
    if (!$found) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $userData['leads'] = array_values(array_filter($userData['leads'], function($l) use ($leadId) {
        return $l['id'] !== $leadId;
    }));
    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;

case 'restore-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['id'] ?? '';

    foreach ($userData['leads'] as &$l) {
        if ($l['id'] === $leadId) {
            unset($l['deleted_at']);
            unset($l['deleted_by']);
            $l['updated_at'] = date('c');
            logActivity($l, 'restored', 'Restored from trash');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'message' => 'Lead restored']);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'empty-trash':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $userData['leads'] = array_values(array_filter($userData['leads'], function($l) {
        return empty($l['deleted_at']);
    }));
    saveUserData($user['id'], $userData);
    respond(['success' => true, 'message' => 'Trash emptied']);
    break;

case 'save-requisitions':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $requisitions = $input['requisitions'] ?? null;
    
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['requisitions'] = $requisitions;
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'save-call-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $outcome = $input['outcome'] ?? '';
            $outcomeConfig = macktilesCallOutcomeConfig($outcome);
            if (!$outcomeConfig) {
                respond(['success' => false, 'error' => 'Invalid call outcome'], 400);
            }
            $nextAction = $input['next_action'] ?? $outcomeConfig['next_action'];
            $lead['call_outcome'] = $outcome;
            $lead['requisitions'] = $input['requisitions'] ?? $lead['requisitions'];
            $lead['call_notes'] = $input['notes'] ?? '';
            $lead['next_action'] = $nextAction;
            $lead['followup_date'] = $input['followup_date'] ?? null;
            if ($outcome === 'not_right_time_park_90' && empty($lead['followup_date'])) {
                $lead['followup_date'] = date('Y-m-d', strtotime('+90 days'));
            }
            if (in_array($outcome, ['not_interested', 'wrong_number', 'not_right_time_park_90'])) {
                $lead['rejection_reason'] = $outcomeConfig['label'];
                $lead['disqualified_reason'] = $outcomeConfig['label'];
            }
            if ($outcome === 'consultation_booked') {
                $lead['consultation_type'] = $input['consultation_type'] ?? $lead['consultation_type'] ?? 'phone consultation';
            }
            // macktilesCallOutcomeConfig() is the outcome's default stage, but
            // the rep's chosen next action can deliberately override it (e.g.
            // outcome alone would land at 'engaged', but they picked "Book
            // Consultation" or "Nurture / Park" as what actually happens
            // next) — mirrors the intent the frontend used to compute
            // client-side. A client-supplied stage/status beyond that is
            // ignored so a stale or hand-mirrored value can't override this.
            $targetStage = $outcomeConfig['stage'];
            if ($nextAction === 'consultation_booked') $targetStage = 'consultation_booked';
            elseif ($nextAction === 'nurture_parked') $targetStage = 'nurture_parked';
            setLeadStage($lead, $targetStage, 'call_outcome:' . $outcome, $user['id']);
            $lead['last_action'] = 'call_logged';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            $callHistory = $lead['call_history'] ?? [];
            $pendingIndex = null;
            for ($i = count($callHistory) - 1; $i >= 0; $i--) {
                if (($callHistory[$i]['status'] ?? '') === 'pending') {
                    $pendingIndex = $i;
                    break;
                }
            }
            $entry = [
                'id' => $pendingIndex !== null ? $callHistory[$pendingIndex]['id'] : 'call_' . bin2hex(random_bytes(8)),
                'started_at' => $pendingIndex !== null ? ($callHistory[$pendingIndex]['started_at'] ?? date('c')) : ($lead['call_started_at'] ?? date('c')),
                'completed_at' => date('c'),
                'status' => 'completed',
                'outcome' => $outcome,
                'outcome_label' => $outcomeConfig['label'],
                'notes' => $lead['call_notes'],
                'rep_id' => $user['id'],
                'rep_name' => $user['name'] ?? $user['email']
            ];
            if ($pendingIndex !== null) $callHistory[$pendingIndex] = $entry;
            else $callHistory[] = $entry;
            $lead['call_history'] = $callHistory;
            logActivity($lead, 'call_logged', 'Call outcome: ' . ($outcomeConfig['label'] ?? $outcome));
            saveUserData($user['id'], $userData);

            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'update-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $ownerId = resolveLeadOwnerId($user, $input['owner_id'] ?? null);
    $userData = getUserData($ownerId);
    $leadId = $input['id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Update allowed fields
            $allowedFields = ['call_anchor', 'email_skipped', 'followup_date', 'notes', 'source', 'source_detail', 'assigned_to', 'urgency_flag', 'rejection_reason', 'consultation_type'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $lead[$field] = $input[$field];
                }
            }
            $requestedStage = trim($input['stage'] ?? $input['status'] ?? '');
            if ($requestedStage !== '') {
                $prevStage = getLeadStage($lead);
                setLeadStage($lead, $requestedStage, 'manual_update', $user['id']);
                if ($prevStage !== $requestedStage) {
                    logActivity($lead, 'stage_change', 'Stage changed to ' . $requestedStage);
                }
            }
            $lead = normalizeLeadForMapping($lead);
            $lead['updated_at'] = date('c');
            saveUserData($ownerId, $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'import':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $csvData = $input['data'] ?? [];
    $skipDuplicates = $input['skip_duplicates'] ?? true;
    $source = normalizeLeadSource($input['source'] ?? 'firmable');

    if (!is_array($csvData) || empty($csvData)) respond(['success' => false, 'error' => 'No data provided'], 400);

    // Build sets for duplicate detection
    $existingEmails = [];
    $existingZohoIds = [];
    $existingNameKeys = [];
    foreach ($userData['leads'] as $lead) {
        if (!empty($lead['deleted_at'])) continue;
        if (!empty($lead['email'])) $existingEmails[strtolower($lead['email'])] = true;
        if (!empty($lead['zoho_id'])) $existingZohoIds[$lead['zoho_id']] = true;
        if (empty($lead['email']) && (!empty($lead['first_name']) || !empty($lead['last_name']))) {
            $existingNameKeys[strtolower(($lead['first_name']??'').'|'.($lead['last_name']??'').'|'.($lead['company']??''))] = true;
        }
    }

    $imported = 0;
    $skipped = 0;
    $duplicates = [];

    foreach ($csvData as $row) {
        if (!is_array($row)) continue;
        $email = trim(str_replace(['"', "'"], '', $row['email'] ?? $row['Email'] ?? $row['EMAIL'] ?? ''));
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        // Must have at least a name, email, company or phone to be a valid lead
        $firstName = trim($row['first_name'] ?? $row['First name'] ?? $row['firstname'] ?? '');
        $lastName = trim($row['last_name'] ?? $row['Last name'] ?? $row['lastname'] ?? '');
        $company = trim($row['company'] ?? $row['Company'] ?? $row['company_name'] ?? $row['Company name'] ?? '');
        $phone = trim($row['phone'] ?? $row['Phone'] ?? $row['mobile'] ?? $row['Mobile'] ?? '');
        if (!$email && !$firstName && !$lastName && !$company && !$phone) continue;

        // Extract Zoho ID if present (common Zoho export fields)
        $zohoId = trim($row['zoho_id'] ?? $row['ZOHO_ID'] ?? $row['Record Id'] ?? $row['RECORDID'] ?? $row['id'] ?? '');

        // Check for duplicates
        $isDuplicate = false;
        $duplicateReason = '';

        if ($skipDuplicates) {
            // Check by Zoho ID first (most reliable)
            if (!empty($zohoId) && isset($existingZohoIds[$zohoId])) {
                $isDuplicate = true;
                $duplicateReason = 'zoho_id';
            }
            // Then check by email (only if email exists)
            elseif (!empty($email) && isset($existingEmails[strtolower($email)])) {
                $isDuplicate = true;
                $duplicateReason = 'email';
            }
            // Check by name + company if no email
            elseif (empty($email) && (!empty($firstName) || !empty($lastName))) {
                $nameCompanyKey = strtolower($firstName . '|' . $lastName . '|' . $company);
                if (isset($existingNameKeys[$nameCompanyKey])) {
                    $isDuplicate = true;
                    $duplicateReason = 'name_company';
                }
            }
        }

        if ($isDuplicate) {
            $skipped++;
            $duplicates[] = ['email' => $email, 'reason' => $duplicateReason];
            continue;
        }

        $rowSource = normalizeLeadSource($row['source'] ?? $row['Source'] ?? $source);
        $warm = !empty($row['warm']) || stripos($row['lead_temperature'] ?? $row['Lead Temperature'] ?? '', 'warm') !== false;
        $initialStage = initialStageForSource($rowSource, $warm);

        // Create new lead
        $newLead = array_merge([
            'id' => generateId('lead_'),
            'first_name' => sanitizeInput($row['first_name'] ?? $row['First Name'] ?? $row['firstname'] ?? $row['First_Name'] ?? ''),
            'last_name' => sanitizeInput($row['last_name'] ?? $row['Last Name'] ?? $row['lastname'] ?? $row['Last_Name'] ?? ''),
            'email' => $email,
            'phone' => sanitizeInput($row['phone'] ?? $row['Phone'] ?? $row['phone_number'] ?? $row['Mobile'] ?? $row['Phone_Number'] ?? ''),
            'company' => sanitizeInput($row['company'] ?? $row['Company'] ?? $row['company_name'] ?? $row['Account_Name'] ?? $row['Company_Name'] ?? ''),
            'title' => sanitizeInput($row['title'] ?? $row['Title'] ?? $row['job_title'] ?? $row['Designation'] ?? ''),
            'industry' => sanitizeInput($row['industry'] ?? $row['Industry'] ?? ''),
            'country' => sanitizeInput($row['country'] ?? $row['Country'] ?? $row['location'] ?? $row['Mailing_Country'] ?? ''),
            'website' => trim($row['website'] ?? $row['Website'] ?? $row['Company_Website'] ?? ''),
            'linkedin' => trim($row['linkedin'] ?? $row['LinkedIn'] ?? $row['LinkedIn_URL'] ?? ''),
            'company_size' => sanitizeInput($row['company_size'] ?? $row['employees'] ?? $row['No_of_Employees'] ?? ''),
            'notes' => sanitizeInput($row['notes'] ?? $row['Notes'] ?? $row['Description'] ?? ''),
            'enrichment' => '',
            'requisitions' => null,
            'source' => $rowSource,
            'source_detail' => sanitizeInput($row['source_detail'] ?? $row['Source Detail'] ?? $row['Lead_Source'] ?? $row['lead_source'] ?? ''),
            'assigned_to' => sanitizeInput($row['assigned_to'] ?? $row['Assigned To'] ?? ''),
            'stage' => $initialStage,
            'status' => stageToLegacyStatus($initialStage),
            'stage_entered_at' => date('c'),
            'stage_history' => [],
            'urgency_flag' => $rowSource === 'inbound' ? 'high' : 'normal',
            'call_history' => [],
            'rejection_reason' => '',
            'consultation_type' => sanitizeInput($row['consultation_type'] ?? $row['Consultation Type'] ?? ''),
            'emails_sent' => 0,
            'last_email_type' => null,
            'last_action' => 'imported',
            'last_action_at' => date('c'),
            'email_history' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
            // Zoho-specific fields for tracking
            'zoho_id' => $zohoId ?: null,
            'import_source' => $rowSource,
            'imported_at' => date('c'),
            // Additional Zoho fields if present
            'lead_source' => trim($row['Lead_Source'] ?? $row['lead_source'] ?? ''),
            'lead_status_zoho' => trim($row['Lead_Status'] ?? $row['Status'] ?? ''),
            'annual_revenue' => trim($row['Annual_Revenue'] ?? $row['annual_revenue'] ?? ''),
            'rating' => trim($row['Rating'] ?? $row['rating'] ?? '')
        ], getDefaultStageFields());

        $newLead = normalizeLeadForMapping($newLead);
        logActivity($newLead, 'created', 'Lead imported');

        $userData['leads'][] = $newLead;

        // Track for duplicate detection in this batch
        if (!empty($email)) $existingEmails[strtolower($email)] = true;
        if (empty($email) && (!empty($firstName) || !empty($lastName))) {
            $existingNameKeys[strtolower($firstName . '|' . $lastName . '|' . $company)] = true;
        }
        if (!empty($zohoId)) $existingZohoIds[$zohoId] = true;

        $imported++;
    }

    saveUserData($user['id'], $userData);

    $response = [
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'message' => "Imported $imported leads" . ($skipped > 0 ? ", skipped $skipped duplicates" : "")
    ];

    if ($skipped > 0 && count($duplicates) <= 10) {
        $response['duplicates'] = $duplicates;
    }

    respond($response);
    break;

case 'delete-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $id = $input['id'] ?? '';
    if (!$id) respond(['success' => false, 'error' => 'No ID'], 400);
    foreach ($userData['leads'] as &$l) {
        if ($l['id'] === $id) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash');
            saveUserData($user['id'], $userData);
            respond(['success' => true]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// Moves a lead from one rep's pipeline to another's — admin-only, since it
// crosses account boundaries. The caller doesn't need to already own the
// lead (an admin reassigning between two OTHER reps is a normal case), so
// this searches every user's leads rather than just the caller's own.
case 'reassign-lead':
    if ($method !== 'POST') break;
    $user = requireAdmin();
    $leadId = $input['lead_id'] ?? '';
    $newOwnerId = $input['new_owner_id'] ?? '';
    if (!$leadId || !$newOwnerId) respond(['success' => false, 'error' => 'lead_id and new_owner_id are required'], 400);

    $allUsers = getUsers();
    $newOwner = null;
    foreach ($allUsers as $u) { if ($u['id'] === $newOwnerId) { $newOwner = $u; break; } }
    if (!$newOwner) respond(['success' => false, 'error' => 'Target user not found'], 404);

    $currentOwnerId = null;
    $lead = null;
    foreach ($allUsers as $u) {
        foreach (dbLoadLeadsByOwner($u['id']) as $l) {
            if ($l['id'] === $leadId) { $currentOwnerId = $u['id']; $lead = $l; break 2; }
        }
    }
    if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);
    if ($currentOwnerId === $newOwnerId) respond(['success' => false, 'error' => 'Lead is already assigned to that user'], 400);

    $ok = dbReassignLead($leadId, $newOwnerId);
    if (!$ok) respond(['success' => false, 'error' => 'Reassignment failed'], 500);

    // Reload under the new owner so logActivity()/saveUserData() write into
    // the right place, and record who moved it and from where for the trail.
    $newOwnerData = getUserData($newOwnerId);
    foreach ($newOwnerData['leads'] as &$l) {
        if ($l['id'] === $leadId) {
            $prevOwner = null;
            foreach ($allUsers as $u) { if ($u['id'] === $currentOwnerId) { $prevOwner = $u; break; } }
            $fromName = $prevOwner['name'] ?? 'Unassigned';
            logActivity($l, 'reassigned', "Reassigned from {$fromName} to {$newOwner['name']} (by {$user['name']})");
            $l['updated_at'] = date('c');
            saveUserData($newOwnerId, $newOwnerData);
            respond(['success' => true, 'lead' => $l]);
        }
    }
    respond(['success' => true]);
    break;

case 'bulk-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $ids = $input['ids'] ?? [];
    $count = 0;
    foreach ($userData['leads'] as &$l) {
        if (in_array($l['id'], $ids)) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash (bulk delete)');
            $count++;
        }
    }
    saveUserData($user['id'], $userData);
    respond(['success' => true, 'deleted' => $count]);
    break;

case 'export':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $ids = $input['ids'] ?? [];
    $leadsToExport = $userData['leads'] ?? [];
    if (!empty($ids)) $leadsToExport = array_filter($leadsToExport, function($l) use ($ids) { return in_array($l['id'], $ids); });
    
    $exportData = array_map(function($l) {
        $req = $l['requisitions'] ?? [];
        return [
            'First Name' => $l['first_name'] ?? '', 'Last Name' => $l['last_name'] ?? '',
            'Email' => $l['email'] ?? '', 'Phone' => $l['phone'] ?? '',
            'Company' => $l['company'] ?? '', 'Title' => $l['title'] ?? '',
            'Industry' => $l['industry'] ?? '', 'Country' => $l['country'] ?? '',
            'Website' => $l['website'] ?? '', 'LinkedIn' => $l['linkedin'] ?? '',
            'Company Size' => $l['company_size'] ?? '', 'Notes' => $l['notes'] ?? '',
            'Lead Status' => ucfirst(str_replace('_', ' ', $l['status'] ?? 'new')),
            'Emails Sent' => $l['emails_sent'] ?? 0,
            'Business Type' => is_array($req['business_type'] ?? null) ? implode(', ', $req['business_type']) : ($req['business_type'] ?? ''),
            'Team Size' => $req['team_size'] ?? '',
            'Project Volume' => $req['project_volume'] ?? '',
            'Tile Sourcing' => is_array($req['tile_sourcing'] ?? null) ? implode(', ', $req['tile_sourcing']) : ($req['tile_sourcing'] ?? ''),
            'Pain Point' => $req['pain_point'] ?? '',
            'Market Segment' => $req['market_segment'] ?? '',
            'Location' => is_array($req['location'] ?? null) ? implode(', ', $req['location']) : ($req['location'] ?? ''),
            'Req Notes' => $req['other_notes'] ?? ''
        ];
    }, array_values($leadsToExport));
    respond(['success' => true, 'data' => $exportData, 'count' => count($exportData)]);
    break;

case 'export-csv':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadIds = $input['lead_ids'] ?? null;

    $exportLeads = array_filter($userData['leads'] ?? [], function($l) {
        return empty($l['deleted_at']);
    });
    if ($leadIds) {
        $exportLeads = array_filter($exportLeads, function($l) use ($leadIds) {
            return in_array($l['id'], $leadIds);
        });
    }

    $csvFields = ['first_name','last_name','email','phone','company','title','industry','country','website','linkedin','company_size','status','fit_grade','fit_score','emails_sent','calls_made','call_outcome','notes','created_at','updated_at'];

    $rows = [];
    $rows[] = $csvFields;
    foreach (array_values($exportLeads) as $lead) {
        $row = [];
        foreach ($csvFields as $field) {
            $value = $lead[$field] ?? '';
            // fit_score starts life as a nested scoring-breakdown object
            // (icp_fit/authority_score/etc.) before a lead is ever scored,
            // and only becomes a plain number once calculateLeadGrade() runs
            // — an unscored lead's array shape stringified to the literal
            // word "Array" in every CSV row via fputcsv().
            if ($field === 'fit_score' && is_array($value)) {
                $value = $value['overall_score'] ?? '';
            }
            $row[] = $value;
        }
        $rows[] = $row;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');
    $fp = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
    break;

case 'generate-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $lead = $input['lead'] ?? [];
    $emailType = $input['type'] ?? 'initial';
    $previousEmail = $input['previous_email'] ?? '';
    $enrichment = $input['enrichment'] ?? $lead['enrichment'] ?? '';

    // Stage gating warning (soft - we still allow but warn)
    $stageWarning = null;
    if (empty($enrichment) && $emailType === 'initial') {
        $stageWarning = 'This lead has not been researched. For best results, research the lead first to get personalized insights.';
    }
    $includeSignature = $input['include_signature'] ?? true;
    
    // Get email history for context
    $emailHistory = $lead['email_history'] ?? [];
    $lastSentEmail = '';
    if (!empty($emailHistory)) {
        $lastSentEmail = $emailHistory[count($emailHistory) - 1]['content'] ?? '';
    }
    
    // Use provided previous email or fall back to last sent
    if (empty($previousEmail) && !empty($lastSentEmail)) {
        $previousEmail = $lastSentEmail;
    }
    
    // Use new template-based generator
    $res = generateEmailContent(
        $provider,
        $apiKey,
        $lead,
        $emailType,
        $input['custom_instructions'] ?? '',
        $enrichment,
        $userData['settings'] ?? [],
        $previousEmail,
        $includeSignature,
        $admin,
        $user['name'] ?? ''
    );
    
    if ($res['success']) {
        $leadId = $lead['id'] ?? '';
        if ($leadId) {
            foreach ($userData['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['last_action'] = 'email_generated';
                    $l['last_action_at'] = date('c');
                    $l['last_email_type'] = $emailType;
                    break;
                }
            }
            saveUserData($user['id'], $userData);
        }
        $response = ['success' => true, 'email' => $res['content']];
        if ($stageWarning) {
            $response['warning'] = $stageWarning;
        }
        respond($response);
    }
    respond(['success' => false, 'error' => $res['error'] ?? 'Generation failed'], 500);
    break;

case 'generate-call-pitch':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $name = $input['name'] ?? '';
    $title = $input['title'] ?? '';
    $company = $input['company'] ?? '';
    $industry = $input['industry'] ?? '';
    $pitchType = $input['pitch_type'] ?? 'cold';
    $customInstructions = $input['custom_instructions'] ?? '';
    $leadId = $input['lead_id'] ?? '';

    if (!$name) respond(['success' => false, 'error' => 'Name is required'], 400);

    // Resolve which account owns this lead — admins generating a pitch for a
    // lead from the team-wide queue may not be its owner, so the save target
    // must follow the lead, not default to the caller. Mirrors enrich-lead.
    $ownerId = $user['id'];
    $userData = getUserData($ownerId);
    $leadData = null;
    if ($leadId) {
        foreach ($userData['leads'] as $l) { if ($l['id'] === $leadId) { $leadData = $l; break; } }
        if (!$leadData && (!empty($user['is_admin']) || !empty($user['is_super_admin']))) {
            foreach (getUsers() as $u) {
                if ($u['id'] === $user['id']) continue;
                foreach (dbLoadLeadsByOwner($u['id']) as $l) {
                    if ($l['id'] === $leadId) { $ownerId = $u['id']; $userData = getUserData($ownerId); $leadData = $l; break 2; }
                }
            }
        }
    }

    $res = generateCallPitch(
        $provider,
        $apiKey,
        $name,
        $title,
        $company,
        $industry,
        $pitchType,
        $customInstructions,
        $userData['settings'] ?? [],
        $leadData,
        $admin,
        $user['name'] ?? ''
    );

    if (!$res['success']) respond(['success' => false, 'error' => $res['error'] ?? 'Generation failed'], 500);

    // Persist onto the lead so it's still there next time the modal opens —
    // same durability as enrichment/research.
    $updatedLead = $leadData;
    if ($leadId && $leadData) {
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $leadId) {
                $l['call_pitch'] = [
                    'title' => $res['title'],
                    'pitch' => $res['pitch'],
                    'pitch_type' => $pitchType,
                    'generated_at' => date('c'),
                ];
                $l['updated_at'] = date('c');
                $updatedLead = $l;
                break;
            }
        }
        unset($l);
        saveUserData($ownerId, $userData);
    }

    respond(['success' => true, 'title' => $res['title'], 'pitch' => $res['pitch'], 'lead' => $updatedLead]);
    break;

case 'enrich-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $lead = $input['lead'] ?? [];
    // Resolve which account actually owns this lead — admins researching a
    // lead from the team-wide queue may not be its owner, so the save target
    // must follow the lead, not default to the caller.
    $ownerId = $user['id'];
    $userData = getUserData($ownerId);
    $ownsIt = false;
    foreach ($userData['leads'] as $l) { if ($l['id'] === ($lead['id'] ?? null)) { $ownsIt = true; break; } }
    if (!$ownsIt && (!empty($user['is_admin']) || !empty($user['is_super_admin']))) {
        foreach (getUsers() as $u) {
            if ($u['id'] === $user['id']) continue;
            foreach (dbLoadLeadsByOwner($u['id']) as $l) {
                if ($l['id'] === ($lead['id'] ?? null)) { $ownerId = $u['id']; $userData = getUserData($ownerId); $ownsIt = true; break 2; }
            }
        }
    }
    // Reject BEFORE the LLM call, not just at save time — otherwise a caller
    // with no real access to this lead could still get real (billed) research
    // content back in the response, even though it would never actually save
    // anywhere. The client always sends a real lead's id, so failing to find
    // it among the caller's own or (if admin) any other user's leads means
    // they have no business researching it.
    if (!$ownsIt) respond(['success' => false, 'error' => 'Lead not found or not accessible'], 403);

    $prompt = buildResearchPrompt($lead);
    $res = callLLM($provider, $apiKey, $prompt, $admin);
    
    if ($res['success']) {
        // Clean up the response - extract JSON if wrapped in markdown
        $content = $res['content'];
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $content = trim($matches[1]);
        }
        
        // Parse the JSON to extract scoring and sources
        $parsed = json_decode($content, true);
        $researchScore = null;
        $sources = [];
        
        if ($parsed) {
            // Extract research_score
            if (isset($parsed['research_score'])) {
                $researchScore = $parsed['research_score'];
            }
            // Extract sources
            if (isset($parsed['sources']) && is_array($parsed['sources'])) {
                $sources = $parsed['sources'];
            }
        }
        
        $leadId = $lead['id'] ?? '';
        $updatedLead = null;
        if ($leadId) {
            foreach ($userData['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['enrichment'] = $content;
                    $l['last_action'] = 'researched';
                    $l['last_action_at'] = date('c');
                    $l['updated_at'] = date('c');
                    if (in_array(getLeadStage($l), ['new_lead', 'research'])) {
                        setLeadStage($l, 'research', 'research_completed', $user['id']);
                    }

                    // Auto-calculate ICP grade using shared function
                    $gradeResult = calculateLeadGrade($l, $admin, $parsed);
                    $l['fit_grade'] = $gradeResult['grade'];
                    $l['fit_score'] = $gradeResult['score'];

                    // Auto-populate stage intelligence from enrichment
                    if ($parsed) {
                        // Stage 1: Account Intelligence
                        if (!empty($parsed['company_profile'])) {
                            if (!isset($l['account_intelligence'])) $l['account_intelligence'] = [];
                            $l['account_intelligence']['company_profile'] = $parsed['company_profile'];
                            $l['account_intelligence']['completed'] = true;
                            $l['account_intelligence']['completed_at'] = date('c');
                        }
                        if (!empty($parsed['industry_intelligence'])) {
                            if (!isset($l['account_intelligence'])) $l['account_intelligence'] = [];
                            $l['account_intelligence']['industry_context'] = [
                                'sector_challenges' => array_map(function($c) {
                                    return is_array($c) ? ($c['challenge'] ?? $c['text'] ?? reset($c) ?? '') : $c;
                                }, $parsed['industry_intelligence']['top_challenges'] ?? []),
                                'market_trends' => $parsed['industry_intelligence']['trends'] ?? [],
                                'competitive_landscape' => $parsed['industry_intelligence']['competitive_pressures'] ?? ''
                            ];
                            if (!empty($parsed['sources'])) {
                                $l['account_intelligence']['sources'] = $parsed['sources'];
                            }
                        }

                        // Stage 2: Persona Intelligence
                        if (!empty($parsed['prospect_analysis'])) {
                            if (!isset($l['persona_intelligence'])) $l['persona_intelligence'] = [];
                            $l['persona_intelligence']['kpis'] = [
                                'primary_metrics' => $parsed['prospect_analysis']['success_metrics'] ?? []
                            ];
                            $l['persona_intelligence']['decision_profile'] = [
                                'authority_level' => $parsed['prospect_analysis']['buying_power'] ?? '',
                                'responsibilities' => $parsed['prospect_analysis']['responsibilities'] ?? []
                            ];
                            $l['persona_intelligence']['completed'] = true;
                            $l['persona_intelligence']['completed_at'] = date('c');
                        }
                        if (!empty($parsed['sales_strategy']['objections'])) {
                            if (!isset($l['persona_intelligence'])) $l['persona_intelligence'] = [];
                            $l['persona_intelligence']['objection_profile'] = [
                                'likely_objections' => array_map(function($o) {
                                    return is_array($o) ? ($o['objection'] ?? $o['text'] ?? reset($o) ?? '') : $o;
                                }, $parsed['sales_strategy']['objections'])
                            ];
                        }

                        // Stage 3: Pain Hypothesis
                        if (!empty($parsed['prospect_analysis']['pain_points'])) {
                            if (!isset($l['pain_hypothesis'])) $l['pain_hypothesis'] = [];
                            $l['pain_hypothesis']['hypotheses'] = array_map(function($p) {
                                return is_array($p) ? $p : ['pain' => $p, 'evidence' => ''];
                            }, $parsed['prospect_analysis']['pain_points']);
                            $l['pain_hypothesis']['completed'] = true;
                            $l['pain_hypothesis']['completed_at'] = date('c');
                        }

                        // Mark all stages complete
                        $l['intelligence_stage'] = 4;
                    }

                    logActivity($l, 'researched', 'Lead researched (Grade ' . ($l['fit_grade'] ?? '?') . ')');
                    $updatedLead = $l;
                    break;
                }
            }
            saveUserData($ownerId, $userData);
        }
        respond([
            'success' => true,
            'enrichment' => $content, 
            'lead' => $updatedLead,
            'research_score' => $researchScore,
            'sources' => $sources
        ]);
    }
    respond(['success' => false, 'error' => $res['error']], 500);
    break;

case 'save-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $emailType = $input['type'] ?? 'initial';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Save both subject and content in email_history
            $lead['email_history'][] = [
                'type' => $emailType,
                'subject' => $input['subject'] ?? '',
                'content' => $input['content'] ?? '',
                'sent_at' => date('c')
            ];
            $lead['emails_sent'] = count($lead['email_history']);
            $lead['last_email_type'] = $emailType;
            $lead['last_action'] = 'email_sent';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            // Update canonical stage to email sent for cold outreach.
            if (in_array(getLeadStage($lead), ['new_lead', 'research', 'engaged'])) {
                setLeadStage($lead, 'email_sent', 'email_sent:' . $emailType, $user['id']);
            }
            logActivity($lead, 'email_sent', ucfirst(str_replace('_', ' ', $emailType)) . ' email sent');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'email-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $emailIndex = intval($input['email_index'] ?? -1);
    $outcome = $input['outcome'] ?? ''; // replied, bounced, no_response, meeting_booked

    $validOutcomes = ['replied', 'bounced', 'no_response', 'meeting_booked'];
    if (!in_array($outcome, $validOutcomes)) {
        respond(['success' => false, 'error' => 'Invalid outcome. Use: ' . implode(', ', $validOutcomes)], 400);
    }

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            if ($emailIndex >= 0 && isset($lead['email_history'][$emailIndex])) {
                $lead['email_history'][$emailIndex]['outcome'] = $outcome;
                $lead['email_history'][$emailIndex]['outcome_at'] = date('c');
            } else {
                // Apply to most recent email
                $lastIdx = count($lead['email_history']) - 1;
                if ($lastIdx >= 0) {
                    $lead['email_history'][$lastIdx]['outcome'] = $outcome;
                    $lead['email_history'][$lastIdx]['outcome_at'] = date('c');
                }
            }

            // Update lead status based on outcome
            if ($outcome === 'meeting_booked') {
                setLeadStage($lead, 'consultation_booked', 'email_outcome:meeting_booked', $user['id']);
                $lead['last_action'] = 'meeting_booked';
                logActivity($lead, 'stage_change', 'Meeting booked — moved to Consultation Booked');
            } elseif ($outcome === 'replied') {
                setLeadStage($lead, 'engaged', 'email_outcome:replied', $user['id']);
                $lead['last_action'] = 'email_replied';
                logActivity($lead, 'stage_change', 'Lead replied — moved to Engaged');
            }
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'start-call':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['call_started_at'] = date('c');
            $lead['calls_made'] = ($lead['calls_made'] ?? 0) + 1;
            $lead['last_action'] = 'call_started';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            $lead['call_history'] = $lead['call_history'] ?? [];
            $lead['call_history'][] = [
                'id' => 'call_' . bin2hex(random_bytes(8)),
                'started_at' => date('c'),
                'status' => 'pending',
                'rep_id' => $user['id'],
                'rep_name' => $user['name'] ?? $user['email']
            ];
            if (in_array(getLeadStage($lead), ['email_sent', 'research', 'new_lead'])) {
                setLeadStage($lead, 'call_attempted', 'call_started', $user['id']);
            }
            logActivity($lead, 'call_started', 'Call started');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'grade-override':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['grade_override'] = [
                'enabled' => true,
                'channel' => $input['channel'] ?? 'all',
                'reason' => $input['reason'] ?? '',
                'notes' => $input['notes'] ?? '',
                'overridden_by' => $user['name'] ?? $user['email'],
                'overridden_at' => date('c'),
                'original_grade' => $lead['fit_grade'] ?? ''
            ];
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'recalculate-grade':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Check if lead has enrichment data
            if (empty($lead['enrichment'])) {
                respond(['success' => false, 'error' => 'Lead has no research data. Research the lead first.'], 400);
            }

            // Calculate ICP grade using shared function
            $gradeResult = calculateLeadGrade($lead, $admin);
            $lead['fit_grade'] = $gradeResult['grade'];
            $lead['fit_score'] = $gradeResult['score'];
            $lead['updated_at'] = date('c');

            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead, 'grade' => $gradeResult['grade'], 'score' => $gradeResult['score']]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// Corrects calls_made when it's been inflated by dial attempts that never
// actually completed (e.g. the period where Aircall accepted dial requests
// but never connected — every click added +1 with no real call happening,
// and no call_history entry to reflect it). Admin-only, cross-owner via
// resolveLeadOwnerId(), since this is a rare manual cleanup action, not a
// routine rep-facing one.
case 'reset-call-count':
    if ($method !== 'POST') break;
    $user = requireAdmin();
    $ownerId = resolveLeadOwnerId($user, $input['owner_id'] ?? null);
    $userData = getUserData($ownerId);
    $leadId = $input['lead_id'] ?? '';
    if (!$leadId) respond(['success' => false, 'error' => 'lead_id is required'], 400);

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $realCallCount = count($lead['call_history'] ?? []);
            $lead['calls_made'] = $realCallCount;
            $lead['updated_at'] = date('c');
            logActivity($lead, 'call_count_corrected', "Call count corrected to match real call history ({$realCallCount})");
            saveUserData($ownerId, $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// ============ PROACTIVE INTELLIGENCE ENDPOINTS ============

case 'log-activity':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';
    $activityType = $input['type'] ?? '';
    $details = $input['details'] ?? null;

    if (!$activityType) {
        respond(['success' => false, 'error' => 'Activity type is required'], 400);
    }

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Add activity to the lead's activities array
            $lead['activities'] = logLeadActivity($lead, $activityType, $details);
            $lead['last_action'] = $activityType;
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            // Recalculate all scores
            $scores = recalculateAllLeadScores($lead, $admin);
            $lead = array_merge($lead, $scores);

            saveUserData($user['id'], $userData);
            respond([
                'success' => true,
                'lead' => $lead,
                'scores' => $scores
            ]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'recalculate-all-scores':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? null; // Optional: recalculate single lead

    $updated = 0;
    foreach ($userData['leads'] as &$lead) {
        // If lead_id provided, only update that lead
        if ($leadId && $lead['id'] !== $leadId) continue;

        // Recalculate scores
        $scores = recalculateAllLeadScores($lead, $admin);
        $lead = array_merge($lead, $scores);
        $updated++;

        // If single lead requested, respond immediately
        if ($leadId) {
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead, 'scores' => $scores]);
        }
    }

    saveUserData($user['id'], $userData);
    respond(['success' => true, 'updated' => $updated, 'message' => "Recalculated scores for $updated leads"]);
    break;

case 'dashboard-briefing':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $admin = getAdmin();
    $teamWide = !empty($user['is_admin']) && ($_GET['scope'] ?? '') === 'team';

    if ($teamWide) {
        // Admin team-wide view: pull every OTHER rep's leads (not the viewer's
        // own — "mine" and "team" are meant to be non-overlapping, not team
        // being a superset that includes yourself), recalculate + save each
        // back to its own owner (never cross-write into another user's bucket),
        // then tag each lead with who owns it so the queue/attention items can
        // show a rep name.
        $isSuperAdmin = $user['is_super_admin'] ?? false;
        $allUsers = getUsers();
        $teamUsers = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
        $teamUsers = array_values(array_filter($teamUsers, fn($u) => $u['id'] !== $user['id']));
        $leads = [];
        foreach ($teamUsers as $teamUser) {
            $teamUserData = getUserData($teamUser['id']);
            $teamLeads = $teamUserData['leads'] ?? [];
            foreach ($teamLeads as &$lead) {
                $scores = recalculateAllLeadScores($lead, $admin);
                $lead = array_merge($lead, $scores);
            }
            unset($lead);
            $teamUserData['leads'] = $teamLeads;
            saveUserData($teamUser['id'], $teamUserData);
            foreach ($teamLeads as $lead) {
                if (!empty($lead['deleted_at'])) continue;
                $lead['_owner_id'] = $teamUser['id'];
                $lead['_owner_name'] = $teamUser['name'] ?? 'User';
                $leads[] = $lead;
            }
        }
    } else {
        $userData = getUserData($user['id']);
        $leads = array_values(array_filter($userData['leads'] ?? [], fn($l) => empty($l['deleted_at'])));

        // First, recalculate all scores to ensure they're current
        foreach ($leads as &$lead) {
            $scores = recalculateAllLeadScores($lead, $admin);
            $lead = array_merge($lead, $scores);
        }
        unset($lead);
        // Save updated scores
        $userData['leads'] = $leads;
        saveUserData($user['id'], $userData);
    }

    // Group leads by temperature
    $temperatureGroups = [
        'on_fire' => [],
        'hot' => [],
        'warm' => [],
        'cold' => []
    ];

    foreach ($leads as $lead) {
        $temp = $lead['temperature'] ?? 'cold';
        if (isset($temperatureGroups[$temp])) {
            $temperatureGroups[$temp][] = [
                'id' => $lead['id'],
                'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
                'company' => $lead['company'] ?? '',
                'status' => $lead['status'] ?? 'new',
                'fit_grade' => $lead['fit_grade'] ?? '',
                'engagement_score' => $lead['engagement_score'] ?? 0,
                'velocity' => $lead['velocity'] ?? 'stalled',
                'days_since_activity' => calculateDaysSinceLastActivity($lead),
                'owner_id' => $lead['_owner_id'] ?? null,
                'owner_name' => $lead['_owner_name'] ?? null
            ];
        }
    }

    // Generate focus queue
    $focusQueueResult = generateFocusQueue($leads, $admin, 10);
    $focusQueue = $focusQueueResult['items'];
    $focusQueueTotal = $focusQueueResult['total'];

    // Find attention-needed leads
    $attentionNeeded = findAttentionNeeded($leads);

    // Generate summary stats
    $summary = [
        'total_leads' => count($leads),
        'on_fire_count' => count($temperatureGroups['on_fire']),
        'hot_count' => count($temperatureGroups['hot']),
        'warm_count' => count($temperatureGroups['warm']),
        'cold_count' => count($temperatureGroups['cold']),
        'callbacks_due' => count(array_filter($leads, function($l) {
            return !empty($l['followup_date']) && strtotime($l['followup_date']) <= strtotime('today');
        })),
        'overdue_count' => count(array_filter($leads, function($l) {
            return !empty($l['followup_date']) && strtotime($l['followup_date']) < strtotime('today');
        })),
        'emails_ready' => count(array_filter($leads, function($l) {
            return getLeadStage($l) === 'research';
        }))
    ];

    respond([
        'success' => true,
        'summary' => $summary,
        'temperature_groups' => $temperatureGroups,
        'focus_queue' => $focusQueue,
        'focus_queue_total' => $focusQueueTotal,
        'attention_needed' => $attentionNeeded,
        'team_scope' => $teamWide,
        'can_view_team' => !empty($user['is_admin'])
    ]);
    break;

case 'focus-queue':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leads = array_values(array_filter($userData['leads'] ?? [], fn($l) => empty($l['deleted_at'])));
    $limit = intval($_GET['limit'] ?? 10);

    $focusQueueResult = generateFocusQueue($leads, $admin, $limit);
    respond(['success' => true, 'focus_queue' => $focusQueueResult['items'], 'focus_queue_total' => $focusQueueResult['total']]);
    break;

case 'skip-focus-item':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadId = $input['lead_id'] ?? '';
    $duration = $input['duration'] ?? '1day'; // 1hour, 1day, 3days, 1week
    // Admins can skip an item from the team-wide queue that belongs to
    // another rep; everyone else can only skip their own leads.
    $ownerId = (!empty($user['is_admin']) && !empty($input['owner_id'])) ? $input['owner_id'] : $user['id'];

    $skipDurations = [
        '1hour' => '+1 hour',
        '1day' => '+1 day',
        '3days' => '+3 days',
        '1week' => '+1 week'
    ];

    $skipUntil = date('c', strtotime($skipDurations[$duration] ?? '+1 day'));

    $userData = getUserData($ownerId);
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['skipped_until'] = $skipUntil;
            $lead['updated_at'] = date('c');
            saveUserData($ownerId, $userData);
            respond(['success' => true, 'skipped_until' => $skipUntil]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'drop-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadId = $input['lead_id'] ?? '';
    $reason = $input['reason'] ?? '';
    // Admins can drop a lead from the team-wide queue that belongs to
    // another rep; everyone else can only drop their own leads.
    $ownerId = (!empty($user['is_admin']) && !empty($input['owner_id'])) ? $input['owner_id'] : $user['id'];
    $userData = getUserData($ownerId);

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            setLeadStage($lead, 'nurture_parked', 'drop_lead', $user['id']);
            $lead['disqualified_reason'] = $reason;
            $lead['rejection_reason'] = $reason;
            $lead['disqualified_at'] = date('c');
            $lead['last_action'] = 'disqualified';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            logActivity($lead, 'stage_change', 'Dropped' . ($reason ? " — {$reason}" : ''));
            saveUserData($ownerId, $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'next-best-action':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $_GET['lead_id'] ?? '';
    $useAI = ($_GET['use_ai'] ?? 'false') === 'true';

    foreach ($userData['leads'] as $lead) {
        if ($lead['id'] === $leadId) {
            if ($useAI) {
                $nba = generateNextBestAction($lead, $admin);
            } else {
                $daysSince = calculateDaysSinceLastActivity($lead);
                $nba = generateRuleBasedNBA($lead, $daysSince);
            }
            respond(['success' => true, 'recommendation' => $nba]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// ===== TEAM CHAT =====
case 'chat-channels':
    $user = requireAuth();
    if ($method === 'GET') {
        $channels = getChatChannels();
        $unread = getChatUnreadCounts($user['id']);
        $isAdmin = isChatAdmin($user);
        $channels = array_values(array_filter($channels, function($ch) use ($user, $isAdmin) {
            $members = $ch['members'] ?? [];
            return empty($members) || $isAdmin || in_array($user['id'], $members, true);
        }));
        foreach ($channels as &$ch) $ch['unread'] = $unread[$ch['id']] ?? 0;
        respond(['success' => true, 'channels' => $channels]);
    }
    if ($method === 'POST') {
        if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Only admins can create channels'], 403);
        $name = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'] ?? '')));
        if (!$name) respond(['success' => false, 'error' => 'Channel name required'], 400);
        $channels = getChatChannels();
        foreach ($channels as $ch) {
            if ($ch['name'] === $name) respond(['success' => false, 'error' => 'Channel already exists'], 400);
        }
        $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
        $newChannel = ['id' => 'channel_' . bin2hex(random_bytes(4)), 'name' => $name, 'description' => $input['description'] ?? '', 'members' => $members, 'created_by' => $user['id'], 'created_at' => date('c')];
        $channels[] = $newChannel;
        saveChatChannels($channels);
        respond(['success' => true, 'channel' => $newChannel]);
    }
    if ($method === 'DELETE') {
        if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Only admins can delete channels'], 403);
        $channelId = $input['id'] ?? '';
        if ($channelId === 'channel_general') respond(['success' => false, 'error' => 'Cannot delete the general channel'], 400);
        if (!$channelId) respond(['success' => false, 'error' => 'Channel ID required'], 400);
        $channels = array_values(array_filter(getChatChannels(), fn($ch) => $ch['id'] !== $channelId));
        saveChatChannels($channels);
        respond(['success' => true]);
    }
    break;

case 'chat-channel-members':
    $user = requireAuth();
    if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Admin only'], 403);
    if ($method === 'POST') {
        $channelId = $input['channel_id'] ?? '';
        $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
        $channels = getChatChannels();
        $found = false;
        foreach ($channels as &$ch) {
            if ($ch['id'] === $channelId) {
                $ch['members'] = $members;
                if (!empty($input['name'])) {
                    $newName = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'])));
                    if ($newName) $ch['name'] = $newName;
                }
                if (isset($input['description'])) $ch['description'] = trim($input['description']);
                $found = true;
                break;
            }
        }
        if (!$found) respond(['success' => false, 'error' => 'Channel not found'], 404);
        saveChatChannels($channels);
        respond(['success' => true]);
    }
    break;

case 'chat-messages':
    $user = requireAuth();
    $threadId = $_GET['thread'] ?? $input['thread'] ?? '';
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $threadId);

    if ($method === 'GET') {
        $messages = getChannelMessages($safe);
        $since = $_GET['since'] ?? null;
        if ($since) $messages = array_values(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $since));
        // Stamp last-read using the newest message actually served in THIS
        // response, not wall-clock now(). Using now() races a concurrent
        // sender: if their INSERT and this GET overlap, now() can land after
        // the new message's created_at even though it was never actually
        // returned here, which silently suppresses that message's push
        // notification (pushChatNotification's "already read" check) while
        // the message itself still shows up fine on the next fetch — exactly
        // the "message arrives, notification sometimes doesn't" symptom.
        if (empty($_GET['peek']) && !empty($messages)) {
            $latestSentAt = max(array_map(fn($m) => $m['sent_at'] ?? '', $messages));
            dbSetLastRead($user['id'], $safe, $latestSentAt);
        }
        respond(['success' => true, 'messages' => array_slice($messages, -100)]);
    }

    if ($method === 'POST') {
        $text = trim($input['text'] ?? '');
        if (!$text) respond(['success' => false, 'error' => 'Message required'], 400);
        $msg = [
            'id'        => 'msg_' . bin2hex(random_bytes(6)),
            'user_id'   => $user['id'],
            'user_name' => $user['name'] ?? $user['email'],
            'text'      => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
            'sent_at'   => date('c'),
            'reactions' => []
        ];
        // Single-row insert — never touches any other message in the thread,
        // so two people sending at the same instant can't clobber each other
        // (the old load-all/save-all pattern here could silently drop a
        // message that landed in the gap between another sender's read and
        // write; confirmed under real concurrent load before this fix).
        addChannelMessage($safe, $msg);
        // The message is saved — that's the part the sender is waiting on.
        // Respond now; the Pusher push + per-recipient notification writes
        // below are all best-effort background work (see respondThenContinue()).
        respondThenContinue(['success' => true, 'message' => $msg]);

        // Push instantly to anyone with this thread open right now.
        pusherTrigger('private-thread-' . $safe, 'new-message', $msg);

        $senderName = $user['name'] ?? $user['email'];
        $isDm = strpos($threadId, 'dm_') === 0;
        if ($isDm) {
            foreach (getUsers() as $u) {
                if (($u['id'] ?? '') === $user['id']) continue;
                if (getDmThreadId($user['id'], $u['id']) === $threadId) {
                    pushChatNotification($u['id'], [
                        'id'         => 'notif_' . bin2hex(random_bytes(6)),
                        'notif_key'  => 'dm_' . $threadId . '_' . substr(md5($text), 0, 8),
                        'type'       => 'chat_dm',
                        'title'      => "DM from {$senderName}",
                        'body'       => substr($text, 0, 100),
                        'thread_id'  => $threadId,
                        // The message's own timestamp, not a fresh date('c') —
                        // pushChatNotification()'s "already read" check compares
                        // this against dbGetLastRead(); a separately-generated
                        // timestamp here can land a hair after lastRead gets
                        // stamped by a concurrent poll for THIS message, silently
                        // swallowing the notification even though the message
                        // itself was correctly delivered.
                        'created_at' => $msg['sent_at'],
                        'read'       => false,
                    ]);
                    break;
                }
            }
        } else {
            $threadLabel = '#' . $safe;
            $channelMembers = [];
            foreach (getChatChannels() as $ch) {
                if ($ch['id'] === $threadId) {
                    $threadLabel = '#' . $ch['name'];
                    $channelMembers = $ch['members'] ?? [];
                    break;
                }
            }
            // Notify all channel members (or all users for public channels).
            $allUsers = getUsers();
            foreach ($allUsers as $u) {
                if (($u['id'] ?? '') === $user['id']) continue;
                $isPublic = empty($channelMembers);
                $isMember = in_array($u['id'], $channelMembers, true);
                if (!$isPublic && !$isMember) continue;
                pushChatNotification($u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'ch_' . $threadId . '_' . $msg['id'] . '_' . $u['id'],
                    'type'       => 'chat_message',
                    'title'      => "{$senderName} in {$threadLabel}",
                    'body'       => substr($text, 0, 100),
                    'thread_id'  => $threadId,
                    // See the DM notification above for why this uses the
                    // message's own timestamp rather than a fresh date('c').
                    'created_at' => $msg['sent_at'],
                    'read'       => false,
                ]);
            }
            // @mentions only make sense in channels, not DMs
            notifyChatMentions($user, $text, $threadId, $threadLabel, $msg['sent_at']);
        }
        exit; // response already sent above via respondThenContinue()
    }
    break;

case 'chat-react':
    $user = requireAuth();
    if ($method !== 'POST') break;
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId    = $input['message_id'] ?? '';
    $emoji    = $input['emoji'] ?? '';
    if (!$threadId || !$msgId || !$emoji) respond(['success' => false, 'error' => 'Missing fields'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    // Read to find the target message, but write back only that one row
    // (updateChannelMessage) — never the whole thread — so a reaction here
    // can't race with and drop a message someone else is concurrently sending.
    $messages = getChannelMessages($threadId);
    $target = null;
    foreach ($messages as $m) {
        if ($m['id'] === $msgId) { $target = $m; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Message not found'], 404);
    if (!isset($target['reactions'])) $target['reactions'] = [];
    $existing = false;
    foreach ($target['reactions'] as &$r) {
        if ($r['emoji'] === $emoji) {
            if (in_array($user['id'], $r['users'])) {
                $r['users'] = array_values(array_filter($r['users'], fn($u) => $u !== $user['id']));
            } else {
                $r['users'][] = $user['id'];
            }
            if (empty($r['users'])) $target['reactions'] = array_values(array_filter($target['reactions'], fn($rx) => $rx['emoji'] !== $emoji));
            $existing = true;
            break;
        }
    }
    unset($r);
    if (!$existing) $target['reactions'][] = ['emoji' => $emoji, 'users' => [$user['id']]];
    updateChannelMessage($threadId, $msgId, $target);
    respondThenContinue(['success' => true]);
    pusherTrigger('private-thread-' . $threadId, 'thread-updated', ['reason' => 'reaction']);
    exit;
    break;

case 'chat-dm-threads':
    $user = requireAuth();
    if ($method !== 'GET') break;
    $unread = getChatUnreadCounts($user['id']);
    $threads = [];
    foreach (getUsers() as $u) {
        if ($u['id'] === $user['id']) continue;
        $threadId = getDmThreadId($user['id'], $u['id']);
        $threads[] = [
            'thread_id' => $threadId,
            'user_id'   => $u['id'],
            'user_name' => $u['name'] ?? $u['email'],
            'unread'    => $unread[$threadId] ?? 0
        ];
    }
    respond(['success' => true, 'threads' => $threads]);
    break;

case 'chat-delete-message':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId = $input['message_id'] ?? '';
    if (!$threadId || !$msgId) respond(['success' => false, 'error' => 'Missing fields'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $messages = getChannelMessages($threadId);
    $found = false;
    $isAdminUser = isChatAdmin($user);
    foreach ($messages as $m) {
        if ($m['id'] === $msgId) {
            if ($m['user_id'] !== $user['id'] && !$isAdminUser) {
                respond(['success' => false, 'error' => 'You can only delete your own messages'], 403);
            }
            $found = true;
            break;
        }
    }
    if (!$found) respond(['success' => false, 'error' => 'Message not found'], 404);
    // Single-row delete — doesn't touch or reason about any other message in
    // the thread, so it can't race with a concurrent send/react the way a
    // whole-thread rewrite could.
    removeChannelMessage($threadId, $msgId);
    respondThenContinue(['success' => true]);
    pusherTrigger('private-thread-' . $threadId, 'thread-updated', ['reason' => 'delete']);
    exit;
    break;

case 'chat-pin-message':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId    = $input['message_id'] ?? '';
    $unpin    = !empty($input['unpin']);
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $messages = getChannelMessages($threadId);

    if ($unpin) {
        $target = null;
        foreach ($messages as $m) { if ($m['id'] === $msgId) { $target = $m; break; } }
        if ($target === null) respond(['success' => false, 'error' => 'Message not found'], 404);
        $target['pinned'] = false; $target['pinned_at'] = null; $target['pinned_by'] = null;
        // Targeted single-row update — see the note on chat-react above.
        updateChannelMessage($threadId, $msgId, $target);
        respondThenContinue(['success' => true]);
        pusherTrigger('private-thread-' . $threadId, 'thread-updated', ['reason' => 'unpin']);
        exit;
    } else {
        $replacedName = null;
        // Only ever expect at most one other pinned message (pinning is
        // exclusive per thread) — update just that row, plus the newly
        // pinned target below, never the rest of the thread.
        foreach ($messages as $m) {
            if (!empty($m['pinned']) && $m['id'] !== $msgId) {
                if (($m['pinned_by'] ?? '') !== $user['id'] && !empty($m['pinned_by_name'])) $replacedName = $m['pinned_by_name'];
                $m['pinned'] = false; $m['pinned_at'] = null; $m['pinned_by'] = null;
                updateChannelMessage($threadId, $m['id'], $m);
            }
        }
        $target = null;
        foreach ($messages as $m) { if ($m['id'] === $msgId) { $target = $m; break; } }
        if ($target === null) respond(['success' => false, 'error' => 'Message not found'], 404);
        $target['pinned'] = true;
        $target['pinned_at'] = date('c');
        $target['pinned_by'] = $user['id'];
        $target['pinned_by_name'] = $user['name'] ?? $user['email'];
        updateChannelMessage($threadId, $msgId, $target);
        respondThenContinue(['success' => true, 'replaced' => $replacedName]);
        pusherTrigger('private-thread-' . $threadId, 'thread-updated', ['reason' => 'pin']);
        exit;
    }
    break;

case 'chat-upload':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['thread'] ?? '');
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    if (empty($_FILES['file'])) respond(['success' => false, 'error' => 'No file uploaded'], 400);

    $file = $_FILES['file'];
    if ($file['size'] > 5 * 1024 * 1024) respond(['success' => false, 'error' => 'File too large (max 5MB)'], 400);

    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','text/csv'];
    if (!in_array($file['type'], $allowed)) respond(['success' => false, 'error' => 'File type not allowed'], 400);

    $uploadDir = DATA_DIR . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) respond(['success' => false, 'error' => 'Upload failed'], 500);

    $caption = trim($_POST['caption'] ?? '');
    $isImage = strpos($file['type'], 'image/') === 0;
    $msg = [
        'id'        => 'msg_' . bin2hex(random_bytes(6)),
        'user_id'   => $user['id'],
        'user_name' => $user['name'] ?? $user['email'],
        'text'      => $caption !== '' ? htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') : '',
        // Root-relative (leading slash), not 'data/uploads/...' — a bare
        // relative path resolves against the CURRENT PAGE's URL, so it can
        // point somewhere different depending on how the viewer navigated to
        // the app (e.g. a trailing path segment), producing a 404 for one
        // person and a working image for another even though it's the same
        // file on the same domain.
        'file'      => ['name' => $file['name'], 'path' => '/data/uploads/' . $filename, 'type' => $file['type'], 'size' => $file['size'], 'is_image' => $isImage],
        'sent_at'   => date('c'),
        'reactions' => []
    ];
    // Single-row insert — same concurrency-safety reasoning as chat-messages
    // POST above.
    addChannelMessage($threadId, $msg);
    // Respond as soon as the message is saved — everything below (Pusher
    // push, per-recipient notifications) is background work, same pattern
    // as chat-messages POST.
    respondThenContinue(['success' => true, 'message' => $msg]);
    // This was previously missing entirely, so an uploaded file/image never
    // showed live for anyone else with the thread open — they only found out
    // via the slower notification-poll path. Chat threads should behave the
    // same regardless of whether the message has an attachment.
    pusherTrigger('private-thread-' . $threadId, 'new-message', $msg);

    // Push notifications for uploads — same logic as chat-messages POST
    $senderName = $user['name'] ?? $user['email'];
    $isDm = strpos($threadId, 'dm_') === 0;
    $notifBody = $isImage ? '📷 Image' : '📎 ' . $file['name'];
    if ($caption !== '') $notifBody .= ': ' . substr($caption, 0, 80);
    if ($isDm) {
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $user['id']) continue;
            if (getDmThreadId($user['id'], $u['id']) === $threadId) {
                pushChatNotification($u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'dm_' . $threadId . '_' . $msg['id'],
                    'type'       => 'chat_dm',
                    'title'      => "DM from {$senderName}",
                    'body'       => $notifBody,
                    'thread_id'  => $threadId,
                    // The message's own timestamp — see chat-messages POST for
                    // why a fresh date('c') here can race dbSetLastRead() and
                    // silently suppress the notification.
                    'created_at' => $msg['sent_at'],
                    'read'       => false,
                ]);
                break;
            }
        }
    } else {
        $threadLabel = '#' . $threadId;
        $channelMembers = [];
        foreach (getChatChannels() as $ch) {
            if ($ch['id'] === $threadId) { $threadLabel = '#' . $ch['name']; $channelMembers = $ch['members'] ?? []; break; }
        }
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $user['id']) continue;
            $isPublic = empty($channelMembers);
            if (!$isPublic && !in_array($u['id'], $channelMembers, true)) continue;
            pushChatNotification($u['id'], [
                'id'         => 'notif_' . bin2hex(random_bytes(6)),
                'notif_key'  => 'ch_' . $threadId . '_' . $msg['id'] . '_' . $u['id'],
                'type'       => 'chat_message',
                'title'      => "{$senderName} in {$threadLabel}",
                'body'       => $notifBody,
                'thread_id'  => $threadId,
                'created_at' => $msg['sent_at'],
                'read'       => false,
            ]);
        }
    }
    exit; // response already sent above via respondThenContinue()
    break;

case 'chat-unread':
    $user = requireAuth();
    if ($method !== 'GET') break;
    $counts = getChatUnreadCounts($user['id']);
    respond(['success' => true, 'total' => array_sum($counts), 'counts' => $counts]);
    break;

case 'notifications':
    if ($method === 'GET') {
        $user = requireAuth();
        $notifications = dbLoadNotifications($user['id'], 50);

        // Ensure all notifications have title + body fields for UI
        foreach ($notifications as &$n) {
            if (empty($n['title']) && !empty($n['message'])) {
                $n['title'] = $n['message'];
                $n['body']  = '';
            }
        }
        unset($n);

        respond(['success' => true, 'notifications' => $notifications]);
    }
    if ($method === 'POST') {
        $user = requireAuth();
        $action = $input['action'] ?? '';
        $notifId = $input['notification_id'] ?? '';

        if ($action === 'mark_read') {
            dbMarkNotificationRead($user['id'], $notifId);
        } elseif ($action === 'mark_all_read') {
            dbMarkAllNotificationsRead($user['id']);
        } elseif ($action === 'dismiss') {
            dbDeleteNotification($user['id'], $notifId);
        } elseif ($action === 'dismiss_all') {
            dbDeleteAllNotifications($user['id']);
            kvSet('notif_dismissed_at', $user['id'], date('c'));
        }

        respond(['success' => true]);
    }
    break;

case 'generate-notifications':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = array_filter($userData['leads'] ?? [], fn($l) => empty($l['deleted_at']));
    $allExisting = dbLoadNotifications($user['id'], 50);

    // Generate new ones — pass ALL existing so duplicates are prevented
    $newNotifications = generateNotifications($leads, $allExisting);

    // Drop any generated notifications created before the last dismiss_all
    $dismissedAt = kvGet('notif_dismissed_at', $user['id']);
    if ($dismissedAt) {
        $newNotifications = array_values(array_filter($newNotifications, fn($n) => ($n['created_at'] ?? '') > $dismissedAt));
    }

    // Single-row insert per notification — safe under concurrency, and each
    // one already carries its own id/notif_key so no separate cap/merge step
    // is needed here (dbLoadNotifications() already limits reads to the most
    // recent 50 per user).
    foreach ($newNotifications as $n) {
        dbInsertNotification($user['id'], $n);
    }

    respond(['success' => true, 'new_count' => count($newNotifications), 'notifications' => $newNotifications]);
    break;

case 'settings':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    if ($method === 'GET') {
        $admin = getAdmin();
        $settings = $userData['settings'] ?? [];
        $settings['api_configured'] = !empty($admin[($admin['default_provider'] ?? 'groq') . '_key']);
        respond(['success' => true, 'settings' => $settings]);
    }
    if ($method === 'POST') {
        $userData['settings'] = [
            'sender_name' => trim($input['sender_name'] ?? ''),
            'sender_company' => trim($input['sender_company'] ?? 'Macktiles Australia'),
            'sender_title' => trim($input['sender_title'] ?? ''),
            'company_description' => trim($input['company_description'] ?? ''),
            'value_proposition' => trim($input['value_proposition'] ?? ''),
            'social_proof' => trim($input['social_proof'] ?? ''),
            'calendar_link' => trim($input['calendar_link'] ?? ''),
            'email_tone' => $input['email_tone'] ?? 'professional',
            'signature' => $input['signature'] ?? ''
        ];
        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }
    break;

case 'activity-feed':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $limit = min(50, intval($_GET['limit'] ?? 20));

    $iconMap = [
        'created'        => '➕',
        'deleted'        => '🗑️',
        'restored'       => '♻️',
        'stage_change'   => '🔄',
        'call_logged'    => '📞',
        'call_started'   => '📞',
        'call_completed' => '📞',
        'call_missed'    => '📵',
        'email_sent'     => '📧',
        'researched'     => '🔍',
        'reassigned'     => '👤',
    ];

    // Admin sees all users' activity; rep sees only their own; super admins see everyone
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    if ($user['is_admin'] ?? false) {
        $allUsers = $isSuperAdmin ? getUsers() : array_values(array_filter(getUsers(), fn($u) => empty($u['is_super_admin'])));
    } else {
        $allUsers = [$user];
    }
    $userMap = [];
    foreach ($allUsers as $u) $userMap[$u['id']] = $u['name'] ?? $u['email'];

    $activities = [];
    foreach ($allUsers as $u) {
        $uData = getUserData($u['id']);
        $repName = $u['name'] ?? $u['email'];
        foreach ($uData['leads'] ?? [] as $lead) {
            $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
            $company = $lead['company'] ?? '';

            foreach ($lead['activity_log'] ?? [] as $entry) {
                $type = $entry['type'] ?? 'update';
                $activities[] = [
                    'type'      => $type,
                    'icon'      => $iconMap[$type] ?? '📝',
                    'lead_id'   => $lead['id'],
                    'lead_name' => $name,
                    'company'   => $company,
                    'detail'    => $entry['detail'] ?? '',
                    'rep'       => $repName,
                    'timestamp' => $entry['timestamp'] ?? $lead['updated_at'],
                ];
            }

            // Legacy: leads with no activity_log yet
            if (empty($lead['activity_log'])) {
                foreach ($lead['email_history'] ?? [] as $email) {
                    $activities[] = [
                        'type'      => 'email_sent',
                        'icon'      => '📧',
                        'lead_id'   => $lead['id'],
                        'lead_name' => $name,
                        'company'   => $company,
                        'detail'    => ucfirst(str_replace('_', ' ', $email['type'] ?? 'initial')) . ' email sent',
                        'rep'       => $repName,
                        'timestamp' => $email['sent_at'] ?? $lead['updated_at'],
                    ];
                }
                if (!empty($lead['enrichment'])) {
                    $activities[] = [
                        'type'      => 'researched',
                        'icon'      => '🔍',
                        'lead_id'   => $lead['id'],
                        'lead_name' => $name,
                        'company'   => $company,
                        'detail'    => 'Lead researched',
                        'rep'       => $repName,
                        'timestamp' => $lead['last_action_at'] ?? $lead['updated_at'],
                    ];
                }
            }
        }
    }

    usort($activities, function($a, $b) {
        return strtotime($b['timestamp'] ?? '0') - strtotime($a['timestamp'] ?? '0');
    });

    respond(['success' => true, 'activities' => array_slice($activities, 0, $limit)]);
    break;

case 'stats':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = array_filter($userData['leads'] ?? [], function($l) { return empty($l['deleted_at']); });
    $stats = [
        'total' => count($leads),
        'new_lead' => 0,
        'research' => 0,
        'email_sent' => 0,
        'call_attempted' => 0,
        'engaged' => 0,
        'consultation_booked' => 0,
        'nurture_parked' => 0,
        'won' => 0,
        'lost' => 0,
        // Legacy aliases for backwards compatibility
        'new' => 0,
        'researched' => 0,
        'call_due' => 0,
        'outcome_logged' => 0,
        'qualified' => 0,
        'disqualified' => 0,
        // Legacy statuses for backwards compatibility
        'contacted' => 0,
        'replied' => 0,
        'meeting_booked' => 0,
        'not_interested' => 0
    ];
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($stats[$stage])) $stats[$stage]++;
        $legacy = stageToLegacyStatus($stage);
        if ($legacy !== $stage && isset($stats[$legacy])) $stats[$legacy]++;
    }
    respond(['success' => true, 'stats' => $stats]);
    break;

// ============ SALES EXCELLENCE SYSTEM ============

case 'command-center':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $allUsers = getUsers();
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    $users = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
    $teamActivity = [];
    $leads = [];
    $settings = [];
    if (!empty($user['is_admin'])) {
        foreach ($users as $teamUser) {
            $teamData = getUserData($teamUser['id']);
            $teamActivity[$teamUser['id']] = [
                'user_id' => $teamUser['id'],
                'name' => $teamUser['name'] ?? 'User',
                'calls' => 0,
                'emails' => 0,
                'research' => 0,
                'outcomes' => 0,
                'consultations' => 0,
                'leads_owned' => 0
            ];
            foreach (($teamData['leads'] ?? []) as $lead) {
                if (!empty($lead['deleted_at'])) continue;
                $lead['_owner_id'] = $teamUser['id'];
                $lead['_owner_name'] = $teamUser['name'] ?? 'User';
                $leads[] = $lead;
                $teamActivity[$teamUser['id']]['leads_owned']++;
            }
        }
        $settings = getUserData($user['id'])['settings'] ?? [];
    } else {
        $userData = getUserData($user['id']);
        foreach (($userData['leads'] ?? []) as $lead) {
            if (empty($lead['deleted_at'])) $leads[] = $lead;
        }
        $settings = $userData['settings'] ?? [];
        $teamActivity[$user['id']] = [
            'user_id' => $user['id'],
            'name' => $user['name'] ?? 'User',
            'calls' => 0,
            'emails' => 0,
            'research' => 0,
            'outcomes' => 0,
            'consultations' => 0,
            'leads_owned' => count($leads)
        ];
    }
    $today = viewerToday();

    // Total counts
    $total = count($leads);

    // Grade distribution
    $grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'ungraded' => 0];
    foreach ($leads as $l) {
        $grade = $l['fit_grade'] ?? '';
        if (isset($grades[$grade])) {
            $grades[$grade]++;
        } else {
            $grades['ungraded']++;
        }
    }

    // Temperature distribution
    $temperatures = ['on_fire' => 0, 'hot' => 0, 'warm' => 0, 'cold' => 0];
    foreach ($leads as $l) {
        $temp = $l['temperature'] ?? 'cold';
        if (isset($temperatures[$temp])) {
            $temperatures[$temp]++;
        }
    }

    // Velocity distribution
    $velocities = ['accelerating' => 0, 'stable' => 0, 'slowing' => 0, 'stalled' => 0];
    foreach ($leads as $l) {
        $vel = $l['velocity'] ?? 'stalled';
        if (isset($velocities[$vel])) {
            $velocities[$vel]++;
        }
    }

    // Status counts for funnel
    $statuses = [
        'new_lead' => 0, 'research' => 0, 'email_sent' => 0,
        'call_attempted' => 0, 'engaged' => 0, 'consultation_booked' => 0,
        'nurture_parked' => 0, 'won' => 0, 'lost' => 0
    ];
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($statuses[$stage])) {
            $statuses[$stage]++;
        }
        // Won leads have necessarily passed through consultation_booked, so count them there too
        // (forward progress should not empty the earlier funnel step).
        if ($stage === 'won') {
            $statuses['consultation_booked']++;
        }
    }

    // Calculate conversion funnel rates
    $funnel = [];
    $stageOrder = ['new_lead', 'research', 'email_sent', 'call_attempted', 'engaged', 'consultation_booked', 'won'];
    $cumulative = 0;
    foreach ($stageOrder as $i => $stage) {
        $count = $statuses[$stage];
        $cumulative += $count;
        $rate = null;
        if ($i > 0 && $cumulative > 0) {
            // Calculate rate from previous cumulative stage
            $previousCumulative = 0;
            for ($j = 0; $j < $i; $j++) {
                $previousCumulative += $statuses[$stageOrder[$j]];
            }
            if ($previousCumulative > 0) {
                $rate = round(($cumulative / ($previousCumulative + $count)) * 100);
            }
        }
        $funnel[] = ['stage' => $stage, 'count' => $statuses[$stage], 'cumulative' => $cumulative, 'rate' => $rate];
    }

    // Today's activity tracking
    $todaysCalls = 0;
    $todaysEmails = 0;
    $todaysResearch = 0;
    $todaysOutcomes = 0;

    // Count today's activity from the authoritative per-lead sources the app actually writes to:
    //   calls/outcomes/conversions -> call_history[]   emails -> email_history[]   research -> activity_log[]
    foreach ($leads as $l) {
        $ownerId = $l['_owner_id'] ?? $user['id'];

        // Calls + outcomes from call_history (every started call counts; outcome only when logged)
        foreach (($l['call_history'] ?? []) as $call) {
            $when = $call['completed_at'] ?? $call['started_at'] ?? '';
            if (viewerDateOf($when) !== $today) continue;
            $todaysCalls++;
            if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['calls']++;
            if (($call['status'] ?? '') === 'completed') {
                $todaysOutcomes++;
                if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['outcomes']++;
            }
        }

        // Emails from email_history
        foreach (($l['email_history'] ?? []) as $email) {
            if (isset($email['sent_at']) && viewerDateOf($email['sent_at']) === $today) {
                $todaysEmails++;
                if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['emails']++;
            }
        }

        // Research from activity_log
        foreach (($l['activity_log'] ?? []) as $act) {
            if (($act['type'] ?? '') === 'researched' && viewerDateOf($act['timestamp'] ?? '') === $today) {
                $todaysResearch++;
                if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['research']++;
            }
        }

        // Consultations: leads currently booked or won (won has passed through booked)
        if (in_array(getLeadStage($l), ['consultation_booked', 'won']) && isset($teamActivity[$ownerId])) {
            $teamActivity[$ownerId]['consultations']++;
        }
    }

    // Daily targets — team-wide view (admin) uses the admin-configured default
    // (Admin Settings > Team Daily Targets) directly, since this widget sums
    // every rep's activity, not just the viewer's. A rep's own single-owner
    // dashboard uses their personal override if set, else that same admin
    // default, via effectiveDailyTargets().
    if (!empty($user['is_admin'])) {
        $adminSettings = getAdmin();
        $dailyTargets = $adminSettings['daily_targets'] ?? ['calls' => 40, 'emails' => 40, 'research' => 25, 'outcomes' => 40];
    } else {
        $dailyTargets = effectiveDailyTargets($settings);
    }

    $dailyProgress = [
        'calls' => ['done' => $todaysCalls, 'target' => $dailyTargets['calls'] ?? 40],
        'emails' => ['done' => $todaysEmails, 'target' => $dailyTargets['emails'] ?? 40],
        'research' => ['done' => $todaysResearch, 'target' => $dailyTargets['research'] ?? 25],
        'outcomes' => ['done' => $todaysOutcomes, 'target' => $dailyTargets['outcomes'] ?? $dailyTargets['calls'] ?? 40]
    ];

    // Success rates by grade (qualified / total for each grade)
    $successByGrade = [];
    foreach (['A', 'B', 'C', 'D'] as $g) {
        $gradeLeads = array_filter($leads, fn($l) => ($l['fit_grade'] ?? '') === $g);
        $qualified = count(array_filter($gradeLeads, fn($l) => getLeadStage($l) === 'consultation_booked' || getLeadStage($l) === 'won'));
        $gradeTotal = count($gradeLeads);
        $successByGrade[$g] = $gradeTotal > 0 ? round(($qualified / $gradeTotal) * 100) : 0;
    }

    // Success rates by temperature
    $successByTemp = [];
    foreach (['on_fire', 'hot', 'warm', 'cold'] as $t) {
        $tempLeads = array_filter($leads, fn($l) => ($l['temperature'] ?? 'cold') === $t);
        $qualified = count(array_filter($tempLeads, fn($l) => getLeadStage($l) === 'consultation_booked' || getLeadStage($l) === 'won'));
        $tempTotal = count($tempLeads);
        $successByTemp[$t] = $tempTotal > 0 ? round(($qualified / $tempTotal) * 100) : 0;
    }

    // Overdue leads count
    $overdueCount = 0;
    $now = time();
    foreach ($leads as $l) {
        if (!empty($l['followup_date'])) {
            $followupTime = strtotime($l['followup_date']);
            if ($followupTime && $followupTime < $now && !in_array(getLeadStage($l), ['consultation_booked', 'nurture_parked', 'won', 'lost'])) {
                $overdueCount++;
            }
        }
    }

    $sourceSplit = [];
    $parkedReasons = [];
    $stageAges = [];
    $leadsAddedThisWeek = 0;
    $consultationsMtd = 0;
    $emailedCount = 0;
    $repliedCount = 0;
    $staleCount = 0;
    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    foreach ($leads as $l) {
        $source = $l['source'] ?? 'firmable';
        $sourceSplit[$source] = ($sourceSplit[$source] ?? 0) + 1;
        $stage = getLeadStage($l);
        $entered = strtotime($l['stage_entered_at'] ?? $l['created_at'] ?? 'now');
        $stageAges[$stage][] = max(0, floor(($now - $entered) / 86400));
        $sla = calculateSLAStatus($l);
        if (!empty($sla['is_overdue']) && !in_array($stage, ['won', 'lost', 'nurture_parked'])) $staleCount++;
        if (substr($l['created_at'] ?? '', 0, 10) >= $weekStart) $leadsAddedThisWeek++;
        if ($stage === 'consultation_booked' && substr($l['stage_entered_at'] ?? $l['updated_at'] ?? '', 0, 10) >= $monthStart) $consultationsMtd++;
        if (!empty($l['email_history'])) {
            $emailedCount++;
            foreach ($l['email_history'] as $eh) {
                if (($eh['outcome'] ?? '') === 'replied' || ($eh['outcome'] ?? '') === 'meeting_booked') {
                    $repliedCount++;
                    break;
                }
            }
        }
        if (in_array($stage, ['nurture_parked', 'lost'])) {
            $reason = $l['rejection_reason'] ?? $l['disqualified_reason'] ?? 'No reason logged';
            $parkedReasons[$reason] = ($parkedReasons[$reason] ?? 0) + 1;
        }
    }
    $avgTimeInStage = [];
    foreach ($stageAges as $stage => $ages) {
        $avgTimeInStage[$stage] = count($ages) ? round(array_sum($ages) / count($ages), 1) : 0;
    }

    // Calculate streak (consecutive days hitting targets)
    $streak = 0;
    $dailyPerformance = $settings['daily_performance'] ?? [];
    $checkDate = viewerNow()->modify('-1 day');
    for ($i = 0; $i < 30; $i++) {
        $dateStr = $checkDate->format('Y-m-d');
        if (isset($dailyPerformance[$dateStr])) {
            $dayData = $dailyPerformance[$dateStr];
            $callsHit = ($dayData['calls_done'] ?? 0) >= ($dayData['calls_target'] ?? 40);
            $emailsHit = ($dayData['emails_done'] ?? 0) >= ($dayData['emails_target'] ?? 40);
            if ($callsHit && $emailsHit) {
                $streak++;
            } else {
                break;
            }
        } else {
            break;
        }
        $checkDate->modify('-1 day');
    }

    respond([
        'success' => true,
        'totals' => [
            'all' => $total,
            'grades' => $grades,
            'qualified' => $statuses['consultation_booked'],
            'disqualified' => $statuses['nurture_parked'] + $statuses['lost'],
            'consultation_booked' => $statuses['consultation_booked'],
            'won' => $statuses['won'],
            'nurture_parked' => $statuses['nurture_parked'],
            'overdue' => $overdueCount,
            'stale' => $staleCount
        ],
        'temperature' => $temperatures,
        'velocity' => $velocities,
        'funnel' => $funnel,
        'daily_progress' => $dailyProgress,
        'success_rates' => [
            'by_grade' => $successByGrade,
            'by_temperature' => $successByTemp
        ],
        'management' => [
            'consultations_mtd' => $consultationsMtd,
            'leads_added_this_week' => $leadsAddedThisWeek,
            'response_rate' => $emailedCount > 0 ? round(($repliedCount / $emailedCount) * 100) : 0,
            'average_time_in_stage' => $avgTimeInStage,
            'parked_reasons' => $parkedReasons,
            'lead_source_split' => $sourceSplit,
            'team_activity' => array_values($teamActivity)
        ],
        'streak' => $streak,
        'date' => $today
    ]);
    break;

case 'activity-report':
    if ($method !== 'GET') break;
    $user = requireAdmin();
    $allUsers = getUsers();
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    // Super admin sees everyone; regular admin sees non-super-admins only
    $reportUsers = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));

    // Resolve date range. Accepts ?range=today|week|month or explicit ?from=YYYY-MM-DD&to=YYYY-MM-DD.
    // Boundaries are computed against the viewer's own clock (viewerToday()),
    // not the server's — see the same reasoning in calls-report above.
    $vToday = viewerToday();
    $range = $_GET['range'] ?? '';
    $fromDate = $_GET['from'] ?? '';
    $toDate = $_GET['to'] ?? '';
    if ($range === 'today') {
        $fromDate = $vToday;
        $toDate = $vToday;
    } elseif ($range === 'week') {
        $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($vToday)));
        $toDate = $vToday;
    } elseif ($range === 'month') {
        $fromDate = date('Y-m-01', strtotime($vToday));
        $toDate = $vToday;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($vToday)));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = $vToday;
    // Inclusive bounds as timestamps — shifted from viewer-local midnight
    // into the correct UTC instant using the same offset the request sent.
    $offsetMin = viewerOffsetMinutes();
    $fromTs = strtotime($fromDate . ' 00:00:00 UTC') + ($offsetMin * 60);
    $toTs = strtotime($toDate . ' 23:59:59 UTC') + ($offsetMin * 60);
    $inRange = function($iso) use ($fromTs, $toTs) {
        if (empty($iso)) return false;
        $t = strtotime($iso);
        return $t !== false && $t >= $fromTs && $t <= $toTs;
    };

    $repStats = [];
    $feed = [];
    foreach ($reportUsers as $rep) {
        $repId = $rep['id'];
        $repStats[$repId] = [
            'user_id' => $repId,
            'name' => $rep['name'] ?? $rep['email'] ?? 'User',
            'is_admin' => $rep['is_admin'] ?? false,
            'calls' => 0,
            'emails' => 0,
            'research' => 0,
            'outcomes' => 0,
            'conversions' => 0,
            'leads_created' => 0,
            'active_days' => 0,
            'conversion_rate' => 0,
            'avg_calls_per_day' => 0
        ];
        $activeDays = [];
        $repData = getUserData($repId);
        foreach (($repData['leads'] ?? []) as $lead) {
            $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
            if ($leadName === '') $leadName = $lead['company'] ?? 'Unknown lead';

            // Calls + outcomes + conversions from call_history (authoritative, timestamped, rep-attributed)
            // Count every call attempt (started or completed); outcomes/conversions only when logged.
            foreach (($lead['call_history'] ?? []) as $call) {
                $isCompleted = ($call['status'] ?? '') === 'completed';
                $when = $call['completed_at'] ?? $call['started_at'] ?? '';
                if (!$inRange($when)) continue;
                $repStats[$repId]['calls']++;
                $activeDays[viewerDateOf($when)] = true;
                $outcome = $call['outcome'] ?? '';
                if ($isCompleted) {
                    $repStats[$repId]['outcomes']++;
                    if ($outcome === 'consultation_booked') $repStats[$repId]['conversions']++;
                }
                $feed[] = [
                    'user_id' => $repId,
                    'rep_name' => $repStats[$repId]['name'],
                    'type' => 'call',
                    'detail' => $isCompleted ? ('Call: ' . ($call['outcome_label'] ?? $outcome)) : 'Call started (no outcome logged)',
                    'lead' => $leadName,
                    'timestamp' => $when
                ];
            }

            // Emails from email_history
            foreach (($lead['email_history'] ?? []) as $email) {
                $when = $email['sent_at'] ?? '';
                if (!$inRange($when)) continue;
                $repStats[$repId]['emails']++;
                $activeDays[viewerDateOf($when)] = true;
                $feed[] = [
                    'user_id' => $repId,
                    'rep_name' => $repStats[$repId]['name'],
                    'type' => 'email',
                    'detail' => 'Email sent' . (!empty($email['type']) ? ' (' . str_replace('_', ' ', $email['type']) . ')' : ''),
                    'lead' => $leadName,
                    'timestamp' => $when
                ];
            }

            // Research / created from activity_log
            foreach (($lead['activity_log'] ?? []) as $act) {
                $when = $act['timestamp'] ?? '';
                if (!$inRange($when)) continue;
                $type = $act['type'] ?? '';
                if ($type === 'researched') {
                    $repStats[$repId]['research']++;
                } elseif ($type === 'created') {
                    $repStats[$repId]['leads_created']++;
                } else {
                    continue; // calls/emails already counted from their own sources
                }
                $activeDays[viewerDateOf($when)] = true;
                $feed[] = [
                    'user_id' => $repId,
                    'rep_name' => $repStats[$repId]['name'],
                    'type' => $type,
                    'detail' => $act['detail'] ?? $type,
                    'lead' => $leadName,
                    'timestamp' => $when
                ];
            }
        }
        $repStats[$repId]['active_days'] = count($activeDays);
        $repStats[$repId]['conversion_rate'] = $repStats[$repId]['calls'] > 0
            ? round(($repStats[$repId]['conversions'] / $repStats[$repId]['calls']) * 100, 1) : 0;
        $repStats[$repId]['avg_calls_per_day'] = $repStats[$repId]['active_days'] > 0
            ? round($repStats[$repId]['calls'] / $repStats[$repId]['active_days'], 1) : 0;
    }

    // Team totals
    $totals = ['calls' => 0, 'emails' => 0, 'research' => 0, 'outcomes' => 0, 'conversions' => 0, 'leads_created' => 0];
    foreach ($repStats as $s) {
        foreach ($totals as $k => $v) $totals[$k] += $s[$k];
    }
    $totals['conversion_rate'] = $totals['calls'] > 0 ? round(($totals['conversions'] / $totals['calls']) * 100, 1) : 0;

    // Sort feed newest first, cap to a reasonable size for the view
    usort($feed, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));
    $feed = array_slice($feed, 0, 500);

    respond([
        'success' => true,
        'from' => $fromDate,
        'to' => $toDate,
        'reps' => array_values($repStats),
        'totals' => $totals,
        'feed' => $feed
    ]);
    break;

case 'calls-report':
    if ($method !== 'GET') break;
    $user = requireAdmin();
    $allUsers = getUsers();
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    $reportUsers = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));

    // "Today"/"this week"/"this month" are computed against the viewer's own
    // clock (see viewerToday()), not the server's — a rep in a different
    // timezone than whoever's viewing the report should still see boundaries
    // that make sense for the report's date labels as shown.
    $vToday = viewerToday();
    $range = $_GET['range'] ?? '';
    $fromDate = $_GET['from'] ?? '';
    $toDate = $_GET['to'] ?? '';
    if ($range === 'today') { $fromDate = $vToday; $toDate = $vToday; }
    elseif ($range === 'week') { $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($vToday))); $toDate = $vToday; }
    elseif ($range === 'month') { $fromDate = date('Y-m-01', strtotime($vToday)); $toDate = $vToday; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-d', strtotime('monday this week', strtotime($vToday)));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = $vToday;
    // Convert viewer-local midnight-to-midnight into the correct UTC instants
    // by shifting the boundary by the viewer's offset before parsing.
    $offsetMin = viewerOffsetMinutes();
    $fromTs = strtotime($fromDate . ' 00:00:00 UTC') + ($offsetMin * 60);
    $toTs = strtotime($toDate . ' 23:59:59 UTC') + ($offsetMin * 60);

    $repFilter = trim((string)($_GET['rep_id'] ?? ''));
    $outcomeFilter = trim((string)($_GET['outcome'] ?? '')); // '', 'answered', 'missed'

    $calls = [];
    foreach ($reportUsers as $rep) {
        if ($repFilter && $rep['id'] !== $repFilter) continue;
        $repData = getUserData($rep['id']);
        foreach (($repData['leads'] ?? []) as $lead) {
            if (empty($lead['call_history'])) continue;
            $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
            if ($leadName === '') $leadName = $lead['company'] ?? 'Unknown lead';
            foreach ($lead['call_history'] as $call) {
                $when = $call['completed_at'] ?? $call['started_at'] ?? '';
                $t = strtotime($when);
                if ($t === false || $t < $fromTs || $t > $toTs) continue;
                $isAircall = ($call['via'] ?? '') === 'aircall';
                $outcome = $isAircall ? ($call['outcome'] ?? '') : '';
                if ($outcomeFilter && $outcomeFilter !== $outcome) continue;
                $calls[] = [
                    'call_id' => $call['id'] ?? '',
                    'lead_id' => $lead['id'],
                    'lead_name' => $leadName,
                    'company' => $lead['company'] ?? '',
                    'rep_id' => $rep['id'],
                    'rep_name' => $rep['name'] ?? $rep['email'] ?? 'User',
                    'via' => $call['via'] ?? 'manual',
                    'direction' => $call['direction'] ?? '',
                    'outcome' => $outcome,
                    'outcome_label' => $call['outcome_label'] ?? ($call['status'] ?? ''),
                    'duration_seconds' => (int)($call['duration_seconds'] ?? 0),
                    'recording_url' => $call['recording_url'] ?? '',
                    'notes' => $call['notes'] ?? '',
                    'tags' => $call['tags'] ?? [],
                    'timestamp' => $when,
                ];
            }
        }
    }

    usort($calls, fn($a, $b) => strtotime($b['timestamp']) <=> strtotime($a['timestamp']));

    $summary = ['total' => count($calls), 'answered' => 0, 'missed' => 0, 'manual' => 0, 'with_recording' => 0];
    foreach ($calls as $c) {
        if ($c['via'] === 'aircall') { $c['outcome'] === 'missed' ? $summary['missed']++ : $summary['answered']++; }
        else $summary['manual']++;
        if (!empty($c['recording_url'])) $summary['with_recording']++;
    }

    respond([
        'success' => true,
        'from' => $fromDate,
        'to' => $toDate,
        'calls' => array_slice($calls, 0, 500),
        'summary' => $summary,
        'reps' => array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'] ?? $u['email']], $reportUsers),
    ]);
    break;

// Deletes a single call_history entry from the Reports > Calls table (admin
// only). Scoped to the owning rep's own lead data via resolveLeadOwnerId(),
// same pattern as reassign-lead/cross-owner update-lead, so a plain admin
// can only do this for reps below them and never for a super admin's data.
case 'delete-call-log':
    if ($method !== 'POST') break;
    $user = requireAdmin();
    $leadId = trim((string)($input['lead_id'] ?? ''));
    $callId = trim((string)($input['call_id'] ?? ''));
    $repId = trim((string)($input['rep_id'] ?? ''));
    if ($leadId === '' || $callId === '' || $repId === '') {
        respond(['success' => false, 'error' => 'lead_id, call_id, and rep_id are required'], 400);
    }
    $ownerId = resolveLeadOwnerId($user, $repId);
    $userData = getUserData($ownerId);
    $found = false;
    $recordingToDelete = '';
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] !== $leadId) continue;
        $before = count($lead['call_history'] ?? []);
        foreach (($lead['call_history'] ?? []) as $c) {
            if (($c['id'] ?? '') === $callId) { $recordingToDelete = $c['recording_url'] ?? ''; break; }
        }
        $lead['call_history'] = array_values(array_filter($lead['call_history'] ?? [], fn($c) => ($c['id'] ?? '') !== $callId));
        $found = count($lead['call_history']) < $before;
        if ($found) {
            $lead['updated_at'] = date('c');
            logActivity($lead, 'call_log_deleted', 'Call log entry removed by ' . ($user['name'] ?? 'admin'));
        }
        break;
    }
    unset($lead);
    if (!$found) respond(['success' => false, 'error' => 'Call log entry not found'], 404);
    // Recording files are stored on our own disk (aircallStoreRecording downloads
    // them locally since Aircall's own URLs expire after ~10 minutes) — deleting
    // the log entry without deleting its file would leave an orphaned recording
    // of a phone call sitting on disk with no reference anywhere pointing to it.
    if ($recordingToDelete && strpos($recordingToDelete, 'data/recordings/') === 0) {
        $filePath = DATA_DIR . '/recordings/' . basename($recordingToDelete);
        if (is_file($filePath)) @unlink($filePath);
    }
    saveUserData($ownerId, $userData);
    respond(['success' => true]);
    break;

// ===== ACTIVITY PING (records a heartbeat for session analytics) =====
case 'activity-ping':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $page = trim($input['page'] ?? $_GET['page'] ?? '');
    dbRecordPing(
        $user['id'],
        $user['name'] ?? $user['email'] ?? '',
        !empty($user['is_super_admin']) ? 'super_admin' : (!empty($user['is_admin']) ? 'admin' : 'rep'),
        $page
    );
    respond(['success' => true]);
    break;

// ===== USER SESSIONS (admin analytics: groups pings into sessions) =====
case 'user-sessions':
    if ($method !== 'GET') break;
    $user = requireAdmin();
    $isSuperAdmin = $user['is_super_admin'] ?? false;

    $days = intval($_GET['days'] ?? 7);
    if ($days < 1 || $days > 90) $days = 7;

    $allPings = dbLoadPings($days);        // ordered by user_id, pinged_at ASC
    $lastSeenAll = dbLoadLastSeen();
    $lastSeenMap = [];
    foreach ($lastSeenAll as $ls) $lastSeenMap[$ls['user_id']] = $ls;

    // Group each user's pings into sessions (gap > 30 min starts a new session).
    $userPings = [];
    foreach ($allPings as $p) $userPings[$p['user_id']][] = $p;

    $userSessions = [];
    foreach ($userPings as $uid => $pings) {
        $sessions = [];
        $sessStart = null; $sessEnd = null; $sessPages = []; $prevT = null;
        foreach ($pings as $p) {
            $t = strtotime($p['pinged_at']);
            if ($prevT === null || ($t - $prevT) > 1800) {
                if ($sessStart !== null) {
                    $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
                    $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
                }
                $sessStart = $p['pinged_at']; $sessPages = [];
            }
            $sessEnd = $p['pinged_at'];
            if (!empty($p['page'])) $sessPages[] = $p['page'];
            $prevT = $t;
        }
        if ($sessStart !== null) {
            $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
            $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
        }
        $userSessions[$uid] = $sessions;
    }

    // Build per-user summary. Super admin sees everyone; regular admin excludes super admins.
    $result = [];
    $allUsers = getUsers();
    foreach ($allUsers as $u) {
        if (!$isSuperAdmin && !empty($u['is_super_admin'])) continue;
        $uid = $u['id'];
        $ls = $lastSeenMap[$uid] ?? null;
        $sessions = $userSessions[$uid] ?? [];
        $today = viewerToday();
        $todayMins = 0;
        foreach ($sessions as $s) {
            if (viewerDateOf($s['start']) === $today) $todayMins += $s['duration_mins'];
        }
        $result[] = [
            'user_id'              => $uid,
            'user_name'            => $u['name'] ?? $u['email'],
            'user_role'            => !empty($u['is_super_admin']) ? 'super_admin' : (!empty($u['is_admin']) ? 'admin' : 'rep'),
            'last_seen'            => $ls['pinged_at'] ?? null,
            'last_page'            => $ls['page'] ?? null,
            'active_mins_today'    => min($todayMins, 480),
            'sessions_this_period' => count($sessions),
            'sessions'             => array_reverse($sessions),
        ];
    }
    usort($result, fn($a, $b) => strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''));
    respond(['success' => true, 'sessions' => $result, 'days' => $days]);
    break;

case 'lead-batches':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $teamWide = !empty($user['is_admin']) && ($_GET['scope'] ?? '') === 'team';
    if ($teamWide) {
        // "Team" = every OTHER rep's leads, not including the viewer's own —
        // matches dashboard-briefing's scope=team so the two toggles agree.
        $isSuperAdmin = $user['is_super_admin'] ?? false;
        $allUsers = getUsers();
        $teamUsers = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
        $teamUsers = array_values(array_filter($teamUsers, fn($u) => $u['id'] !== $user['id']));
        $leads = [];
        foreach ($teamUsers as $teamUser) {
            foreach ((getUserData($teamUser['id'])['leads'] ?? []) as $lead) {
                $lead['_owner_id'] = $teamUser['id'];
                $lead['_owner_name'] = $teamUser['name'] ?? 'User';
                $leads[] = $lead;
            }
        }
    } else {
        $userData = getUserData($user['id']);
        $leads = $userData['leads'] ?? [];
    }
    $today = viewerToday();
    $now = time();

    $batches = [
        'needs_research' => [],
        'needs_outreach' => [],
        'needs_calling' => [],
        'needs_followup' => [],
        'hot_focus' => [],
        'overdue' => [],
        'inbound_urgent' => []
    ];

    foreach ($leads as $l) {
        if (!empty($l['deleted_at'])) continue;
        $status = getLeadStage($l);
        // A poor ICP score (fit_grade Disqualified) no longer hides a lead from
        // its stage-based queue — a rep should still see and decide on a
        // researched-but-low-scoring lead, not have it vanish silently.
        $enrichment = $l['enrichment'] ?? '';
        $emailsSent = $l['emails_sent'] ?? 0;
        $callsMade = $l['calls_made'] ?? 0;
        $temperature = $l['temperature'] ?? 'cold';
        $velocity = $l['velocity'] ?? 'stalled';

        if (in_array($status, ['won', 'lost'])) continue;

        if (($l['source'] ?? '') === 'inbound' && $status === 'call_attempted') {
            $batches['inbound_urgent'][] = $l;
        }

        // Needs Research: genuinely new, unresearched leads only. Previously
        // this also fired for empty($enrichment) regardless of stage, so a
        // lead that skipped research entirely (e.g. emailed or called without
        // ever being researched) still landed here instead of its real
        // stage's bucket — e.g. an email_sent lead with no enrichment showed
        // in Needs Research instead of Calls Due, disagreeing with Focus
        // Queue and the Today dashboard's own stage-based counts for it.
        if ($status === 'new_lead') {
            $batches['needs_research'][] = $l;
        }
        // Needs Outreach: has research but hasn't been emailed yet. Stage-based
        // (not just "zero emails sent") so a lead that skipped straight to a
        // call — e.g. call_attempted with no email ever sent — lands in Calls
        // Due below, not back in Needs Outreach.
        elseif ($status === 'research') {
            $batches['needs_outreach'][] = $l;
        }
        // Needs Calling: matches the Today dashboard's "Calls Due" stat card —
        // stage-based only (email_sent or call_attempted), so the two numbers
        // never disagree for the same lead.
        elseif (in_array($status, ['email_sent', 'call_attempted'])) {
            $batches['needs_calling'][] = $l;
        }

        // followup_date is stored as a plain date (no time). Compare by
        // calendar day (string, "YYYY-MM-DD") rather than converting to a
        // timestamp and comparing against $now — a timestamp comparison
        // resolves "today" to midnight, which reads as already-past the
        // instant any time ticks by, wrongly landing a same-day follow-up in
        // both Needs Follow-up and Overdue at once.
        $followupDay = !empty($l['followup_date']) ? substr($l['followup_date'], 0, 10) : null;

        // Needs Follow-up: a follow-up call was explicitly scheduled (e.g. via
        // "Schedule follow-up" on a call outcome) for today specifically —
        // once the day passes it belongs in Overdue instead, not both. Also
        // catches stalled leads that haven't been touched in a while, so a
        // lead doesn't need an explicit date to surface for re-engagement.
        if ((($followupDay === $today) || $velocity === 'stalled') && !empty($enrichment) && !in_array($status, ['consultation_booked', 'nurture_parked'])) {
            $batches['needs_followup'][] = $l;
        }

        // Hot Focus: On fire or hot temperature
        if (in_array($temperature, ['on_fire', 'hot']) && !in_array($status, ['consultation_booked', 'nurture_parked'])) {
            $batches['hot_focus'][] = $l;
        }

        // Overdue: follow-up date's calendar day is strictly before today.
        if ($followupDay !== null && $followupDay < $today && !in_array($status, ['consultation_booked', 'nurture_parked', 'won', 'lost'])) {
            $batches['overdue'][] = $l;
        }
    }

    // Sort batches by priority (hot leads first, then by days since activity)
    foreach ($batches as $key => &$batch) {
        usort($batch, function($a, $b) {
            // Priority: on_fire > hot > warm > cold
            $tempOrder = ['on_fire' => 0, 'hot' => 1, 'warm' => 2, 'cold' => 3];
            $aTemp = $tempOrder[$a['temperature'] ?? 'cold'] ?? 3;
            $bTemp = $tempOrder[$b['temperature'] ?? 'cold'] ?? 3;
            if ($aTemp !== $bTemp) return $aTemp - $bTemp;

            // Then by fit grade
            $gradeOrder = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, '' => 4];
            $aGrade = $gradeOrder[$a['fit_grade'] ?? ''] ?? 4;
            $bGrade = $gradeOrder[$b['fit_grade'] ?? ''] ?? 4;
            return $aGrade - $bGrade;
        });
    }
    // Critical: break the reference left dangling by "foreach (... as &$batch)"
    // above. Without this, the next foreach on $batches (by value, same var
    // name) silently overwrites the last bucket's array with each iterated
    // bucket in turn — a classic PHP foreach-reference bug that was corrupting
    // inbound_urgent with leads from whichever bucket got processed last.
    unset($batch);

    // Return counts and limited lead previews
    $batchSummary = [];
    foreach ($batches as $key => $batch) {
        $batchSummary[$key] = [
            'count' => count($batch),
            'leads' => array_slice(array_map(function($l) {
                return [
                    'id' => $l['id'],
                    'name' => trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')),
                    'company' => $l['company'] ?? '',
                    'status' => stageToLegacyStatus(getLeadStage($l)),
                    'stage' => getLeadStage($l),
                    'temperature' => $l['temperature'] ?? 'cold',
                    'fit_grade' => $l['fit_grade'] ?? '',
                    'days_inactive' => calculateDaysSinceLastActivity($l),
                    'owner_id' => $l['_owner_id'] ?? null,
                    'owner_name' => $l['_owner_name'] ?? null
                ];
            }, $batch), 0, 20) // Limit to 20 per batch for performance
        ];
    }

    respond(['success' => true, 'batches' => $batchSummary, 'team_scope' => $teamWide, 'can_view_team' => !empty($user['is_admin'])]);
    break;

case 'daily-targets':
    $user = requireAuth();
    $userData = getUserData($user['id']);

    if ($method === 'GET') {
        $targets = effectiveDailyTargets($userData['settings'] ?? []) + [
            'call_outcomes_required' => true,
            'stage_updates_required' => true,
            'enabled' => true
        ];
        respond(['success' => true, 'targets' => $targets]);
    }

    if ($method === 'POST') {
        if (!isset($userData['settings'])) {
            $userData['settings'] = [];
        }
        $userData['settings']['daily_targets'] = [
            'calls' => intval($input['calls'] ?? 40),
            'emails' => intval($input['emails'] ?? 40),
            'followups' => intval($input['followups'] ?? 25),
            'research' => intval($input['research'] ?? 25),
            'weekly_imports' => intval($input['weekly_imports'] ?? 75),
            'call_outcomes_required' => $input['call_outcomes_required'] ?? true,
            'stage_updates_required' => $input['stage_updates_required'] ?? true,
            'enabled' => $input['enabled'] ?? true
        ];
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'targets' => $userData['settings']['daily_targets']]);
    }
    break;

case 'daily-commitments':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $today = viewerToday();

    if ($method === 'GET') {
        $commitments = $userData['settings']['daily_commitments'] ?? [];
        $leads = array_values(array_filter($userData['leads'] ?? [], fn($l) => empty($l['deleted_at'])));

        // Filter to today's commitments
        $todaysCommitments = array_filter($commitments, function($c) use ($today) {
            return isset($c['date']) && $c['date'] === $today;
        });

        // Enrich with lead info
        $todaysCommitments = array_map(function($c) use ($leads) {
            if (!empty($c['lead_id'])) {
                foreach ($leads as $l) {
                    if ($l['id'] === $c['lead_id']) {
                        $c['lead_name'] = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                        $c['lead_company'] = $l['company'] ?? '';
                        break;
                    }
                }
            }
            return $c;
        }, array_values($todaysCommitments));

        // Also get incomplete from previous days (carry-over)
        $carryOver = array_filter($commitments, function($c) use ($today) {
            return isset($c['date']) && $c['date'] < $today && ($c['status'] ?? 'pending') === 'pending';
        });

        // Get targets
        $targets = effectiveDailyTargets($userData['settings'] ?? []);

        // Calculate streak
        $streak = 0;
        $dailyPerformance = $userData['settings']['daily_performance'] ?? [];
        $checkDate = viewerNow()->modify('-1 day');
        for ($i = 0; $i < 30; $i++) {
            $dateStr = $checkDate->format('Y-m-d');
            if (isset($dailyPerformance[$dateStr])) {
                $dayData = $dailyPerformance[$dateStr];
                $callsHit = ($dayData['calls_done'] ?? 0) >= ($dayData['calls_target'] ?? 40);
                $emailsHit = ($dayData['emails_done'] ?? 0) >= ($dayData['emails_target'] ?? 40);
                if ($callsHit && $emailsHit) {
                    $streak++;
                } else {
                    break;
                }
            } else {
                break;
            }
            $checkDate->modify('-1 day');
        }

        respond([
            'success' => true,
            'commitments' => $todaysCommitments,
            'carryover' => array_values($carryOver),
            'targets' => $targets,
            'streak' => $streak,
            'date' => $today
        ]);
    }

    if ($method === 'POST') {
        // Add a new commitment
        if (!isset($userData['settings']['daily_commitments'])) {
            $userData['settings']['daily_commitments'] = [];
        }

        $newCommitment = [
            'id' => 'commit_' . bin2hex(random_bytes(4)),
            'lead_id' => $input['lead_id'] ?? null,
            'action' => $input['action'] ?? 'call',
            'description' => $input['description'] ?? '',
            'due_time' => $input['due_time'] ?? '09:00',
            'status' => 'pending',
            'date' => $today,
            'created_at' => date('c')
        ];

        $userData['settings']['daily_commitments'][] = $newCommitment;
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'commitment' => $newCommitment]);
    }

    if ($method === 'PUT') {
        // Update commitment status
        $commitmentId = $input['commitment_id'] ?? '';
        $newStatus = $input['status'] ?? 'completed';

        if (!isset($userData['settings']['daily_commitments'])) {
            respond(['success' => false, 'error' => 'No commitments found'], 404);
        }

        $found = false;
        foreach ($userData['settings']['daily_commitments'] as &$c) {
            if ($c['id'] === $commitmentId) {
                $c['status'] = $newStatus;
                $c['completed_at'] = $newStatus === 'completed' ? date('c') : null;
                $found = true;
                break;
            }
        }

        if (!$found) {
            respond(['success' => false, 'error' => 'Commitment not found'], 404);
        }

        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }

    if ($method === 'DELETE') {
        // Delete commitment
        $commitmentId = $input['commitment_id'] ?? '';

        if (!isset($userData['settings']['daily_commitments'])) {
            respond(['success' => false, 'error' => 'No commitments found'], 404);
        }

        $userData['settings']['daily_commitments'] = array_filter(
            $userData['settings']['daily_commitments'],
            fn($c) => $c['id'] !== $commitmentId
        );
        $userData['settings']['daily_commitments'] = array_values($userData['settings']['daily_commitments']);

        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }
    break;

case 'daily-progress':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = $userData['leads'] ?? [];
    $settings = $userData['settings'] ?? [];
    $today = viewerToday();

    // Count today's activities
    $todaysCalls = 0;
    $todaysEmails = 0;
    $todaysResearch = 0;

    foreach ($leads as $l) {
        // Calls from call_history
        foreach (($l['call_history'] ?? []) as $call) {
            if (viewerDateOf($call['completed_at'] ?? $call['started_at'] ?? '') === $today) $todaysCalls++;
        }
        // Emails from email_history
        foreach (($l['email_history'] ?? []) as $email) {
            if (isset($email['sent_at']) && viewerDateOf($email['sent_at']) === $today) $todaysEmails++;
        }
        // Research from activity_log
        foreach (($l['activity_log'] ?? []) as $act) {
            if (($act['type'] ?? '') === 'researched' && viewerDateOf($act['timestamp'] ?? '') === $today) $todaysResearch++;
        }
    }

    // Get targets
    $targets = effectiveDailyTargets($settings);

    // Get commitments completed today
    $commitments = $settings['daily_commitments'] ?? [];
    $todaysCommitments = array_filter($commitments, fn($c) => ($c['date'] ?? '') === $today);
    $completedCommitments = array_filter($todaysCommitments, fn($c) => ($c['status'] ?? '') === 'completed');

    // Calculate streak
    $streak = 0;
    $dailyPerformance = $settings['daily_performance'] ?? [];
    $checkDate = viewerNow()->modify('-1 day');
    for ($i = 0; $i < 30; $i++) {
        $dateStr = $checkDate->format('Y-m-d');
        if (isset($dailyPerformance[$dateStr])) {
            $d = $dailyPerformance[$dateStr];
            if (($d['calls_done'] ?? 0) >= ($d['calls_target'] ?? 40) && ($d['emails_done'] ?? 0) >= ($d['emails_target'] ?? 40)) {
                $streak++;
            } else {
                break;
            }
        } else {
            break;
        }
        $checkDate->modify('-1 day');
    }

    // Calculate progress status
    $callsTarget = $targets['calls'] ?? 40;
    $emailsTarget = $targets['emails'] ?? 40;
    $researchTarget = $targets['research'] ?? 25;

    $progress = [
        'calls' => [
            'done' => $todaysCalls,
            'target' => $callsTarget,
            'percent' => $callsTarget > 0 ? round(($todaysCalls / $callsTarget) * 100) : 0,
            'status' => $todaysCalls >= $callsTarget ? 'complete' : ($todaysCalls >= $callsTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'emails' => [
            'done' => $todaysEmails,
            'target' => $emailsTarget,
            'percent' => $emailsTarget > 0 ? round(($todaysEmails / $emailsTarget) * 100) : 0,
            'status' => $todaysEmails >= $emailsTarget ? 'complete' : ($todaysEmails >= $emailsTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'research' => [
            'done' => $todaysResearch,
            'target' => $researchTarget,
            'percent' => $researchTarget > 0 ? round(($todaysResearch / $researchTarget) * 100) : 0,
            'status' => $todaysResearch >= $researchTarget ? 'complete' : ($todaysResearch >= $researchTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'commitments' => [
            'done' => count($completedCommitments),
            'total' => count($todaysCommitments)
        ]
    ];

    respond([
        'success' => true,
        'progress' => $progress,
        'streak' => $streak,
        'date' => $today
    ]);
    break;

case 'save-daily-performance':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $today = viewerToday();

    // This is called at end of day or when user logs out
    // Saves today's performance for streak tracking
    if (!isset($userData['settings']['daily_performance'])) {
        $userData['settings']['daily_performance'] = [];
    }

    // Get today's actual counts
    $leads = $userData['leads'] ?? [];
    $todaysCalls = 0;
    $todaysEmails = 0;

    foreach ($leads as $l) {
        foreach (($l['call_history'] ?? []) as $call) {
            if (viewerDateOf($call['completed_at'] ?? $call['started_at'] ?? '') === $today) $todaysCalls++;
        }
        foreach (($l['email_history'] ?? []) as $email) {
            if (isset($email['sent_at']) && viewerDateOf($email['sent_at']) === $today) $todaysEmails++;
        }
    }

    $targets = effectiveDailyTargets($userData['settings'] ?? []);

    $userData['settings']['daily_performance'][$today] = [
        'calls_done' => $todaysCalls,
        'calls_target' => $targets['calls'] ?? 40,
        'emails_done' => $todaysEmails,
        'emails_target' => $targets['emails'] ?? 40,
        'saved_at' => date('c')
    ];

    // Keep only last 90 days of performance data
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    $userData['settings']['daily_performance'] = array_filter(
        $userData['settings']['daily_performance'],
        fn($date) => $date >= $cutoff,
        ARRAY_FILTER_USE_KEY
    );

    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;
// ============ ADMIN ICP CONFIG ENDPOINTS ============

case 'save-icp-config':
    if ($method !== 'POST') break;
    requireAdmin();
    $admin = getAdmin();

    if (isset($input['icp_config'])) {
        $admin['icp_config'] = $input['icp_config'];
    }
    if (isset($input['stage_validation_rules'])) {
        $admin['stage_validation_rules'] = $input['stage_validation_rules'];
    }
    if (isset($input['outreach_rules'])) {
        $admin['outreach_rules'] = $input['outreach_rules'];
    }

    saveAdmin($admin);
    respond(['success' => true, 'admin' => $admin]);
    break;

case 'get-icp-config':
    if ($method !== 'GET') break;
    requireAdmin();
    $admin = getAdmin();
    respond([
        'success' => true,
        'icp_config' => $admin['icp_config'] ?? [],
        'stage_validation_rules' => $admin['stage_validation_rules'] ?? [],
        'outreach_rules' => $admin['outreach_rules'] ?? []
    ]);
    break;

// ============ MIGRATION ENDPOINT ============

case 'migrate-leads-to-stage-model':
    if ($method !== 'POST') break;
    requireAdmin();
    $users = getUsers();
    $migratedCount = 0;

    foreach ($users as $u) {
        $userData = getUserData($u['id']);
        foreach ($userData['leads'] as &$lead) {
            $before = json_encode($lead);
            foreach (getDefaultStageFields() as $field => $value) {
                if (!isset($lead[$field])) $lead[$field] = $value;
            }
            $lead = normalizeLeadForMapping($lead);
            if ($before !== json_encode($lead)) {
                $migratedCount++;
            }
        }

        saveUserData($u['id'], $userData);
    }

    respond(['success' => true, 'migrated' => $migratedCount, 'message' => "Migrated {$migratedCount} leads to stage model"]);
    break;

// ============ AIRCALL INTEGRATION ============

case 'test-aircall':
    if (!AIRCALL_ENABLED) respond(['success' => false, 'error' => 'Aircall integration is currently disabled'], 503);
    requireSuperAdmin();
    $res = aircallRequest('GET', '/ping');
    if ($res['ok']) {
        respond(['success' => true, 'message' => 'Aircall connected']);
    }
    respond(['success' => false, 'error' => $res['error'] ?: ('Aircall returned HTTP ' . $res['status'])], 200);
    break;

case 'aircall-dial':
    if (!AIRCALL_ENABLED) respond(['success' => false, 'error' => 'Aircall integration is currently disabled'], 503);
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    if (empty($admin['aircall_api_id']) || empty($admin['aircall_api_token'])) {
        respond(['success' => false, 'error' => 'Aircall is not configured yet. An admin must add the API keys in the Admin Panel.'], 400);
    }
    $aUserId = trim((string)($user['aircall_user_id'] ?? ''));
    $aNumberId = trim((string)($user['aircall_number_id'] ?? ''));
    if (!$aUserId || !$aNumberId) {
        respond(['success' => false, 'error' => 'Your account is not linked to Aircall. Ask an admin to set your Aircall User ID and Number ID on the Users page.'], 400);
    }
    $leadId = $input['lead_id'] ?? '';
    $userData = getUserData($user['id']);
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $phone = normalizePhoneE164(trim((string)($lead['phone'] ?? '')));
            if (!$phone) respond(['success' => false, 'error' => 'This lead has no phone number'], 400);
            $res = aircallRequest('POST', '/users/' . rawurlencode($aUserId) . '/calls', ['number_id' => (int)$aNumberId, 'to' => $phone]);
            if (!$res['ok']) {
                $msg = $res['error'] ?: ('Aircall rejected the call (HTTP ' . $res['status'] . '). Check your Aircall User ID / Number ID.');
                respond(['success' => false, 'error' => $msg], 200);
            }
            // Call accepted — Aircall now rings the rep's app. calls_made is
            // NOT incremented here: Aircall accepting the dial request only
            // means it started ringing, not that a call actually happened
            // (e.g. the app never connects, or nobody answers on Aircall's
            // side) — the call.ended webhook is the only point that knows
            // whether a call genuinely occurred, and is the sole place that
            // increments calls_made and writes the real call_history entry.
            // Previously this double-counted every real call (once here,
            // once in the webhook) and phantom-counted every call that never
            // completed, e.g. 12 "Call started" activity entries with zero
            // matching call_history rows once Aircall failed to connect.
            $lead['call_started_at'] = date('c');
            $lead['last_action'] = 'call_started';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            if (in_array(getLeadStage($lead), ['email_sent', 'research', 'new_lead'])) {
                setLeadStage($lead, 'call_attempted', 'call_started', $user['id']);
            }
            logActivity($lead, 'call_started', 'Call started via Aircall');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead, 'message' => 'Dialing — answer the call in your Aircall app']);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'aircall-status':
    if (!AIRCALL_ENABLED) respond(['success' => false, 'error' => 'Aircall integration is currently disabled'], 503);
    if ($method !== 'GET') break;
    requireAdmin();
    $state = kvGet('aircall', 'oncall') ?: [];
    // Prune stale entries (calls that never got a hungup/ended event)
    $changed = false;
    foreach ($state as $cid => $c) {
        if (strtotime($c['started_at'] ?? '') < time() - 4 * 3600) { unset($state[$cid]); $changed = true; }
    }
    if ($changed) kvSet('aircall', 'oncall', $state);
    respond(['success' => true, 'on_call' => array_values($state)]);
    break;

case 'aircall-webhook':
    if (!AIRCALL_ENABLED) respond(['success' => true, 'ignored' => true]);
    if ($method !== 'POST') break;
    $admin = getAdmin();
    // Integration inactive → acknowledge quietly so Aircall doesn't disable the webhook
    if (empty($admin['aircall_api_id']) && empty($admin['aircall_webhook_token'])) {
        respond(['success' => true, 'ignored' => true]);
    }
    // Verify the webhook token if one is configured. Aircall's dashboard
    // auto-generates this token itself (no field to choose how it's sent),
    // and its exact transport isn't documented in the public API reference,
    // so we accept it from every plausible location: the headers Aircall (or
    // similar webhook providers) commonly use, the JSON body, or a ?token=
    // query param appended to the webhook URL manually as a fallback.
    $expected = $admin['aircall_webhook_token'] ?? '';
    $headerToken = $_SERVER['HTTP_X_AIRCALL_WEBHOOK_TOKEN']
        ?? $_SERVER['HTTP_X_WEBHOOK_TOKEN']
        ?? $_SERVER['HTTP_X_AIRCALL_TOKEN']
        ?? '';
    if (!$headerToken && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $headerToken = preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']);
    }
    $provided = (string)($headerToken ?: ($_GET['token'] ?? ($input['token'] ?? '')));
    if ($expected && !hash_equals($expected, $provided)) {
        respond(['success' => false, 'error' => 'Invalid webhook token'], 401);
    }
    $event = $input['event'] ?? '';
    $data = $input['data'] ?? [];
    $callId = (string)($data['id'] ?? '');
    if (!$event || !$callId) respond(['success' => true, 'ignored' => true]);

    $aircallUserId = (string)($data['user']['id'] ?? '');
    $repUser = aircallFindUserByAircallId($aircallUserId);

    switch ($event) {
        case 'call.created':
            $state = kvGet('aircall', 'oncall') ?: [];
            $state[$callId] = [
                'call_id' => $callId,
                'aircall_user_id' => $aircallUserId,
                'user_id' => $repUser['id'] ?? null,
                'user_name' => $repUser['name'] ?? ($data['user']['name'] ?? 'Unknown'),
                'phone' => (string)($data['raw_digits'] ?? ''),
                'direction' => $data['direction'] ?? '',
                'started_at' => date('c'),
            ];
            kvSet('aircall', 'oncall', $state);
            pusherTrigger('private-admins', 'aircall-oncall-changed', ['on_call' => array_values($state)]);
            break;

        case 'call.hungup':
            $state = kvGet('aircall', 'oncall') ?: [];
            if (isset($state[$callId])) {
                unset($state[$callId]);
                kvSet('aircall', 'oncall', $state);
                pusherTrigger('private-admins', 'aircall-oncall-changed', ['on_call' => array_values($state)]);
            }
            break;

        case 'call.ended':
            // Clear live state (in case call.hungup was missed)
            $state = kvGet('aircall', 'oncall') ?: [];
            if (isset($state[$callId])) {
                unset($state[$callId]);
                kvSet('aircall', 'oncall', $state);
                pusherTrigger('private-admins', 'aircall-oncall-changed', ['on_call' => array_values($state)]);
            }

            $match = aircallFindLeadByPhone((string)($data['raw_digits'] ?? ''), $repUser['id'] ?? null);
            if (!$match) break; // No matching lead — nothing to log against

            $ownerData = getUserData($match['owner_id']);
            foreach ($ownerData['leads'] as &$lead) {
                if ($lead['id'] !== $match['lead_id']) continue;
                $lead['call_history'] = $lead['call_history'] ?? [];
                // Skip if this Aircall call was already logged (webhook retries)
                foreach ($lead['call_history'] as $existing) {
                    if (($existing['aircall_call_id'] ?? '') === $callId) { saveUserData($match['owner_id'], $ownerData); respond(['success' => true]); }
                }
                $answered = !empty($data['answered_at']);
                $missed = !$answered || !empty($data['missed_call_reason']);
                $recording = '';
                if (!empty($data['recording'])) {
                    $recording = aircallStoreRecording((string)$data['recording'], $callId);
                }
                // status/completed_at follow the vocabulary the rest of the app's stats
                // and reports already key off (see activity-report, dashboard-briefing);
                // outcome/outcome_label carry the Aircall-specific answered/missed detail.
                $lead['call_history'][] = [
                    'id' => 'call_' . bin2hex(random_bytes(8)),
                    'aircall_call_id' => $callId,
                    'via' => 'aircall',
                    'direction' => $data['direction'] ?? 'outbound',
                    'status' => 'completed',
                    'outcome' => $missed ? 'missed' : 'answered',
                    'outcome_label' => $missed ? 'Missed (Aircall)' : 'Answered (Aircall)',
                    'started_at' => !empty($data['started_at']) ? date('c', (int)$data['started_at']) : date('c'),
                    'completed_at' => !empty($data['ended_at']) ? date('c', (int)$data['ended_at']) : date('c'),
                    'duration_seconds' => (int)($data['duration'] ?? 0),
                    'recording_url' => $recording,
                    'rep_id' => $repUser['id'] ?? '',
                    'rep_name' => $repUser['name'] ?? ($data['user']['name'] ?? ''),
                    'notes' => '',
                    'tags' => [],
                ];
                if (!$missed) {
                    $lead['calls_made'] = ($lead['calls_made'] ?? 0) + 1;
                    if (in_array(getLeadStage($lead), ['email_sent', 'research', 'new_lead'])) {
                        setLeadStage($lead, 'call_attempted', 'aircall_call', $repUser['id'] ?? 'aircall');
                    }
                }
                $lead['last_action'] = $missed ? 'call_missed' : 'call_completed';
                $lead['last_action_at'] = date('c');
                $lead['updated_at'] = date('c');
                logActivity($lead, $missed ? 'call_missed' : 'call_completed', 'Aircall ' . ($data['direction'] ?? 'outbound') . ' call — ' . ($missed ? 'missed' : gmdate('i\m s\s', (int)($data['duration'] ?? 0))));
                // Save the lead/call_history update BEFORE sending any
                // notifications below. pushChatNotification()/notifyAdminsOfCall()
                // each do their own read-modify-write on a user's data — if the
                // rep taking the call is also this lead's owner (or an admin who
                // owns it), a notification saved to their account would get
                // silently overwritten by this function's own save if it ran
                // afterward with its now-stale in-memory $ownerData copy.
                $leadForNotify = $lead;
                unset($lead);
                saveUserData($match['owner_id'], $ownerData);
                notifyAdminsOfCall($leadForNotify, $repUser['name'] ?? ($data['user']['name'] ?? 'A rep'), $callId, $missed, (int)($data['duration'] ?? 0), $recording);
                // Prompt the rep who actually took the call to log an outcome —
                // an Aircall call only ever gets answered/missed automatically;
                // it never picks a real outcome (interested, booked, etc.), so
                // without this nudge a completed call can sit with no
                // follow-up action taken. Only for answered calls — a missed
                // call has nothing to log yet.
                if (!$missed && !empty($repUser['id'])) {
                    $leadNameForNotif = trim(($leadForNotify['first_name'] ?? '') . ' ' . ($leadForNotify['last_name'] ?? ''));
                    if ($leadNameForNotif === '') $leadNameForNotif = $leadForNotify['company'] ?? 'this lead';
                    pushChatNotification($repUser['id'], [
                        'id'         => 'notif_' . bin2hex(random_bytes(6)),
                        'notif_key'  => "aircall_log_outcome_{$callId}",
                        'type'       => 'log_call_outcome',
                        'title'      => 'Log the outcome',
                        'body'       => "Call with {$leadNameForNotif} ended — log what happened",
                        'lead_id'    => $leadForNotify['id'] ?? '',
                        'created_at' => date('c'),
                        'read'       => false,
                    ]);
                }
                break;
            }
            break;

        case 'call.commented':
        case 'call.tagged':
            $match = aircallFindLeadByPhone((string)($data['raw_digits'] ?? ''), $repUser['id'] ?? null);
            if (!$match) break;
            $ownerData = getUserData($match['owner_id']);
            foreach ($ownerData['leads'] as &$lead) {
                if ($lead['id'] !== $match['lead_id']) continue;
                if (empty($lead['call_history'])) break;
                foreach ($lead['call_history'] as &$entry) {
                    if (($entry['aircall_call_id'] ?? '') !== $callId) continue;
                    if ($event === 'call.commented') {
                        $comments = array_map(fn($c) => $c['content'] ?? '', $data['comments'] ?? []);
                        $entry['notes'] = trim(implode("\n", array_filter($comments)));
                    } else {
                        $entry['tags'] = array_values(array_map(fn($t) => $t['name'] ?? '', $data['tags'] ?? []));
                    }
                    break;
                }
                unset($entry);
                $lead['updated_at'] = date('c');
                break;
            }
            unset($lead);
            saveUserData($match['owner_id'], $ownerData);
            break;
    }
    respond(['success' => true]);
    break;

// ===== Feedback & Support (feedback/support channel to Levata; super-admin-only for now, see support.php) =====
case 'tickets':
    if ($method !== 'GET') break;
    requireSuperAdmin();
    $store = getTicketsStore();
    $tickets = $store['tickets'];
    usort($tickets, function ($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    respond([
        'success' => true,
        'tickets' => $tickets,
        'summary' => ticketsSummary($tickets),
    ]);
    break;

case 'save-ticket':
    if ($method !== 'POST') break;
    $u = requireSuperAdmin();
    $store = getTicketsStore();
    $now = date('c');
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        $found = false;
        foreach ($store['tickets'] as &$ticket) {
            if (($ticket['id'] ?? '') === $id) {
                $ticket = applyTicketFields($ticket, $input);
                $ticket['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        unset($ticket);
        if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
        saveTicketsStore($store);
        respond(['success' => true, 'id' => $id]);
    }

    $ticket = [
        'id' => 'tkt_' . bin2hex(random_bytes(8)),
        'ticket_no' => nextTicketNo($store),
        'status' => 'open',
        'created_by' => $u['id'] ?? '',
        'created_by_name' => $u['name'] ?? '',
        'created_by_email' => $u['email'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
        'replies' => [],
    ];
    $ticket = applyTicketFields($ticket, $input);
    if ($ticket['subject'] === '' || $ticket['message'] === '') {
        respond(['success' => false, 'error' => 'Subject and message are required'], 400);
    }
    // Best-effort: forward to the Levata hub and remember its ticket id for reply routing.
    $hubId = forwardTicketToHub($ticket);
    if ($hubId) $ticket['hub_ticket_id'] = $hubId;
    $store['tickets'][] = $ticket;
    saveTicketsStore($store);
    respond(['success' => true, 'id' => $ticket['id'], 'ticket_no' => $ticket['ticket_no']]);
    break;

case 'ticket-reply':
    if ($method !== 'POST') break;
    $u = requireSuperAdmin();
    $ticketId = trim($input['ticket_id'] ?? '');
    $message = trim($input['message'] ?? '');
    if ($message === '') respond(['success' => false, 'error' => 'Reply cannot be empty'], 400);
    $store = getTicketsStore();
    $target = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) { $target = &$ticket; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    if (!isset($target['replies']) || !is_array($target['replies'])) $target['replies'] = [];
    $reply = [
        'id' => 'rep_' . bin2hex(random_bytes(6)),
        'author_id' => $u['id'] ?? '',
        'author_name' => $u['name'] ?? '',
        'is_staff' => false,
        'message' => $message,
        'created_at' => date('c'),
    ];
    $target['replies'][] = $reply;
    $target['updated_at'] = date('c');
    $ticketCopy = $target;
    unset($target);
    saveTicketsStore($store);
    // Best-effort: push this reply onward to the Levata hub so it sees the conversation.
    sendReplyToHub($ticketCopy, $reply);
    respond(['success' => true]);
    break;

case 'update-ticket-status':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    global $VALID_TICKET_STATUS, $VALID_TICKET_PRIORITY;
    $ticketId = trim($input['id'] ?? '');
    $store = getTicketsStore();
    $found = false;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) {
            if (isset($input['status']) && in_array($input['status'], $VALID_TICKET_STATUS, true)) {
                $ticket['status'] = $input['status'];
            }
            if (isset($input['priority']) && in_array($input['priority'], $VALID_TICKET_PRIORITY, true)) {
                $ticket['priority'] = $input['priority'];
            }
            $ticket['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    saveTicketsStore($store);
    respond(['success' => true]);
    break;

case 'delete-ticket':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    $id = trim($input['id'] ?? '');
    $store = getTicketsStore();
    $store['tickets'] = array_values(array_filter($store['tickets'], function ($t) use ($id) {
        return ($t['id'] ?? '') !== $id;
    }));
    saveTicketsStore($store);
    respond(['success' => true]);
    break;

case 'ingest-reply':
    // Public endpoint: the Levata hub pushes a staff reply back here. Authenticated
    // solely by the shared secret (admin.json 'ticket_hub_secret'). Never exposed
    // in the UI; disabled unless that secret is configured.
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $replySecret = trim($admin['ticket_hub_secret'] ?? '');
    if ($replySecret === '') respond(['success' => false, 'error' => 'Reply ingest not enabled'], 404);
    if (!hash_equals($replySecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $localId = trim($input['remote_id'] ?? '');
    $reply = $input['reply'] ?? null;
    $ok = ingestReplyFromHub($localId, $reply);
    if (!$ok) respond(['success' => false, 'error' => 'Ticket not found or empty reply'], 404);
    respond(['success' => true]);
    break;

default:
    respond(['success' => false, 'error' => 'Invalid endpoint'], 404);
}

// ============ LLM FUNCTIONS ============

// Tries the requested provider first; on a rate-limit/quota error (e.g. Groq's
// daily token cap) it transparently retries the same prompt against the next
// configured provider instead of failing the whole request. $admin is needed
// (not just $apiKey) so the fallback can look up the other providers' keys —
// every call site already has $admin in scope, so nothing else needs to change.
function callLLM($provider, $apiKey, $prompt, $admin = null) {
    $result = callLLMProvider($provider, $apiKey, $prompt);
    if ($result['success'] || !$admin || !isRateLimitError($result)) return $result;

    $fallbackOrder = ['groq', 'cerebras', 'gemini', 'anthropic'];
    foreach ($fallbackOrder as $fallbackProvider) {
        if ($fallbackProvider === $provider) continue;
        $fallbackKey = $admin[$fallbackProvider . '_key'] ?? '';
        if (!$fallbackKey) continue;
        $fallbackResult = callLLMProvider($fallbackProvider, $fallbackKey, $prompt);
        if ($fallbackResult['success']) {
            $fallbackResult['fallback_provider'] = $fallbackProvider;
            $fallbackResult['fallback_reason'] = $result['error'] ?? 'Primary provider rate-limited';
            return $fallbackResult;
        }
        // Keep trying the remaining providers only if this one also hit a
        // rate limit; a non-rate-limit failure means don't cascade further.
        if (!isRateLimitError($fallbackResult)) return $fallbackResult;
    }
    return $result; // All providers exhausted — surface the original error.
}

function callLLMProvider($provider, $apiKey, $prompt) {
    switch ($provider) {
        case 'groq': return callGroq($apiKey, $prompt);
        case 'gemini': return callGemini($apiKey, $prompt);
        case 'anthropic': return callAnthropic($apiKey, $prompt);
        case 'cerebras': return callCerebras($apiKey, $prompt);
        default: return ['success' => false, 'error' => 'Unknown provider'];
    }
}

// Groq/Gemini/Anthropic all phrase rate-limit errors differently, and not
// every failure includes a reliable status code from curl alone, so this
// checks both the HTTP status (429) and common wording as a safety net.
function isRateLimitError($result) {
    if (($result['status'] ?? null) === 429) return true;
    $msg = strtolower($result['error'] ?? '');
    return $msg !== '' && (
        strpos($msg, 'rate limit') !== false ||
        strpos($msg, 'quota') !== false ||
        strpos($msg, 'tokens per day') !== false ||
        strpos($msg, 'resource_exhausted') !== false
    );
}

function callGroq($apiKey, $prompt) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'llama-3.3-70b-versatile',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 2048,
            'temperature' => 0.7
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'status' => 0];
    $r = json_decode($response, true);
    return isset($r['choices'][0]['message']['content'])
        ? ['success' => true, 'content' => $r['choices'][0]['message']['content']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error', 'status' => $status];
}

function callCerebras($apiKey, $prompt) {
    // OpenAI-compatible chat/completions API, same response shape as Groq.
    $ch = curl_init('https://api.cerebras.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'gpt-oss-120b',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 2048,
            'temperature' => 0.7
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'status' => 0];
    $r = json_decode($response, true);
    return isset($r['choices'][0]['message']['content'])
        ? ['success' => true, 'content' => $r['choices'][0]['message']['content']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error', 'status' => $status];
}

function callGemini($apiKey, $prompt) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'status' => 0];
    $r = json_decode($response, true);
    return isset($r['candidates'][0]['content']['parts'][0]['text'])
        ? ['success' => true, 'content' => $r['candidates'][0]['content']['parts'][0]['text']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error', 'status' => $status];
}

function callAnthropic($apiKey, $prompt) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => 'claude-sonnet-4-20250514',
            'max_tokens' => 2048,
            'system' => 'You are a B2B sales intelligence AI. Always return valid JSON when asked for structured data. Be specific, practical, and focused on driving conversions.',
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'status' => 0];
    $r = json_decode($response, true);
    return isset($r['content'][0]['text'])
        ? ['success' => true, 'content' => $r['content'][0]['text']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error', 'status' => $status];
}

// ============ LEGACY RESEARCH PROMPT ============
// CRITICAL: JSON structure MUST match frontend renderResearch() expectations EXACTLY

function buildResearchPrompt($lead) {
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? 'Unknown';
    $title = $lead['title'] ?? 'Unknown';
    $industry = $lead['industry'] ?? 'Unknown';
    $country = $lead['country'] ?? 'Unknown';
    $website = $lead['website'] ?? '';
    $size = $lead['company_size'] ?? 'Unknown';

    return "You are a B2B sales intelligence analyst. Research this prospect for Macktiles Australia — an Australian architectural tile brand with a curated range for modern Australian homes. Macktiles operates from a Melbourne showroom with 14 contemporary designs, the Macktiles Visualiser tool, Italian production technology, and the Batik Designer Collection pre-order range. The launch goal is to book consultations, showroom visits, or defined follow-up calls, not to close transactions inside the first outreach.

PROSPECT:
- Name: {$name}
- Title: {$title}
- Company: {$company}
- Industry: {$industry}
- Country: {$country}
- Website: {$website}
- Company Size: {$size}

IMPORTANT: Return ONLY valid JSON. No markdown code blocks. No backticks. No explanations before or after. Just the raw JSON object.

The JSON must have this EXACT structure:

{
  \"research_score\": {
    \"score\": 75,
    \"quality\": \"Good\",
    \"factors\": [\"Has company website\", \"Known industry\", \"Clear job title\"]
  },
  \"sources\": [
    {\"title\": \"Company Website\", \"url\": \"https://example.com\", \"description\": \"Official company information\"},
    {\"title\": \"LinkedIn Profile\", \"url\": \"https://linkedin.com/company/example\", \"description\": \"Company overview and employee data\"},
    {\"title\": \"Industry Report\", \"url\": \"https://example.com/report\", \"description\": \"Market analysis and trends\"}
  ],
  \"company_profile\": {
    \"description\": \"2-3 sentences describing what this company does, their business model, who they serve\",
    \"key_products_services\": [\"Product/Service 1\", \"Product/Service 2\", \"Product/Service 3\"],
    \"market_position\": \"Their market position and competitive standing\",
    \"growth_stage\": \"Startup/Growth/Mature/Enterprise\"
  },
  \"industry_intelligence\": {
    \"top_challenges\": [
      {\"challenge\": \"Challenge they face when sourcing tiles for their projects\", \"impact\": \"How this affects their timelines or costs\"},
      {\"challenge\": \"Quality or consistency issue with their current tile supplier\", \"impact\": \"Business impact on their clients or projects\"},
      {\"challenge\": \"Pricing, margins, or availability challenge\", \"impact\": \"Why this matters to their bottom line\"}
    ],
    \"trends\": [\"Industry trend 1\", \"Industry trend 2\"],
    \"competitive_pressures\": \"What competitors are doing\"
  },
  \"prospect_analysis\": {
    \"pain_points\": [
      {\"pain\": \"Specific pain point for this role\", \"evidence\": \"Why they likely have this pain\"},
      {\"pain\": \"Another relevant pain point\", \"evidence\": \"Evidence or reasoning\"}
    ],
    \"responsibilities\": [\"Key responsibility 1\", \"Key responsibility 2\", \"Key responsibility 3\"],
    \"success_metrics\": [\"KPI 1\", \"KPI 2\"],
    \"buying_power\": \"Decision Maker / Influencer / Evaluator / End User\"
  },
  \"sales_strategy\": {
    \"opening_hooks\": [
      {\"hook\": \"Attention-grabbing opener for this prospect\", \"why\": \"Why this works\"},
      {\"hook\": \"Another personalized opening line\", \"why\": \"Why this resonates\"}
    ],
    \"value_angles\": [
      {\"angle\": \"Value proposition most relevant to them\", \"connects_to\": \"Which pain it addresses\"},
      {\"angle\": \"Secondary value angle\", \"connects_to\": \"Related pain point\"}
    ],
    \"discovery_questions\": [
      \"Where do you currently source your tiles from?\",
      \"What matters most to you when choosing a tile supplier — price, range, quality, or delivery speed?\",
      \"How many projects are you running at the moment, and do you have a steady tile supply for them?\",
      \"Have you ever had a project delayed because tiles were out of stock or delivery was late?\"
    ],
    \"objections\": [{\"objection\": \"Likely objection\", \"response\": \"How to handle it\"}],
    \"avoid\": [\"Thing NOT to say\", \"Another thing to avoid\"]
  }
}

SCORING GUIDELINES:
- Grade A eligible: builder, architect/designer, tradie, tiler, or property developer; decision-maker identified; project timeline workable; Melbourne-based.
- Grade B eligible: segment match with decision-maker unclear, purchasing-team buyer, or timeline uncertainty.
- Grade C eligible: segment match with significant unknowns, interstate location, or timeline too short for current stock.
- Disqualify or flag: wrong segment, not contactable, immediate larger supply beyond current stock capacity, or explicit no-contact request.
- Score quality should reflect evidence completeness, not whether the lead should be called.

SOURCES: Generate realistic, plausible source URLs based on the company name and industry. Include 2-4 sources that would logically provide the research data.

Generate the research now with real, specific insights based on the prospect information. Include call hooks that lead toward a second engagement: showroom visit, phone consultation booking, sample discussion, or defined follow-up call.";
}

// ============ EMAIL GENERATION ============
// Template-based approach: LLM fills specific parts, we assemble the structure

function generateEmailContent($provider, $apiKey, $lead, $emailType, $customInstructions, $enrichment, $settings, $previousEmail = '', $includeSignature = true, $admin = null, $accountName = '') {
    $firstName = $lead['first_name'] ?? 'there';
    $lastName = $lead['last_name'] ?? '';
    $title = $lead['title'] ?? '';
    $company = $lead['company'] ?? '';
    $industry = $lead['industry'] ?? '';

    // Settings can be present-but-empty (not just unset), which ?? won't
    // catch — so a blank sender_name would still leak through as "Your Name"
    // literally in the email. Prefer the rep's real account name instead.
    $senderName = trim($settings['sender_name'] ?? '') !== '' ? $settings['sender_name'] : ($accountName ?: 'Your Name');
    $senderTitle = $settings['sender_title'] ?? '';
    $senderCompany = $settings['sender_company'] ?? '';
    $companyDesc = $settings['company_description'] ?? 'Macktiles Australia is an Australian architectural tile brand with a Melbourne showroom, 14 contemporary designs, a Visualiser tool, Italian production technology, and the Batik Designer Collection pre-order range';
    $valueProp = $settings['value_proposition'] ?? '';
    $socialProof = $settings['social_proof'] ?? '';
    $signature = $settings['signature'] ?? '';

    // Parse research data
    $research = [];
    if ($enrichment) {
        $parsed = json_decode($enrichment, true);
        if ($parsed) $research = $parsed;
    }

    // Extract useful bits from research
    $companyInfo = $research['company_profile']['description'] ?? '';
    $products = $research['company_profile']['key_products_services'] ?? [];
    $challenges = $research['industry_intelligence']['top_challenges'] ?? [];
    $painPoints = $research['prospect_analysis']['pain_points'] ?? [];
    $hooks = $research['sales_strategy']['opening_hooks'] ?? [];
    $valueAngles = $research['sales_strategy']['value_angles'] ?? [];

    // Build context for LLM
    $context = "PROSPECT: {$firstName} {$lastName}, {$title} at {$company} ({$industry})
COMPANY INFO: {$companyInfo}
PRODUCTS/SERVICES: " . implode(', ', array_slice($products, 0, 3)) . "
THEIR CHALLENGES: " . ($challenges[0]['challenge'] ?? 'operational efficiency') . "
THEIR PAIN POINTS: " . ($painPoints[0]['pain'] ?? 'manual processes') . "
SUGGESTED HOOK: " . ($hooks[0]['hook'] ?? '') . "
VALUE ANGLE: " . ($valueAngles[0]['angle'] ?? $valueProp) . "

SENDER: {$senderName}, {$senderTitle} at {$senderCompany}
WHAT WE SELL: {$companyDesc}
SOCIAL PROOF: {$socialProof}";

    // Add stage intelligence context if available
    if (!empty($lead['pain_hypothesis']['hypotheses'])) {
        $primaryPain = $lead['pain_hypothesis']['hypotheses'][0]['pain'] ?? '';
        $impact = $lead['pain_hypothesis']['commercial_impact']['quantified_impact'] ?? '';
        $context .= "\nVALIDATED PAIN HYPOTHESIS: {$primaryPain}";
        if ($impact) $context .= " (Commercial Impact: {$impact})";
    }
    if (!empty($lead['persona_intelligence']['kpis']['primary_metrics'])) {
        $kpis = implode(', ', $lead['persona_intelligence']['kpis']['primary_metrics']);
        $context .= "\nPERSONA KPIs: {$kpis}";
    }
    if (!empty($lead['persona_intelligence']['objection_profile']['likely_objections'])) {
        $objections = implode(', ', array_slice($lead['persona_intelligence']['objection_profile']['likely_objections'], 0, 2));
        $context .= "\nLIKELY OBJECTIONS: {$objections}";
    }
    if (!empty($lead['account_intelligence']['trigger_signals'])) {
        $signals = [];
        foreach (['growth_signals', 'cost_pressure_signals', 'technology_signals'] as $type) {
            if (!empty($lead['account_intelligence']['trigger_signals'][$type])) {
                $signals = array_merge($signals, array_slice($lead['account_intelligence']['trigger_signals'][$type], 0, 1));
            }
        }
        if ($signals) {
            $context .= "\nTRIGGER SIGNALS: " . implode(', ', $signals);
        }
    }

    // Add email performance context from outcomes
    if (!empty($lead['email_history'])) {
        $replied = 0; $noResponse = 0; $bounced = 0; $meetingBooked = 0;
        foreach ($lead['email_history'] as $eh) {
            $o = $eh['outcome'] ?? '';
            if ($o === 'replied') $replied++;
            elseif ($o === 'no_response') $noResponse++;
            elseif ($o === 'bounced') $bounced++;
            elseif ($o === 'meeting_booked') $meetingBooked++;
        }
        if ($noResponse > 0) {
            $context .= "\nEMAIL HISTORY: {$noResponse} email(s) got no response. Try a different angle or shorter message.";
        }
        if ($replied > 0) {
            $context .= "\nEMAIL HISTORY: Previous email(s) got replies - maintain similar conversational tone.";
        }
        if ($meetingBooked > 0) {
            $context .= "\nEMAIL HISTORY: A meeting was booked before. Reference the relationship.";
        }
    }

    // Different prompts based on email type
    // Language style guidelines applied to all email types
    $languageRules = "
WRITING STYLE:
- Use simple, clear English that is easy to read and understand
- Keep sentences short (under 20 words where possible)
- Avoid jargon, buzzwords, and corporate-speak
- Use proper capitalization: capitalize only the first word of sentences and proper nouns (names, company names)
- Do NOT use all caps except for acronyms
- No exclamation marks - keep it calm and professional
- Write conversationally, like a colleague, not a salesperson
- Australian business English: direct, practical, and not overly formal
- The goal is the next conversation, not selling tiles in the email
- Never attach or offer to attach a company profile or product catalogue in a cold email
- Never use the subject line to make a sales claim
- Do not mention Sri Lanka or manufacturing origin in cold emails
- If manufacturing comes up, frame credibility as Italian robotics, Italian digital printing inks, and Italian production process
";

    // Rendered as a hard requirement right before the task instructions (not
    // buried in the context block) so the model can't skip it while following
    // the strict part-by-part output format below.
    $instructionsBlock = $customInstructions
        ? "\nMANDATORY INSTRUCTIONS FROM THE REP — YOU MUST FOLLOW THESE, THEY OVERRIDE ANY CONFLICTING GUIDANCE ABOVE:\n{$customInstructions}\n"
        : '';

    switch ($emailType) {
        case 'initial':
            $prompt = "{$context}
{$languageRules}
{$instructionsBlock}
Generate these 4 parts for a cold email. Be SPECIFIC using the research above. No generic filler.

1. SUBJECT (4-6 words, lowercase except proper nouns, specific to their business, creates curiosity, and does not make a sales claim - like \"tiles for {$company} projects?\" or \"tile options for {$industry}?\")

2. OPENER (1-2 sentences that reference something SPECIFIC about their company, role, or situation. Use the research. Write simply and directly. Example: \"Saw {$company} recently completed that development in Melbourne\" or \"Managing tile supply across multiple {$industry} projects is always a juggling act\")

3. PROBLEM_BRIDGE (1-2 sentences: Name a specific problem they likely face with tile sourcing, then connect it to Macktiles' Melbourne showroom, Visualiser tool, curated 14-design range, availability for renovation-scale projects, or design-forward Batik range where relevant. No hard sell.)

4. CTA (1 clear, low-friction question that points to a phone consultation, showroom visit, sample conversation, or quick follow-up call. Do not ask to send a catalogue.)

Return ONLY in this exact format:
SUBJECT: [your subject]
OPENER: [your opener]
PROBLEM_BRIDGE: [your problem and bridge]
CTA: [your question]";
            break;

        case 'followup1':
            $prompt = "{$context}
{$languageRules}
{$instructionsBlock}
PREVIOUS EMAIL SENT:
{$previousEmail}

Generate these 3 parts for follow-up #1 (3-4 days after initial). Reference the previous email naturally.

1. SUBJECT (either \"Re: [original topic]\" or a new angle - use lowercase except proper nouns; no sales claim)

2. RECONNECT_VALUE (2-3 sentences: Brief casual reconnect referencing your last email, then share something useful about their project type, current tile sourcing, or Macktiles' Visualiser/showroom/stock angle. No hard sell.)

3. CTA (1 direct question, more specific than first email)

Return ONLY in this exact format:
SUBJECT: [your subject]
RECONNECT_VALUE: [your reconnect and new value]
CTA: [your question]";
            break;

        case 'followup2':
            $prompt = "{$context}
{$languageRules}
{$instructionsBlock}
PREVIOUS EMAIL SENT:
{$previousEmail}

Generate these 3 parts for follow-up #2 (final value-add before breakup).

1. SUBJECT (continue thread or case study hook - use lowercase except proper nouns)

2. ACKNOWLEDGE_PROOF (2-3 sentences: Acknowledge they are busy, then lead with one practical proof point or product angle. Reference the Batik Collection pre-order or Visualiser only where relevant. Keep it brief.)

3. CTA_EASYOUT (1-2 sentences: Ask for the meeting but give them an easy out)

Return ONLY in this exact format:
SUBJECT: [your subject]
ACKNOWLEDGE_PROOF: [your acknowledgment and proof]
CTA_EASYOUT: [your ask with easy out]";
            break;

        case 'breakup':
            $prompt = "{$context}
{$languageRules}
{$instructionsBlock}
Generate these 2 parts for a breakup email (final, closing the loop).

1. SUBJECT (\"closing the loop\" or \"should I close your file?\" - use lowercase)

2. BODY (3-4 sentences: Acknowledge you have reached out without response - no guilt. Give permission to say no. Leave door open for future. Keep it dignified and simple.)

Return ONLY in this exact format:
SUBJECT: [your subject]
BODY: [your complete body]";
            break;

        default:
            $prompt = "{$context}\n{$instructionsBlock}\nWrite a brief professional email.";
    }

    // Call LLM
    $res = callLLM($provider, $apiKey, $prompt, $admin);

    if (!$res['success']) {
        return $res;
    }

    // Parse LLM response
    $llmOutput = $res['content'];
    $parts = [];

    // Extract parts using regex
    if (preg_match('/SUBJECT:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['subject'] = trim($m[1]);
    }
    if (preg_match('/OPENER:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['opener'] = trim($m[1]);
    }
    if (preg_match('/PROBLEM_BRIDGE:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['problem_bridge'] = trim($m[1]);
    }
    if (preg_match('/CTA:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['cta'] = trim($m[1]);
    }
    if (preg_match('/RECONNECT_VALUE:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['reconnect_value'] = trim($m[1]);
    }
    if (preg_match('/ACKNOWLEDGE_PROOF:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['acknowledge_proof'] = trim($m[1]);
    }
    if (preg_match('/CTA_EASYOUT:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['cta_easyout'] = trim($m[1]);
    }
    if (preg_match('/BODY:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['body'] = trim($m[1]);
    }

    // Assemble final email with guaranteed structure
    $subject = $parts['subject'] ?? 'Quick question';
    $subject = trim(str_replace(['—', '–', '"', '"'], ['', '', '"', '"'], $subject));
    $subject = ucfirst($subject); // Capitalize first letter

    switch ($emailType) {
        case 'initial':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['opener'] ?? "Hope you're doing well.") . "\n\n";
            $body .= ($parts['problem_bridge'] ?? '') . "\n\n";
            $body .= ($parts['cta'] ?? 'Would this be worth a conversation?') . "\n\n";
            $body .= $senderName;
            if ($senderTitle) $body .= "\n{$senderTitle}";
            if ($senderCompany) $body .= "\n{$senderCompany}";
            break;

        case 'followup1':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['reconnect_value'] ?? 'Following up on my previous note.') . "\n\n";
            $body .= ($parts['cta'] ?? 'Worth a quick call?') . "\n\n";
            $body .= $senderName;
            break;

        case 'followup2':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['acknowledge_proof'] ?? 'I know you\'re busy, so I\'ll keep this short.') . "\n\n";
            $body .= ($parts['cta_easyout'] ?? 'If the timing isn\'t right, no worries at all.') . "\n\n";
            $body .= $senderName;
            break;

        case 'breakup':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['body'] ?? 'I\'ve reached out a few times without hearing back. Should I close your file, or would it make sense to reconnect in a few months?') . "\n\n";
            $body .= $senderName;
            break;

        default:
            $body = "Hi {$firstName},\n\n{$llmOutput}\n\n{$senderName}";
    }

    // Clean up the body - remove em-dashes and excessive punctuation
    $body = str_replace(['—', '–'], [',', ','], $body);
    $body = preg_replace('/\n{3,}/', "\n\n", $body);

    // Add signature if enabled and exists
    if ($includeSignature && !empty($signature)) {
        $body .= "\n\n" . $signature;
    }

    return [
        'success' => true,
        'content' => "SUBJECT: {$subject}\n\n{$body}"
    ];
}

// ============ CALL PITCH GENERATION ============

function generateCallPitch($provider, $apiKey, $name, $title, $company, $industry, $pitchType, $customInstructions, $settings, $leadData = null, $admin = null, $accountName = '') {
    // Settings values can be present-but-empty (not just unset), which ??
    // won't catch — so a blank string still needs an explicit fallback or the
    // script reads "it's here" / "I'm the ." Prefer the rep's real account
    // name over a generic placeholder since it's actually usable on a call.
    $senderName = trim($settings['sender_name'] ?? '') !== '' ? $settings['sender_name'] : ($accountName ?: 'Your Name');
    $senderTitle = trim($settings['sender_title'] ?? '') !== '' ? $settings['sender_title'] : 'Business Development Manager';
    $senderCompany = $settings['sender_company'] ?? 'Macktiles Australia';
    $companyDesc = $settings['company_description'] ?? 'Macktiles Australia is an Australian architectural tile brand with a Melbourne showroom, 14 contemporary designs, the Macktiles Visualiser tool, Italian production technology, and the Batik Designer Collection pre-order range';
    $valueProp = $settings['value_proposition'] ?? '';

    $emailSent = ($pitchType === 'cold_with_email' || $pitchType === 'email_sent');

    $context = "PROSPECT: {$name}" . ($title ? ", {$title}" : "") . ($company ? " at {$company}" : "") . ($industry ? " ({$industry})" : "") . "
CALLER: {$senderName}, {$senderTitle} from {$senderCompany}
WHAT WE DO: {$companyDesc}
EMAIL ALREADY SENT: " . ($emailSent ? 'Yes' : 'No');

    if ($customInstructions) {
        $context .= "\n\nCUSTOM CONTEXT:\n{$customInstructions}";
    }

    // Add stage intelligence context if available
    if ($leadData) {
        if (!empty($leadData['pain_hypothesis']['hypotheses'])) {
            $painList = [];
            foreach (array_slice($leadData['pain_hypothesis']['hypotheses'], 0, 3) as $h) {
                $painList[] = $h['pain'] ?? '';
            }
            $context .= "\n\nVALIDATED PAIN POINTS:\n- " . implode("\n- ", array_filter($painList));
        }
        if (!empty($leadData['persona_intelligence']['kpis']['primary_metrics'])) {
            $context .= "\n\nPERSONA KPIs: " . implode(', ', $leadData['persona_intelligence']['kpis']['primary_metrics']);
        }
        if (!empty($leadData['persona_intelligence']['objection_profile']['likely_objections'])) {
            $context .= "\n\nLIKELY OBJECTIONS:\n- " . implode("\n- ", array_slice($leadData['persona_intelligence']['objection_profile']['likely_objections'], 0, 3));
        }
        if (!empty($leadData['account_intelligence']['trigger_signals'])) {
            $signals = [];
            foreach (['growth_signals', 'cost_pressure_signals', 'technology_signals'] as $type) {
                if (!empty($leadData['account_intelligence']['trigger_signals'][$type])) {
                    $signals = array_merge($signals, array_slice($leadData['account_intelligence']['trigger_signals'][$type], 0, 1));
                }
            }
            if ($signals) {
                $context .= "\n\nTRIGGER SIGNALS: " . implode(', ', $signals);
            }
        }
    }

    $reasonA = "I will be really quick. I am calling about an email I sent earlier about Macktiles. We manufacture and supply premium tiles across Australia and I wanted to see if you had a chance to look at it.";

    $reasonB = "I will be really quick. We are Macktiles Australia — we manufacture and supply premium wall and floor tiles. We have been working with a lot of builders and designers in your area and I wanted to see if you are currently looking at your tile supply.";

    $prompt = "{$context}

WRITING STYLE FOR CALL SCRIPT:
- Use simple, clear English that is easy to say out loud
- Keep sentences short and natural
- Avoid jargon and corporate buzzwords
- Write conversationally, like you are talking to a colleague
- Use contractions naturally (I am, we are, you are)
- The goal of every first call is a second engagement: showroom visit, phone consultation, sample discussion, or defined follow-up call
- Position Macktiles as designed and tested for Australian interiors
- Mention the Visualiser tool, Melbourne showroom, curated 14-design range, Italian technology, and Batik pre-order angle only where relevant
- Do not lead with Sri Lanka. If asked where tiles are made, say quality Sri Lankan clay with Italian manufacturing technology

Generate a call script following the EXACT structure below. Use the visual formatting exactly as shown - the emojis, boxes, and arrows help the sales person navigate during a live call.

╔══════════════════════════════════════════════════════════════╗
║  📞 OPENING                                                   ║
╚══════════════════════════════════════════════════════════════╝

\"Hey {$name}, it's {$senderName} here from {$senderCompany}. I'm the {$senderTitle}. How are you doing today?\"

(If they ask): \"I'm doing well, thanks for asking.\"

╔══════════════════════════════════════════════════════════════╗
║  🎯 REASON FOR CALL                                           ║
╚══════════════════════════════════════════════════════════════╝

" . ($emailSent ? "\"{$reasonA}\"" : "\"{$reasonB}\"") . "

\"Do you have a couple of minutes, or is now a bad time?\"

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY NOW IS NOT A GOOD TIME                           │
└─────────────────────────────────────────────────────────────┘
\"No problem at all — when would be a better time to call back?\"

      ↳ They say YES, they have a couple of minutes → Continue to WHO WE ARE ⬇️
      ↳ They say NOT INTERESTED at all → Skip to GRACEFUL EXIT

╔══════════════════════════════════════════════════════════════╗
║  🏢 WHO WE ARE                                                ║
╚══════════════════════════════════════════════════════════════╝

\"At {$senderCompany}, we are an Australian architectural tile brand with a Melbourne showroom and a curated range for modern Australian interiors. We work directly with builders, architects, designers, tradies, tilers, and developers.

Our range has 14 contemporary designs, including terrazzo, travertine, cotto, and stone looks. We also have a Visualiser tool so prospects can see tiles in an actual space before committing.\"

╔══════════════════════════════════════════════════════════════╗
║  ⚡ PAIN POINTS                                               ║
╚══════════════════════════════════════════════════════════════╝

\"What we commonly hear from businesses like yours is:
• Inconsistent tile quality from current suppliers
• Long lead times or stock-outs that delay projects
• Having to juggle multiple suppliers to get the full range you need
• [ADD 2 INDUSTRY-SPECIFIC PAIN POINTS FOR {$industry}]

Does any of that sound familiar?\"

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY NO (nothing familiar)                           │
└─────────────────────────────────────────────────────────────┘
\"Understood. Just out of curiosity:
• Are you getting the tile range and quality you need from your current supplier?
• Are deliveries reliable and on time for your projects?
• Are you happy with the pricing you are getting right now?\"

      ↳ Something resonates → Continue to THE ASK ⬇️
      ↳ Still no           → Skip to GRACEFUL EXIT

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY YES (something resonates)                       │
└─────────────────────────────────────────────────────────────┘
\"That's helpful. If you could fix one thing with your tile supply tomorrow — better pricing, faster delivery, wider range, or more consistent quality — which would you pick?\"

[LISTEN]

\"I know you're busy, so I won't take more time. Based on what you've shared, there may be an opportunity worth exploring.\"

╔══════════════════════════════════════════════════════════════╗
║  📅 THE ASK                                                   ║
╚══════════════════════════════════════════════════════════════╝

\"What I would suggest is a quick next step, either a phone consultation, a showroom visit, or a sample discussion for a current project.

In that conversation we can:
• Check which designs fit your project
• Talk through stock timing and lead time
• Show how the Visualiser could help with selection

No pressure at all, just a practical look at whether there is a fit. Would you be open to that?\"

┌─────────────────────────────────────────────────────────────┐
│  ✅ THEY SAY YES → BOOK IT                                   │
└─────────────────────────────────────────────────────────────┘
\"Great. I've got my calendar open. Would [Day] work, or would [Day] be better?\"

[THEY PICK]

\"Perfect. I'll send a calendar invite. Your email is [confirm] — correct?\"

\"Thanks {$name}, looking forward to [Day]. Have a great day.\"

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY NO to the ask                                   │
└─────────────────────────────────────────────────────────────┘
      ↳ Skip to GRACEFUL EXIT

╔══════════════════════════════════════════════════════════════╗
║  🚪 GRACEFUL EXIT (if not interested)                        ║
╚══════════════════════════════════════════════════════════════╝

\"No problem at all. Would it be better if I checked back in a few months, or should I leave it there for now?\"

\"Thanks for your time, {$name}. Have a great day.\"

══════════════════════════════════════════════════════════════

INSTRUCTIONS:
1. Keep this EXACT structure with all the box formatting
2. Replace [ADD 2 INDUSTRY-SPECIFIC PAIN POINTS] with real pain points for {$industry}
3. Keep all the navigation arrows (↳ ⬇️) so the caller knows where to go. Do NOT put the 🚪 emoji anywhere except the GRACEFUL EXIT header itself — it must never appear in a Skip to GRACEFUL EXIT navigation line, only in the actual box title
4. Everything in quotes is what to say out loud
5. There is exactly ONE GRACEFUL EXIT box in the whole script, placed once at the very end. There are exactly THREE points in the script that jump to it — after the opening if they say not interested, after pain points if still nothing resonates, and after THE ASK if they decline the meeting. Do NOT write GRACEFUL EXIT text at any of those three points — only write the jump instruction there, and write the actual GRACEFUL EXIT box once, at the end
6. Do not invent any additional branches, boxes, or exit points beyond the ones already in this template";

    $res = callLLM($provider, $apiKey, $prompt, $admin);

    if (!$res['success']) {
        return $res;
    }

    $pitchTitles = [
        'cold_with_email' => 'Cold Call Script (Email Sent)',
        'email_sent' => 'Cold Call Script (Email Sent)',
        'cold_no_email' => 'Cold Call Script (No Prior Email)',
        'no_email' => 'Cold Call Script (No Prior Email)',
        'callback' => 'Call Back Script',
        'discovery' => 'Discovery Call Script',
        'demo' => 'Demo Introduction Script'
    ];

    return [
        'success' => true,
        'title' => $pitchTitles[$pitchType] ?? 'Call Script',
        'pitch' => $res['content']
    ];
}

// ============ AIRCALL API HELPER FUNCTIONS ============

function aircallRequest($method, $path, $body = null) {
    $admin = getAdmin();
    $id = trim((string)($admin['aircall_api_id'] ?? ''));
    $token = trim((string)($admin['aircall_api_token'] ?? ''));
    if (!$id || !$token) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Aircall API keys not configured'];

    $ch = curl_init('https://api.aircall.io/v1' . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $id . ':' . $token,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'Could not reach Aircall: ' . $curlErr];
    $json = json_decode($response, true);
    $ok = $status >= 200 && $status < 300;
    $error = '';
    if (!$ok) {
        if (getenv('QA_DEBUG')) error_log('[aircall] ' . $status . ' ' . $method . ' ' . $path . ' -> ' . $response);
        if ($status === 401 || $status === 403) $error = 'Aircall rejected the API keys. Check the API ID and Token.';
        elseif (is_array($json) && !empty($json['message'])) $error = 'Aircall: ' . $json['message'];
        elseif (is_array($json) && !empty($json['error'])) $error = 'Aircall: ' . (is_array($json['error']) ? json_encode($json['error']) : $json['error']);
        elseif (is_array($json) && !empty($json['errors'])) $error = 'Aircall: ' . json_encode($json['errors']);
        elseif ($response) $error = 'Aircall: ' . substr($response, 0, 300);
    }
    return ['ok' => $ok, 'status' => $status, 'body' => $json, 'error' => $error];
}

function aircallFindUserByAircallId($aircallUserId) {
    if (!$aircallUserId) return null;
    foreach (getUsers() as $u) {
        if ((string)($u['aircall_user_id'] ?? '') === (string)$aircallUserId) return $u;
    }
    return null;
}

function aircallNormalizePhone($phone) {
    return preg_replace('/\D+/', '', (string)$phone);
}

// Phones match when normalized digits are equal, or one ends with the other's
// last 8 digits (handles +61 4xx vs 04xx style prefix differences).
function aircallPhonesMatch($a, $b) {
    $a = aircallNormalizePhone($a);
    $b = aircallNormalizePhone($b);
    if (strlen($a) < 6 || strlen($b) < 6) return false;
    if ($a === $b) return true;
    $suffix = min(8, strlen($a), strlen($b));
    return substr($a, -$suffix) === substr($b, -$suffix);
}

// Search all users' leads for a phone match. $preferUserId is checked first so
// calls land on the dialing rep's copy of the lead when duplicates exist.
function aircallFindLeadByPhone($phone, $preferUserId = null) {
    if (strlen(aircallNormalizePhone($phone)) < 6) return null;
    $users = getUsers();
    if ($preferUserId) {
        usort($users, fn($x, $y) => ($y['id'] === $preferUserId) <=> ($x['id'] === $preferUserId));
    }
    foreach ($users as $u) {
        foreach (dbLoadLeadsByOwner($u['id']) as $lead) {
            // Never match a trashed lead — a call to that number shouldn't
            // silently resurrect stage/call_history on something the rep deleted.
            if (!empty($lead['deleted_at'])) continue;
            if (aircallPhonesMatch($lead['phone'] ?? '', $phone)) {
                return ['owner_id' => $u['id'], 'lead_id' => $lead['id']];
            }
        }
    }
    return null;
}

// Aircall recording URLs expire ~10 minutes after call.ended fires, so download
// the file immediately and serve our own copy. Falls back to the raw URL.
function aircallStoreRecording($url, $callId) {
    if (!$url) return '';
    $dir = DATA_DIR . '/recordings';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)$callId);
    $file = $dir . '/aircall_' . $safeId . '.mp3';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $audio = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audio !== false && $status === 200 && strlen($audio) > 0 && @file_put_contents($file, $audio) !== false) {
        return 'data/recordings/aircall_' . $safeId . '.mp3';
    }
    return $url; // Download failed — keep the original (short-lived) URL
}

// Notify every admin whenever a rep's Aircall call finishes — answered or
// missed — so they can jump straight to the recording from the bell.
function notifyAdminsOfCall(array $lead, string $repName, string $callId, bool $missed, int $durationSeconds, string $recordingUrl): void {
    $leadName = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    if ($leadName === '') $leadName = $lead['company'] ?? 'a lead';
    $durLabel = $durationSeconds > 0 ? (intdiv($durationSeconds, 60) . 'm ' . ($durationSeconds % 60) . 's') : '';
    foreach (getAllUsersIncludingInternal() as $u) {
        if (empty($u['is_admin']) && empty($u['is_super_admin'])) continue;
        pushChatNotification($u['id'], [
            'id'            => 'notif_' . bin2hex(random_bytes(6)),
            'notif_key'     => "aircall_call_{$callId}_{$u['id']}",
            'type'          => $missed ? 'callback_overdue' : 'call_logged',
            'title'         => $missed ? "Missed call — {$repName}" : "Call completed — {$repName}",
            'body'          => $missed ? "{$repName} missed a call with {$leadName}" : "{$repName} called {$leadName}" . ($durLabel ? " ({$durLabel})" : ''),
            'lead_id'       => $lead['id'] ?? '',
            'recording_url' => $recordingUrl,
            'created_at'    => date('c'),
            'read'          => false,
        ]);
    }
}
