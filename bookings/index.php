<?php
// bookings/index.php - Daftar SPK (Surat Pemesanan Kendaraan) & Booking Fee
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

// Auto check for expired bookings whose deadline has passed and are still 'active'
try {
    $expStmt = $db->query("
        SELECT b.id, b.vehicle_id 
        FROM vehicle_bookings b 
        WHERE b.status = 'active' AND b.expiry_date < CURDATE()
    ");
    $expiredList = $expStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($expiredList)) {
        $updateBooking = $db->prepare("UPDATE vehicle_bookings SET status = 'expired' WHERE id = ?");
        foreach ($expiredList as $exp) {
            $updateBooking->execute([$exp['id']]);
        }
    }
} catch (Exception $e) {}

// Filters & Pagination
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$offset = ($page - 1) * $limit;

// Handle Release Unit / Cancel Booking Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid.', 'danger');
    } else {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        
        if ($_POST['action'] === 'release_unit') {
            try {
                $bStmt = $db->prepare("SELECT vehicle_id, status FROM vehicle_bookings WHERE id = ?");
                $bStmt->execute([$bookingId]);
                $bData = $bStmt->fetch();

                if ($bData) {
                    $upB = $db->prepare("UPDATE vehicle_bookings SET status = 'cancelled' WHERE id = ?");
                    $upB->execute([$bookingId]);

                    $upV = $db->prepare("UPDATE vehicles SET status = 'available' WHERE id = ? AND status = 'reserved'");
                    $upV->execute([$bData['vehicle_id']]);

                    showAlert('Unit berhasil dirilis kembali ke status Siap Jual dan status SPK dibatalkan.', 'success');
                    redirect('bookings/');
                }
            } catch (PDOException $e) {
                showAlert('Gagal merilis unit: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

try {
    // 1. KPI Statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_spk,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
            SUM(CASE WHEN status = 'converted' THEN 1 ELSE 0 END) as converted_count,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_count,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            COALESCE(SUM(CASE WHEN status = 'active' THEN booking_fee ELSE 0 END), 0) as active_booking_fees,
            COALESCE(SUM(CASE WHEN status = 'converted' THEN booking_fee ELSE 0 END), 0) as total_collected_fees
        FROM vehicle_bookings
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_spk' => 0,
        'active_count' => 0,
        'converted_count' => 0,
        'expired_count' => 0,
        'cancelled_count' => 0,
        'active_booking_fees' => 0,
        'total_collected_fees' => 0
    ];

    // 2. Query Data with Filters
    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(b.booking_code LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR v.license_plate LIKE ? OR m.name LIKE ? OR br.name LIKE ?)";
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s, $s, $s, $s]);
    }

    if (!empty($status_filter)) {
        $where[] = "b.status = ?";
        $params[] = $status_filter;
    }

    $whereSql = implode(' AND ', $where);

    // Count Total
    $countStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM vehicle_bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands br ON m.brand_id = br.id
        JOIN customers c ON b.customer_id = c.id
        WHERE $whereSql
    ");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);

    // Fetch Records
    $sql = "
        SELECT 
            b.*,
            v.license_plate, v.year as vehicle_year, v.color as vehicle_color, v.status as vehicle_status,
            CONCAT(br.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name,
            c.name as customer_name, c.phone as customer_phone,
            u.full_name as staff_name,
            DATEDIFF(b.expiry_date, CURDATE()) as days_remaining
        FROM vehicle_bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands br ON m.brand_id = br.id
        JOIN customers c ON b.customer_id = c.id
        JOIN users u ON b.user_id = u.id
        WHERE $whereSql
        ORDER BY b.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Terjadi kesalahan database: " . $e->getMessage();
}
?>

<style>
    /* Consistent Header */
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
    @media (max-width: 768px) {
        .search-row { flex-wrap: wrap; }
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
        width: 170px;
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
        background: #f1f5f9;
        color: #475569;
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
    .status-completed, .status-converted { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-active { background: #fef3c7; color: #92400e; }
    .status-expired { background: #fee2e2; color: #991b1b; }
    .status-cancelled { background: #f1f5f9; color: #64748b; }

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
    .action-btn-print:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
    .action-btn-release:hover { background: #fee2e2; border-color: #fecaca; color: #ef4444; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .search-input-group input:focus { background: #162032; border-color: #475569; }
    [data-theme="dark"] .btn-search,
    [data-theme="dark"] .btn-reset-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .record-count { background: #0f172a; color: #cbd5e1; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
</style>

<div class="vehicles-page-header">
    <div>
        <h1>Manajemen SPK & Tanda Jadi</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">SPK & Booking Fee</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i>
            <span>Buat SPK Baru</span>
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger mb-3 border-0 shadow-sm" style="border-radius: 10px;">
    <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
</div>
<?php endif; ?>

<!-- Summary Grid (4 Cards) -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-file-signature"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['total_spk']); ?></div>
            <div class="summary-label">Total SPK Diterbitkan</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['active_count']); ?></div>
            <div class="summary-label">SPK Aktif (Unit Hold)</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-coins"></i>
        </div>
        <div>
            <div class="summary-value">Rp <?php echo number_format($stats['active_booking_fees'], 0, ',', '.'); ?></div>
            <div class="summary-label">Booking Fee Tertampung</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-handshake"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['converted_count']); ?></div>
            <div class="summary-label">Realisasi Terjual (Deal)</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="search-card">
    <form method="GET" action="" class="search-row">
        <div class="search-input-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Cari nomor SPK, nama pemesan, nomor WA, plat nomor..." value="<?php echo htmlspecialchars($search); ?>">
        </div>

        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>Semua Status SPK</option>
            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Aktif (Unit Hold)</option>
            <option value="converted" <?php echo $status_filter === 'converted' ? 'selected' : ''; ?>>Terkonversi (Terjual)</option>
            <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired (Kedaluwarsa)</option>
            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
        </select>

        <button type="submit" class="btn-search">
            <i class="fas fa-filter"></i> Filter
        </button>

        <?php if (!empty($search) || !empty($status_filter)): ?>
            <a href="index.php" class="btn-reset-search" title="Reset Filter">
                <i class="fas fa-redo"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h3 class="table-card-title">Daftar Surat Pemesanan Kendaraan</h3>
        <span class="record-count"><?php echo number_format($totalRecords); ?> Data</span>
    </div>

    <div class="table-responsive">
        <table class="vehicles-table">
            <thead>
                <tr>
                    <th>NO. SPK & TANGGAL</th>
                    <th>KENDARAAN</th>
                    <th>PEMESAN / PELANGGAN</th>
                    <th class="text-end">TANDA JADI (FEE)</th>
                    <th class="text-end">HARGA SEPAKAT</th>
                    <th>MASA BERLAKU</th>
                    <th class="text-center">STATUS</th>
                    <th class="text-end">AKSI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="fas fa-file-circle-xmark fa-2x mb-2 text-secondary opacity-50 d-block"></i>
                            Tidak ada data SPK yang ditemukan.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                        <?php
                        $phoneClean = preg_replace('/[^0-9]/', '', $b['customer_phone']);
                        if (strpos($phoneClean, '0') === 0) {
                            $phoneClean = '62' . substr($phoneClean, 1);
                        }

                        // Badge status class
                        $badgeClass = 'status-cancelled';
                        $statusLabel = 'Dibatalkan';
                        if ($b['status'] === 'active') {
                            $badgeClass = 'status-in_progress';
                            $statusLabel = 'Aktif (Hold)';
                        } elseif ($b['status'] === 'converted') {
                            $badgeClass = 'status-completed';
                            $statusLabel = 'Terjual (Deal)';
                        } elseif ($b['status'] === 'expired') {
                            $badgeClass = 'status-expired';
                            $statusLabel = 'Expired';
                        }
                        ?>
                        <tr>
                            <td>
                                <a href="view.php?id=<?php echo $b['id']; ?>" class="fw-bold text-decoration-none text-dark">
                                    <?php echo htmlspecialchars($b['booking_code']); ?>
                                </a>
                                <div class="text-muted small" style="font-size: 0.72rem;">
                                    <?php echo date('d/m/Y', strtotime($b['booking_date'])); ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($b['vehicle_name']); ?></div>
                                <div class="d-flex align-items-center gap-1 mt-0.5">
                                    <span class="plate-badge"><?php echo htmlspecialchars($b['license_plate']); ?></span>
                                    <small class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($b['vehicle_color']); ?></small>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($b['customer_name']); ?></div>
                                <div class="small mt-0.5">
                                    <a href="https://wa.me/<?php echo $phoneClean; ?>" target="_blank" class="text-decoration-none text-muted d-inline-flex align-items-center gap-1">
                                        <i class="fab fa-whatsapp text-success"></i>
                                        <span><?php echo htmlspecialchars($b['customer_phone']); ?></span>
                                    </a>
                                </div>
                            </td>
                            <td class="text-end">
                                <div class="price-text text-success">Rp <?php echo number_format($b['booking_fee'], 0, ',', '.'); ?></div>
                                <small class="text-muted" style="font-size: 0.72rem;"><?php echo htmlspecialchars($b['payment_method'] === 'bank_transfer' ? 'Transfer' : ($b['payment_method'] === 'cash' ? 'Cash' : 'EDC')); ?></small>
                            </td>
                            <td class="text-end">
                                <div class="price-text">Rp <?php echo number_format($b['agreed_price'], 0, ',', '.'); ?></div>
                                <small class="text-muted" style="font-size: 0.72rem;">Sisa: Rp <?php echo number_format($b['agreed_price'] - $b['booking_fee'], 0, ',', '.'); ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold" style="font-size: 0.8rem;"><?php echo date('d/m/Y', strtotime($b['expiry_date'])); ?></div>
                                <?php if ($b['status'] === 'active'): ?>
                                    <?php if ($b['days_remaining'] > 0): ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning px-1.5 py-0.5" style="font-size: 0.7rem;">Sisa <?php echo $b['days_remaining']; ?> hari</span>
                                    <?php elseif ($b['days_remaining'] == 0): ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger px-1.5 py-0.5" style="font-size: 0.7rem;">Hari Terakhir!</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger px-1.5 py-0.5" style="font-size: 0.7rem;">Lewat Waktu</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="status-badge <?php echo $badgeClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <div class="action-btns">
                                    <a href="view.php?id=<?php echo $b['id']; ?>" class="action-btn action-btn-view" title="Lihat Detail SPK">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="print.php?id=<?php echo $b['id']; ?>" target="_blank" class="action-btn action-btn-print" title="Cetak Surat SPK (PDF/A4)">
                                        <i class="fas fa-print"></i>
                                    </a>
                                    <?php if ($b['status'] === 'active' || $b['status'] === 'expired'): ?>
                                        <button type="button" class="action-btn action-btn-release" title="Rilis Unit Kembali ke Siap Jual" onclick="openReleaseModal(<?php echo $b['id']; ?>, '<?php echo htmlspecialchars(addslashes($b['booking_code'])); ?>', '<?php echo htmlspecialchars(addslashes($b['vehicle_name'])); ?>')">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="d-flex justify-content-between align-items-center p-3 border-top" style="font-size: 0.8rem;">
        <span class="text-muted">Halaman <?php echo $page; ?> dari <?php echo $totalPages; ?></span>
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Sebelumnya</a>
            </li>
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>">Selanjutnya</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Release Unit -->
<div class="modal fade" id="releaseUnitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="release_unit">
                <input type="hidden" name="booking_id" id="releaseBookingId" value="">

                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold text-dark fs-6">Rilis Unit ke Siap Jual</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-2">Anda akan membatalkan reservasi unit untuk:</p>
                    <div class="p-3 bg-light rounded-3 mb-3 border">
                        <div class="fw-bold text-dark" id="modalBookingCode">-</div>
                        <div class="text-muted small" id="modalVehicleName">-</div>
                    </div>
                    <div class="alert alert-warning border-0 small py-2 px-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-1"></i> Unit mobil akan otomatis kembali berstatus <strong>available (Siap Jual)</strong> dan status SPK ini akan diubah menjadi <strong>cancelled</strong>.
                    </div>
                </div>
                <div class="modal-footer border-top bg-light py-2 px-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Tutup</button>
                    <button type="submit" class="btn btn-sm btn-danger px-4 fw-semibold">Ya, Rilis Unit Sekarang</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openReleaseModal(id, code, vehicle) {
    document.getElementById('releaseBookingId').value = id;
    document.getElementById('modalBookingCode').textContent = code;
    document.getElementById('modalVehicleName').textContent = vehicle;
    const myModal = new bootstrap.Modal(document.getElementById('releaseUnitModal'));
    myModal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
