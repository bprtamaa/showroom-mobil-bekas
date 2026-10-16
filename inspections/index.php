<?php
// inspections/index.php - Daftar & Sertifikasi Hasil Inspeksi Kendaraan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/inspection_helpers.php';
requireLogin();

if (!defined('UPLOADS_URL')) {
    define('UPLOADS_URL', (defined('BASE_URL') ? BASE_URL : '/showroom/') . 'uploads/');
}

// Search & filter parameters
$search = trim($_GET['search'] ?? '');
$grade_filter = trim($_GET['grade'] ?? '');
$cert_filter = trim($_GET['cert'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sorting
$sort_column = $_GET['sort'] ?? 'inspection_date';
$sort_order = strtoupper($_GET['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

$allowed_sorts = [
    'inspection_date' => 'i.inspection_date',
    'overall_score' => 'i.overall_score',
    'overall_grade' => 'i.overall_grade',
    'created_at' => 'i.created_at'
];
$db_sort = $allowed_sorts[$sort_column] ?? 'i.inspection_date';

try {
    // Stats KPI Summary Query
    $statsQuery = $db->query("
        SELECT 
            COUNT(*) as total_inspections,
            SUM(CASE WHEN overall_grade IN ('A+', 'A') THEN 1 ELSE 0 END) as total_grade_a,
            SUM(CASE WHEN flood_free = 1 AND accident_free = 1 THEN 1 ELSE 0 END) as total_certified,
            AVG(overall_score) as avg_score
        FROM vehicle_inspections
    ");
    $stats = $statsQuery->fetch(PDO::FETCH_ASSOC);

    $whereClauses = [];
    $params = [];

    if (!empty($search)) {
        $whereClauses[] = "(v.license_plate LIKE ? OR b.name LIKE ? OR m.name LIKE ? OR i.inspector_name LIKE ?)";
        $searchParam = "%$search%";
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }

    if (!empty($grade_filter) && in_array($grade_filter, ['A+', 'A', 'B', 'C', 'D'])) {
        $whereClauses[] = "i.overall_grade = ?";
        $params[] = $grade_filter;
    }

    if ($cert_filter === 'flood_free') {
        $whereClauses[] = "i.flood_free = 1";
    } elseif ($cert_filter === 'accident_free') {
        $whereClauses[] = "i.accident_free = 1";
    } elseif ($cert_filter === 'certified') {
        $whereClauses[] = "i.flood_free = 1 AND i.accident_free = 1";
    }

    $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

    $countSql = "
        SELECT COUNT(*) as total 
        FROM vehicle_inspections i
        LEFT JOIN vehicles v ON i.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        $whereSql
    ";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalRecords = (int)($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    $totalPages = ceil($totalRecords / $limit);

    // Fetch inspections
    $dataSql = "
        SELECT 
            i.*,
            v.license_plate, v.year, v.color, v.mileage, v.status as vehicle_status, v.selling_price, v.images,
            b.name as brand_name,
            m.name as model_name
        FROM vehicle_inspections i
        LEFT JOIN vehicles v ON i.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        $whereSql
        ORDER BY $db_sort $sort_order
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($dataSql);
    $stmt->execute($params);
    $inspections = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Gagal memuat data inspeksi: " . $e->getMessage();
    $inspections = [];
    $totalRecords = 0;
    $totalPages = 0;
    $stats = ['total_inspections' => 0, 'total_grade_a' => 0, 'total_certified' => 0, 'avg_score' => 0];
}
?>

<style>
    /* ===== Consistent Master Data Page Styles ===== */
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

    /* ===== Summary Cards Grid ===== */
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
        text-decoration: none;
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
    }
    .cert-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 2px 7px;
        border-radius: 6px;
    }
    .cert-pill.safe { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .cert-pill.warn { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

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
    .action-btn-cert:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
    .action-btn-edit:hover { background: #fefce8; border-color: #fef08a; color: #ca8a04; }
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

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { border-color: #60a5fa; background: #162032; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .pagination-list li a,
    [data-theme="dark"] .pagination-list li span { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .pagination-list li.active a,
    [data-theme="dark"] .pagination-list li.active span { background: #3b82f6; border-color: #3b82f6; color: #fff; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Inspeksi Kendaraan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>vehicles/" class="text-primary text-decoration-underline">Stok Mobil</a></li>
                <li class="breadcrumb-item active" aria-current="page">Inspeksi Kendaraan</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="create.php" class="btn-add-vehicle">
            <i class="fas fa-plus"></i> Tambah Inspeksi
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-clipboard-check"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['total_inspections'] ?? 0); ?></div>
            <div class="summary-label">Total Unit Diinspeksi</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-medal"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo number_format($stats['total_grade_a'] ?? 0); ?></div>
            <div class="summary-label">Grade A+ & Grade A</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-shield-alt"></i>
        </div>
        <div>
            <div class="summary-value text-primary"><?php echo number_format($stats['total_certified'] ?? 0); ?></div>
            <div class="summary-label">Bebas Banjir & Tabrak</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-star-half-alt"></i>
        </div>
        <div>
            <div class="summary-value text-warning"><?php echo number_format($stats['avg_score'] ?? 0, 1); ?>/100</div>
            <div class="summary-label">Rata-Rata Skor Fisik</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card (Identical with Stok Mobil) -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Cari nopol, merek, model, inspektor..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="grade" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Grade</option>
                    <option value="A+" <?php echo $grade_filter === 'A+' ? 'selected' : ''; ?>>Grade A+</option>
                    <option value="A" <?php echo $grade_filter === 'A' ? 'selected' : ''; ?>>Grade A</option>
                    <option value="B" <?php echo $grade_filter === 'B' ? 'selected' : ''; ?>>Grade B</option>
                    <option value="C" <?php echo $grade_filter === 'C' ? 'selected' : ''; ?>>Grade C</option>
                    <option value="D" <?php echo $grade_filter === 'D' ? 'selected' : ''; ?>>Grade D</option>
                </select>
                <select name="cert" class="filter-select" onchange="this.form.submit()" style="width:190px;">
                    <option value="">Semua Jaminan</option>
                    <option value="certified" <?php echo $cert_filter === 'certified' ? 'selected' : ''; ?>>Bebas Banjir & Tabrak</option>
                    <option value="flood_free" <?php echo $cert_filter === 'flood_free' ? 'selected' : ''; ?>>Bebas Banjir</option>
                    <option value="accident_free" <?php echo $cert_filter === 'accident_free' ? 'selected' : ''; ?>>Bebas Tabrak</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($grade_filter) || !empty($cert_filter)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table Card (Identical with Stok Mobil) -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Laporan & Sertifikat Inspeksi</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Data</span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&grade=<?php echo urlencode($grade_filter); ?>&cert=<?php echo urlencode($cert_filter); ?>'">
                <option value="5" <?php echo $limit == 5 ? 'selected' : ''; ?>>5</option>
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
            </select>
        </div>
    </div>

    <div class="table-responsive">
        <table class="vehicles-table">
            <thead>
                <tr>
                    <th style="width: 50px;">NO</th>
                    <th>UNIT KENDARAAN</th>
                    <th>GRADE & SKOR</th>
                    <th>JAMINAN SERTIFIKASI</th>
                    <th>TANGGAL & INSPEKTOR</th>
                    <th class="text-end" style="width: 120px;">AKSI</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inspections)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="fas fa-clipboard-check fa-2x text-muted mb-2 d-block"></i>
                            <div class="fw-semibold">Belum ada laporan inspeksi.</div>
                            <small class="text-muted">Lakukan inspeksi checklist pada stok mobil untuk menerbitkan sertifikat.</small>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php 
                    $no = $offset + 1;
                    foreach ($inspections as $insp): 
                    ?>
                        <tr>
                            <td class="text-muted fw-bold"><?php echo $no++; ?></td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo htmlspecialchars($insp['brand_name'] . ' ' . $insp['model_name']); ?></div>
                                <div class="d-flex align-items-center gap-1 mt-1">
                                    <span class="plate-badge"><?php echo htmlspecialchars($insp['license_plate'] ?: 'No Plat'); ?></span>
                                    <span class="text-muted small">• <?php echo (int)$insp['year']; ?></span>
                                    <span class="text-muted small">• <?php echo number_format($insp['mileage']); ?> KM</span>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column gap-1">
                                    <div><?php echo renderGradeBadge($insp['overall_grade'], $insp['overall_score'], 'sm'); ?></div>
                                    <div class="text-muted small" style="font-size:0.72rem;">
                                        Rangka: <strong><?php echo (int)$insp['frame_score']; ?>%</strong> • Mesin: <strong><?php echo (int)$insp['engine_score']; ?>%</strong>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php if ($insp['flood_free']): ?>
                                        <span class="cert-pill safe"><i class="fas fa-check"></i> Bebas Banjir</span>
                                    <?php else: ?>
                                        <span class="cert-pill warn"><i class="fas fa-times"></i> Bekas Banjir</span>
                                    <?php endif; ?>

                                    <?php if ($insp['accident_free']): ?>
                                        <span class="cert-pill safe"><i class="fas fa-check"></i> Bebas Tabrak</span>
                                    <?php else: ?>
                                        <span class="cert-pill warn"><i class="fas fa-times"></i> Bekas Tabrak</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark small">
                                    <?php echo formatDate($insp['inspection_date'], 'd M Y'); ?>
                                </div>
                                <div class="text-muted" style="font-size:0.75rem;">
                                    <?php echo htmlspecialchars($insp['inspector_name']); ?>
                                </div>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <a href="view.php?id=<?php echo $insp['id']; ?>" class="action-btn action-btn-cert" title="Lihat Sertifikat">
                                        <i class="fas fa-certificate"></i>
                                    </a>
                                    <a href="edit.php?id=<?php echo $insp['id']; ?>" class="action-btn action-btn-edit" title="Edit Hasil">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <button type="button" class="action-btn action-btn-delete" 
                                            onclick="deleteInspection(<?php echo $insp['id']; ?>, '<?php echo htmlspecialchars(addslashes($insp['license_plate'])); ?>')" 
                                            title="Hapus Laporan">
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

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-wrapper">
            <ul class="pagination-list">
                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&grade=<?php echo urlencode($grade_filter); ?>&cert=<?php echo urlencode($cert_filter); ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="<?php echo $page == $i ? 'active' : ''; ?>">
                        <a href="?page=<?php echo $i; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&grade=<?php echo urlencode($grade_filter); ?>&cert=<?php echo urlencode($cert_filter); ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>&search=<?php echo urlencode($search); ?>&grade=<?php echo urlencode($grade_filter); ?>&cert=<?php echo urlencode($cert_filter); ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </div>
    <?php endif; ?>
</div>

<script>
function deleteInspection(id, plate) {
    if (confirm('Apakah Anda yakin ingin menghapus data laporan inspeksi untuk kendaraan (' + plate + ')?')) {
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
</script>

<?php include '../includes/footer.php'; ?>
