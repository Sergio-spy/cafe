<?php
/**
 * Café API — backend único con PHP + SQLite
 * v2: asistentes por ronda + algoritmo de turno mejorado
 */

define('SECRET_TOKEN', 'token_cafe_mrcoach');
define('DB_PATH',      __DIR__ . '/cafe.db');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$token = $_SERVER['HTTP_X_TOKEN'] ?? '';
if ($token !== SECRET_TOKEN) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

function getDB(): PDO {
    static $db = null;
    if ($db) return $db;

    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('PRAGMA foreign_keys=OFF');

    $db->exec("
        CREATE TABLE IF NOT EXISTS people (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            active     INTEGER NOT NULL DEFAULT 1,
            color      TEXT    NOT NULL DEFAULT 'c-amber',
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        );
    ");

    // Comprobar columnas actuales de payments
    $cols = $db->query("PRAGMA table_info(payments)")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cols)) {
        // Tabla nueva — crearla con todas las columnas
        $db->exec("
            CREATE TABLE payments (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                person_id    INTEGER NOT NULL REFERENCES people(id),
                amount       REAL    NOT NULL,
                attendee_ids TEXT    DEFAULT '[]',
                paid_at      TEXT    NOT NULL DEFAULT (datetime('now')),
                note         TEXT
            );
        ");
    } else {
        // Tabla existente — añadir attendee_ids si no existe
        $existingCols = array_column($cols, 'name');
        if (!in_array('attendee_ids', $existingCols)) {
            // Sin NOT NULL para compatibilidad con SQLite antiguo
            $db->exec("ALTER TABLE payments ADD COLUMN attendee_ids TEXT DEFAULT '[]'");
            $db->exec("UPDATE payments SET attendee_ids = '[]' WHERE attendee_ids IS NULL");
        }
    }

    $db->exec('PRAGMA foreign_keys=ON');
    return $db;
}

