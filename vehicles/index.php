<?php
// vehicles/index.php - Daftar Stok Mobil
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/inspection_helpers.php';
requireLogin();

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$brand_filter = trim($_GET['brand'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$aging_filter = trim($_GET['aging'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Default sorting
$sort_column = $_GET['sort'] ?? 'v.created_at';
$sort_order = $_GET['order'] ?? 'ASC';

// Whitelist columns for sorting
$allowed_sort_columns = ['v.created_at', 'brand_name', 'v.year', 'v.selling_price', 'v.status'];
if (!in_array($sort_column, $allowed_sort_columns)) {
    $sort_column = 'v.created_at';
}
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// DB Sort Order: Invert logic for Time-based sorting (Newest first on ASC)
$db_sort_order = $sort_order;
if ($sort_column === 'v.created_at') {
    $db_sort_order = ($sort_order === 'ASC') ? 'DESC' : 'ASC';
}

try {
    $whereClauses = ["1=1"];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(v.chassis_number LIKE ? OR v.license_plate LIKE ? OR m.name LIKE ? OR b.name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($status_filter)) {
        if ($status_filter === 'consignment') {
            $whereClauses[] = "v.is_consignment = 1";
        } else {
            $whereClauses[] = "v.status = ?";
            $params[] = $status_filter;
        }
    }
    
    if (!empty($brand_filter)) {
        $whereClauses[] = "m.brand_id = ?";
        $params[] = $brand_filter;
    }
    
    if (!empty($type_filter)) {
        $whereClauses[] = "m.car_type_id = ?";
        $params[] = $type_filter;
    }

    if (!empty($aging_filter)) {
        if ($aging_filter === 'fresh') {
            $whereClauses[] = "DATEDIFF(CURDATE(), COALESCE(v.purchase_date, DATE(v.created_at))) <= 30";
        } elseif ($aging_filter === 'warning') {
            $whereClauses[] = "DATEDIFF(CURDATE(), COALESCE(v.purchase_date, DATE(v.created_at))) BETWEEN 31 AND 60";
        } elseif ($aging_filter === 'critical') {
            $whereClauses[] = "DATEDIFF(CURDATE(), COALESCE(v.purchase_date, DATE(v.created_at))) > 60";
        }
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
    
    // Count total records
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        $whereClause
    ");
    $stmt->execute($params);
    $totalRecords = $stmt->fetchColumn() ?: 0;
    $totalPages = ceil($totalRecords / $limit);
    
    // Get records
    $sql = "
        SELECT v.*,
               m.name as model_name, 
               b.name as brand_name,
               ct.name as car_type_name,
               insp.overall_grade,
               insp.overall_score,
               insp.flood_free,
               insp.accident_free
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN (
            SELECT vi1.* 
            FROM vehicle_inspections vi1
            INNER JOIN (
                SELECT vehicle_id, MAX(id) as max_id 
                FROM vehicle_inspections 
                GROUP BY vehicle_id
            ) vi2 ON vi1.id = vi2.max_id
        ) insp ON v.id = insp.vehicle_id
        $whereClause
        ORDER BY $sort_column $db_sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get filters data
    $stmt = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC");
    $carTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get vehicle statistics for summary cards
    $stmt = $db->query("SELECT 
                            COUNT(*) as total_vehicles,
                            SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_vehicles,
                            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold_vehicles,
                            SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_vehicles,
                            SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved_vehicles,
                            SUM(CASE WHEN status = 'available' AND DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) <= 30 THEN 1 ELSE 0 END) as fresh_vehicles,
                            SUM(CASE WHEN status = 'available' AND DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) as warning_vehicles,
                            SUM(CASE WHEN status = 'available' AND DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) > 60 THEN 1 ELSE 0 END) as critical_vehicles
                        FROM vehicles");
    $vStats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_vehicles' => 0,
        'available_vehicles' => 0,
        'sold_vehicles' => 0,
        'maintenance_vehicles' => 0,
        'reserved_vehicles' => 0,
        'fresh_vehicles' => 0,
        'warning_vehicles' => 0,
        'critical_vehicles' => 0
    ];
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}
?>

<style>
    /* ===== Header Styling ===== */
    .vehicles-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .vehicles-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .vehicles-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }

    /* ===== Summary Cards Grid (Matching Maintenance, Inspections, Tracking) ===== */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 14px;
        margin-bottom: 20px;
    }
    .summary-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .summary-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(0,0,0,0.06);
    }
    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .summary-icon.slate { background: #f1f5f9; color: #475569; }
    .summary-icon.emerald { background: #dcfce7; color: #16a34a; }
    .summary-icon.blue { background: #e0f2fe; color: #0284c7; }
    .summary-icon.amber { background: #fef3c7; color: #d97706; }
    .summary-icon.indigo { background: #e0e7ff; color: #4f46e5; }
    .summary-icon.rose { background: #ffe4e6; color: #e11d48; }

    .summary-value {
        font-size: 1.35rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .summary-label {
        font-size: 0.72rem;
        color: #64748b;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.04em;
        margin-top: 2px;
    }
    .dot-amber { background-color: #f59e0b; }
    .dot-indigo { background-color: #6366f1; }
    .dot-rose { background-color: #f43f5e; }
    .dot-blue { background-color: #3b82f6; }
    .vehicles-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .btn-header-secondary {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 16px;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-header-secondary:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .btn-add-vehicle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.15s, transform 0.1s;
        text-decoration: none;
    }
    .btn-add-vehicle:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }

    /* Search Card */
    .search-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
    }
    .search-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    .search-input-group {
        flex: 1;
        position: relative;
    }
    .search-input-group i {
        position: absolute;
        left: 13px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.8rem;
        pointer-events: none;
    }
    .search-input-group input {
        width: 100%;
        padding: 9px 14px 9px 36px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .search-input-group input:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }
    .filter-select {
        width: 150px;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
    }
    .filter-select:focus {
        border-color: #94a3b8;
        background: #fff;
        outline: none;
    }
    .btn-search {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #475569;
        font-weight: 600;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .btn-search:hover { background: #f1f5f9; border-color: #cbd5e1; color: #1e293b; }
    .btn-reset-search {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #94a3b8;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-reset-search:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

    /* Table Card */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        transition: box-shadow 0.2s;
    }
    .table-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .table-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .table-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .table-card-controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .record-count {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
    }
    .limit-select {
        width: 64px;
        padding: 5px 8px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.15s;
    }
    .limit-select:focus { border-color: #94a3b8; outline: none; }

    /* Table Styling */
    .vehicles-table {
        width: 100%;
        min-width: 1180px;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 12px 14px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .vehicles-table tbody td {
        padding: 12px 14px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
        white-space: nowrap;
    }
    .vehicles-table tbody td.col-car-info {
        white-space: normal;
        min-width: 200px;
    }
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Elements in table */
    .car-title-link {
        font-weight: 700;
        color: #1e293b;
        text-decoration: none;
        transition: color 0.15s;
    }
    .car-title-link:hover { color: #2563eb; }
    .plate-badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        white-space: nowrap;
    }
    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
        display: inline-block;
    }

    /* Sort Controls */
    .sort-controls {
        display: inline-flex;
        flex-direction: column;
        margin-left: 6px;
        gap: 0;
        vertical-align: middle;
    }
    .sort-controls a {
        display: block;
        line-height: 1;
        font-size: 0.65rem;
        color: #cbd5e1;
        transition: color 0.15s;
    }
    .sort-controls a:hover { color: #64748b; }
    .sort-controls a.active-sort { color: #2d3436; }

    /* Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .status-available { background: #dcfce7; color: #166534; }
    .status-sold { background: #fee2e2; color: #991b1b; }
    .status-maintenance { background: #fef3c7; color: #92400e; }
    .status-reserved { background: #e0f2fe; color: #075985; }

    /* No Column */
    .col-no { width: 50px; text-align: center; }
    .col-no-val { color: #94a3b8; font-weight: 500; font-size: 0.8rem; text-align: center; white-space: nowrap; }

    /* Action Buttons */
    .action-btns {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        justify-content: flex-end;
        white-space: nowrap;
        flex-wrap: nowrap;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        flex-shrink: 0;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-sim:hover { background: #fffbeb; border-color: #fde68a; color: #d97706; }
    .action-btn-view:hover { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
    .action-btn-sell:hover { background: #f0fdf4; border-color: #86efac; color: #15803d; }
    .action-btn-delete:hover { background: #fef2f2; border-color: #fecaca; color: #dc2626; }

    /* Pagination */
    .pagination-wrapper {
        display: flex;
        justify-content: center;
        padding: 16px 20px 20px;
    }
    .pagination-list {
        display: flex;
        align-items: center;
        gap: 4px;
        list-style: none;
        padding: 0;
        margin: 0;
    }
    .pagination-list li a,
    .pagination-list li span {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 34px;
        height: 34px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        background: #fff;
        border: 1px solid #e2e8f0;
        text-decoration: none;
        transition: all 0.15s;
    }
    .pagination-list li a:hover { background: #f1f5f9; border-color: #cbd5e1; }
    .pagination-list li.active a,
    .pagination-list li.active span {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }
    .pagination-list li.disabled a,
    .pagination-list li.disabled span {
        color: #cbd5e1;
        pointer-events: none;
        background: #f8fafc;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 48px 24px;
    }
    .empty-state-icon {
        font-size: 2.5rem;
        color: #e2e8f0;
        margin-bottom: 12px;
    }
    .empty-state h5 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 4px;
    }
    .empty-state p {
        font-size: 0.82rem;
        color: #94a3b8;
        margin-bottom: 16px;
    }
    .btn-cancel {
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-cancel:hover { background: #f1f5f9; color: #334155; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-icon.slate { background: rgba(148,163,184,0.15); color: #cbd5e1; }
    [data-theme="dark"] .summary-icon.emerald { background: rgba(34,197,94,0.15); color: #86efac; }
    [data-theme="dark"] .summary-icon.blue { background: rgba(2,132,199,0.15); color: #7dd3fc; }
    [data-theme="dark"] .summary-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-label { color: #94a3b8; }
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .car-title-link { color: #f1f5f9; }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-sim:hover { background: rgba(245,158,11,0.15); border-color: rgba(245,158,11,0.3); color: #fcd34d; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #86efac; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
    [data-theme="dark"] .action-btn-sell:hover { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }
    [data-theme="dark"] .action-btn-delete:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
    [data-theme="dark"] .status-available { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-sold { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .status-maintenance { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .status-reserved { background: rgba(14,165,233,0.15); color: #7dd3fc; }
    [data-theme="dark"] .pagination-list li a,
    [data-theme="dark"] .pagination-list li span { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .pagination-list li a:hover { background: #334155; }
    [data-theme="dark"] .pagination-list li.active a { background: #3b82f6; border-color: #3b82f6; }
    [data-theme="dark"] .pagination-list li.disabled a { background: #0f172a; border-color: #1e293b; color: #475569; }
    [data-theme="dark"] .sort-controls a.active-sort { color: #60a5fa; }
    [data-theme="dark"] .col-no-val { color: #64748b; }
    [data-theme="dark"] .empty-state-icon { color: #334155; }
    [data-theme="dark"] .empty-state h5 { color: #e2e8f0; }
    [data-theme="dark"] .empty-state p { color: #94a3b8; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .record-count { background-color: rgba(59,130,246,0.15) !important; color: #93c5fd !important; }

    /* Responsive */
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 120px; }
    }
    @media (max-width: 768px) {
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .vehicles-page-header { flex-direction: column; gap: 10px; }
        .vehicles-header-actions { width: 100%; justify-content: flex-start; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .vehicles-table thead th,
        .vehicles-table tbody td { padding: 10px 8px; }
    }
    @media (max-width: 480px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Stok Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Stok Mobil</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="available.php" class="btn-header-secondary">
            <i class="fas fa-check-circle text-success"></i> Mobil Tersedia
        </a>
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i> Tambah Mobil
        </a>
    </div>
</div>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-car"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($vStats['total_vehicles'] ?? 0); ?></div>
            <div class="summary-label">Total Armada</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo number_format($vStats['available_vehicles'] ?? 0); ?></div>
            <div class="summary-label">Mobil Siap Jual</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-handshake"></i>
        </div>
        <div>
            <div class="summary-value text-primary"><?php echo number_format($vStats['sold_vehicles'] ?? 0); ?></div>
            <div class="summary-label">Mobil Terjual</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-tools"></i>
        </div>
        <div>
            <div class="summary-value text-warning"><?php echo number_format(($vStats['maintenance_vehicles'] ?? 0) + ($vStats['reserved_vehicles'] ?? 0)); ?></div>
            <div class="summary-label">Perawatan & Booking</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari nama mobil, nopol, nomor rangka..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="available" <?php echo $status_filter == 'available' ? 'selected' : ''; ?>>Tersedia</option>
                    <option value="consignment" <?php echo $status_filter == 'consignment' ? 'selected' : ''; ?>>Mobil Titipan</option>
                    <option value="sold" <?php echo $status_filter == 'sold' ? 'selected' : ''; ?>>Terjual</option>
                    <option value="maintenance" <?php echo $status_filter == 'maintenance' ? 'selected' : ''; ?>>Perbaikan</option>
                    <option value="reserved" <?php echo $status_filter == 'reserved' ? 'selected' : ''; ?>>Dipesan</option>
                </select>
                <select name="brand" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Merek</option>
                    <?php foreach ($brands as $brand): ?>
                    <option value="<?php echo $brand['id']; ?>" <?php echo $brand_filter == $brand['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($brand['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Jenis</option>
                    <?php foreach ($carTypes as $type): ?>
                    <option value="<?php echo $type['id']; ?>" <?php echo $type_filter == $type['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($type['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="aging" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Umur Stok</option>
                    <option value="fresh" <?php echo $aging_filter == 'fresh' ? 'selected' : ''; ?>>Fresh (&le; 30 Hari) [<?php echo $vStats['fresh_vehicles']; ?>]</option>
                    <option value="warning" <?php echo $aging_filter == 'warning' ? 'selected' : ''; ?>>Perhatian (31-60 Hari) [<?php echo $vStats['warning_vehicles']; ?>]</option>
                    <option value="critical" <?php echo $aging_filter == 'critical' ? 'selected' : ''; ?>>Stok Macet (> 60 Hari) [<?php echo $vStats['critical_vehicles']; ?>]</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($status_filter) || !empty($brand_filter) || !empty($type_filter) || !empty($aging_filter)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Vehicles Table Card -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Stok Mobil</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Unit</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($vehicles)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th class="col-no" style="width: 50px;">
                            <span>No</span>
                            <span class="sort-controls">
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&aging=<?php echo urlencode($aging_filter); ?>&limit=<?php echo $limit; ?>&sort=v.created_at&order=ASC" 
                                   class="<?php echo ($sort_column == 'v.created_at' && $sort_order == 'ASC') ? 'active-sort' : ''; ?>"
                                   title="Terbaru"><i class="fas fa-caret-up"></i></a>
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&aging=<?php echo urlencode($aging_filter); ?>&limit=<?php echo $limit; ?>&sort=v.created_at&order=DESC" 
                                   class="<?php echo ($sort_column == 'v.created_at' && $sort_order == 'DESC') ? 'active-sort' : ''; ?>"
                                   title="Terlama"><i class="fas fa-caret-down"></i></a>
                            </span>
                        </th>
                        <th style="min-width: 180px;">Mobil</th>
                        <th style="min-width: 100px;">No. Polisi</th>
                        <th style="min-width: 80px;">Warna</th>
                        <th class="text-center" style="min-width: 70px;">Tahun</th>
                        <th class="text-end" style="min-width: 95px;">KM</th>
                        <th class="text-end" style="min-width: 130px;">Harga Jual</th>
                        <th class="text-center" style="min-width: 110px;">Umur Stok</th>
                        <th class="text-center" style="min-width: 90px;">Status</th>
                        <th class="text-end" style="min-width: 220px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $idx => $v): 
                        $vstatus = strtolower((string)($v['status'] ?? 'available'));
                        $vLabel = 'Tersedia';
                        $vClass = 'status-available';
                        if ($vstatus === 'sold') { $vLabel = 'Terjual'; $vClass = 'status-sold'; }
                        elseif ($vstatus === 'maintenance') { $vLabel = 'Perbaikan'; $vClass = 'status-maintenance'; }
                        elseif ($vstatus === 'reserved') { $vLabel = 'Dipesan'; $vClass = 'status-reserved'; }
                        $agingInfo = calculateVehicleAging($v['purchase_date'] ?? null, null, $v['created_at'] ?? null, $v['purchase_price'] ?? 0);
                    ?>
                    <tr>
                        <td class="col-no-val"><?php echo $offset + $idx + 1; ?></td>
                        <td class="col-car-info">
                            <div>
                                <div class="d-flex align-items-center gap-1.5 flex-wrap">
                                    <a href="view.php?id=<?php echo $v['id']; ?>" class="car-title-link">
                                        <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name']); ?>
                                    </a>
                                    <?php if (!empty($v['is_consignment'])): ?>
                                        <span class="badge" style="background:#fef3c7; color:#b45309; border:1px solid #fde68a; font-size:0.65rem; font-weight:700; padding:2px 6px; border-radius:4px;">
                                            <i class="fas fa-handshake me-1"></i>Titip Jual
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2 mt-1" style="font-size: 0.75rem;">
                                    <span class="text-muted"><i class="fas fa-car-side me-1"></i><?php echo htmlspecialchars($v['car_type_name'] ?? '-'); ?></span>
                                    <?php if (!empty($v['overall_grade'])): ?>
                                        <span>&bull;</span>
                                        <span><?php echo renderGradeBadge($v['overall_grade'], null, 'sm'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="text-nowrap">
                            <?php if (!empty($v['license_plate'])): ?>
                                <span class="plate-badge"><?php echo htmlspecialchars($v['license_plate']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><span class="text-muted"><?php echo htmlspecialchars($v['color'] ?: '-'); ?></span></td>
                        <td class="text-center text-nowrap"><strong><?php echo $v['year'] ?: '-'; ?></strong></td>
                        <td class="text-end text-muted fw-medium text-nowrap"><?php echo number_format($v['mileage']); ?> km</td>
                        <td class="text-end text-nowrap"><span class="price-text"><?php echo formatCurrency($v['selling_price']); ?></span></td>
                        <td class="text-center text-nowrap">
                            <div class="d-inline-flex align-items-center gap-1">
                                <?php echo renderAgingBadge($v['purchase_date'] ?? null, null, $v['created_at'] ?? null); ?>
                                <?php if ($v['status'] === 'available' && $agingInfo['days'] > 30): ?>
                                    <button type="button" class="btn btn-sm btn-link text-warning p-0" 
                                            onclick='openRepriceModal(<?php echo htmlspecialchars(json_encode([
                                                "id" => $v["id"],
                                                "title" => $v["brand_name"] . " " . $v["model_name"] . " " . $v["year"],
                                                "days" => $agingInfo["days"],
                                                "category" => $agingInfo["category_label"],
                                                "purchase_date" => formatDate($v["purchase_date"] ?: $v["created_at"], "d M Y"),
                                                "purchase_price" => (float)$v["purchase_price"],
                                                "selling_price" => (float)$v["selling_price"],
                                                "holding_cost" => $agingInfo["holding_cost"],
                                                "suggested_pct" => $agingInfo["suggested_discount_pct"]
                                            ]), ENT_QUOTES, "UTF-8"); ?>)'
                                            data-bs-toggle="tooltip" title="Analisis Reprice & Holding Cost">
                                        <i class="fas fa-calculator text-warning"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-center text-nowrap">
                            <span class="status-badge <?php echo $vClass; ?>">
                                <?php echo $vLabel; ?>
                            </span>
                        </td>
                        <td class="text-end text-nowrap">
                            <div class="action-btns">
                                <?php if ($v['status'] === 'available' && $agingInfo['days'] > 30): ?>
                                <button type="button" class="action-btn text-warning border-warning-subtle" 
                                        onclick='openRepriceModal(<?php echo htmlspecialchars(json_encode([
                                            "id" => $v["id"],
                                            "title" => $v["brand_name"] . " " . $v["model_name"] . " " . $v["year"],
                                            "days" => $agingInfo["days"],
                                            "category" => $agingInfo["category_label"],
                                            "purchase_date" => formatDate($v["purchase_date"] ?: $v["created_at"], "d M Y"),
                                            "purchase_price" => (float)$v["purchase_price"],
                                            "selling_price" => (float)$v["selling_price"],
                                            "holding_cost" => $agingInfo["holding_cost"],
                                            "suggested_pct" => $agingInfo["suggested_discount_pct"]
                                        ]), ENT_QUOTES, "UTF-8"); ?>)'
                                        data-bs-toggle="tooltip" title="Rekomendasi Reprice">
                                    <i class="fas fa-tags"></i>
                                </button>
                                <?php endif; ?>
                                <a href="view.php?id=<?php echo $v['id']; ?>" 
                                   class="action-btn text-danger border-danger-subtle" data-bs-toggle="tooltip" title="Salin Iklan Marketplace">
                                    <i class="fas fa-bullhorn"></i>
                                </a>
                                <a href="<?php echo BASE_URL; ?>leasing/simulation.php?vehicle_id=<?php echo $v['id']; ?>" 
                                   class="action-btn action-btn-sim" data-bs-toggle="tooltip" title="Simulasi Kredit">
                                    <i class="fas fa-calculator"></i>
                                </a>
                                <a href="view.php?id=<?php echo $v['id']; ?>" 
                                   class="action-btn action-btn-view" data-bs-toggle="tooltip" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $v['id']; ?>" 
                                   class="action-btn action-btn-edit" data-bs-toggle="tooltip" title="Edit Data">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($v['status'] === 'available'): ?>
                                <a href="<?php echo BASE_URL; ?>transactions/create.php?vehicle_id=<?php echo $v['id']; ?>" 
                                   class="action-btn action-btn-sell" data-bs-toggle="tooltip" title="Proses Penjualan">
                                    <i class="fas fa-shopping-cart"></i>
                                </a>
                                <?php endif; ?>
                                <button type="button" class="action-btn action-btn-delete" 
                                        onclick="deleteVehicle(<?php echo $v['id']; ?>)" 
                                        data-bs-toggle="tooltip" title="Hapus Data">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <ul class="pagination-list">
                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-car"></i></div>
            <h5><?php echo (!empty($search) || !empty($status_filter) || !empty($brand_filter) || !empty($type_filter)) ? 'Mobil Tidak Ditemukan' : 'Belum Ada Stok Mobil'; ?></h5>
            <p>
                <?php echo (!empty($search) || !empty($status_filter) || !empty($brand_filter) || !empty($type_filter)) 
                    ? 'Tidak ditemukan data mobil yang cocok dengan parameter filter pencarian.' 
                    : 'Belum ada kendaraan dalam stok showroom.'; ?>
            </p>
            <?php if (!empty($search) || !empty($status_filter) || !empty($brand_filter) || !empty($type_filter)): ?>
            <a href="index.php" class="btn-cancel">
                <i class="fas fa-redo-alt me-1"></i>Reset Filter
            </a>
            <?php else: ?>
            <a href="create.php" class="btn-add-vehicle">
                <i class="fas fa-plus"></i> Tambah Mobil Pertama
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Rekomendasi Reprice & Holding Cost -->
<div class="modal fade" id="repriceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <div class="d-flex align-items-center gap-2">
                    <div class="p-2 bg-warning bg-opacity-10 text-warning-emphasis rounded-3">
                        <i class="fas fa-tags fa-lg"></i>
                    </div>
                    <div>
                        <h6 class="modal-title fw-bold text-dark mb-0">Analisis Umur Stok & Rekomendasi Reprice</h6>
                        <small class="text-muted" id="rpUnitTitle">-</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <!-- Status & Days alert -->
                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold">Lama Mengendap</div>
                            <h4 class="fw-bold text-danger mb-0 mt-1"><span id="rpDays">0</span> Hari</h4>
                            <small class="text-muted" id="rpPurchaseDate">-</small>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold">Harga Modal Beli</div>
                            <h5 class="fw-bold text-dark mb-0 mt-1">Rp <span id="rpPurchasePrice">0</span></h5>
                            <small class="text-muted">HPP Awal</small>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="p-3 bg-light rounded-3 border text-center">
                            <div class="text-muted small fw-semibold">Harga Jual Saat Ini</div>
                            <h5 class="fw-bold text-primary mb-0 mt-1">Rp <span id="rpSellingPrice">0</span></h5>
                            <small class="text-muted">Margin: Rp <span id="rpCurrentMargin">0</span></small>
                        </div>
                    </div>
                </div>

                <!-- Holding Cost & Depreciation Analysis -->
                <div class="card border-warning-subtle bg-warning bg-opacity-10 rounded-3 mb-4">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-start gap-2">
                            <i class="fas fa-exclamation-triangle text-warning fa-lg mt-1"></i>
                            <div>
                                <div class="fw-bold text-dark">Estimasi Beban Modal Tertahan (*Holding Cost*)</div>
                                <p class="text-muted small mb-2">
                                    Dengan asumsi cost of capital & depresiasi ~1%/bulan (0.033%/hari), unit ini telah menyerap estimasi beban bunga modal mengendap sebesar:
                                </p>
                                <div class="h5 fw-bold text-danger mb-0">Rp <span id="rpHoldingCost">0</span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reprice Simulator -->
                <div class="border rounded-3 p-3 bg-white">
                    <h6 class="fw-bold text-dark mb-3">
                        <i class="fas fa-sliders-h text-primary me-2"></i>Simulasi Penyesuaian Harga Cepat (*Quick Reprice*)
                    </h6>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold text-muted">Diskon Rekomendasi (%):</label>
                            <div class="input-group">
                                <input type="number" id="rpDiscountInput" class="form-control" min="0" max="30" step="0.5" value="5" oninput="calculateSimulatedReprice()">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">Saran sistem untuk stok macet: 2% - 5%</small>
                        </div>
                        <div class="col-md-7">
                            <div class="p-3 bg-success bg-opacity-10 border border-success-subtle rounded-3">
                                <div class="text-muted small fw-semibold">Target Harga Jual Baru (Setelah Diskon):</div>
                                <h4 class="fw-bold text-success mb-1">Rp <span id="rpNewPrice">0</span></h4>
                                <small class="text-dark">Sisa Estimasi Margin: <strong>Rp <span id="rpNewMargin">0</span></strong></small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top bg-light">
                <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Tutup</button>
                <a id="rpEditBtn" href="#" class="btn btn-primary px-4 shadow-sm">
                    <i class="fas fa-edit me-1"></i> Edit Harga di Data Mobil
                </a>
            </div>
        </div>
    </div>
</div>

<script>
let currentRepriceData = null;

function openRepriceModal(data) {
    currentRepriceData = data;
    document.getElementById('rpUnitTitle').innerText = data.title;
    document.getElementById('rpDays').innerText = data.days;
    document.getElementById('rpPurchaseDate').innerText = 'Masuk: ' + data.purchase_date;
    document.getElementById('rpPurchasePrice').innerText = Number(data.purchase_price).toLocaleString('id-ID');
    document.getElementById('rpSellingPrice').innerText = Number(data.selling_price).toLocaleString('id-ID');
    
    const margin = data.selling_price - data.purchase_price;
    document.getElementById('rpCurrentMargin').innerText = Number(margin).toLocaleString('id-ID');
    document.getElementById('rpHoldingCost').innerText = Number(data.holding_cost).toLocaleString('id-ID');
    document.getElementById('rpDiscountInput').value = data.suggested_pct || 5;
    document.getElementById('rpEditBtn').href = 'edit.php?id=' + data.id;

    calculateSimulatedReprice();

    var modal = new bootstrap.Modal(document.getElementById('repriceModal'));
    modal.show();
}

function calculateSimulatedReprice() {
    if (!currentRepriceData) return;
    const discountPct = parseFloat(document.getElementById('rpDiscountInput').value) || 0;
    const currentPrice = currentRepriceData.selling_price;
    const purchasePrice = currentRepriceData.purchase_price;

    const discountAmount = Math.round(currentPrice * (discountPct / 100));
    const newPrice = Math.max(0, currentPrice - discountAmount);
    const newMargin = newPrice - purchasePrice;

    document.getElementById('rpNewPrice').innerText = Number(newPrice).toLocaleString('id-ID');
    document.getElementById('rpNewMargin').innerText = Number(newMargin).toLocaleString('id-ID');
}

function deleteVehicle(id) {
    if (confirm('Apakah Anda yakin ingin menghapus data mobil ini? Tindakan ini tidak dapat dibatalkan.')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'delete.php';
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = id;
        
        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = '<?php echo generateCSRFToken(); ?>';
        
        form.appendChild(idInput);
        form.appendChild(csrfInput);
        document.body.appendChild(form);
        form.submit();
    }
}

// Tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../includes/footer.php'; ?>