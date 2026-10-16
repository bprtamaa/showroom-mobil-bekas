<?php
// car_types/index.php
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard/');
    exit();
}
include '../includes/header.php';
requireLogin();

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}

// Handle POST Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // ADD TYPE
        if ($action === 'add_type') {
            $name = sanitizeInput($_POST['name'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $error = 'Token keamanan tidak valid (CSRF)';
            } elseif (empty($name)) {
                $error = 'Nama jenis mobil harus diisi';
            } else {
                try {
                    // Check duplicate
                    $stmt = $db->prepare("SELECT id FROM car_types WHERE name = ?");
                    $stmt->execute([$name]);
                    if ($stmt->fetch()) {
                        $error = 'Nama jenis mobil sudah ada';
                    } else {
                        $stmt = $db->prepare("INSERT INTO car_types (name, status, created_at) VALUES (?, ?, NOW())");
                        if ($stmt->execute([$name, $status])) {
                            showAlert('Jenis mobil berhasil ditambahkan', 'success');
                            redirect('car_types/');
                        } else {
                            $error = 'Gagal menyimpan data jenis mobil';
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
        
        // EDIT TYPE
        elseif ($action === 'edit_type') {
            $id = (int)($_POST['id'] ?? 0);
            $name = sanitizeInput($_POST['name'] ?? '');
            $status = $_POST['status'] ?? 'active';
            
            if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
                $error = 'Token keamanan tidak valid (CSRF)';
            } elseif (empty($id)) {
                $error = 'ID jenis mobil tidak valid';
            } elseif (empty($name)) {
                $error = 'Nama jenis mobil harus diisi';
            } else {
                try {
                    // Check duplicate (exclude self)
                    $stmt = $db->prepare("SELECT id FROM car_types WHERE name = ? AND id != ?");
                    $stmt->execute([$name, $id]);
                    if ($stmt->fetch()) {
                        $error = 'Nama jenis mobil sudah digunakan';
                    } else {
                        $stmt = $db->prepare("UPDATE car_types SET name = ?, status = ?, updated_at = NOW() WHERE id = ?");
                        if ($stmt->execute([$name, $status, $id])) {
                            showAlert('Jenis mobil berhasil diperbarui', 'success');
                            redirect('car_types/');
                        } else {
                            $error = 'Gagal memperbarui data jenis mobil';
                        }
                    }
                } catch (PDOException $e) {
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
    }
}

// Handle search, pagination, and sorting
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'name';
$sort_order = $_GET['order'] ?? 'ASC';

// Custom sorting logic for "No" column
if ($sort_column === 'created_at') {
    $sort_column = 'name';
}

$allowed_sort_cols = ['name', 'id', 'created_at', 'status', 'model_count', 'vehicle_count'];
if (!in_array($sort_column, $allowed_sort_cols)) {
    $sort_column = 'name';
}
$sort_order = strtoupper($sort_order) === 'DESC' ? 'DESC' : 'ASC';

$sortMap = [
    'created_at' => 'ct.created_at',
    'name' => 'ct.name',
    'id' => 'ct.id',
    'status' => 'ct.status',
    'model_count' => 'model_count',
    'vehicle_count' => 'vehicle_count'
];
$db_sort_column = $sortMap[$sort_column] ?? 'ct.name';

// Initialize variables
$totalRecords = 0;
$totalPages = 0;
$carTypes = [];

try {
    $whereClause = '';
    $params = [];
    
    if (!empty($search)) {
        $whereClause = "WHERE ct.name LIKE ?";
        $params = ["%$search%"];
    }
    
    // Get total records
    $countSql = "SELECT COUNT(*) as total FROM car_types ct $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $countRow = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalRecords = $countRow['total'] ?? 0;
    $totalPages = ceil($totalRecords / $limit);
    
    // Get car types with statistics
    $sql = "
        SELECT ct.*,
               COUNT(DISTINCT m.id) as model_count,
               COUNT(DISTINCT v.id) as vehicle_count
        FROM car_types ct
        LEFT JOIN models m ON ct.id = m.car_type_id
        LEFT JOIN vehicles v ON m.id = v.model_id
        $whereClause
        GROUP BY ct.id
        ORDER BY $db_sort_column $sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $carTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error loading car types: " . $e->getMessage();
}
?>

<style>
    /* ===== Car Types Page Specific Styles ===== */
    .car-types-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .car-types-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .car-types-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .btn-add-type {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.2s, transform 0.15s;
        white-space: nowrap;
    }
    .btn-add-type:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-add-type i { font-size: 0.75rem; }

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
        transform: none;
    }
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
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
        transform: none;
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
    .types-table {
        width: 100%;
        border-collapse: collapse;
    }
    .types-table thead th {
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
    .types-table tbody td {
        padding: 12px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .types-table tbody tr {
        transition: background 0.15s;
    }
    .types-table tbody tr:hover {
        background: #f8fafc;
    }
    .types-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Type Name Cell */
    .type-name-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .type-icon-placeholder {
        width: 34px;
        height: 34px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .type-icon-placeholder i { font-size: 0.8rem; color: #64748b; }
    .type-name-text { font-weight: 700; color: #1e293b; }

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

    /* Status Badge */
    .status-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
    }
    .status-active { background: #dcfce7; color: #166534; }
    .status-inactive { background: #fee2e2; color: #991b1b; }

    /* Date Text */
    .date-text { font-size: 0.78rem; color: #94a3b8; }

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
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.15s;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #dbeafe; border-color: #93c5fd; color: #2563eb; }
    .action-btn-edit:hover { background: #e0e7ff; border-color: #a5b4fc; color: #4f46e5; }
    .action-btn-delete:hover { background: #fee2e2; border-color: #fca5a5; color: #dc2626; }

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

    /* Modal Improvements */
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
    [data-theme="dark"] .car-types-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-type { background: #3b82f6; }
    [data-theme="dark"] .btn-add-type:hover { background: #2563eb; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .types-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .types-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .types-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .type-name-text { color: #f1f5f9; }
    [data-theme="dark"] .type-icon-placeholder { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .type-icon-placeholder i { color: #94a3b8; }
    [data-theme="dark"] .status-active { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .status-inactive { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #60a5fa; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(99,102,241,0.15); border-color: rgba(99,102,241,0.3); color: #a5b4fc; }
    [data-theme="dark"] .action-btn-delete:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
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
    [data-theme="dark"] .btn-save { background: #3b82f6; }
    [data-theme="dark"] .btn-save:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .record-count { background-color: rgba(59,130,246,0.15) !important; color: #93c5fd !important; }

    /* Responsive */
    @media (max-width: 576px) {
        .car-types-page-header {
            flex-direction: column;
            gap: 10px;
        }
        .btn-add-type { width: 100%; justify-content: center; }
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .types-table thead th,
        .types-table tbody td { padding: 10px 10px; }
        .action-btn { width: 28px; height: 28px; font-size: 0.68rem; }
    }
</style>

<!-- Page Header -->
<div class="car-types-page-header">
    <div>
        <h1>Jenis Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Jenis Mobil</li>
            </ol>
        </nav>
    </div>
    <button type="button" class="btn-add-type" data-bs-toggle="modal" data-bs-target="#addCarTypeModal">
        <i class="fas fa-plus"></i> Tambah Jenis Mobil
    </button>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Search -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari jenis mobil..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search)): ?>
                <a href="?" class="btn-reset-search" title="Reset pencarian">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Car Types Table -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Jenis Mobil</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Jenis</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($carTypes)): ?>
        <div class="table-responsive">
            <table class="types-table">
                <thead>
                    <tr>
                        <th class="col-no">
                            <span>No</span>
                            <span class="sort-controls">
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=name&order=ASC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'ASC') ? 'active-sort' : ''; ?>"
                                   title="A-Z"><i class="fas fa-caret-up"></i></a>
                                <a href="?page=<?php echo $page; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=name&order=DESC" 
                                   class="<?php echo ($sort_column == 'name' && $sort_order == 'DESC') ? 'active-sort' : ''; ?>"
                                   title="Z-A"><i class="fas fa-caret-down"></i></a>
                            </span>
                        </th>
                        <th>Nama Jenis</th>
                        <th class="col-hide-md">Jumlah Model</th>
                        <th class="col-hide-md">Jumlah Unit</th>
                        <th>Status</th>
                        <th class="col-hide-sm">Dibuat</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($carTypes as $index => $carType): ?>
                    <tr>
                        <td class="col-no-val"><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <div class="type-name-cell">
                                <div class="type-icon-placeholder">
                                    <i class="fas fa-car-side"></i>
                                </div>
                                <span class="type-name-text"><?php echo htmlspecialchars($carType['name']); ?></span>
                            </div>
                        </td>
                        <td class="col-hide-md">
                            <span class="badge-modern bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1"><?php echo number_format($carType['model_count']); ?> Model</span>
                        </td>
                        <td class="col-hide-md">
                            <span class="badge-modern bg-success bg-opacity-10 text-success fw-semibold px-2 py-1"><?php echo number_format($carType['vehicle_count']); ?> Unit</span>
                        </td>
                        <td>
                            <?php
                            $ctstatus = strtolower((string)($carType['status'] ?? ''));
                            $statusLabel = $ctstatus === 'active' ? 'Aktif' : ($ctstatus === 'inactive' ? 'Tidak Aktif' : ucfirst($carType['status']));
                            $statusClass = $ctstatus === 'active' ? 'status-active' : 'status-inactive';
                            ?>
                            <span class="status-badge <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                        </td>
                        <td class="col-hide-sm">
                            <span class="date-text"><?php echo formatDate($carType['created_at']); ?></span>
                        </td>
                        <td>
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $carType['id']; ?>" class="action-btn action-btn-view" data-bs-toggle="tooltip" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-edit" 
                                        onclick='openEditModal(<?php echo htmlspecialchars(json_encode($carType), ENT_QUOTES, "UTF-8"); ?>)'
                                        data-bs-toggle="tooltip" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="action-btn action-btn-delete" 
                                        onclick="deleteCarType(<?php echo $carType['id']; ?>)" 
                                        data-bs-toggle="tooltip" title="Hapus">
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
                    <a href="?page=<?php echo max(1, $page - 1); ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                <li class="<?php echo $i == $page ? 'active' : ''; ?>">
                    <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo min($totalPages, $page + 1); ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-car-side"></i></div>
            <h5><?php echo !empty($search) ? 'Jenis Mobil Tidak Ditemukan' : 'Tidak ada data jenis mobil'; ?></h5>
            <p>
                <?php echo !empty($search) 
                    ? 'Tidak ada jenis mobil yang cocok dengan pencarian "<strong>' . htmlspecialchars($search) . '</strong>".' 
                    : 'Belum ada jenis mobil yang ditambahkan ke sistem.'; ?>
            </p>
            <?php if (!empty($search)): ?>
            <a href="?" class="btn-cancel">
                <i class="fas fa-redo-alt me-1"></i>Reset Pencarian
            </a>
            <?php else: ?>
            <button type="button" class="btn-add-type" data-bs-toggle="modal" data-bs-target="#addCarTypeModal">
                <i class="fas fa-plus"></i> Tambah Jenis Mobil Pertama
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function deleteCarType(id) {
    if (confirmDelete('Apakah Anda yakin ingin menghapus jenis mobil ini? Semua model terkait juga akan terpengaruh.')) {
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
    const modal = document.getElementById('editCarTypeModal');
    const form = document.getElementById('editCarTypeForm');
    
    // Set form values
    form.querySelector('[name="id"]').value = data.id;
    form.querySelector('[name="name"]').value = data.name;
    form.querySelector('[name="status"]').value = data.status;
    
    // Open modal
    new bootstrap.Modal(modal).show();
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<!-- Add Car Type Modal -->
<div class="modal fade modal-custom" id="addCarTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Tambah Jenis Mobil Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="addCarTypeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_type">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label form-label-sm">Nama Jenis Mobil <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" placeholder="Contoh: SUV, Sedan, MPV, Hatchback" required>
                        <div class="form-hint">Nama jenis mobil harus unik</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label form-label-sm">Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active" selected>Aktif</option>
                            <option value="inactive">Tidak Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Simpan Jenis Mobil
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Car Type Modal -->
<div class="modal fade modal-custom" id="editCarTypeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Jenis Mobil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editCarTypeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_type">
                    <input type="hidden" name="id" value="">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label form-label-sm">Nama Jenis Mobil <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_status" class="form-label form-label-sm">Status</label>
                        <select class="form-select" id="edit_status" name="status">
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

<?php include '../includes/footer.php'; ?>
