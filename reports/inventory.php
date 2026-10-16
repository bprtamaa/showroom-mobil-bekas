<?php
// reports/inventory.php - Laporan Inventori Stok Mobil Showroom
session_start();
require_once '../config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if ($role !== 'admin') {
    header('Location: ../dashboard/');
    exit;
}

$pageTitle = 'Aplikasi Manajemen Showroom';
include '../includes/header.php';
requireLogin();

$db = new Database();
$conn = $db->connect();

// Get filter parameters
$status = isset($_GET['status']) ? $_GET['status'] : '';
$brand_id = isset($_GET['brand_id']) ? $_GET['brand_id'] : '';
$car_type_id = isset($_GET['car_type_id']) ? $_GET['car_type_id'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : (defined('RECORDS_PER_PAGE') ? RECORDS_PER_PAGE : 10);
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Get all brands & car types
$stmt = $conn->query("SELECT id, name FROM brands ORDER BY name ASC");
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $conn->query("SELECT id, name FROM car_types ORDER BY name ASC");
$carTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query for vehicles
$whereClauses = ["1=1"];
$params = [];

if (!empty($status)) {
    $whereClauses[] = "v.status = ?";
    $params[] = $status;
}

if (!empty($brand_id)) {
    $whereClauses[] = "b.id = ?";
    $params[] = $brand_id;
}

if (!empty($car_type_id)) {
    $whereClauses[] = "m.car_type_id = ?";
    $params[] = $car_type_id;
}

$whereSql = implode(' AND ', $whereClauses);

// Sorting logic
$valid_sort_columns = ['purchase_date', 'selling_price', 'year', 'mileage', 'status', 'brand_name'];
$sort_column = isset($_GET['sort']) && in_array($_GET['sort'], $valid_sort_columns) ? $_GET['sort'] : 'purchase_date';
$sort_order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

$order_clause = "v." . $sort_column;
if ($sort_column === 'brand_name') {
    $order_clause = "b.name";
}

// Count total records
$countQuery = "SELECT COUNT(*) as total
               FROM vehicles v
               JOIN models m ON v.model_id = m.id
               JOIN brands b ON m.brand_id = b.id
               JOIN car_types ct ON m.car_type_id = ct.id
               WHERE $whereSql";
$countStmt = $conn->prepare($countQuery);
$countStmt->execute($params);
$totalRecords = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
$totalPages = max(1, (int)ceil($totalRecords / $limit));

// Get vehicles
$query = "SELECT 
            v.id,
            v.chassis_number,
            v.license_plate,
            v.year as year,
            v.color,
            v.mileage,
            v.selling_price,
            v.status,
            v.purchase_date,
            b.name as brand_name,
            m.name as model_name,
            ct.name as type_name
          FROM vehicles v
          JOIN models m ON v.model_id = m.id
          JOIN brands b ON m.brand_id = b.id
          JOIN car_types ct ON m.car_type_id = ct.id
          WHERE $whereSql
          ORDER BY $order_clause $sort_order, v.created_at DESC
          LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stmt = $conn->query("SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
                        SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,
                        SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved,
                        SUM(CASE WHEN status = 'available' THEN selling_price ELSE 0 END) as available_value
                      FROM vehicles");
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Advanced inventory metrics
$stmt = $conn->query("SELECT COUNT(*) as sold_last_30_days 
                      FROM transactions 
                      WHERE payment_status = 'paid' 
                      AND transaction_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
$soldLast30Days = (int)($stmt->fetch(PDO::FETCH_ASSOC)['sold_last_30_days'] ?? 0);
$turnoverRate = ($stats['available'] ?? 0) > 0 ? ($soldLast30Days / $stats['available']) * 100 : 0;

$stmt = $conn->query("SELECT AVG(DATEDIFF(NOW(), COALESCE(purchase_date, DATE(created_at)))) as avg_age 
                      FROM vehicles 
                      WHERE status = 'available'");
$avgStockAge = round($stmt->fetch(PDO::FETCH_ASSOC)['avg_age'] ?? 0);

$stmt = $conn->query("SELECT COUNT(*) as dead_stock,
                             COALESCE(SUM(purchase_price), 0) as dead_stock_modal,
                             COALESCE(SUM(selling_price), 0) as dead_stock_value
                      FROM vehicles 
                      WHERE status = 'available' 
                      AND DATEDIFF(NOW(), COALESCE(purchase_date, DATE(created_at))) > 60");
$deadStockData = $stmt->fetch(PDO::FETCH_ASSOC);
$deadStock = (int)($deadStockData['dead_stock'] ?? 0);
$deadStockValue = (float)($deadStockData['dead_stock_value'] ?? 0);
$deadStockModal = (float)($deadStockData['dead_stock_modal'] ?? 0);

$stmt = $conn->query("SELECT COUNT(*) as at_risk
                      FROM vehicles 
                      WHERE status = 'available' 
                      AND DATEDIFF(NOW(), COALESCE(purchase_date, DATE(created_at))) BETWEEN 31 AND 60");
$atRiskCount = (int)($stmt->fetch(PDO::FETCH_ASSOC)['at_risk'] ?? 0);

// Status Labels
$statusLabels = [
    'available' => 'Tersedia',
    'sold' => 'Terjual',
    'reserved' => 'Dipesan',
    'maintenance' => 'Perbaikan'
];

$printCompanyName = defined('APP_NAME') ? APP_NAME : 'Mulya Motor Showroom';
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

    /* ===== Summary Grid ===== */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 992px) {
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
    .summary-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 14px 18px;
        display: flex;
        align-items: center;
        gap: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: all 0.2s;
    }
    .summary-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transform: translateY(-1px);
    }
    .summary-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
    .summary-icon.slate { background: #f1f5f9; color: #475569; }
    .summary-icon.emerald { background: #dcfce7; color: #166534; }
    .summary-icon.blue { background: #eff6ff; color: #2563eb; }
    .summary-icon.amber { background: #fef3c7; color: #b45309; }
    .summary-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
        letter-spacing: -0.02em;
    }
    .summary-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 2px;
    }

    /* ===== Search Card ===== */
    .search-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
    }
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
    }
    .filter-select {
        min-width: 150px;
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

    /* ===== Table Card ===== */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 20px;
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
        display: flex;
        align-items: center;
        gap: 8px;
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

    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 12px 14px;
        font-size: 0.7rem;
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
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }

    .plate-badge {
        display: inline-block;
        padding: 2px 7px;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .status-available { background: #dcfce7; color: #166534; }
    .status-sold { background: #fee2e2; color: #991b1b; }
    .status-reserved, .status-maintenance { background: #fef3c7; color: #92400e; }

    .action-btns {
        display: inline-flex;
        align-items: center;
        gap: 5px;
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
    .action-btn:hover { transform: translateY(-1px); background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .filter-select,
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .btn-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }

    @media print {
        .btn, .sidebar, .navbar, .card-header, form, .no-print, .vehicles-page-header, 
        .summary-grid, .search-card, .table-card, footer, .footer, #footer {
            display: none !important;
        }
        .print-only { display: block !important; }
        body { margin: 0; padding: 10px; background-color: white; color: black; }
        @page { margin: 1cm; size: A4; }
    }
    .print-only { display: none; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Laporan Inventori Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>reports/" class="text-primary text-decoration-underline">Laporan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Inventori Mobil</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>reports/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" onclick="window.print()" class="btn-add-vehicle">
            <i class="fas fa-print"></i> Cetak Laporan
        </button>
    </div>
</div>

<!-- Summary Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-sync-alt"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($turnoverRate, 1); ?>%</div>
            <div class="summary-label">Tingkat Perputaran</div>
            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $soldLast30Days; ?> Terjual (30 hari)</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($avgStockAge); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Hari</span></div>
            <div class="summary-label">Umur Stok Rerata</div>
            <small class="text-muted" style="font-size: 0.72rem;">Unit tersedia</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($deadStock); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Unit</span></div>
            <div class="summary-label">Stok Mati (>90 Hari)</div>
            <small class="text-danger fw-bold" style="font-size: 0.72rem;"><?php echo formatCurrency($deadStockValue); ?></small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($atRiskCount); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Unit</span></div>
            <div class="summary-label">Stok Berisiko</div>
            <small class="text-muted" style="font-size: 0.72rem;">Umur 60-90 hari</small>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status Stok</option>
                    <option value="available" <?php echo $status == 'available' ? 'selected' : ''; ?>>Tersedia</option>
                    <option value="reserved" <?php echo $status == 'reserved' ? 'selected' : ''; ?>>Dipesan</option>
                    <option value="sold" <?php echo $status == 'sold' ? 'selected' : ''; ?>>Terjual</option>
                </select>

                <select name="brand_id" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Merek</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $b['id'] == $brand_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="car_type_id" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Jenis Bodi</option>
                    <?php foreach ($carTypes as $type): ?>
                        <option value="<?php echo $type['id']; ?>" <?php echo $type['id'] == $car_type_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($type['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-search">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">
            <i class="fas fa-warehouse text-primary"></i> Daftar Unit Inventori
        </h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                <?php echo number_format($totalRecords); ?> Unit
            </span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&status=<?php echo urlencode($status); ?>&brand_id=<?php echo urlencode($brand_id); ?>&car_type_id=<?php echo urlencode($car_type_id); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (count($vehicles) > 0): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th style="min-width: 160px;">MEREK & MODEL</th>
                        <th style="min-width: 140px;">NO. PLAT & RANGKA</th>
                        <th style="min-width: 90px;">WARNA</th>
                        <th style="min-width: 100px;">ODOMETER</th>
                        <th style="min-width: 130px;">HARGA JUAL</th>
                        <th style="min-width: 110px;" class="text-center">STATUS</th>
                        <th style="width: 70px;" class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = $offset + 1;
                    foreach ($vehicles as $v): 
                        $stClass = 'status-available';
                        switch($v['status']) {
                            case 'sold': $stClass = 'status-sold'; break;
                            case 'reserved':
                            case 'maintenance': $stClass = 'status-reserved'; break;
                        }
                    ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($v['type_name'] ?? ''); ?> • Tahun <?php echo $v['year']; ?></div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($v['license_plate'] ?: 'No Plat'); ?></span>
                            </div>
                            <div class="text-muted small text-nowrap mt-1" style="font-size:0.75rem;">NR: <?php echo htmlspecialchars($v['chassis_number'] ?: '-'); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($v['color'] ?: '-'); ?></td>
                        <td class="text-muted"><?php echo number_format($v['mileage'] ?? 0); ?> km</td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($v['selling_price']); ?></div>
                        </td>
                        <td class="text-center">
                            <span class="status-badge <?php echo $stClass; ?>">
                                <?php echo $statusLabels[$v['status']] ?? $v['status']; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="action-btns">
                                <a href="../vehicles/view.php?id=<?php echo $v['id']; ?>" class="action-btn" title="Lihat Detail Mobil">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="p-3 border-top d-flex justify-content-center">
            <nav aria-label="Pagination">
                <ul class="pagination pagination-sm mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?status=<?php echo urlencode($status); ?>&brand_id=<?php echo urlencode($brand_id); ?>&car_type_id=<?php echo urlencode($car_type_id); ?>&page=<?php echo max(1, $page - 1); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?status=<?php echo urlencode($status); ?>&brand_id=<?php echo urlencode($brand_id); ?>&car_type_id=<?php echo urlencode($car_type_id); ?>&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?status=<?php echo urlencode($status); ?>&brand_id=<?php echo urlencode($brand_id); ?>&car_type_id=<?php echo urlencode($car_type_id); ?>&page=<?php echo min($totalPages, $page + 1); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-warehouse fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada unit inventori yang cocok dengan kriteria filter.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print Only Section -->
<div class="print-only">
    <div class="print-header" style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 5px 0; font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($printCompanyName); ?></h3>
        <h3 style="margin: 8px 0; font-size: 15px; font-weight: bold;">Laporan Inventori Stok Mobil</h3>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <thead>
            <tr style="background-color: #f5f5f5; border-bottom: 2px solid #333;">
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">No</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Merek & Model</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Plat Nomor</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Warna</th>
                <th style="padding: 6px; text-align: right; border: 1px solid #ddd; font-size: 11px;">Harga Jual</th>
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $pNo = 1;
            foreach ($vehicles as $v): 
            ?>
            <tr>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo $pNo++; ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ')'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($v['license_plate'] ?: '-'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($v['color'] ?: '-'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($v['selling_price'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo $statusLabels[$v['status']] ?? $v['status']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
