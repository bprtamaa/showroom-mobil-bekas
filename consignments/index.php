<?php
// consignments/index.php - Daftar Mobil Titip Jual (Konsinyasi)
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
$status_filter = trim($_GET['status'] ?? '');
$brand_filter = trim($_GET['brand'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sorting
$sort_column = $_GET['sort'] ?? 'c.created_at';
$sort_order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$allowed_sort = ['c.created_at', 'c.end_date', 'c.target_selling_price', 'c.net_price_to_owner', 'c.status'];
if (!in_array($sort_column, $allowed_sort)) {
    $sort_column = 'c.created_at';
}

// Handle Delete Consignment Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_consignment') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid.', 'danger');
    } else {
        $deleteId = (int)($_POST['id'] ?? 0);
        try {
            $stmtDel = $db->prepare("DELETE FROM consignments WHERE id = ?");
            $stmtDel->execute([$deleteId]);
            showAlert('Data titip jual konsinyasi berhasil dihapus.', 'success');
            redirect('consignments/');
        } catch (PDOException $e) {
            showAlert('Gagal menghapus data: ' . $e->getMessage(), 'danger');
        }
    }
}

try {
    // 1. Get Summary Statistics for KPI Cards
    $today = date('Y-m-d');
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_consignments,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as total_active,
            SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as total_sold,
            SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as total_returned,
            SUM(CASE WHEN status = 'active' AND end_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as total_expiring_soon,
            COALESCE(SUM(CASE WHEN status = 'active' THEN target_selling_price ELSE 0 END), 0) as total_active_value,
            COALESCE(SUM(CASE WHEN status = 'sold' THEN commission_amount ELSE 0 END), 0) as total_earned_commission
        FROM consignments
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_consignments' => 0,
        'total_active' => 0,
        'total_sold' => 0,
        'total_returned' => 0,
        'total_expiring_soon' => 0,
        'total_active_value' => 0,
        'total_earned_commission' => 0
    ];

    // 2. Build Query & Filters
    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $whereClauses[] = "(c.consignment_code LIKE ? OR c.license_plate LIKE ? OR c.model_name LIKE ? OR c.brand_name LIKE ? OR c.owner_name LIKE ? OR c.owner_phone LIKE ?)";
        $sParam = "%$search%";
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
    }

    if (!empty($status_filter)) {
        $whereClauses[] = "c.status = ?";
        $params[] = $status_filter;
    }

    if (!empty($brand_filter)) {
        $whereClauses[] = "(c.brand_id = ? OR c.brand_name = ?)";
        $params[] = $brand_filter;
        $params[] = $brand_filter;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    // Count Total Records
    $countStmt = $db->prepare("SELECT COUNT(*) FROM consignments c $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    // Get Consignment Records
    $sql = "
        SELECT c.*,
               DATEDIFF(c.end_date, CURDATE()) as days_left
        FROM consignments c
        $whereSql
        ORDER BY $sort_column $sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $consignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get Brands for Filter
    $stmtBrands = $db->query("SELECT DISTINCT brand_name FROM consignments WHERE brand_name IS NOT NULL AND brand_name != '' ORDER BY brand_name ASC");
    $filterBrands = $stmtBrands->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}
?>

<style>
    /* ===== Consignments Index Specific Styles (Matching Vehicles & Tracking Design) ===== */
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
    .vehicles-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
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

    /* Summary Cards Grid */
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
    .filter-select:focus { border-color: #94a3b8; background: #fff; outline: none; }
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
        transition: box-shadow 0.2s;
    }
    .table-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .table-card .table-responsive {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }
    .table-card .table-responsive::-webkit-scrollbar {
        height: 6px;
    }
    .table-card .table-responsive::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
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
    }

    /* Vehicles & Consignment Table */
    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 960px;
    }
    .vehicles-table thead th {
        padding: 10px 12px;
        font-size: 0.68rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .vehicles-table tbody td {
        padding: 10px 12px;
        font-size: 0.82rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
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

    .plate-badge {
        display: inline-block;
        padding: 2px 7px;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        border-radius: 5px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #475569;
        letter-spacing: 0.04em;
        font-family: monospace;
    }

    .btn-wa {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #059669;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-wa:hover {
        background: #10b981;
        color: #fff;
    }

    .action-btn {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        flex-shrink: 0;
    }
    .action-btn-view:hover { background: rgba(34,197,94,0.12); color: #16a34a; border-color: rgba(34,197,94,0.25); }
    .action-btn-edit:hover { background: rgba(59,130,246,0.12); color: #2563eb; border-color: rgba(59,130,246,0.25); }
    .action-btn-delete:hover { background: rgba(239,68,68,0.12); color: #dc2626; border-color: rgba(239,68,68,0.25); }

    .action-col {
        white-space: nowrap;
        text-align: center;
        width: 100px;
    }
    .action-group {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        flex-shrink: 0;
    }

    /* Expiry countdown badge */
    .badge-expiry {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
    }
    .badge-expiry.safe { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    .badge-expiry.warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    .badge-expiry.danger { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-icon.slate { background: rgba(148,163,184,0.15); color: #cbd5e1; }
    [data-theme="dark"] .summary-icon.emerald { background: rgba(34,197,94,0.15); color: #86efac; }
    [data-theme="dark"] .summary-icon.blue { background: rgba(2,132,199,0.15); color: #7dd3fc; }
    [data-theme="dark"] .summary-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-label { color: #94a3b8; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-wa { background: rgba(16,185,129,0.15); border-color: rgba(16,185,129,0.3); color: #6ee7b7; }

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
        <h1>Titip Jual</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Titip Jual</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i> Tambah Mobil Titipan
        </a>
    </div>
</div>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-file-signature"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['total_consignments']); ?></div>
            <div class="summary-label">Total Titip Jual</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-car-side"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo number_format($stats['total_active']); ?></div>
            <div class="summary-label">Unit Aktif Titip</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-handshake"></i>
        </div>
        <div>
            <div class="summary-value text-primary"><?php echo number_format($stats['total_sold']); ?></div>
            <div class="summary-label">Berhasil Terjual</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-clock-rotate-left"></i>
        </div>
        <div>
            <div class="summary-value text-warning"><?php echo number_format($stats['total_expiring_soon']); ?></div>
            <div class="summary-label">Jatuh Tempo (H-7)</div>
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
                    <input type="text" name="search" placeholder="Cari kode titipan, nama mobil, no. polisi, nama pemilik..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Aktif Dititipkan</option>
                    <option value="sold" <?php echo $status_filter == 'sold' ? 'selected' : ''; ?>>Laku Terjual</option>
                    <option value="returned" <?php echo $status_filter == 'returned' ? 'selected' : ''; ?>>Dikembalikan</option>
                    <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
                </select>
                <select name="brand" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Merek</option>
                    <?php foreach ($filterBrands as $fb): ?>
                    <option value="<?php echo htmlspecialchars($fb); ?>" <?php echo $brand_filter == $fb ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($fb); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($status_filter) || !empty($brand_filter)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset Filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">
            <i class="fas fa-list text-primary me-2"></i>Daftar Mobil Titip Jual
        </h5>
        <div class="table-card-controls">
            <span class="record-count bg-light text-muted border">
                <?php echo number_format($totalRecords); ?> Transaksi
            </span>
            <form method="GET" class="d-inline">
                <?php if ($search): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
                <?php if ($brand_filter): ?><input type="hidden" name="brand" value="<?php echo htmlspecialchars($brand_filter); ?>"><?php endif; ?>
                <select name="limit" class="limit-select" onchange="this.form.submit()">
                    <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                    <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                    <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                </select>
            </form>
        </div>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="min-width: 120px;">KODE & TANGGAL</th>
                        <th style="min-width: 150px;">MOBIL TITIPAN</th>
                        <th style="min-width: 140px;">PEMILIK / REKANAN</th>
                        <th style="min-width: 115px;" class="text-end">HARGA NET</th>
                        <th style="min-width: 115px;" class="text-end">TARGET JUAL</th>
                        <th style="min-width: 105px;" class="text-end">KOMISI</th>
                        <th style="min-width: 110px;">BATAS WAKTU</th>
                        <th style="min-width: 90px;" class="text-center">STATUS</th>
                        <th style="min-width: 100px;" class="action-col">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($consignments)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5">
                            <i class="fas fa-handshake fa-2x text-muted opacity-50 mb-2"></i>
                            <div class="fw-bold text-dark-emphasis">Belum ada data mobil titip jual</div>
                            <small class="text-muted">Klik tombol "+ Tambah Mobil Titipan" untuk membuat pendaftaran baru.</small>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($consignments as $c): 
                        $daysLeft = (int)$c['days_left'];
                        $waPhone = preg_replace('/[^0-9]/', '', $c['owner_phone']);
                        if (substr($waPhone, 0, 1) === '0') {
                            $waPhone = '62' . substr($waPhone, 1);
                        }
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-dark-emphasis small"><?php echo htmlspecialchars($c['consignment_code']); ?></div>
                            <div class="text-muted" style="font-size:0.72rem;"><?php echo date('d M Y', strtotime($c['start_date'])); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark-emphasis"><?php echo htmlspecialchars($c['brand_name'] . ' ' . $c['model_name']); ?></div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="plate-badge"><?php echo htmlspecialchars($c['license_plate']); ?></span>
                                <small class="text-muted" style="font-size:0.72rem;"><?php echo $c['year']; ?> • <?php echo htmlspecialchars($c['color']); ?></small>
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark-emphasis"><?php echo htmlspecialchars($c['owner_name']); ?></div>
                            <div class="mt-1">
                                <a href="https://wa.me/<?php echo $waPhone; ?>?text=Halo%20<?php echo urlencode($c['owner_name']); ?>%2C%20terkait%20mobil%20titipan%20<?php echo urlencode($c['brand_name'] . ' ' . $c['model_name'] . ' (' . $c['license_plate'] . ')'); ?>" target="_blank" class="btn-wa">
                                    <i class="fab fa-whatsapp"></i> <?php echo htmlspecialchars($c['owner_phone']); ?>
                                </a>
                            </div>
                        </td>
                        <td class="text-end fw-semibold text-dark-emphasis">
                            Rp <?php echo number_format($c['net_price_to_owner'], 0, ',', '.'); ?>
                        </td>
                        <td class="text-end fw-bold text-primary">
                            Rp <?php echo number_format($c['target_selling_price'], 0, ',', '.'); ?>
                        </td>
                        <td class="text-end">
                            <div class="fw-bold text-success">
                                Rp <?php echo number_format($c['commission_amount'], 0, ',', '.'); ?>
                            </div>
                            <small class="text-muted" style="font-size:0.7rem;">
                                <?php echo $c['target_selling_price'] > 0 ? round(($c['commission_amount'] / $c['target_selling_price']) * 100, 1) : 0; ?>% margin
                            </small>
                        </td>
                        <td>
                            <div class="fw-semibold" style="font-size:0.8rem;"><?php echo date('d M Y', strtotime($c['end_date'])); ?></div>
                            <?php if ($c['status'] === 'active'): ?>
                                <?php if ($daysLeft < 0): ?>
                                    <span class="badge-expiry danger"><i class="fas fa-exclamation-triangle"></i> Lewat <?php echo abs($daysLeft); ?> hari</span>
                                <?php elseif ($daysLeft <= 7): ?>
                                    <span class="badge-expiry warning"><i class="fas fa-clock"></i> Sisa <?php echo $daysLeft; ?> hari</span>
                                <?php else: ?>
                                    <span class="badge-expiry safe"><i class="fas fa-check"></i> Sisa <?php echo $daysLeft; ?> hari</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge bg-light text-muted border" style="font-size:0.7rem;">Selesai</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($c['status'] === 'active'): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">Aktif Titip</span>
                            <?php elseif ($c['status'] === 'sold'): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">Terjual</span>
                            <?php elseif ($c['status'] === 'returned'): ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">Dikembalikan</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary fw-bold px-2 py-1" style="font-size:0.75rem; border-radius:6px;">Dibatalkan</span>
                            <?php endif; ?>
                        </td>
                        <td class="action-col">
                            <div class="action-group">
                                <a href="view.php?id=<?php echo $c['id']; ?>" class="action-btn action-btn-view" title="Detail & Cetak Perjanjian">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="edit.php?id=<?php echo $c['id']; ?>" class="action-btn action-btn-edit" title="Edit Data">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-delete" title="Hapus" onclick="confirmDelete(<?php echo $c['id']; ?>, '<?php echo htmlspecialchars($c['consignment_code']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-transparent border-top p-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <small class="text-muted">
            Menampilkan <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $totalRecords); ?> dari <?php echo $totalRecords; ?> data
        </small>
        <ul class="pagination mb-0 pagination-sm">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&limit=<?php echo $limit; ?>">Sebelumnya</a>
            </li>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&limit=<?php echo $limit; ?>"><?php echo $i; ?></a>
                    </li>
                <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&brand=<?php echo urlencode($brand_filter); ?>&limit=<?php echo $limit; ?>">Selanjutnya</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete_consignment">
    <input type="hidden" name="id" id="deleteId">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
</form>

<script>
function confirmDelete(id, code) {
    if (confirm('Apakah Anda yakin ingin menghapus data titip jual ' + code + '? Data yang dihapus tidak dapat dikembalikan.')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>
