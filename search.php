<?php
// search.php - Global Search API Endpoint

// Set JSON header first to ensure AJAX always gets JSON
header('Content-Type: application/json; charset=utf-8');

// Start session before config to handle timeout gracefully for AJAX
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in BEFORE loading config (which may redirect on timeout)
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'results' => []]);
    exit;
}

// Update last activity to prevent session timeout during search
$_SESSION['last_activity'] = time();

// Define BASE_URL if not already set (since we skip config.php to avoid redirect)
if (!defined('BASE_URL')) {
    define('BASE_URL', '/showroom/');
}

// Load database connection
require_once __DIR__ . '/config/database.php';
$database = new Database();
$db = $database->getConnection();

$query = trim($_GET['q'] ?? '');

if (strlen($query) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

$results = [];
$searchTerm = "%{$query}%";

try {
    // Search vehicles (by plate, chassis, brand/model name)
    $stmt = $db->prepare("
        SELECT v.id, v.license_plate, v.status,
               CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        WHERE v.license_plate LIKE ?
           OR v.chassis_number LIKE ?
           OR CONCAT(b.name, ' ', m.name) LIKE ?
        ORDER BY v.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $vehicles = $stmt->fetchAll();

    foreach ($vehicles as $v) {
        $statusMap = [
            'available' => 'Tersedia',
            'sold' => 'Terjual',
            'maintenance' => 'Perawatan',
            'reserved' => 'Booking'
        ];
        $results[] = [
            'type' => 'vehicle',
            'icon' => 'fas fa-car',
            'title' => $v['vehicle_name'],
            'subtitle' => $v['license_plate'] . ' — ' . ($statusMap[$v['status']] ?? ucfirst($v['status'])),
            'url' => BASE_URL . 'vehicles/view.php?id=' . $v['id']
        ];
    }

    // Search customers
    $stmt = $db->prepare("
        SELECT id, name, phone
        FROM customers
        WHERE name LIKE ? OR phone LIKE ? OR id_card LIKE ?
        ORDER BY name ASC
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $customers = $stmt->fetchAll();

    foreach ($customers as $c) {
        $results[] = [
            'type' => 'customer',
            'icon' => 'fas fa-user',
            'title' => $c['name'],
            'subtitle' => $c['phone'],
            'url' => BASE_URL . 'customers/view.php?id=' . $c['id']
        ];
    }

    // Search transactions
    $stmt = $db->prepare("
        SELECT t.id, t.transaction_code, t.total_amount, t.transaction_date,
               c.name as customer_name
        FROM transactions t
        JOIN customers c ON t.customer_id = c.id
        WHERE t.transaction_code LIKE ? OR c.name LIKE ?
        ORDER BY t.transaction_date DESC
        LIMIT 5
    ");
    $stmt->execute([$searchTerm, $searchTerm]);
    $transactions = $stmt->fetchAll();

    foreach ($transactions as $t) {
        $results[] = [
            'type' => 'transaction',
            'icon' => 'fas fa-receipt',
            'title' => $t['transaction_code'],
            'subtitle' => $t['customer_name'] . ' — Rp ' . number_format($t['total_amount'], 0, ',', '.'),
            'url' => BASE_URL . 'transactions/view.php?id=' . $t['id']
        ];
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'results' => []]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'results' => []]);
    exit;
}

echo json_encode(['results' => $results, 'total' => count($results)]);
