<?php
// promotions/index.php - Manajemen Promo & Diskon Terjadwal
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
$page = max(1, (int)($_GET['page'] ?? 1));

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Handle Toggle Status / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid.', 'danger');
    } else {
        $pId = (int)($_POST['id'] ?? 0);
        if ($_POST['action'] === 'delete_promo') {
            try {
                $stmtDel = $db->prepare("DELETE FROM promotions WHERE id = ?");
                $stmtDel->execute([$pId]);
                showAlert('Data promo berhasil dihapus.', 'success');
                redirect('promotions/');
            } catch (PDOException $e) {
                showAlert('Gagal menghapus promo: ' . $e->getMessage(), 'danger');
            }
        } elseif ($_POST['action'] === 'toggle_status') {
            $newStatus = $_POST['status'] === 'active' ? 'inactive' : 'active';
            try {
                $stmtTog = $db->prepare("UPDATE promotions SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmtTog->execute([$newStatus, $pId]);
                showAlert('Status promo berhasil diubah.', 'success');
                redirect('promotions/');
            } catch (PDOException $e) {
                showAlert('Gagal mengubah status: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

try {
    $today = date('Y-m-d');

    // 1. KPI Statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_promos,
            SUM(CASE WHEN status = 'active' AND end_date >= CURDATE() AND start_date <= CURDATE() THEN 1 ELSE 0 END) as active_now,
            SUM(CASE WHEN end_date < CURDATE() THEN 1 ELSE 0 END) as expired_promos,
            SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_promos
        FROM promotions
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'total_promos' => 0,
        'active_now' => 0,
        'expired_promos' => 0,
        'inactive_promos' => 0
    ];

    // 2. Query List
    $whereClauses = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $whereClauses[] = "(title LIKE ? OR promo_badge LIKE ? OR promo_code LIKE ?)";
        $sParam = "%$search%";
        $params[] = $sParam;
        $params[] = $sParam;
        $params[] = $sParam;
    }

    if ($status_filter === 'active_now') {
        $whereClauses[] = "status = 'active' AND end_date >= CURDATE() AND start_date <= CURDATE()";
    } elseif ($status_filter === 'expired') {
        $whereClauses[] = "end_date < CURDATE()";
    } elseif ($status_filter === 'inactive') {
        $whereClauses[] = "status = 'inactive'";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);

    $countStmt = $db->prepare("SELECT COUNT(*) FROM promotions $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);

    $sql = "
        SELECT *,
               DATEDIFF(end_date, CURDATE()) as days_left
        FROM promotions
        $whereSql
        ORDER BY status = 'active' DESC, end_date >= CURDATE() DESC, created_at DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
}
?>

<style>
    /* ===== Promotions Index Specific Styles ===== */
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
    .btn-add-promo {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 18px;
        background: #ef4444;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: background 0.15s, transform 0.1s;
        text-decoration: none;
    }
    .btn-add-promo:hover {
        background: #dc2626;
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
    .summary-icon.red { background: #fee2e2; color: #ef4444; }
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
        width: 180px;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        cursor: pointer;
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
    }
    .btn-reset-search:hover { background: #fee2e2; color: #ef4444; border-color: #fecaca; }

    /* Table Card */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
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
    .vehicles-table tbody tr:hover { background: #f8fafc; }
    .vehicles-table tbody tr:last-child td { border-bottom: none; }

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
    }
    .action-btn-edit:hover { background: rgba(59,130,246,0.12); color: #2563eb; border-color: rgba(59,130,246,0.25); }
    .action-btn-delete:hover { background: rgba(239,68,68,0.12); color: #dc2626; border-color: rgba(239,68,68,0.25); }
    .action-btn-toggle:hover { background: rgba(16,185,129,0.12); color: #059669; border-color: rgba(16,185,129,0.25); }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-icon.slate { background: rgba(148,163,184,0.15); color: #cbd5e1; }
    [data-theme="dark"] .summary-icon.emerald { background: rgba(34,197,94,0.15); color: #86efac; }
    [data-theme="dark"] .summary-icon.red { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .summary-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-label { color: #94a3b8; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }

    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 140px; }
    }
    @media (max-width: 768px) {
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Manajemen Promo & Diskon</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Promo & Diskon</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="create.php" class="btn-add-promo">
            <i class="fas fa-plus"></i> Tambah Promo Baru
        </a>
    </div>
</div>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-tags"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($stats['total_promos']); ?></div>
            <div class="summary-label">Total Promo Terdata</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-fire"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo number_format($stats['active_now']); ?></div>
            <div class="summary-label">Promo Sedang Aktif</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon red">
            <i class="fas fa-percent"></i>
        </div>
        <div>
            <div class="summary-value text-danger"><?php echo number_format($stats['expired_promos']); ?></div>
            <div class="summary-label">Promo Berakhir / Expired</div>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-eye-slash"></i>
        </div>
        <div>
            <div class="summary-value text-warning"><?php echo number_format($stats['inactive_promos']); ?></div>
            <div class="summary-label">Draf / Nonaktif</div>
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
                    <input type="text" name="search" placeholder="Cari nama promo, badge, atau kode promo..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="active_now" <?php echo $status_filter === 'active_now' ? 'selected' : ''; ?>>Sedang Aktif (Live)</option>
                    <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Sudah Berakhir (Expired)</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Nonaktif / Draf</option>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($status_filter)): ?>
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
            <i class="fas fa-bullhorn text-danger me-2"></i>Daftar Event Promo & Diskon
        </h5>
        <span class="badge bg-light text-muted border px-2.5 py-1" style="font-size:0.75rem;">
            <?php echo number_format($totalRecords); ?> Promo
        </span>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="min-width: 220px;">PROMO & BADGE</th>
                        <th style="min-width: 140px;">BESARAN DISKON</th>
                        <th style="min-width: 150px;">CAKUPAN UNIT</th>
                        <th style="min-width: 160px;">PERIODE TAYANG</th>
                        <th style="min-width: 120px;" class="text-center">STATUS LIVE</th>
                        <th style="min-width: 110px;" class="text-center pe-4">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($promotions)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <i class="fas fa-tag fa-2x text-muted opacity-50 mb-2"></i>
                            <div class="fw-bold text-dark-emphasis">Belum ada data promo / event diskon</div>
                            <small class="text-muted">Klik "+ Tambah Promo Baru" untuk membuat penawaran menarik bagi pelanggan.</small>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($promotions as $p): 
                        $isLive = ($p['status'] === 'active' && strtotime($p['end_date']) >= strtotime($today) && strtotime($p['start_date']) <= strtotime($today));
                        $isExpired = (strtotime($p['end_date']) < strtotime($today));
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-dark-emphasis"><?php echo htmlspecialchars($p['title']); ?></div>
                            <div class="d-flex align-items-center gap-2 mt-1">
                                <span class="badge bg-danger bg-opacity-10 text-danger border border-danger-subtle fw-bold" style="font-size: 0.7rem;">
                                    🔥 <?php echo htmlspecialchars($p['promo_badge']); ?>
                                </span>
                                <?php if (!empty($p['promo_code'])): ?>
                                <span class="badge bg-light text-muted border font-monospace" style="font-size: 0.7rem;">
                                    KODE: <?php echo htmlspecialchars($p['promo_code']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($p['discount_type'] === 'percentage'): ?>
                                <span class="fw-bold text-danger" style="font-size: 1rem;">Diskon <?php echo number_format($p['discount_amount'], 0); ?>%</span>
                            <?php else: ?>
                                <span class="fw-bold text-danger" style="font-size: 0.95rem;">Potongan Rp <?php echo number_format($p['discount_amount'], 0, ',', '.'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($p['target_scope'] === 'all_vehicles'): ?>
                                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2 py-1" style="font-size:0.75rem;">Semua Stok Ready</span>
                            <?php elseif ($p['target_scope'] === 'selected_vehicles'): ?>
                                <span class="badge bg-info bg-opacity-10 text-info fw-semibold px-2 py-1" style="font-size:0.75rem;">Unit Pilihan Tertentu</span>
                            <?php else: ?>
                                <span class="badge bg-secondary bg-opacity-10 text-secondary fw-semibold px-2 py-1" style="font-size:0.75rem;">Merek Tertentu</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark-emphasis" style="font-size:0.8rem;">
                                <?php echo date('d M Y', strtotime($p['start_date'])); ?> - <?php echo date('d M Y', strtotime($p['end_date'])); ?>
                            </div>
                            <?php if ($isLive): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-bold" style="font-size:0.7rem;">
                                    <i class="fas fa-clock me-1"></i> Sisa <?php echo (int)$p['days_left']; ?> hari lagi
                                </span>
                            <?php elseif ($isExpired): ?>
                                <span class="badge bg-secondary bg-opacity-10 text-muted" style="font-size:0.7rem;">
                                    Kedaluwarsa
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning bg-opacity-10 text-warning" style="font-size:0.7rem;">
                                    Mulai <?php echo date('d M Y', strtotime($p['start_date'])); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($isLive): ?>
                                <span class="badge bg-success text-white fw-bold px-2.5 py-1" style="font-size:0.72rem; border-radius: 6px;">
                                    <i class="fas fa-bolt me-1"></i> LIVE
                                </span>
                            <?php elseif ($p['status'] === 'inactive'): ?>
                                <span class="badge bg-light text-muted border px-2 py-1" style="font-size:0.72rem; border-radius: 6px;">
                                    Nonaktif
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger fw-semibold px-2 py-1" style="font-size:0.72rem; border-radius: 6px;">
                                    Selesai
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center pe-4">
                            <div class="d-flex align-items-center justify-content-center gap-1">
                                <a href="edit.php?id=<?php echo $p['id']; ?>" class="action-btn action-btn-edit" title="Edit Promo">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-toggle" title="Ubah Status Aktif/Nonaktif" onclick="toggleStatus(<?php echo $p['id']; ?>, '<?php echo $p['status']; ?>')">
                                    <i class="fas <?php echo ($p['status'] === 'active') ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted'; ?>"></i>
                                </button>
                                <button type="button" class="action-btn action-btn-delete" title="Hapus Promo" onclick="confirmDelete(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['title']); ?>')">
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
</div>

<form id="actionForm" method="POST" style="display: none;">
    <input type="hidden" name="action" id="formAction">
    <input type="hidden" name="id" id="formId">
    <input type="hidden" name="status" id="formStatus">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
</form>

<script>
function toggleStatus(id, currentStatus) {
    document.getElementById('formAction').value = 'toggle_status';
    document.getElementById('formId').value = id;
    document.getElementById('formStatus').value = currentStatus;
    document.getElementById('actionForm').submit();
}

function confirmDelete(id, title) {
    if (confirm('Hapus promo "' + title + '"?')) {
        document.getElementById('formAction').value = 'delete_promo';
        document.getElementById('formId').value = id;
        document.getElementById('actionForm').submit();
    }
}
</script>

<?php include '../includes/footer.php'; ?>
