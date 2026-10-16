<?php
// documents/tracking.php - Tracking Dokumen Kendaraan (STNK & BPKB)
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/document_helpers.php';
requireLogin();

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}

// Handle Update Document Position POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_doc_tracking') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF tidak valid.';
    } else {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $stnk_status = $_POST['stnk_status'] ?? 'available';
        $stnk_expiry_date = !empty($_POST['stnk_expiry_date']) ? $_POST['stnk_expiry_date'] : null;
        $stnk_tax_date = !empty($_POST['stnk_tax_date']) ? $_POST['stnk_tax_date'] : null;
        $bpkb_status = $_POST['bpkb_status'] ?? 'in_showroom';
        $bpkb_number = sanitizeInput($_POST['bpkb_number'] ?? '');
        $bpkb_location_notes = sanitizeInput($_POST['bpkb_location_notes'] ?? '');
        $faktur_status = $_POST['faktur_status'] ?? 'available';
        $estimated_completion_date = !empty($_POST['estimated_completion_date']) ? $_POST['estimated_completion_date'] : null;
        $agent_service_name = sanitizeInput($_POST['agent_service_name'] ?? '');
        $tracking_notes = sanitizeInput($_POST['tracking_notes'] ?? '');

        if ($vehicle_id) {
            try {
                $check = $db->prepare("SELECT id FROM vehicle_documents WHERE vehicle_id = ?");
                $check->execute([$vehicle_id]);
                $exists = $check->fetch();

                if ($exists) {
                    $updateStmt = $db->prepare("
                        UPDATE vehicle_documents
                        SET stnk_status = ?, stnk_expiry_date = ?, stnk_tax_date = ?,
                            bpkb_status = ?, bpkb_number = ?, bpkb_location_notes = ?,
                            faktur_status = ?, estimated_completion_date = ?, agent_service_name = ?,
                            tracking_notes = ?, updated_at = NOW()
                        WHERE vehicle_id = ?
                    ");
                    $updateStmt->execute([
                        $stnk_status, $stnk_expiry_date, $stnk_tax_date,
                        $bpkb_status, $bpkb_number, $bpkb_location_notes,
                        $faktur_status, $estimated_completion_date, $agent_service_name,
                        $tracking_notes, $vehicle_id
                    ]);
                } else {
                    $insStmt = $db->prepare("
                        INSERT INTO vehicle_documents (
                            vehicle_id, stnk_status, stnk_expiry_date, stnk_tax_date,
                            bpkb_status, bpkb_number, bpkb_location_notes,
                            faktur_status, estimated_completion_date, agent_service_name,
                            tracking_notes, created_at, updated_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $insStmt->execute([
                        $vehicle_id, $stnk_status, $stnk_expiry_date, $stnk_tax_date,
                        $bpkb_status, $bpkb_number, $bpkb_location_notes,
                        $faktur_status, $estimated_completion_date, $agent_service_name,
                        $tracking_notes
                    ]);
                }
                showAlert('Status tracking dokumen berhasil diperbarui.', 'success');
                redirect('documents/tracking.php');
            } catch (PDOException $e) {
                $error = 'Gagal menyimpan data: ' . $e->getMessage();
            }
        }
    }
}

// Search, Pagination, & Filter
$search = trim($_GET['search'] ?? '');
$bpkb_filter = trim($_GET['bpkb_status'] ?? '');
$stnk_filter = trim($_GET['stnk_status'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';

if ($sort_column === 'created_at') {
    $sort_column = 'name';
}

$sortMap = [
    'name' => 'b.name, m.name',
    'plate' => 'v.license_plate',
    'bpkb' => 'doc.bpkb_status',
    'created_at' => 'v.created_at'
];
$db_sort_column = $sortMap[$sort_column] ?? 'b.name, m.name';
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

$whereClauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(v.license_plate LIKE ? OR b.name LIKE ? OR m.name LIKE ? OR doc.bpkb_number LIKE ?)";
    $searchParam = "%$search%";
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

if (!empty($bpkb_filter)) {
    if ($bpkb_filter === 'none') {
        $whereClauses[] = "doc.bpkb_status IS NULL";
    } else {
        $whereClauses[] = "doc.bpkb_status = ?";
        $params[] = $bpkb_filter;
    }
}

if (!empty($stnk_filter)) {
    $whereClauses[] = "doc.stnk_status = ?";
    $params[] = $stnk_filter;
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

$totalRecords = 0;
$totalPages = 0;
$vehicles = [];

try {
    // KPI Stats
    $kpiQuery = $db->query("
        SELECT 
            COUNT(v.id) as total_vehicles,
            SUM(CASE WHEN doc.bpkb_status = 'in_showroom' OR doc.bpkb_status IS NULL THEN 1 ELSE 0 END) as bpkb_showroom,
            SUM(CASE WHEN doc.bpkb_status = 'in_leasing' THEN 1 ELSE 0 END) as bpkb_leasing,
            SUM(CASE WHEN doc.bpkb_status IN ('mutasi_process', 'cabut_berkas') THEN 1 ELSE 0 END) as bpkb_process
        FROM vehicles v
        LEFT JOIN vehicle_documents doc ON v.id = doc.vehicle_id
    ");
    $kpi = $kpiQuery->fetch(PDO::FETCH_ASSOC);

    // Get total count for pagination
    $countSql = "
        SELECT COUNT(v.id) as total
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN vehicle_documents doc ON v.id = doc.vehicle_id
        $whereSql
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
    $totalRecords = $countRow['total'] ?? 0;
    $totalPages = ceil($totalRecords / $limit);

    // List Vehicles with Documents
    $query = "
        SELECT 
            v.id, v.license_plate, v.chassis_number, v.engine_number, v.year, v.color, v.status as vehicle_status,
            b.name as brand_name,
            m.name as model_name,
            doc.id as doc_id, doc.stnk_status, doc.stnk_expiry_date, doc.stnk_tax_date,
            doc.bpkb_status, doc.bpkb_number, doc.bpkb_location_notes, doc.faktur_status,
            doc.estimated_completion_date, doc.agent_service_name, doc.tracking_notes, doc.updated_at as doc_updated_at
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN vehicle_documents doc ON v.id = doc.vehicle_id
        $whereSql
        ORDER BY v.status = 'available' DESC, doc.bpkb_status = 'mutasi_process' DESC, $db_sort_column $sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<style>
    /* ===== Document Tracking Page Specific Styles (Matching Brands Design) ===== */
    .doc-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .doc-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .doc-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }

    /* KPI Grid */
    .doc-kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 20px;
    }
    .doc-kpi-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: transform 0.15s, box-shadow 0.15s;
    }
    .doc-kpi-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .doc-kpi-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .doc-kpi-icon.indigo { background: #eef2ff; color: #4f46e5; }
    .doc-kpi-icon.emerald { background: #ecfdf5; color: #059669; }
    .doc-kpi-icon.blue { background: #eff6ff; color: #2563eb; }
    .doc-kpi-icon.amber { background: #fffbeb; color: #d97706; }

    .doc-kpi-val {
        font-size: 1.45rem;
        font-weight: 800;
        line-height: 1.1;
        color: #1e293b;
    }
    .doc-kpi-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-top: 2px;
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
        width: 200px;
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
    .tracking-table {
        width: 100%;
        border-collapse: collapse;
    }
    .tracking-table thead th {
        padding: 10px 16px;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .tracking-table tbody td {
        padding: 12px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .tracking-table tbody tr {
        transition: background 0.15s;
    }
    .tracking-table tbody tr:hover {
        background: #f8fafc;
    }
    .tracking-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Cell Elements */
    .unit-title-link {
        font-weight: 700;
        color: #1e293b;
        text-decoration: none;
        transition: color 0.15s;
    }
    .unit-title-link:hover { color: #2563eb; }
    .plate-badge {
        display: inline-block;
        padding: 2px 7px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
    }
    .date-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
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

    /* Status Badges */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-showroom { background: #dcfce7; color: #166534; }
    .status-leasing { background: #dbeafe; color: #1e40af; }
    .status-process { background: #fef3c7; color: #92400e; }
    .status-released { background: #f1f5f9; color: #475569; }

    /* No Column */
    .col-no { width: 52px; text-align: center; }
    .col-no-val { color: #94a3b8; font-weight: 500; font-size: 0.8rem; text-align: center; }

    /* Action Buttons */
    .action-btns {
        display: flex;
        align-items: center;
        gap: 4px;
        justify-content: flex-end;
    }
    .btn-action-update {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        font-size: 0.75rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-action-update:hover {
        background: #eff6ff;
        border-color: #93c5fd;
        color: #2563eb;
        transform: translateY(-1px);
    }

    /* Col visibility */
    .col-hide-sm { display: none; }
    .col-hide-md { display: none; }
    @media (min-width: 768px) { .col-hide-md { display: table-cell; } }
    @media (min-width: 1024px) { .col-hide-sm { display: table-cell; } }

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

    /* Modal Custom Styling */
    .modal-custom .modal-content {
        border-radius: 16px;
        border: 1px solid #e2e8f0;
        overflow: hidden;
    }
    .modal-custom .modal-header {
        padding: 18px 22px 14px;
        border-bottom: none;
    }
    .modal-custom .modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
    }
    .modal-custom .modal-title i { color: #64748b; }
    .modal-custom .modal-body {
        padding: 8px 22px 22px;
    }
    .modal-custom .modal-footer {
        padding: 14px 22px;
        background: #fafbfc;
        border-top: 1px solid #f1f5f9;
    }
    .form-label-sm {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 5px;
    }
    .form-hint {
        font-size: 0.72rem;
        color: #94a3b8;
        margin-top: 3px;
    }
    .vehicle-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 16px;
    }
    .btn-save {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 20px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s;
    }
    .btn-save:hover { background: #1e272e; color: #fff; }
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
    }
    .btn-cancel:hover { background: #f1f5f9; color: #334155; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .doc-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .doc-kpi-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .doc-kpi-val { color: #f1f5f9; }
    [data-theme="dark"] .doc-kpi-icon.indigo { background: rgba(99,102,241,0.15); color: #a5b4fc; }
    [data-theme="dark"] .doc-kpi-icon.emerald { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .doc-kpi-icon.blue { background: rgba(59,130,246,0.15); color: #93c5fd; }
    [data-theme="dark"] .doc-kpi-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
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
    [data-theme="dark"] .tracking-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .tracking-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .tracking-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .unit-title-link { color: #f1f5f9; }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-action-update { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-action-update:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #60a5fa; }
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
    [data-theme="dark"] .modal-custom .modal-title { color: #f1f5f9; }
    [data-theme="dark"] .modal-custom .modal-title i { color: #94a3b8; }
    [data-theme="dark"] .modal-custom .modal-footer { background: #162032; border-top-color: #334155; }
    [data-theme="dark"] .form-label-sm { color: #cbd5e1; }
    [data-theme="dark"] .form-hint { color: #64748b; }
    [data-theme="dark"] .vehicle-info-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .btn-save { background: #3b82f6; }
    [data-theme="dark"] .btn-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .record-count { background-color: rgba(59,130,246,0.15) !important; color: #93c5fd !important; }

    /* Responsive */
    @media (max-width: 768px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 140px; }
    }
    @media (max-width: 576px) {
        .doc-page-header { flex-direction: column; gap: 10px; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .tracking-table thead th,
        .tracking-table tbody td { padding: 10px 10px; }
    }
</style>

<!-- Page Header -->
<div class="doc-page-header">
    <div>
        <h1>Tracking Dokumen Kendaraan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/" class="text-primary text-decoration-underline">Stok Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tracking Dokumen</li>
            </ol>
        </nav>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- KPI Stats Grid -->
<div class="doc-kpi-grid">
    <div class="doc-kpi-card">
        <div class="doc-kpi-icon indigo"><i class="fas fa-car"></i></div>
        <div>
            <div class="doc-kpi-val"><?php echo number_format($kpi['total_vehicles'] ?? 0); ?></div>
            <div class="doc-kpi-label">Total Unit Mobil</div>
        </div>
    </div>

    <div class="doc-kpi-card">
        <div class="doc-kpi-icon emerald"><i class="fas fa-shield-alt"></i></div>
        <div>
            <div class="doc-kpi-val text-success"><?php echo number_format($kpi['bpkb_showroom'] ?? 0); ?></div>
            <div class="doc-kpi-label">BPKB di Showroom</div>
        </div>
    </div>

    <div class="doc-kpi-card">
        <div class="doc-kpi-icon blue"><i class="fas fa-building"></i></div>
        <div>
            <div class="doc-kpi-val text-primary"><?php echo number_format($kpi['bpkb_leasing'] ?? 0); ?></div>
            <div class="doc-kpi-label">BPKB di Leasing</div>
        </div>
    </div>

    <div class="doc-kpi-card">
        <div class="doc-kpi-icon amber"><i class="fas fa-sync-alt"></i></div>
        <div>
            <div class="doc-kpi-val text-warning"><?php echo number_format($kpi['bpkb_process'] ?? 0); ?></div>
            <div class="doc-kpi-label">Mutasi / Cabut Berkas</div>
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
                    <input type="text" name="search" placeholder="Cari plat nomor, merek, model, atau nomor BPKB..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="bpkb_status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Posisi BPKB</option>
                    <option value="in_showroom" <?php echo $bpkb_filter === 'in_showroom' ? 'selected' : ''; ?>>Di Showroom (Tersedia)</option>
                    <option value="in_leasing" <?php echo $bpkb_filter === 'in_leasing' ? 'selected' : ''; ?>>Di Leasing (Agunan)</option>
                    <option value="mutasi_process" <?php echo $bpkb_filter === 'mutasi_process' ? 'selected' : ''; ?>>Proses Mutasi / Balik Nama</option>
                    <option value="cabut_berkas" <?php echo $bpkb_filter === 'cabut_berkas' ? 'selected' : ''; ?>>Proses Cabut Berkas</option>
                    <option value="released_to_buyer" <?php echo $bpkb_filter === 'released_to_buyer' ? 'selected' : ''; ?>>Diserahkan ke Pembeli</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($bpkb_filter)): ?>
                <a href="tracking.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Document List Table -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Dokumen Kendaraan</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Unit</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
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
            <table class="tracking-table">
                <thead>
                    <tr>
                        <th class="col-no">
                            <span>No</span>
                            <span class="sort-controls">
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&limit=<?php echo $limit; ?>&sort=name&order=ASC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'ASC') ? 'active-sort' : ''; ?>"
                                   title="A-Z"><i class="fas fa-caret-up"></i></a>
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&limit=<?php echo $limit; ?>&sort=name&order=DESC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'DESC') ? 'active-sort' : ''; ?>"
                                   title="Z-A"><i class="fas fa-caret-down"></i></a>
                            </span>
                        </th>
                        <th>Unit Mobil</th>
                        <th>Posisi BPKB</th>
                        <th>Status STNK & Pajak</th>
                        <th class="col-hide-md">Lokasi / Biro Jasa</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $idx => $v): 
                        $bpkbStatus = $v['bpkb_status'] ?: 'in_showroom';
                        
                        $bpkbLabel = 'Di Showroom';
                        $bpkbClass = 'status-showroom';
                        $bpkbIcon = 'fa-shield-alt';

                        if ($bpkbStatus === 'in_leasing') {
                            $bpkbLabel = 'Di Leasing';
                            $bpkbClass = 'status-leasing';
                            $bpkbIcon = 'fa-building';
                        } elseif ($bpkbStatus === 'mutasi_process') {
                            $bpkbLabel = 'Proses Mutasi / BBN';
                            $bpkbClass = 'status-process';
                            $bpkbIcon = 'fa-sync-alt';
                        } elseif ($bpkbStatus === 'cabut_berkas') {
                            $bpkbLabel = 'Cabut Berkas';
                            $bpkbClass = 'status-process';
                            $bpkbIcon = 'fa-file-export';
                        } elseif ($bpkbStatus === 'released_to_buyer') {
                            $bpkbLabel = 'Ke Pembeli';
                            $bpkbClass = 'status-released';
                            $bpkbIcon = 'fa-check';
                        }

                        // Tax date calculation
                        $isTaxExpired = false;
                        $taxWarning = false;
                        if (!empty($v['stnk_tax_date'])) {
                            $taxTime = strtotime($v['stnk_tax_date']);
                            $now = time();
                            if ($taxTime < $now) {
                                $isTaxExpired = true;
                            } elseif ($taxTime < ($now + (30 * 86400))) {
                                $taxWarning = true;
                            }
                        }
                    ?>
                    <tr>
                        <td class="col-no-val"><?php echo $offset + $idx + 1; ?></td>
                        <td>
                            <div>
                                <a href="<?php echo BASE_URL; ?>vehicles/view.php?id=<?php echo $v['id']; ?>" class="unit-title-link">
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ')'); ?>
                                </a>
                                <div class="d-flex align-items-center gap-2 mt-1" style="font-size: 0.75rem;">
                                    <span class="plate-badge"><?php echo htmlspecialchars($v['license_plate']); ?></span>
                                    <span class="text-muted"><?php echo htmlspecialchars($v['color']); ?></span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="d-flex flex-column gap-1">
                                <span class="status-badge <?php echo $bpkbClass; ?>">
                                    <i class="fas <?php echo $bpkbIcon; ?>"></i><?php echo $bpkbLabel; ?>
                                </span>
                                <?php if (!empty($v['bpkb_number'])): ?>
                                <small class="text-muted font-monospace" style="font-size: 0.72rem;">No. BPKB: <?php echo htmlspecialchars($v['bpkb_number']); ?></small>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div style="font-size: 0.8rem;">
                                <?php if (!empty($v['stnk_tax_date'])): ?>
                                    <?php if ($isTaxExpired): ?>
                                    <span class="date-badge bg-danger bg-opacity-10 text-danger"><i class="fas fa-exclamation-triangle"></i>Pajak Lewat (<?php echo formatDate($v['stnk_tax_date'], 'd M Y'); ?>)</span>
                                    <?php elseif ($taxWarning): ?>
                                    <span class="date-badge bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock"></i>Pajak Mau Habis (<?php echo formatDate($v['stnk_tax_date'], 'd M Y'); ?>)</span>
                                    <?php else: ?>
                                    <span class="text-dark fw-semibold"><i class="fas fa-calendar-check text-success me-1"></i>Pajak: <?php echo formatDate($v['stnk_tax_date'], 'd M Y'); ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted"><i class="fas fa-calendar-times text-muted me-1"></i>Pajak belum dicatat</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="col-hide-md">
                            <div style="font-size: 0.8rem;">
                                <?php if (!empty($v['agent_service_name'])): ?>
                                    <div><i class="fas fa-building text-muted me-1"></i><strong><?php echo htmlspecialchars($v['agent_service_name']); ?></strong></div>
                                <?php endif; ?>
                                <?php if (!empty($v['bpkb_location_notes'])): ?>
                                    <div class="text-muted text-truncate" style="max-width: 180px;" title="<?php echo htmlspecialchars($v['bpkb_location_notes']); ?>">
                                        <i class="fas fa-map-marker-alt text-muted me-1"></i><?php echo htmlspecialchars($v['bpkb_location_notes']); ?>
                                    </div>
                                <?php elseif (empty($v['agent_service_name'])): ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="action-btns">
                                <button type="button" class="btn-action-update" 
                                        onclick='openDocModal(<?php echo htmlspecialchars(json_encode($v), ENT_QUOTES, "UTF-8"); ?>)'
                                        data-bs-toggle="tooltip" title="Update Tracking Dokumen">
                                    <i class="fas fa-edit"></i> Update
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
                    <a href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&bpkb_status=<?php echo urlencode($bpkb_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-folder-open"></i></div>
            <h5><?php echo (!empty($search) || !empty($bpkb_filter)) ? 'Dokumen Tidak Ditemukan' : 'Tidak ada data dokumen'; ?></h5>
            <p>
                <?php echo (!empty($search) || !empty($bpkb_filter)) 
                    ? 'Tidak ditemukan data dokumen kendaraan yang cocok dengan filter pencarian.' 
                    : 'Belum ada data dokumen kendaraan yang tercatat.'; ?>
            </p>
            <?php if (!empty($search) || !empty($bpkb_filter)): ?>
            <a href="tracking.php" class="btn-cancel">
                <i class="fas fa-redo-alt me-1"></i>Reset Filter
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Update Tracking Dokumen -->
<div class="modal fade modal-custom" id="docUpdateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-folder-open me-2"></i>Update Posisi & Status Dokumen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="docUpdateForm">
                <input type="hidden" name="action" value="save_doc_tracking">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="vehicle_id" id="modal_vehicle_id" value="">

                <div class="modal-body">
                    <div class="vehicle-info-box">
                        <div class="small text-muted fw-bold text-uppercase" style="font-size: 0.72rem;">Unit Kendaraan:</div>
                        <div class="fw-bold text-dark" style="font-size: 0.95rem;" id="modal_vehicle_title">-</div>
                        <div class="small text-muted" id="modal_vehicle_plate">-</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="modal_bpkb_status" class="form-label form-label-sm">Posisi & Status BPKB <span class="text-danger">*</span></label>
                            <select class="form-select" id="modal_bpkb_status" name="bpkb_status" required>
                                <option value="in_showroom">Di Showroom (Tersedia / Brankas)</option>
                                <option value="in_leasing">Di Leasing (Agunan Pembiayaan)</option>
                                <option value="mutasi_process">Proses Mutasi / Balik Nama (BBN)</option>
                                <option value="cabut_berkas">Proses Cabut Berkas (Keluar Daerah)</option>
                                <option value="released_to_buyer">Sudah Diserahkan ke Pembeli</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_bpkb_number" class="form-label form-label-sm">Nomor BPKB</label>
                            <input type="text" class="form-control" id="modal_bpkb_number" name="bpkb_number" placeholder="Contoh: M-09128381">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_stnk_status" class="form-label form-label-sm">Status STNK</label>
                            <select class="form-select" id="modal_stnk_status" name="stnk_status">
                                <option value="available">STNK Asli Tersedia & Aktif</option>
                                <option value="expired">Masa Pajak / STNK Habis</option>
                                <option value="renewal_process">Sedang Proses Perpanjangan di Samsat</option>
                                <option value="released_to_buyer">Sudah Diserahkan ke Pembeli</option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_stnk_tax_date" class="form-label form-label-sm">Tanggal Jatuh Tempo Pajak STNK</label>
                            <input type="date" class="form-control" id="modal_stnk_tax_date" name="stnk_tax_date">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_agent_service_name" class="form-label form-label-sm">Nama Biro Jasa / Samsat (Jika Proses)</label>
                            <input type="text" class="form-control" id="modal_agent_service_name" name="agent_service_name" placeholder="Contoh: Biro Jasa Mitra Abadi / Samsat">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label for="modal_estimated_completion_date" class="form-label form-label-sm">Estimasi Selesai Mutasi/Cabut Berkas</label>
                            <input type="date" class="form-control" id="modal_estimated_completion_date" name="estimated_completion_date">
                        </div>

                        <div class="col-12 mb-3">
                            <label for="modal_bpkb_location_notes" class="form-label form-label-sm">Catatan Lokasi Fisik Berkas / Brankas / No. Map</label>
                            <input type="text" class="form-control" id="modal_bpkb_location_notes" name="bpkb_location_notes" placeholder="Contoh: Brankas Utama Lantai 2, Map Hijau No. 14">
                        </div>

                        <div class="col-12 mb-3">
                            <label for="modal_tracking_notes" class="form-label form-label-sm">Catatan Tambahan Tracking</label>
                            <textarea class="form-control" id="modal_tracking_notes" name="tracking_notes" rows="2" placeholder="Catatan progress cabut berkas / nomor resi pengiriman..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Posisi Berkas
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openDocModal(data) {
    if (!data) return;
    
    document.getElementById('modal_vehicle_id').value = data.id || '';
    document.getElementById('modal_vehicle_title').textContent = (data.brand_name || '') + ' ' + (data.model_name || '') + (data.year ? ' (' + data.year + ')' : '');
    document.getElementById('modal_vehicle_plate').textContent = 'Plat: ' + (data.license_plate || '-') + ' | No. Rangka: ' + (data.chassis_number || '-');

    document.getElementById('modal_bpkb_status').value = data.bpkb_status || 'in_showroom';
    document.getElementById('modal_bpkb_number').value = data.bpkb_number || '';
    document.getElementById('modal_stnk_status').value = data.stnk_status || 'available';
    document.getElementById('modal_stnk_tax_date').value = data.stnk_tax_date || '';
    document.getElementById('modal_agent_service_name').value = data.agent_service_name || '';
    document.getElementById('modal_estimated_completion_date').value = data.estimated_completion_date || '';
    document.getElementById('modal_bpkb_location_notes').value = data.bpkb_location_notes || '';
    document.getElementById('modal_tracking_notes').value = data.tracking_notes || '';

    const modalEl = document.getElementById('docUpdateModal');
    if (modalEl) {
        new bootstrap.Modal(modalEl).show();
    }
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php include '../includes/footer.php'; ?>
