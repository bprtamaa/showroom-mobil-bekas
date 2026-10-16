<?php
// transactions/index.php - Daftar Transaksi Penjualan Showroom
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
$date_from = trim($_GET['date_from'] ?? '');
$date_to = trim($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 't.transaction_date';
$sort_order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

$db_sort_column = $sort_column;
$db_sort_order = $sort_order;

try {
    $whereClauses = [];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(t.transaction_code LIKE ? OR c.name LIKE ? OR v.chassis_number LIKE ? OR v.license_plate LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($status_filter)) {
        if ($status_filter === 'installment') {
            $whereClauses[] = "(t.payment_status = 'installment' OR ((t.payment_status = 'pending' OR t.payment_status = '' OR t.payment_status IS NULL) AND t.payment_method = 'installment'))";
        } else {
            if ($status_filter === 'pending') {
                $whereClauses[] = "((t.payment_status = 'pending' OR t.payment_status = '' OR t.payment_status IS NULL) AND (t.payment_method IS NULL OR t.payment_method != 'installment'))";
            } else {
                $whereClauses[] = "t.payment_status = ?";
                $params[] = $status_filter;
            }
        }
    }
    
    if (!empty($date_from)) {
        $whereClauses[] = "t.transaction_date >= ?";
        $params[] = $date_from;
    }
    
    if (!empty($date_to)) {
        $whereClauses[] = "t.transaction_date <= ?";
        $params[] = $date_to;
    }
    
    $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // KPI Stats
    $kpiStmt = $db->query("
        SELECT 
            COUNT(DISTINCT t.id) as total_tx_count,
            COALESCE(SUM(t.total_amount), 0) as total_revenue,
            SUM(CASE WHEN t.payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN t.payment_status = 'installment' OR t.payment_method = 'installment' THEN 1 ELSE 0 END) as installment_count
        FROM transactions t
    ");
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    // Get total records
    $countSql = "
        SELECT COUNT(DISTINCT t.id) as total 
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN vehicles v ON t.vehicle_id = v.id
        $whereClause
    ";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Get transactions
    $sql = "
        SELECT t.*,
               c.name as customer_name,
               c.phone as customer_phone,
               v.license_plate,
               v.year as vehicle_year,
               b.name as brand_name,
               m.name as model_name,
               u.full_name as user_name
        FROM transactions t
        LEFT JOIN customers c ON t.customer_id = c.id
        LEFT JOIN vehicles v ON t.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN users u ON t.user_id = u.id
        $whereClause
        GROUP BY t.id
        ORDER BY $db_sort_column $db_sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<style>
    /* Page Header */
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
    .header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .btn-header-action {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 16px;
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
    .btn-header-action:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .btn-add-vehicle {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 18px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.83rem;
        border-radius: 10px;
        border: none;
        text-decoration: none;
        transition: all 0.15s ease;
        box-shadow: 0 2px 6px rgba(45, 52, 54, 0.2);
    }
    .btn-add-vehicle:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(45, 52, 54, 0.3);
    }

    /* Summary Grid */
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

    /* Search Card */
    .search-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        padding: 14px 18px;
        margin-bottom: 20px;
    }
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
        width: 140px;
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

    /* Vehicles Table */
    .vehicles-table {
        width: 100%;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 10px 14px;
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
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tbody tr:last-child td {
        border-bottom: none;
    }

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
    .status-completed, .status-paid { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-installment { background: #fef3c7; color: #92400e; }
    .status-scheduled { background: #e0f2fe; color: #075985; }
    .status-cancelled, .status-pending { background: #fee2e2; color: #991b1b; }

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
        white-space: nowrap;
    }

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
    .action-btn-print:hover { background: #f8fafc; border-color: #cbd5e1; color: #334155; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select,
    [data-theme="dark"] .date-filter-input,
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .search-input-group input:focus { background: #162032; border-color: #475569; }
    [data-theme="dark"] .btn-search,
    [data-theme="dark"] .btn-reset-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Transaksi Penjualan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Transaksi Penjualan</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="billing.php" class="btn-header-action">
            <i class="fas fa-file-invoice-dollar"></i> Tagihan Cicilan
        </a>
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i> Transaksi Baru
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['total_tx_count'] ?? 0); ?></div>
            <div class="summary-label">Total Transaksi</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['paid_count'] ?? 0); ?></div>
            <div class="summary-label">Transaksi Lunas</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['installment_count'] ?? 0); ?></div>
            <div class="summary-label">Transaksi Kredit/Cicilan</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-wallet"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['total_revenue'] ?? 0); ?></div>
            <div class="summary-label">Akumulasi Penjualan</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="search-card">
    <form method="GET" action="">
        <div class="search-row">
            <div class="search-input-group">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari kode invoice, nama konsumen, plat nomor, atau no rangka...">
            </div>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                <option value="installment" <?php echo $status_filter === 'installment' ? 'selected' : ''; ?>>Cicilan / Kredit</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
            </select>
            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="date-filter-input" title="Dari Tanggal">
            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="date-filter-input" title="Sampai Tanggal">
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Cari
            </button>
            <?php if (!empty($search) || !empty($status_filter) || !empty($date_from) || !empty($date_to)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Transaksi Penjualan</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                <?php echo number_format($totalRecords); ?> Transaksi
            </span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($transactions)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th>KODE & TANGGAL</th>
                        <th>PELANGGAN</th>
                        <th>UNIT MOBIL</th>
                        <th>TOTAL TRANSAKSI</th>
                        <th>STATUS</th>
                        <th class="text-end" style="width: 80px;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($transactions as $index => $tx): 
                        $status = $tx['payment_status'] ?? 'pending';
                        if (empty($status)) $status = 'pending';
                        if (($status === 'pending' || empty($status)) && ($tx['payment_method'] ?? '') === 'installment') {
                            $status = 'installment';
                        }

                        $statusBadgeClass = 'status-scheduled';
                        $statusLabelText = 'Cicilan';
                        switch($status) {
                            case 'paid': $statusBadgeClass = 'status-completed'; $statusLabelText = 'Lunas'; break;
                            case 'installment':
                            case 'partial': $statusBadgeClass = 'status-installment'; $statusLabelText = 'Cicilan'; break;
                            case 'pending': $statusBadgeClass = 'status-cancelled'; $statusLabelText = 'Pending'; break;
                        }
                    ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tx['transaction_code']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo formatDate($tx['transaction_date'], 'd M Y'); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($tx['customer_name']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($tx['customer_phone'] ?: '-'); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tx['brand_name'] . ' ' . $tx['model_name']); ?></div>
                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($tx['license_plate'] ?: 'No Plat'); ?></span>
                                <?php if (!empty($tx['vehicle_year'])): ?>
                                <span class="text-muted small text-nowrap">• <?php echo $tx['vehicle_year']; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($tx['total_amount']); ?></div>
                            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Via <?php echo htmlspecialchars($tx['payment_method'] ?: 'cash'); ?></small>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $statusBadgeClass; ?>">
                                <?php echo $statusLabelText; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $tx['id']; ?>" class="action-btn action-btn-view" title="Lihat Detail">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="print.php?id=<?php echo $tx['id']; ?>" target="_blank" class="action-btn action-btn-print" title="Cetak Invoice">
                                    <i class="fas fa-print"></i>
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
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-receipt fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada transaksi penjualan ditemukan.</div>
            <small class="text-muted">Klik tombol Transaksi Baru untuk mencatat penjualan mobil baru.</small>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>