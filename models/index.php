<?php
// models/index.php - Daftar Model Mobil
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

$search = trim($_GET['search'] ?? '');
$brand_filter = trim($_GET['brand'] ?? '');
$type_filter = trim($_GET['type'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'alpabet';
$sort_order = $_GET['order'] ?? 'ASC';

$dir = ($sort_order === 'DESC' ? 'DESC' : 'ASC');
$db_sort_clause = "b.name $dir, m.name $dir";

if ($sort_column === 'created_at') {
    $db_sort_clause = "m.created_at $dir";
} elseif ($sort_column === 'm.name') {
    $db_sort_clause = "m.name $dir";
} elseif ($sort_column === 'b.name') {
    $db_sort_clause = "b.name $dir";
}

// Handle POST Requests (Add/Edit Model)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        if (!function_exists('sanitizeInput')) { 
            function sanitizeInput($s){ return htmlspecialchars(trim($s), ENT_QUOTES, 'UTF-8'); } 
        }

        if ($action === 'add_model' || $action === 'edit_model') {
            $name = sanitizeInput($_POST['name'] ?? '');
            $brand_id = (int)($_POST['brand_id'] ?? 0);
            $car_type_id = (int)($_POST['car_type_id'] ?? 0);
            $status = $_POST['status'] ?? 'active';
            
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $error = 'Token keamanan tidak valid (CSRF)';
            } elseif (empty($name) || empty($brand_id) || empty($car_type_id)) {
                $error = 'Nama model, merek, dan jenis mobil wajib diisi';
            } else {
                try {
                    if ($action === 'add_model') {
                        $checkStmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ?");
                        $checkStmt->execute([$name, $brand_id]);
                        if ($checkStmt->rowCount() > 0) {
                            $error = 'Model mobil ini sudah ada untuk merek tersebut!';
                        } else {
                            $stmt = $db->prepare("INSERT INTO models (name, brand_id, car_type_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                            if ($stmt->execute([$name, $brand_id, $car_type_id, $status])) {
                                showAlert('Model mobil berhasil ditambahkan!', 'success');
                                redirect('models/index.php');
                            } else {
                                $error = 'Gagal menambahkan data.';
                            }
                        }
                    } elseif ($action === 'edit_model') {
                        $id = (int)($_POST['id'] ?? 0);
                        if (empty($id)) {
                            $error = 'ID Model tidak valid';
                        } else {
                            $checkStmt = $db->prepare("SELECT id FROM models WHERE name = ? AND brand_id = ? AND id != ?");
                            $checkStmt->execute([$name, $brand_id, $id]);
                            if ($checkStmt->rowCount() > 0) {
                                $error = 'Model mobil ini sudah ada untuk merek tersebut!';
                            } else {
                                $stmt = $db->prepare("UPDATE models SET name = ?, brand_id = ?, car_type_id = ?, status = ?, updated_at = NOW() WHERE id = ?");
                                if ($stmt->execute([$name, $brand_id, $car_type_id, $status, $id])) {
                                    showAlert('Model mobil berhasil diperbarui!', 'success');
                                    redirect('models/index.php');
                                } else {
                                    $error = 'Gagal memperbarui data.';
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Database Error: ' . $e->getMessage();
                }
            }
        }
    }
}

try {
    $whereClauses = [];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "m.name LIKE ?";
        $params[] = "%$search%";
    }
    
    if (!empty($brand_filter)) {
        $whereClauses[] = "m.brand_id = ?";
        $params[] = $brand_filter;
    }
    
    if (!empty($type_filter)) {
        $whereClauses[] = "m.car_type_id = ?";
        $params[] = $type_filter;
    }
    
    $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    $countSql = "SELECT COUNT(*) as total FROM models m $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = $stmt->fetch()['total'] ?? 0;
    $totalPages = ceil($totalRecords / $limit);
    
    $sql = "
        SELECT m.*,
               b.name as brand_name,
               ct.name as car_type_name,
               COUNT(v.id) as vehicle_count
        FROM models m
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN vehicles v ON m.id = v.model_id
        $whereClause
        GROUP BY m.id
        ORDER BY $db_sort_clause
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $models = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC");
    $carTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<style>
    /* ===== Model Mobil Page Specific Styles (Matching Brands Design) ===== */
    .models-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .models-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .models-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .btn-add-model {
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
    .btn-add-model:hover {
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
        width: 160px;
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
    .models-table {
        width: 100%;
        border-collapse: collapse;
    }
    .models-table thead th {
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
    .models-table tbody td {
        padding: 12px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .models-table tbody tr {
        transition: background 0.15s;
    }
    .models-table tbody tr:hover {
        background: #f8fafc;
    }
    .models-table tbody tr:last-child td {
        border-bottom: none;
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
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }
    .unit-badge {
        display: inline-block;
        padding: 3px 9px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        background: #eff6ff;
        color: #2563eb;
    }

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
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
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
    [data-theme="dark"] .models-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-model { background: #3b82f6; }
    [data-theme="dark"] .btn-add-model:hover { background: #2563eb; }
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
    [data-theme="dark"] .models-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .models-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .models-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #86efac; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
    [data-theme="dark"] .action-btn-delete:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
    [data-theme="dark"] .status-active { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-inactive { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .unit-badge { background: rgba(59,130,246,0.15); color: #93c5fd; }
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
        .models-page-header { flex-direction: column; gap: 10px; }
        .btn-add-model { width: 100%; justify-content: center; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .models-table thead th,
        .models-table tbody td { padding: 10px 10px; }
    }
</style>

<!-- Page Header -->
<div class="models-page-header">
    <div>
        <h1>Model Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Model Mobil</li>
            </ol>
        </nav>
    </div>
    <button type="button" class="btn-add-model" data-bs-toggle="modal" data-bs-target="#addModelModal">
        <i class="fas fa-plus"></i> Tambah Model
    </button>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Search & Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari nama model mobil..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="brand" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Merek</option>
                    <?php foreach ($brands as $b): ?>
                    <option value="<?php echo $b['id']; ?>" <?php echo $brand_filter == $b['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($b['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Jenis</option>
                    <?php foreach ($carTypes as $t): ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo $type_filter == $t['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($brand_filter) || !empty($type_filter)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Models Table Card -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Model Mobil</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Model</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($models)): ?>
        <div class="table-responsive">
            <table class="models-table">
                <thead>
                    <tr>
                        <th class="col-no">
                            <span>No</span>
                            <span class="sort-controls">
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=alpabet&order=ASC" 
                                   class="<?php echo ($sort_column == 'alpabet' && $sort_order == 'ASC') ? 'active-sort' : ''; ?>"
                                   title="A-Z"><i class="fas fa-caret-up"></i></a>
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=alpabet&order=DESC" 
                                   class="<?php echo ($sort_column == 'alpabet' && $sort_order == 'DESC') ? 'active-sort' : ''; ?>"
                                   title="Z-A"><i class="fas fa-caret-down"></i></a>
                            </span>
                        </th>
                        <th>Merek</th>
                        <th>Model</th>
                        <th>Jenis Mobil</th>
                        <th>Jumlah Unit</th>
                        <th>Status</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($models as $idx => $m): 
                        $mstatus = strtolower((string)($m['status'] ?? 'active'));
                        $isActive = ($mstatus === 'active');
                    ?>
                    <tr>
                        <td class="col-no-val"><?php echo $offset + $idx + 1; ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="fas fa-tag text-muted" style="font-size: 0.8rem;"></i>
                                <span class="fw-semibold text-dark"><?php echo htmlspecialchars($m['brand_name'] ?? '-'); ?></span>
                            </div>
                        </td>
                        <td>
                            <strong class="text-dark"><?php echo htmlspecialchars($m['name']); ?></strong>
                        </td>
                        <td>
                            <span class="text-muted"><?php echo htmlspecialchars($m['car_type_name'] ?? '-'); ?></span>
                        </td>
                        <td>
                            <span class="unit-badge"><?php echo number_format($m['vehicle_count'] ?? 0); ?> Unit</span>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $isActive ? 'status-active' : 'status-inactive'; ?>">
                                <i class="fas <?php echo $isActive ? 'fa-check' : 'fa-times'; ?>"></i>
                                <?php echo $isActive ? 'Aktif' : 'Tidak Aktif'; ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $m['id']; ?>" class="action-btn action-btn-view" data-bs-toggle="tooltip" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-edit" 
                                        onclick='openEditModal(<?php echo htmlspecialchars(json_encode($m), ENT_QUOTES, "UTF-8"); ?>)'
                                        data-bs-toggle="tooltip" title="Edit Model">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="action-btn action-btn-delete" 
                                        onclick="deleteModel(<?php echo $m['id']; ?>)" 
                                        data-bs-toggle="tooltip" title="Hapus Model">
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
                    <a href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&brand=<?php echo urlencode($brand_filter); ?>&type=<?php echo urlencode($type_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-list-alt"></i></div>
            <h5><?php echo (!empty($search) || !empty($brand_filter) || !empty($type_filter)) ? 'Model Tidak Ditemukan' : 'Tidak ada data model'; ?></h5>
            <p>
                <?php echo (!empty($search) || !empty($brand_filter) || !empty($type_filter)) 
                    ? 'Tidak ditemukan data model mobil yang cocok dengan filter pencarian.' 
                    : 'Belum ada data model mobil yang ditambahkan.'; ?>
            </p>
            <?php if (!empty($search) || !empty($brand_filter) || !empty($type_filter)): ?>
            <a href="index.php" class="btn-cancel">
                <i class="fas fa-redo-alt me-1"></i>Reset Filter
            </a>
            <?php else: ?>
            <button type="button" class="btn-add-model" data-bs-toggle="modal" data-bs-target="#addModelModal">
                <i class="fas fa-plus"></i> Tambah Model Baru
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Model Modal -->
<div class="modal fade modal-custom" id="addModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tambah Model Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addModelForm">
                <input type="hidden" name="action" value="add_model">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="brand_id" required>
                             <option value="">-- Pilih Merek Mobil --</option>
                             <?php foreach ($brands as $b): ?>
                             <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="Contoh: Avanza, Pajero Sport, Civic">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jenis Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="car_type_id" required>
                             <option value="">-- Pilih Jenis Mobil --</option>
                             <?php foreach ($carTypes as $t): ?>
                             <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Status</label>
                        <select class="form-select" name="status">
                            <option value="active" selected>Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Model
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Model Modal -->
<div class="modal fade modal-custom" id="editModelModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Model Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editModelForm">
                <input type="hidden" name="action" value="edit_model">
                <input type="hidden" name="id" value="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Merek Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="brand_id" required>
                             <option value="">-- Pilih Merek Mobil --</option>
                             <?php foreach ($brands as $b): ?>
                             <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label form-label-sm">Nama Model <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Jenis Mobil <span class="text-danger">*</span></label>
                        <select class="form-select" name="car_type_id" required>
                             <option value="">-- Pilih Jenis Mobil --</option>
                             <?php foreach ($carTypes as $t): ?>
                             <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                             <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label form-label-sm">Status</label>
                        <select class="form-select" name="status">
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function deleteModel(id) {
    if (confirm('Apakah Anda yakin ingin menghapus model mobil ini?\n\nSemua data mobil terkait model ini mungkin juga terpengaruh.')) {
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

function openEditModal(data) {
    if (!data) return;
    const modalEl = document.getElementById('editModelModal');
    const form = document.getElementById('editModelForm');
    
    form.querySelector('[name="id"]').value = data.id || '';
    form.querySelector('[name="name"]').value = data.name || '';
    form.querySelector('[name="brand_id"]').value = data.brand_id || '';
    form.querySelector('[name="car_type_id"]').value = data.car_type_id || '';
    form.querySelector('[name="status"]').value = data.status || 'active';
    
    new bootstrap.Modal(modalEl).show();
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