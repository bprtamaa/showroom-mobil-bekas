<?php
// customer_requests/index.php - Wishlist & Request Unit Pelanggan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

// Handle status updates & delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid.', 'danger');
    } else {
        $reqId = (int)($_POST['request_id'] ?? 0);
        
        if ($_POST['action'] === 'update_status') {
            $newStatus = in_array($_POST['status'] ?? '', ['active', 'fulfilled', 'cancelled']) ? $_POST['status'] : 'active';
            $matchedVehId = !empty($_POST['matched_vehicle_id']) ? (int)$_POST['matched_vehicle_id'] : null;
            try {
                $stmt = $db->prepare("UPDATE customer_requests SET status = ?, matched_vehicle_id = ? WHERE id = ?");
                $stmt->execute([$newStatus, $matchedVehId, $reqId]);
                showAlert('Status permintaan unit berhasil diperbarui.', 'success');
                redirect('customer_requests/');
            } catch (PDOException $e) {
                showAlert('Gagal memperbarui status: ' . $e->getMessage(), 'danger');
            }
        } elseif ($_POST['action'] === 'delete') {
            try {
                $stmt = $db->prepare("DELETE FROM customer_requests WHERE id = ?");
                $stmt->execute([$reqId]);
                showAlert('Permintaan unit berhasil dihapus.', 'success');
                redirect('customer_requests/');
            } catch (PDOException $e) {
                showAlert('Gagal menghapus permintaan: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$brand_filter = (int)($_GET['brand_id'] ?? 0);

// KPI Statistics
try {
    $statTotal = (int)$db->query("SELECT COUNT(*) FROM customer_requests")->fetchColumn();
    $statActive = (int)$db->query("SELECT COUNT(*) FROM customer_requests WHERE status = 'active'")->fetchColumn();
    $statFulfilled = (int)$db->query("SELECT COUNT(*) FROM customer_requests WHERE status = 'fulfilled'")->fetchColumn();
    $statCancelled = (int)$db->query("SELECT COUNT(*) FROM customer_requests WHERE status = 'cancelled'")->fetchColumn();
} catch (Exception $e) {
    $statTotal = $statActive = $statFulfilled = $statCancelled = 0;
}

// Fetch active available vehicles for live matching engine
$availableVehicles = [];
try {
    $vStmt = $db->query("
        SELECT v.id, v.model_id, v.year, v.color, v.mileage, v.transmission, v.fuel_type, v.selling_price, v.license_plate,
               m.brand_id, m.name AS model_name, b.name AS brand_name, ct.name AS car_type_name
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        WHERE v.status = 'available'
        ORDER BY v.created_at DESC
    ");
    $availableVehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $availableVehicles = [];
}

// Matching Helper Function
function findMatchingVehicles($req, $availableVehicles) {
    $matches = [];
    foreach ($availableVehicles as $v) {
        if (!empty($req['brand_id']) && $v['brand_id'] != $req['brand_id']) {
            continue;
        }
        if (!empty($req['model_id']) && $v['model_id'] != $req['model_id']) {
            continue;
        }
        if (!empty($req['min_year']) && $v['year'] < $req['min_year']) {
            continue;
        }
        if (!empty($req['max_year']) && $v['year'] > $req['max_year']) {
            continue;
        }
        if (!empty($req['transmission']) && $req['transmission'] !== 'any' && $v['transmission'] !== $req['transmission']) {
            continue;
        }
        if (!empty($req['fuel_type']) && $req['fuel_type'] !== 'any' && $v['fuel_type'] !== $req['fuel_type']) {
            continue;
        }
        if (!empty($req['max_budget']) && (float)$req['max_budget'] > 0 && (float)$v['selling_price'] > (float)$req['max_budget']) {
            continue;
        }
        $matches[] = $v;
    }
    return $matches;
}

// Build Query for Requests
$params = [];
$whereClauses = [];

if ($search !== '') {
    $whereClauses[] = "(cr.customer_name LIKE ? OR cr.customer_phone LIKE ? OR b.name LIKE ? OR m.name LIKE ? OR cr.notes LIKE ?)";
    $term = "%{$search}%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

if ($status_filter !== '' && in_array($status_filter, ['active', 'fulfilled', 'cancelled'])) {
    $whereClauses[] = "cr.status = ?";
    $params[] = $status_filter;
}

if ($brand_filter > 0) {
    $whereClauses[] = "cr.brand_id = ?";
    $params[] = $brand_filter;
}

$whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

$requestsList = [];
try {
    $sql = "
        SELECT cr.*, 
               b.name AS brand_name, 
               m.name AS model_name, 
               ct.name AS car_type_name,
               c.phone AS original_customer_phone,
               mv.license_plate AS matched_plate,
               mm.name AS matched_model,
               mb.name AS matched_brand,
               mv.year AS matched_year
        FROM customer_requests cr
        LEFT JOIN brands b ON cr.brand_id = b.id
        LEFT JOIN models m ON cr.model_id = m.id
        LEFT JOIN car_types ct ON cr.car_type_id = ct.id
        LEFT JOIN customers c ON cr.customer_id = c.id
        LEFT JOIN vehicles mv ON cr.matched_vehicle_id = mv.id
        LEFT JOIN models mm ON mv.model_id = mm.id
        LEFT JOIN brands mb ON mm.brand_id = mb.id
        {$whereSql}
        ORDER BY 
            CASE WHEN cr.status = 'active' THEN 0 ELSE 1 END ASC,
            cr.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $requestsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $requestsList = [];
}

// Total requests that have stock matches right now
$totalMatchedActive = 0;
foreach ($requestsList as &$item) {
    if ($item['status'] === 'active') {
        $item['matches'] = findMatchingVehicles($item, $availableVehicles);
        if (!empty($item['matches'])) {
            $totalMatchedActive++;
        }
    } else {
        $item['matches'] = [];
    }
}
unset($item);

// Fetch brands for filter
$brands = [];
try {
    $brands = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
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
        width: 160px;
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
    .status-completed { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-active { background: #fef3c7; color: #92400e; }
    .status-cancelled { background: #f1f5f9; color: #64748b; }

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

    /* Match Box */
    .stock-match-box {
        background: #f0fdf4;
        border: 1px solid #bbf7d0;
        border-radius: 10px;
        padding: 8px 12px;
    }
    .stock-match-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        padding-top: 6px;
        margin-top: 6px;
        border-top: 1px dashed #bbf7d0;
        font-size: 0.78rem;
    }
    .stock-match-item:first-child {
        padding-top: 0;
        margin-top: 0;
        border-top: none;
    }

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
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .stock-match-box { background: rgba(22, 101, 52, 0.15); border-color: rgba(74, 222, 128, 0.25); }
    [data-theme="dark"] .stock-match-item { border-top-color: rgba(74, 222, 128, 0.2); }
</style>

<div class="vehicles-page-header">
    <div>
        <h1>Wishlist & Request Unit Pelanggan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="../dashboard/" class="text-decoration-none text-muted">Dashboard</a></li>
                <li class="breadcrumb-item active" aria-current="page">Request Unit Pelanggan</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i>
            <span>Catat Permintaan Baru</span>
        </a>
    </div>
</div>

<!-- Summary Grid (4 Cards) -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-clipboard-list"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($statTotal); ?></div>
            <div class="summary-label">Total Permintaan</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-hourglass-half"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($statActive); ?></div>
            <div class="summary-label">Sedang Mencari (Aktif)</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-car-side"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($totalMatchedActive); ?></div>
            <div class="summary-label">Stok Cocok di Showroom</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-handshake"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($statFulfilled); ?></div>
            <div class="summary-label">Unit Terpenuhi (Deal)</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card -->
<div class="search-card">
    <form method="GET" action="" class="search-row">
        <div class="search-input-group">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Cari nama calon pembeli, nomor WhatsApp, preferensi mobil..." value="<?php echo htmlspecialchars($search); ?>">
        </div>

        <select name="brand_id" class="filter-select" onchange="this.form.submit()">
            <option value="">Semua Merek</option>
            <?php foreach ($brands as $b): ?>
                <option value="<?php echo $b['id']; ?>" <?php echo $brand_filter === (int)$b['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($b['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select name="status" class="filter-select" onchange="this.form.submit()">
            <option value="" <?php echo $status_filter === '' ? 'selected' : ''; ?>>Semua Status</option>
            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Aktif (Mencari)</option>
            <option value="fulfilled" <?php echo $status_filter === 'fulfilled' ? 'selected' : ''; ?>>Terpenuhi (Deal)</option>
            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Dibatalkan</option>
        </select>

        <button type="submit" class="btn-search">
            <i class="fas fa-filter"></i> Filter
        </button>

        <?php if (!empty($search) || !empty($status_filter) || $brand_filter > 0): ?>
            <a href="index.php" class="btn-reset-search" title="Reset Filter">
                <i class="fas fa-redo"></i>
            </a>
        <?php endif; ?>
    </form>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h3 class="table-card-title">Daftar Permintaan Calon Pembeli</h3>
        <span class="record-count"><?php echo count($requestsList); ?> Data</span>
    </div>

    <div class="table-responsive">
        <table class="vehicles-table">
            <thead>
                <tr>
                    <th style="width: 17%;">PELANGGAN</th>
                    <th style="width: 25%;">KRITERIA MOBIL DICARI</th>
                    <th style="width: 14%;" class="text-end">BUDGET MAKS.</th>
                    <th style="width: 26%;">KETERSEDIAAN STOK SHOWROOM</th>
                    <th style="width: 9%;" class="text-center">STATUS</th>
                    <th style="width: 9%;" class="text-end">AKSI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requestsList)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-search-location fa-2x mb-2 text-secondary opacity-50 d-block"></i>
                            Tidak ada data permintaan unit yang sesuai.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($requestsList as $item): ?>
                        <?php
                        $phoneClean = preg_replace('/[^0-9]/', '', $item['customer_phone']);
                        if (strpos($phoneClean, '0') === 0) {
                            $phoneClean = '62' . substr($phoneClean, 1);
                        }
                        $matches = $item['matches'] ?? [];
                        $hasMatch = !empty($matches);

                        $badgeClass = 'status-cancelled';
                        $statusLabel = 'Dibatalkan';
                        if ($item['status'] === 'active') {
                            $badgeClass = 'status-in_progress';
                            $statusLabel = 'Aktif (Mencari)';
                        } elseif ($item['status'] === 'fulfilled') {
                            $badgeClass = 'status-completed';
                            $statusLabel = 'Terpenuhi';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($item['customer_name']); ?></div>
                                <div class="small mt-0.5">
                                    <a href="https://wa.me/<?php echo $phoneClean; ?>" target="_blank" class="text-decoration-none text-muted d-inline-flex align-items-center gap-1">
                                        <i class="fab fa-whatsapp text-success"></i>
                                        <span><?php echo htmlspecialchars($item['customer_phone']); ?></span>
                                    </a>
                                </div>
                                <div class="text-muted" style="font-size: 0.72rem;">
                                    <?php echo date('d/m/Y', strtotime($item['created_at'])); ?>
                                </div>
                            </td>

                            <td>
                                <div class="fw-bold text-dark">
                                    <?php 
                                    $carSpec = [];
                                    if (!empty($item['brand_name'])) $carSpec[] = $item['brand_name'];
                                    if (!empty($item['model_name'])) $carSpec[] = $item['model_name'];
                                    echo !empty($carSpec) ? htmlspecialchars(implode(' ', $carSpec)) : '<span class="text-muted fst-italic">Semua Model</span>';
                                    ?>
                                </div>
                                <div class="text-secondary small mt-0.5" style="font-size: 0.76rem;">
                                    <?php
                                    $details = [];
                                    if ($item['min_year'] || $item['max_year']) {
                                        if ($item['min_year'] && $item['max_year']) {
                                            $details[] = "Tahun {$item['min_year']} - {$item['max_year']}";
                                        } elseif ($item['min_year']) {
                                            $details[] = "Tahun >= {$item['min_year']}";
                                        } else {
                                            $details[] = "Tahun <= {$item['max_year']}";
                                        }
                                    }
                                    if ($item['transmission'] && $item['transmission'] !== 'any') {
                                        $details[] = strtoupper($item['transmission']);
                                    }
                                    if ($item['fuel_type'] && $item['fuel_type'] !== 'any') {
                                        $details[] = ucfirst($item['fuel_type']);
                                    }
                                    if ($item['color_preference']) {
                                        $details[] = "Warna " . htmlspecialchars($item['color_preference']);
                                    }
                                    echo implode(' • ', $details);
                                    ?>
                                </div>
                                <?php if (!empty($item['notes'])): ?>
                                    <div class="text-muted fst-italic mt-1" style="font-size: 0.73rem;">
                                        "<?php echo htmlspecialchars($item['notes']); ?>"
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <?php if (!empty($item['max_budget']) && (float)$item['max_budget'] > 0): ?>
                                    <div class="price-text">Rp <?php echo number_format($item['max_budget'], 0, ',', '.'); ?></div>
                                    <small class="text-muted" style="font-size: 0.72rem;">Maksimal</small>
                                <?php else: ?>
                                    <span class="text-muted small">Fleksibel</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($item['status'] === 'fulfilled'): ?>
                                    <div class="d-flex align-items-center gap-1.5 text-success small">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Unit telah terbeli</span>
                                    </div>
                                    <?php if (!empty($item['matched_model'])): ?>
                                        <div class="small fw-semibold text-dark mt-0.5">
                                            <?php echo htmlspecialchars($item['matched_brand'] . ' ' . $item['matched_model'] . ' (' . $item['matched_year'] . ')'); ?>
                                            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($item['matched_plate']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($item['status'] === 'cancelled'): ?>
                                    <span class="text-muted small fst-italic">Permintaan telah dibatalkan</span>
                                <?php elseif ($hasMatch): ?>
                                    <div class="stock-match-box">
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="badge bg-success text-white" style="font-size: 0.7rem; font-weight: 600;">
                                                <i class="fas fa-check-circle me-1"></i><?php echo count($matches); ?> Unit Cocok di Stok
                                            </span>
                                        </div>
                                        <?php foreach (array_slice($matches, 0, 2) as $mCar): ?>
                                            <?php
                                            $waText = "Halo Bapak/Ibu " . $item['customer_name'] . ", salam dari " . (defined('APP_NAME') ? APP_NAME : 'Mulya Motor') . ".\n\n"
                                                    . "Mengenai mobil yang Bapak/Ibu cari, saat ini di showroom kami *baru tersedia* unit yang sangat cocok:\n\n"
                                                    . "🚗 *" . $mCar['brand_name'] . " " . $mCar['model_name'] . " (" . $mCar['year'] . ")*\n"
                                                    . "• Transmisi : " . strtoupper($mCar['transmission']) . "\n"
                                                    . "• Warna : " . $mCar['color'] . "\n"
                                                    . "• KM : " . number_format($mCar['mileage'], 0, ',', '.') . " KM\n"
                                                    . "• Plat : " . $mCar['license_plate'] . "\n"
                                                    . "• Harga : Rp " . number_format($mCar['selling_price'], 0, ',', '.') . " (Nego)\n\n"
                                                    . "Apakah Bapak/Ibu berminat untuk cek unit atau test drive langsung di showroom?";
                                            $waLink = "https://wa.me/{$phoneClean}?text=" . urlencode($waText);
                                            ?>
                                            <div class="stock-match-item">
                                                <div class="text-truncate">
                                                    <a href="../vehicles/view.php?id=<?php echo $mCar['id']; ?>" class="fw-semibold text-dark text-decoration-none">
                                                        <?php echo htmlspecialchars($mCar['brand_name'] . ' ' . $mCar['model_name'] . ' ' . $mCar['year']); ?>
                                                    </a>
                                                    <span class="text-muted"> - Rp <?php echo number_format($mCar['selling_price'], 0, ',', '.'); ?></span>
                                                </div>
                                                <a href="<?php echo $waLink; ?>" target="_blank" class="btn btn-sm btn-success py-0 px-2 fw-semibold" style="font-size: 0.68rem; border-radius: 6px; white-space: nowrap;">
                                                    <i class="fab fa-whatsapp me-1"></i> Tawarkan
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if (count($matches) > 2): ?>
                                            <div class="text-muted text-center pt-1" style="font-size: 0.68rem;">+ <?php echo (count($matches) - 2); ?> unit lainnya di showroom</div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="text-muted small d-flex align-items-center gap-1.5">
                                        <i class="fas fa-clock text-secondary opacity-75"></i>
                                        <span>Belum ada unit cocok di showroom</span>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-center">
                                <span class="status-badge <?php echo $badgeClass; ?>">
                                    <?php echo $statusLabel; ?>
                                </span>
                            </td>

                            <td class="text-end">
                                <div class="action-btns">
                                    <a href="https://wa.me/<?php echo $phoneClean; ?>" target="_blank" class="action-btn action-btn-view" title="Hubungi Pelanggan via WhatsApp">
                                        <i class="fab fa-whatsapp text-success"></i>
                                    </a>
                                    <?php if ($item['status'] === 'active'): ?>
                                        <button type="button" class="action-btn action-btn-print" title="Tandai Terpenuhi (Deal)" onclick="openUpdateStatusModal(<?php echo $item['id']; ?>, 'fulfilled')">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <button type="button" class="action-btn action-btn-release" title="Batalkan Permintaan" onclick="openUpdateStatusModal(<?php echo $item['id']; ?>, 'cancelled')">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="action-btn action-btn-print" title="Aktifkan Kembali" onclick="openUpdateStatusModal(<?php echo $item['id']; ?>, 'active')">
                                            <i class="fas fa-redo"></i>
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
</div>

<!-- Modal Update Status -->
<div class="modal fade" id="updateStatusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="request_id" id="modalRequestId" value="">
                <input type="hidden" name="status" id="modalStatusValue" value="">

                <div class="modal-header border-bottom py-3">
                    <h5 class="modal-title fw-bold text-dark fs-6" id="modalTitle">Perbarui Status Permintaan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-3" id="modalDescription">Pilih unit showroom yang dibeli oleh pelanggan (opsional):</p>
                    <div id="matchedVehicleSelectGroup" class="mb-3">
                        <label class="form-label fw-semibold small text-dark">Unit Mobil yang Dibeli (Opsional)</label>
                        <select name="matched_vehicle_id" class="form-select form-select-sm" style="border-radius: 8px;">
                            <option value="">-- Tanpa Hubungan Unit / Beli di Luar --</option>
                            <?php foreach ($availableVehicles as $v): ?>
                                <option value="<?php echo $v['id']; ?>">
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ') - ' . $v['license_plate'] . ' [Rp ' . number_format($v['selling_price'], 0, ',', '.') . ']'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small" style="font-size: 0.72rem;">Catat unit stok showroom yang berhasil terjual ke pelanggan ini.</div>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light py-2 px-4">
                    <button type="button" class="btn btn-sm btn-outline-secondary px-3" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-sm btn-primary px-4 fw-semibold" id="modalSubmitBtn">Konfirmasi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openUpdateStatusModal(reqId, targetStatus) {
    document.getElementById('modalRequestId').value = reqId;
    document.getElementById('modalStatusValue').value = targetStatus;
    const selectGroup = document.getElementById('matchedVehicleSelectGroup');
    const modalTitle = document.getElementById('modalTitle');
    const modalDesc = document.getElementById('modalDescription');
    const submitBtn = document.getElementById('modalSubmitBtn');

    if (targetStatus === 'fulfilled') {
        modalTitle.textContent = 'Tandai Permintaan Terpenuhi (Deal)';
        modalDesc.textContent = 'Pelanggan telah berhasil mendapatkan mobil yang diinginkan. Anda dapat menghubungkannya dengan unit showroom di bawah:';
        selectGroup.style.display = 'block';
        submitBtn.className = 'btn btn-sm btn-success px-4 fw-semibold';
        submitBtn.textContent = 'Simpan & Selesaikan';
    } else if (targetStatus === 'cancelled') {
        modalTitle.textContent = 'Batalkan Permintaan Unit';
        modalDesc.textContent = 'Apakah Anda yakin ingin menandai permintaan calon pembeli ini sebagai dibatalkan?';
        selectGroup.style.display = 'none';
        submitBtn.className = 'btn btn-sm btn-danger px-4 fw-semibold';
        submitBtn.textContent = 'Batalkan Permintaan';
    } else {
        modalTitle.textContent = 'Aktifkan Kembali Permintaan';
        modalDesc.textContent = 'Permintaan akan kembali berstatus aktif dan dicocokkan otomatis dengan stok masuk showroom.';
        selectGroup.style.display = 'none';
        submitBtn.className = 'btn btn-sm btn-primary px-4 fw-semibold';
        submitBtn.textContent = 'Aktifkan';
    }

    const myModal = new bootstrap.Modal(document.getElementById('updateStatusModal'));
    myModal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
