<?php
// vehicles/available.php - Daftar Kendaraan Tersedia
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
$price_range = trim($_GET['price'] ?? '');

try {
    // Build WHERE clause
    $whereClauses = ["v.status = 'available'"];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(v.chassis_number LIKE ? OR v.color LIKE ? OR m.name LIKE ? OR b.name LIKE ? OR v.license_plate LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
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
    
    if (!empty($price_range)) {
        switch ($price_range) {
            case '1':
                $whereClauses[] = "v.selling_price < 100000000";
                break;
            case '2':
                $whereClauses[] = "v.selling_price BETWEEN 100000000 AND 200000000";
                break;
            case '3':
                $whereClauses[] = "v.selling_price BETWEEN 200000000 AND 300000000";
                break;
            case '4':
                $whereClauses[] = "v.selling_price > 300000000";
                break;
        }
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
    
    // Get vehicles
    $sql = "
        SELECT v.*,
               m.name as model_name, 
               b.name as brand_name,
               ct.name as car_type_name
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        $whereClause
        ORDER BY v.created_at DESC
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get filters
    $stmt = $db->query("SELECT id, name FROM brands WHERE status = 'active' ORDER BY name ASC");
    $brands = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->query("SELECT id, name FROM car_types WHERE status = 'active' ORDER BY name ASC");
    $carTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}

$fuelMap = [
    'gasoline' => 'Bensin',
    'petrol' => 'Bensin',
    'diesel' => 'Diesel',
    'electric' => 'Listrik',
    'hybrid' => 'Hybrid',
    'lpg' => 'LPG',
    'cng' => 'CNG'
];
$transMap = [
    'automatic' => 'Otomatis',
    'manual' => 'Manual',
    'cvt' => 'CVT',
    'amt' => 'AMT',
    'dual clutch' => 'Dual Clutch',
    'tiptronic' => 'Tiptronic'
];
?>

<style>
    /* ===== Available Vehicles Page Specific Styles (Matching Brands Design) ===== */
    .avail-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .avail-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .avail-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .avail-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .btn-header-action {
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
    .btn-header-action:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .btn-header-primary {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }
    .btn-header-primary:hover {
        background: #1e272e;
        color: #fff;
        border-color: #1e272e;
    }

    /* KPI Summary Cards */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    .summary-card-modern {
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
    .summary-card-modern:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .summary-card-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        flex-shrink: 0;
    }
    .summary-card-icon.blue { background: #eff6ff; color: #2563eb; }
    .summary-card-icon.emerald { background: #ecfdf5; color: #059669; }
    .summary-card-icon.amber { background: #fffbeb; color: #d97706; }
    .summary-card-icon.indigo { background: #eef2ff; color: #4f46e5; }
    .summary-val {
        font-size: 1.35rem;
        font-weight: 800;
        line-height: 1.1;
        color: #1e293b;
    }
    .summary-lbl {
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
        margin-bottom: 24px;
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
        width: 150px;
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

    /* Vehicle Grid Card */
    .avail-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: transform 0.2s, box-shadow 0.2s;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .avail-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 24px rgba(0,0,0,0.08);
    }
    .avail-card-image-wrap {
        position: relative;
        width: 100%;
        aspect-ratio: 16/9;
        background: #f1f5f9;
        overflow: hidden;
    }
    .avail-card-image-wrap img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.3s ease;
    }
    .avail-card:hover .avail-card-image-wrap img {
        transform: scale(1.04);
    }
    .avail-card-badge-top {
        position: absolute;
        top: 10px;
        left: 10px;
        padding: 3px 8px;
        background: rgba(15,23,42,0.75);
        backdrop-filter: blur(4px);
        color: #fff;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 700;
    }
    .avail-card-body {
        padding: 16px 18px 18px;
        display: flex;
        flex-direction: column;
        flex: 1;
    }
    .avail-card-brand {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
    }
    .avail-card-title {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1e293b;
        margin: 2px 0 6px 0;
        line-height: 1.3;
    }
    .avail-card-tags {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 12px;
    }
    .tag-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.72rem;
        color: #475569;
        font-weight: 600;
    }
    .spec-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
        margin-bottom: 14px;
    }
    .spec-box {
        background: #f8fafc;
        border: 1px solid #f1f5f9;
        border-radius: 8px;
        padding: 6px 10px;
        font-size: 0.75rem;
        color: #475569;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .spec-box i { color: #94a3b8; font-size: 0.75rem; }
    .avail-card-footer {
        margin-top: auto;
        padding-top: 12px;
        border-top: 1px solid #f1f5f9;
    }
    .avail-card-price {
        font-size: 1.15rem;
        font-weight: 800;
        color: #166534;
        margin-bottom: 10px;
    }
    .btn-card-sell {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        width: 100%;
        padding: 8px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.8rem;
        border-radius: 8px;
        border: none;
        text-decoration: none;
        transition: background 0.15s;
        margin-bottom: 6px;
    }
    .btn-card-sell:hover { background: #1e272e; color: #fff; }
    .btn-card-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
    }
    .btn-card-sub {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        padding: 6px;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #475569;
        font-weight: 600;
        font-size: 0.74rem;
        border-radius: 8px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-card-sub:hover { background: #f1f5f9; color: #1e293b; border-color: #cbd5e1; }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 24px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
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

    /* Dark Mode Overrides */
    [data-theme="dark"] .avail-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .summary-card-modern,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .avail-card,
    [data-theme="dark"] .empty-state { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-val { color: #f1f5f9; }
    [data-theme="dark"] .summary-card-icon.blue { background: rgba(59,130,246,0.15); color: #93c5fd; }
    [data-theme="dark"] .summary-card-icon.emerald { background: rgba(16,185,129,0.15); color: #6ee7b7; }
    [data-theme="dark"] .summary-card-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .summary-card-icon.indigo { background: rgba(99,102,241,0.15); color: #a5b4fc; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .avail-card-title { color: #f1f5f9; }
    [data-theme="dark"] .avail-card-image-wrap { background: #0f172a; }
    [data-theme="dark"] .tag-chip,
    [data-theme="dark"] .spec-box { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .avail-card-price { color: #4ade80; }
    [data-theme="dark"] .avail-card-footer { border-top-color: #334155; }
    [data-theme="dark"] .btn-card-sell { background: #3b82f6; }
    [data-theme="dark"] .btn-card-sell:hover { background: #2563eb; }
    [data-theme="dark"] .btn-card-sub { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-card-sub:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .empty-state-icon { color: #334155; }
    [data-theme="dark"] .empty-state h5 { color: #e2e8f0; }
    [data-theme="dark"] .empty-state p { color: #94a3b8; }

    /* Responsive */
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 120px; }
    }
    @media (max-width: 576px) {
        .avail-page-header { flex-direction: column; gap: 10px; }
        .avail-header-actions { width: 100%; justify-content: flex-start; }
    }
</style>

<!-- Page Header -->
<div class="avail-page-header">
    <div>
        <h1>Mobil Tersedia</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/" class="text-primary text-decoration-underline">Stok Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Mobil Tersedia</li>
            </ol>
        </nav>
    </div>
    <div class="avail-header-actions">
        <a href="<?php echo BASE_URL; ?>vehicles/" class="btn-header-action">
            <i class="fas fa-list"></i> Semua Stok
        </a>
        <a href="<?php echo BASE_URL; ?>vehicles/create.php" class="btn-header-action btn-header-primary">
            <i class="fas fa-plus"></i> Tambah Mobil
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<?php if (!empty($vehicles)): 
    $totalValue = array_sum(array_column($vehicles, 'selling_price'));
    $avgPrice = count($vehicles) > 0 ? $totalValue / count($vehicles) : 0;
    $totalPotentialProfit = 0;
    foreach ($vehicles as $v) {
        $totalPotentialProfit += ((float)$v['selling_price'] - (float)($v['purchase_price'] ?? 0));
    }
?>
<!-- KPI Summary Cards -->
<div class="summary-grid">
    <div class="summary-card-modern">
        <div class="summary-card-icon blue"><i class="fas fa-car"></i></div>
        <div>
            <div class="summary-val"><?php echo count($vehicles); ?></div>
            <div class="summary-lbl">Unit Tersedia</div>
        </div>
    </div>
    <div class="summary-card-modern">
        <div class="summary-card-icon emerald"><i class="fas fa-money-bill-wave"></i></div>
        <div>
            <div class="summary-val" style="font-size: 1.15rem;"><?php echo formatCurrency($totalValue); ?></div>
            <div class="summary-lbl">Total Nilai Asset</div>
        </div>
    </div>
    <div class="summary-card-modern">
        <div class="summary-card-icon amber"><i class="fas fa-calculator"></i></div>
        <div>
            <div class="summary-val" style="font-size: 1.15rem;"><?php echo formatCurrency($avgPrice); ?></div>
            <div class="summary-lbl">Rata-rata Harga</div>
        </div>
    </div>
    <div class="summary-card-modern">
        <div class="summary-card-icon indigo"><i class="fas fa-chart-line"></i></div>
        <div>
            <div class="summary-val" style="font-size: 1.15rem;"><?php echo formatCurrency($totalPotentialProfit); ?></div>
            <div class="summary-lbl">Potensi Margin</div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Search & Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari unit mobil, plat nomor, warna..." value="<?php echo htmlspecialchars($search); ?>">
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
                <select name="price" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Harga</option>
                    <option value="1" <?php echo $price_range == '1' ? 'selected' : ''; ?>>< Rp 100 Juta</option>
                    <option value="2" <?php echo $price_range == '2' ? 'selected' : ''; ?>>Rp 100 - 200 Juta</option>
                    <option value="3" <?php echo $price_range == '3' ? 'selected' : ''; ?>>Rp 200 - 300 Juta</option>
                    <option value="4" <?php echo $price_range == '4' ? 'selected' : ''; ?>>> Rp 300 Juta</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($brand_filter) || !empty($type_filter) || !empty($price_range)): ?>
                <a href="available.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Vehicles Grid -->
<?php if (!empty($vehicles)): ?>
<div class="row g-3">
    <?php foreach ($vehicles as $vehicle): 
        $firstImage = null;
        if (!empty($vehicle['images'])) {
            $imgList = json_decode($vehicle['images'], true);
            if (!is_array($imgList)) {
                $imgList = array_filter(array_map('trim', explode(',', $vehicle['images'])));
            }
            if (!empty($imgList)) {
                $firstImage = $imgList[0];
            }
        }
        $uploadPath = __DIR__ . '/../uploads/vehicles/';
        $hasImageFile = $firstImage && file_exists($uploadPath . $firstImage);
    ?>
    <div class="col-xl-4 col-md-6">
        <div class="avail-card">
            <div class="avail-card-image-wrap">
                <?php if ($hasImageFile): ?>
                    <img src="<?php echo BASE_URL . 'uploads/vehicles/' . rawurlencode($firstImage); ?>" 
                         alt="<?php echo htmlspecialchars($vehicle['model_name']); ?>"
                         loading="lazy">
                <?php else: ?>
                    <div class="w-100 h-100 d-flex align-items-center justify-content-center">
                        <i class="fas fa-car text-muted opacity-25 fa-3x"></i>
                    </div>
                <?php endif; ?>
                <?php if (!empty($vehicle['license_plate'])): ?>
                    <span class="avail-card-badge-top"><?php echo htmlspecialchars($vehicle['license_plate']); ?></span>
                <?php endif; ?>
            </div>

            <div class="avail-card-body">
                <div class="avail-card-brand"><?php echo htmlspecialchars($vehicle['brand_name']); ?></div>
                <div class="avail-card-title"><?php echo htmlspecialchars($vehicle['model_name']); ?></div>
                
                <div class="avail-card-tags">
                    <span class="tag-chip"><i class="fas fa-calendar-alt"></i> Tahun <?php echo $vehicle['year'] ?: '-'; ?></span>
                    <?php if (!empty($vehicle['car_type_name'])): ?>
                    <span class="tag-chip"><i class="fas fa-car-side"></i> <?php echo htmlspecialchars($vehicle['car_type_name']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="spec-grid">
                    <div class="spec-box">
                        <i class="fas fa-tachometer-alt"></i>
                        <span><?php echo number_format($vehicle['mileage']); ?> km</span>
                    </div>
                    <div class="spec-box">
                        <i class="fas fa-gas-pump"></i>
                        <span><?php $ft=strtolower(trim((string)$vehicle['fuel_type'])); echo htmlspecialchars($fuelMap[$ft] ?? ucfirst($ft)); ?></span>
                    </div>
                    <div class="spec-box">
                        <i class="fas fa-cog"></i>
                        <span><?php $tr=strtolower(trim((string)$vehicle['transmission'])); echo htmlspecialchars($transMap[$tr] ?? ucfirst($tr)); ?></span>
                    </div>
                    <div class="spec-box">
                        <i class="fas fa-palette"></i>
                        <span><?php echo htmlspecialchars($vehicle['color'] ?: '-'); ?></span>
                    </div>
                </div>

                <div class="avail-card-footer">
                    <div class="avail-card-price"><?php echo formatCurrency($vehicle['selling_price']); ?></div>
                    <a href="<?php echo BASE_URL; ?>transactions/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-card-sell">
                        <i class="fas fa-shopping-cart"></i> Jual Sekarang
                    </a>
                    <div class="btn-card-group">
                        <a href="<?php echo BASE_URL; ?>leasing/simulation.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="btn-card-sub">
                            <i class="fas fa-calculator"></i> Simulasi Kredit
                        </a>
                        <a href="view.php?id=<?php echo $vehicle['id']; ?>" class="btn-card-sub">
                            <i class="fas fa-eye"></i> Detail
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php else: ?>
<div class="empty-state">
    <div class="empty-state-icon"><i class="fas fa-car"></i></div>
    <h5><?php echo (!empty($search) || !empty($brand_filter) || !empty($type_filter) || !empty($price_range)) ? 'Mobil Tidak Ditemukan' : 'Tidak Ada Mobil Tersedia'; ?></h5>
    <p>
        <?php echo (!empty($search) || !empty($brand_filter) || !empty($type_filter) || !empty($price_range)) 
            ? 'Tidak ditemukan stok mobil yang cocok dengan parameter filter pencarian.' 
            : 'Saat ini belum ada stok kendaraan berstatus tersedia.'; ?>
    </p>
    <?php if (!empty($search) || !empty($brand_filter) || !empty($type_filter) || !empty($price_range)): ?>
    <a href="available.php" class="btn-header-action">
        <i class="fas fa-redo-alt me-1"></i>Reset Filter
    </a>
    <?php else: ?>
    <a href="<?php echo BASE_URL; ?>vehicles/create.php" class="btn-header-action btn-header-primary">
        <i class="fas fa-plus"></i> Tambah Mobil Baru
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
