<?php
/**
 * Tools for the Tough Days — Admin Lead Magnet (free guide) signups API
 *
 * GET    /api/admin/lead-magnet.php                          list + search + pagination
 * GET    /api/admin/lead-magnet.php?action=export             CSV export (same filters as list)
 * POST   /api/admin/lead-magnet.php?action=delete  {id}       delete one signup
 */

declare(strict_types=1);

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/admin_auth.php';

header('Content-Type: application/json; charset=utf-8');

$admin = require_admin_auth();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? '';

switch ($action) {
    case '':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_list();
        break;
    case 'export':
        if ($method !== 'GET') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'GET required.');
        }
        handle_export($admin);
        break;
    case 'delete':
        if ($method !== 'POST') {
            json_error(405, 'METHOD_NOT_ALLOWED', 'POST required.');
        }
        handle_delete($admin);
        break;
    default:
        json_error(400, 'INVALID_ACTION', 'Unknown action.');
}

// ─────────────────────────────────────────────
// LIST (search + newsletter filter + pagination)
// ─────────────────────────────────────────────
function handle_list(): never
{
    $q          = trim($_GET['q'] ?? '');
    $newsletter = $_GET['newsletter'] ?? 'all';
    $page       = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize   = (int) ($_GET['page_size'] ?? 25);
    $pageSize   = $pageSize > 0 ? min(100, $pageSize) : 25;
    $offset     = ($page - 1) * $pageSize;

    if (!in_array($newsletter, ['all', 'yes', 'no'], true)) {
        json_error(422, 'INVALID_FILTER', 'newsletter must be one of: all, yes, no.');
    }

    [$where, $params] = build_filter($q, $newsletter);

    $countStmt = db()->prepare("SELECT COUNT(*) AS total FROM lead_magnet_signups WHERE {$where}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetch()['total'];

    $listStmt = db()->prepare(
        "SELECT id, email, lead_magnet, newsletter_opt_in, email_sent,
                utm_source, utm_medium, utm_campaign, landing_page, created_at
         FROM lead_magnet_signups
         WHERE {$where}
         ORDER BY created_at DESC
         LIMIT {$pageSize} OFFSET {$offset}"
    );
    $listStmt->execute($params);

    $signups = array_map('format_signup_row', $listStmt->fetchAll());

    json_ok([
        'signups'     => $signups,
        'page'        => $page,
        'page_size'   => $pageSize,
        'total'       => $total,
        'total_pages' => $pageSize > 0 ? (int) ceil($total / $pageSize) : 0,
    ]);
}

/**
 * Builds a WHERE clause + bound params array for the search/newsletter filter.
 * @return array{0:string,1:array}
 */
function build_filter(string $q, string $newsletter): array
{
    $conditions = ['1=1'];
    $params     = [];

    if ($q !== '') {
        $conditions[] = 'email LIKE ?';
        $params[] = '%' . $q . '%';
    }

    if ($newsletter === 'yes') {
        $conditions[] = 'newsletter_opt_in = 1';
    } elseif ($newsletter === 'no') {
        $conditions[] = 'newsletter_opt_in = 0';
    }

    return [implode(' AND ', $conditions), $params];
}

function format_signup_row(array $row): array
{
    return [
        'id'                => (int) $row['id'],
        'email'             => $row['email'],
        'lead_magnet'       => $row['lead_magnet'],
        'newsletter_opt_in' => (bool) $row['newsletter_opt_in'],
        'email_sent'        => (bool) $row['email_sent'],
        'utm_source'        => $row['utm_source'],
        'utm_medium'        => $row['utm_medium'],
        'utm_campaign'      => $row['utm_campaign'],
        'landing_page'      => $row['landing_page'],
        'created_at'        => $row['created_at'],
    ];
}

// ─────────────────────────────────────────────
// EXPORT (CSV, same filters as list, no pagination)
// ─────────────────────────────────────────────
function handle_export(array $admin): never
{
    $q          = trim($_GET['q'] ?? '');
    $newsletter = $_GET['newsletter'] ?? 'all';

    if (!in_array($newsletter, ['all', 'yes', 'no'], true)) {
        json_error(422, 'INVALID_FILTER', 'newsletter must be one of: all, yes, no.');
    }

    [$where, $params] = build_filter($q, $newsletter);

    audit('admin.lead_magnet.export', null, ['q' => $q, 'newsletter' => $newsletter], (int) $admin['id']);

    $filename = 'lead-magnet-signups-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'id', 'email', 'lead_magnet', 'newsletter_opt_in', 'email_sent',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'landing_page', 'signup_referrer', 'created_at',
    ]);

    $chunkSize = 500;
    $offset    = 0;

    while (true) {
        $stmt = db()->prepare(
            "SELECT id, email, lead_magnet, newsletter_opt_in, email_sent,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    gclid, fbclid, landing_page, signup_referrer, created_at
             FROM lead_magnet_signups
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT {$chunkSize} OFFSET {$offset}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['id'],
                $row['email'],
                $row['lead_magnet'],
                $row['newsletter_opt_in'] ? '1' : '0',
                $row['email_sent'] ? '1' : '0',
                $row['utm_source'],
                $row['utm_medium'],
                $row['utm_campaign'],
                $row['utm_term'],
                $row['utm_content'],
                $row['gclid'],
                $row['fbclid'],
                $row['landing_page'],
                $row['signup_referrer'],
                $row['created_at'],
            ]);
        }

        $offset += $chunkSize;
        if (count($rows) < $chunkSize) {
            break;
        }
    }

    fclose($out);
    exit;
}

// ─────────────────────────────────────────────
// DELETE — e.g. for handling a data-removal request
// ─────────────────────────────────────────────
function handle_delete(array $admin): never
{
    $raw  = file_get_contents('php://input');
    $body = $raw !== '' ? (json_decode($raw, true) ?? []) : $_POST;

    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        json_error(400, 'MISSING_ID', 'id is required.');
    }

    $stmt = db()->prepare('SELECT id, email FROM lead_magnet_signups WHERE id = ?');
    $stmt->execute([$id]);
    $signup = $stmt->fetch();

    if (!$signup) {
        json_error(404, 'NOT_FOUND', 'Signup not found.');
    }

    db()->prepare('DELETE FROM lead_magnet_signups WHERE id = ?')->execute([$id]);

    audit('admin.lead_magnet.delete', null, ['id' => $id, 'email' => $signup['email']], (int) $admin['id']);

    json_ok(['message' => 'Signup deleted.']);
}
