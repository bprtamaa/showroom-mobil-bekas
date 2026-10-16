<?php
// trade_in/index.php - Transaksi Tukar Tambah (Trade-In)
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

$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$whereClauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(tt.trade_in_code LIKE ? OR c.name LIKE ? OR tt.trade_in_license_plate LIKE ? OR tt.trade_in_model_name LIKE ? OR vm.name LIKE ?)";
    $p = "%$search%";
    $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p; $params[] = $p;
}

if (!empty($status_filter)) {
    $whereClauses[] = "tt.status = ?";
    $params[] = $status_filter;
}

$whereSql = "WHERE " . implode(" AND ", $whereClauses);

try {
    // KPI Stats
    $kpiQuery = $db->query("
        SELECT 
            COUNT(*) as total_trade_ins,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as total_completed,
            SUM(appraised_value) as total_appraised_value,
            SUM(price_difference) as total_difference_value
        FROM trade_in_transactions
    ");
    $kpi = $kpiQuery->fetch(PDO::FETCH_ASSOC);

    // Trade In List
    $stmt = $db->prepare("
        SELECT 
            tt.*,
            c.name as customer_name, c.phone as customer_phone,
            v.license_plate as desired_license_plate, v.year as desired_year,
            vb.name as desired_brand_name, vm.name as desired_model_name
        FROM trade_in_transactions tt
        JOIN customers c ON tt.customer_id = c.id
        JOIN vehicles v ON tt.desired_vehicle_id = v.id
        JOIN models vm ON v.model_id = vm.id
        JOIN brands vb ON vm.brand_id = vb.id
        $whereSql
        ORDER BY tt.created_at DESC
    ");
    $stmt->execute($params);
    $tradeIns = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    .record-count {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
    }

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
        white-space: nowrap;
    }
    .status-completed { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-appraisal { background: #fef3c7; color: #92400e; }
    .status-scheduled, .status-approved { background: #e0f2fe; color: #075985; }
    .status-cancelled { background: #fee2e2; color: #991b1b; }

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

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { background: #162032; border-color: #475569; }
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
        <h1>Transaksi Tukar Tambah (Trade-In)</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/" class="text-primary text-decoration-underline">Stok Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tukar Tambah</li>
            </ol>
        </nav>
    </div>
    <div>
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i> Tambah Tukar Tambah
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
            <i class="fas fa-exchange-alt"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['total_trade_ins'] ?? 0); ?></div>
            <div class="summary-label">Total Pengajuan</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['total_completed'] ?? 0); ?></div>
            <div class="summary-label">Selesai & Masuk Stok</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-car"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['total_appraised_value'] ?? 0); ?></div>
            <div class="summary-label">Akumulasi Taksiran</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-wallet"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['total_difference_value'] ?? 0); ?></div>
            <div class="summary-label">Total Selisih Diterima</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="search-card">
    <form method="GET" action="">
        <div class="search-row">
            <div class="search-input-group">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari kode TT, nama konsumen, atau plat mobil lama...">
            </div>
            <select name="status" class="filter-select" onchange="this.form.submit()">
                <option value="">Semua Status</option>
                <option value="appraisal" <?php echo $status_filter === 'appraisal' ? 'selected' : ''; ?>>Tahap Taksiran / Appraisal</option>
                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Disetujui Konsumen</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Selesai & Masuk Stok</option>
                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
            </select>
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Cari
            </button>
            <?php if (!empty($search) || !empty($status_filter)): ?>
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
        <h5 class="table-card-title">Daftar Transaksi Tukar Tambah</h5>
        <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
            <?php echo count($tradeIns); ?> Data
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($tradeIns)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th>KODE & KONSUMEN</th>
                        <th>MOBIL LAMA KONSUMEN (APPRAISAL)</th>
                        <th>MOBIL SHOWROOM YANG DIBELI</th>
                        <th>SELISIH BAYAR</th>
                        <th>STATUS</th>
                        <th class="text-end" style="width: 70px;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($tradeIns as $tt): 
                        $statusKey = $tt['status'] ?? 'appraisal';
                        $statusClass = 'status-appraisal';
                        $statusText = 'Taksiran';
                        switch($statusKey) {
                            case 'appraisal': $statusClass = 'status-in_progress'; $statusText = 'Taksiran / Appraisal'; break;
                            case 'approved': $statusClass = 'status-scheduled'; $statusText = 'Disetujui'; break;
                            case 'completed': $statusClass = 'status-completed'; $statusText = 'Selesai & Masuk Stok'; break;
                            case 'cancelled': $statusClass = 'status-cancelled'; $statusText = 'Dibatalkan'; break;
                        }
                    ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tt['trade_in_code']); ?></div>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($tt['customer_name']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($tt['customer_phone'] ?: '-'); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tt['trade_in_brand_name'] . ' ' . $tt['trade_in_model_name'] . ' (' . $tt['trade_in_year'] . ')'); ?></div>
                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($tt['trade_in_license_plate']); ?></span>
                                <span class="text-muted small text-nowrap">• Taksiran: <strong class="text-success"><?php echo formatCurrency($tt['appraised_value']); ?></strong></span>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tt['desired_brand_name'] . ' ' . $tt['desired_model_name']); ?></div>
                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($tt['desired_license_plate']); ?></span>
                                <span class="text-muted small text-nowrap">• <?php echo formatCurrency($tt['desired_vehicle_price']); ?></span>
                            </div>
                        </td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($tt['price_difference']); ?></div>
                            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Via <?php echo htmlspecialchars($tt['payment_method']); ?></small>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $statusClass; ?>">
                                <?php echo $statusText; ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $tt['id']; ?>" class="action-btn action-btn-view" title="Lihat Detail & Proses">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-exchange-alt fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Belum Ada Transaksi Tukar Tambah</div>
            <small class="text-muted">Mulai transaksi tukar tambah baru untuk mentaksir mobil konsumen dan otomatis memasukkannya ke stok showroom.</small>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
