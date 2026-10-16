<?php
// reports/vehicle_purchases.php - Laporan Pembelian Mobil Showroom
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
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$brand_id = isset($_GET['brand_id']) ? $_GET['brand_id'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : (defined('RECORDS_PER_PAGE') ? RECORDS_PER_PAGE : 10);
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Get all brands
$stmt = $conn->query("SELECT id, name FROM brands ORDER BY name ASC");
$brands = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build query
$whereClauses = ["v.purchase_date BETWEEN ? AND ?"];
$params = [$start_date, $end_date];

if (!empty($brand_id)) {
    $whereClauses[] = "b.id = ?";
    $params[] = $brand_id;
}

if (!empty($status)) {
    $whereClauses[] = "v.status = ?";
    $params[] = $status;
}

$whereSql = implode(' AND ', $whereClauses);

// Sorting parameters
$sort_column = isset($_GET['sort']) ? $_GET['sort'] : 'purchase_date';
$sort_order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';

$allowed_sort_columns = [
    'purchase_date' => 'v.purchase_date',
    'brand_name' => 'b.name',
    'year' => 'v.year',
    'purchase_price' => 'v.purchase_price',
    'selling_price' => 'v.selling_price',
    'status' => 'v.status'
];

$db_sort_column = $allowed_sort_columns[$sort_column] ?? 'v.purchase_date';

// Count total records and summary
$countSql = "SELECT 
                COUNT(*) as total,
                COALESCE(SUM(v.purchase_price), 0) as total_purchase_cost,
                COALESCE(SUM(v.selling_price - v.purchase_price), 0) as total_potential_profit
             FROM vehicles v
             LEFT JOIN models m ON v.model_id = m.id
             LEFT JOIN brands b ON m.brand_id = b.id
             LEFT JOIN car_types ct ON m.car_type_id = ct.id
             WHERE $whereSql";

$countStmt = $conn->prepare($countSql);
$countStmt->execute($params);
$summaryData = $countStmt->fetch();
$totalRecords = (int)($summaryData['total'] ?? 0);
$totalPurchaseCost = (float)($summaryData['total_purchase_cost'] ?? 0);
$totalPotentialProfit = (float)($summaryData['total_potential_profit'] ?? 0);
$totalPages = ceil($totalRecords / $limit);

// Fetch paginated vehicles
$query = "SELECT 
            v.id,
            v.chassis_number,
            v.engine_number,
            v.license_plate,
            v.year,
            v.color,
            v.mileage,
            v.purchase_date,
            v.purchase_price,
            v.selling_price,
            v.status,
            v.notes,
            m.name as model_name,
            b.name as brand_name,
            ct.name as car_type_name
          FROM vehicles v
          LEFT JOIN models m ON v.model_id = m.id
          LEFT JOIN brands b ON m.brand_id = b.id
          LEFT JOIN car_types ct ON m.car_type_id = ct.id
          WHERE $whereSql
          ORDER BY $db_sort_column $sort_order, v.created_at DESC
          LIMIT $limit OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status labels
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
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 768px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
    .summary-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 16px 18px;
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

    /* ===== Search & Filter Card ===== */
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
        min-width: 140px;
        padding: 9px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s;
    }
    .date-filter-input {
        width: 135px;
        padding: 8px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.82rem;
        color: #334155;
        background: #f8fafc;
    }
    .filter-select:focus, .date-filter-input:focus {
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
        color: #1e293b;
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
    [data-theme="dark"] .date-filter-input,
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
        <h1>Laporan Pembelian Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>reports/" class="text-primary text-decoration-underline">Laporan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Pembelian Mobil</li>
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
            <i class="fas fa-car"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($totalRecords); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Unit</span></div>
            <div class="summary-label">Total Unit Mobil Dibeli</div>
            <small class="text-muted" style="font-size: 0.72rem;">Periode terpilih</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($totalPurchaseCost); ?></div>
            <div class="summary-label">Total Biaya Pembelian</div>
            <small class="text-muted" style="font-size: 0.72rem;">Modal pengadaan unit</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($totalPotentialProfit); ?></div>
            <div class="summary-label">Estimasi Potensi Margin</div>
            <small class="text-success fw-bold" style="font-size: 0.72rem;">Selisih Harga Jual - Beli</small>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" class="date-filter-input" title="Tanggal Mulai" required>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" class="date-filter-input" title="Tanggal Selesai" required>

                <select name="brand_id" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Merek</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?php echo $b['id']; ?>" <?php echo $b['id'] == $brand_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($b['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status Unit</option>
                    <option value="available" <?php echo $status == 'available' ? 'selected' : ''; ?>>Tersedia</option>
                    <option value="sold" <?php echo $status == 'sold' ? 'selected' : ''; ?>>Terjual</option>
                    <option value="reserved" <?php echo $status == 'reserved' ? 'selected' : ''; ?>>Dipesan</option>
                    <option value="maintenance" <?php echo $status == 'maintenance' ? 'selected' : ''; ?>>Perbaikan</option>
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
            Daftar Pembelian Mobil
            <small class="text-muted ms-2" style="font-size:0.8rem;font-weight:400;">(<?php echo date('d M Y', strtotime($start_date)); ?> - <?php echo date('d M Y', strtotime($end_date)); ?>)</small>
        </h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                <?php echo number_format($totalRecords); ?> Unit
            </span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&brand_id=<?php echo urlencode($brand_id); ?>&status=<?php echo urlencode($status); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
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
                        <th style="min-width: 120px;">TGL BELI</th>
                        <th style="min-width: 160px;">UNIT MOBIL</th>
                        <th style="min-width: 150px;">NO. RANGKA & PLAT</th>
                        <th style="min-width: 100px;">WARNA</th>
                        <th style="min-width: 130px;">HARGA BELI</th>
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
                            <div class="fw-semibold text-dark text-nowrap"><?php echo date('d M Y', strtotime($v['purchase_date'])); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($v['car_type_name'] ?? ''); ?> • Tahun <?php echo $v['year']; ?></div>
                        </td>
                        <td>
                            <div class="d-flex align-items-center gap-1.5 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($v['license_plate'] ?: 'No Plat'); ?></span>
                            </div>
                            <div class="text-muted small text-nowrap mt-1" style="font-size:0.75rem;">NR: <?php echo htmlspecialchars($v['chassis_number'] ?: '-'); ?></div>
                        </td>
                        <td><?php echo htmlspecialchars($v['color'] ?: '-'); ?></td>
                        <td>
                            <div class="price-text text-danger"><?php echo formatCurrency($v['purchase_price']); ?></div>
                        </td>
                        <td>
                            <?php if ($v['selling_price'] > 0): ?>
                                <div class="price-text text-success"><?php echo formatCurrency($v['selling_price']); ?></div>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
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
                        <a class="page-link" href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&brand_id=<?php echo urlencode($brand_id); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo max(1, $page - 1); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&brand_id=<?php echo urlencode($brand_id); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&brand_id=<?php echo urlencode($brand_id); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo min($totalPages, $page + 1); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-truck-loading fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada data pembelian mobil untuk periode yang dipilih.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print Only Section -->
<div class="print-only">
    <div class="print-header" style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 5px 0; font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($printCompanyName); ?></h3>
        <h3 style="margin: 8px 0; font-size: 15px; font-weight: bold;">Laporan Pembelian Mobil</h3>
        <p style="margin: 4px 0; font-size: 13px;">Periode <?php echo date('d/m/Y', strtotime($start_date)); ?> - <?php echo date('d/m/Y', strtotime($end_date)); ?></p>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <thead>
            <tr style="background-color: #f5f5f5; border-bottom: 2px solid #333;">
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">No</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Tgl Beli</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Unit Mobil</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">No Plat</th>
                <th style="padding: 6px; text-align: right; border: 1px solid #ddd; font-size: 11px;">Harga Beli</th>
                <th style="padding: 6px; text-align: right; border: 1px solid #ddd; font-size: 11px;">Harga Jual</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $pNo = 1;
            foreach ($vehicles as $v): 
            ?>
            <tr>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo $pNo++; ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo date('d/m/Y', strtotime($v['purchase_date'])); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ')'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($v['license_plate'] ?: '-'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($v['purchase_price'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($v['selling_price'], 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f5f5f5; font-weight: bold;">
                <td colspan="4" style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Total Biaya Pembelian:</td>
                <td colspan="2" style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($totalPurchaseCost, 0, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