// ── Algoritmo de turno ──────────────────────────────────────────────────────
// Solo evalúa a los asistentes de hoy.
// Score = (veces_que_ha_pagado × importe_medio_global) + total_pagado_histórico
// Menor score = le toca pagar. Desempate: menos veces pagado → alfabético.
function calcNextPayer(PDO $db, array $candidateIds): ?array {
    if (empty($candidateIds)) return null;

    $avgRound = (float)$db->query("SELECT COALESCE(AVG(amount), 5.0) AS avg FROM payments")->fetch()['avg'];

    $placeholders = implode(',', array_fill(0, count($candidateIds), '?'));
    $stmt = $db->prepare("SELECT * FROM people WHERE id IN ($placeholders) AND active = 1");
    $stmt->execute($candidateIds);
    $people = $stmt->fetchAll();

    if (empty($people)) return null;

    $statsStmt = $db->prepare("
        SELECT COUNT(*) AS times, COALESCE(SUM(amount), 0) AS total
        FROM payments WHERE person_id = ?
    ");

    $scored = [];
    foreach ($people as $p) {
        $statsStmt->execute([$p['id']]);
        $s = $statsStmt->fetch();
        $times = (int)$s['times'];
        $total = (float)$s['total'];
        $scored[] = array_merge($p, [
            'times' => $times,
            'total' => round($total, 2),
            'score' => round(($times * $avgRound) + $total, 4),
        ]);
    }

    usort($scored, function($a, $b) {
        if ($a['score'] !== $b['score']) return $a['score'] <=> $b['score'];
        if ($a['times'] !== $b['times']) return $a['times'] <=> $b['times'];
        return strcmp($a['name'], $b['name']);
    });

    return $scored[0];
}

// ── Router ──────────────────────────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$path   = preg_replace('#^.*/api\.php/?#', '', $path);
$body   = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    $db = getDB();

    // ── GET /stats ───────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === 'stats') {
        $people = $db->query("SELECT * FROM people ORDER BY active DESC, name ASC")->fetchAll();

        $statsStmt = $db->prepare("
            SELECT COUNT(*) AS times, COALESCE(SUM(amount),0) AS total, COALESCE(AVG(amount),0) AS avg_amount
            FROM payments WHERE person_id = ?
        ");
        $avgRound = (float)$db->query("SELECT COALESCE(AVG(amount),5.0) AS avg FROM payments")->fetch()['avg'];

        $enriched = [];
        $nameMap  = [];
        foreach ($people as $p) {
            $statsStmt->execute([$p['id']]);
            $s = $statsStmt->fetch();
            $times = (int)$s['times'];
            $total = (float)$s['total'];
            $row = array_merge($p, [
                'times'      => $times,
                'total'      => round($total, 2),
                'avg_amount' => round((float)$s['avg_amount'], 2),
                'score'      => round(($times * $avgRound) + $total, 4),
            ]);
            $enriched[] = $row;
            $nameMap[$p['id']] = ['name' => $p['name'], 'color' => $p['color']];
        }

        // Últimos 20 pagos enriquecidos con lista de asistentes
        $recentPayments = $db->query("
            SELECT p.*, pe.name, pe.color
            FROM payments p JOIN people pe ON pe.id = p.person_id
            ORDER BY p.paid_at DESC LIMIT 20
        ")->fetchAll();

        foreach ($recentPayments as &$pay) {
            $ids = json_decode($pay['attendee_ids'] ?? '[]', true);
            $pay['attendees'] = array_values(array_filter(
                array_map(fn($id) => isset($nameMap[$id]) ? $nameMap[$id] : null, $ids)
            ));
            $pay['attendee_count'] = count($ids);
        }
        unset($pay);

        $totals = $db->query("SELECT COUNT(*) AS rounds, COALESCE(SUM(amount),0) AS total_spent FROM payments")->fetch();
        $activeIds = array_map(fn($p) => $p['id'], array_filter($enriched, fn($p) => $p['active'] == 1));

        echo json_encode([
            'people'          => $enriched,
            'next_payer'      => calcNextPayer($db, $activeIds),
            'recent_payments' => $recentPayments,
            'avg_round'       => round($avgRound, 2),
            'rounds_total'    => (int)$totals['rounds'],
            'total_spent'     => round((float)$totals['total_spent'], 2),
        ]);
        exit;
    }

    // ── POST /suggest — sugerencia dado un grupo de asistentes ──────────────
    // Body: { "attendee_ids": [1, 3, 4] }
    if ($method === 'POST' && $path === 'suggest') {
        $ids = array_map('intval', $body['attendee_ids'] ?? []);
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'Necesito al menos un asistente']);
            exit;
        }
        echo json_encode(['next_payer' => calcNextPayer($db, $ids)]);
        exit;
    }

    // ── GET /people ──────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === 'people') {
        echo json_encode($db->query("SELECT * FROM people ORDER BY active DESC, name ASC")->fetchAll());
        exit;
    }

    // ── POST /people ─────────────────────────────────────────────────────────
    if ($method === 'POST' && $path === 'people') {
        $name  = trim($body['name'] ?? '');
        $color = trim($body['color'] ?? 'c-amber');
        if (!$name) { http_response_code(400); echo json_encode(['error' => 'Nombre requerido']); exit; }
        $stmt = $db->prepare("INSERT INTO people (name, color) VALUES (?, ?)");
        $stmt->execute([$name, $color]);
        echo json_encode(['id' => (int)$db->lastInsertId(), 'name' => $name, 'color' => $color, 'active' => 1, 'times' => 0, 'total' => 0]);
        exit;
    }

    // ── PUT /people/{id} ─────────────────────────────────────────────────────
    if ($method === 'PUT' && preg_match('#^people/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $fields = []; $vals = [];
        if (isset($body['name']))   { $fields[] = 'name = ?';   $vals[] = trim($body['name']); }
        if (isset($body['active'])) { $fields[] = 'active = ?'; $vals[] = (int)$body['active']; }
        if (isset($body['color']))  { $fields[] = 'color = ?';  $vals[] = $body['color']; }
        if (!$fields) { http_response_code(400); echo json_encode(['error' => 'Sin cambios']); exit; }
        $vals[] = $id;
        $db->prepare("UPDATE people SET " . implode(', ', $fields) . " WHERE id = ?")->execute($vals);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── DELETE /people/{id} ──────────────────────────────────────────────────
    if ($method === 'DELETE' && preg_match('#^people/(\d+)$#', $path, $m)) {
        $db->prepare("UPDATE people SET active = 0 WHERE id = ?")->execute([(int)$m[1]]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── POST /payments ───────────────────────────────────────────────────────
    // Body: { "person_id": 3, "amount": 4.80, "attendee_ids": [1,2,3,4], "note": "" }
    if ($method === 'POST' && $path === 'payments') {
        $personId    = (int)($body['person_id'] ?? 0);
        $amount      = (float)($body['amount'] ?? 0);
        $attendeeIds = array_map('intval', $body['attendee_ids'] ?? []);
        $note        = trim($body['note'] ?? '');

        if (!$personId || $amount <= 0) {
            http_response_code(400); echo json_encode(['error' => 'person_id y amount requeridos']); exit;
        }
        if (empty($attendeeIds)) {
            http_response_code(400); echo json_encode(['error' => 'Indica al menos un asistente']); exit;
        }
        // El pagador siempre está entre los asistentes
        if (!in_array($personId, $attendeeIds)) $attendeeIds[] = $personId;

        $stmt = $db->prepare("INSERT INTO payments (person_id, amount, attendee_ids, coffee_count, note) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$personId, $amount, json_encode(array_values($attendeeIds)), count($attendeeIds), $note ?: null]);
        echo json_encode(['id' => (int)$db->lastInsertId(), 'ok' => true]);
        exit;
    }

    // ── GET /payments ────────────────────────────────────────────────────────
    if ($method === 'GET' && $path === 'payments') {
        $rows = $db->query("
            SELECT p.*, pe.name, pe.color FROM payments p
            JOIN people pe ON pe.id = p.person_id
            ORDER BY p.paid_at DESC LIMIT 100
        ")->fetchAll();
        echo json_encode($rows);
        exit;
    }

    // ── DELETE /payments/{id} ────────────────────────────────────────────────
    if ($method === 'DELETE' && preg_match('#^payments/(\d+)$#', $path, $m)) {
        $db->prepare("DELETE FROM payments WHERE id = ?")->execute([(int)$m[1]]);
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'Ruta no encontrada']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
