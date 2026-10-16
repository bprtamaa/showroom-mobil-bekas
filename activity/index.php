<?php
// activity/index.php - Halaman Riwayat Aktivitas
$pageTitle = 'Riwayat Aktivitas';
include '../includes/header.php';
requireLogin();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;
$filter = $_GET['filter'] ?? '';

try {
    // Build activities from existing tables using UNION
    $filterClause = '';
    $filterParams = [];

    if ($filter === 'vehicle') {
        $filterClause = "HAVING type = 'vehicle_added'";
    } elseif ($filter === 'transaction') {
        $filterClause = "HAVING type = 'transaction'";
    } elseif ($filter === 'maintenance') {
        $filterClause = "HAVING type = 'maintenance'";
    } elseif ($filter === 'customer') {
        $filterClause = "HAVING type = 'customer'";
    }

    // Count total for pagination
    $countSql = "
        SELECT COUNT(*) as total FROM (
            SELECT v.id, 'vehicle_added' as type
            FROM vehicles v
            JOIN models mo ON v.model_id = mo.id
            JOIN brands b ON mo.brand_id = b.id
            " . ($filter === 'vehicle' || $filter === '' ? '' : 'WHERE 1=0') . "

            UNION ALL

            SELECT t.id, 'transaction' as type
            FROM transactions t
            JOIN customers c ON t.customer_id = c.id
            JOIN vehicles v ON t.vehicle_id = v.id
            JOIN models mo ON v.model_id = mo.id
            JOIN brands b ON mo.brand_id = b.id
            " . ($filter === 'transaction' || $filter === '' ? '' : 'WHERE 1=0') . "

            UNION ALL

            SELECT mt.id, 'maintenance' as type
            FROM maintenance mt
            JOIN vehicles v ON mt.vehicle_id = v.id
            JOIN models mo ON v.model_id = mo.id
            JOIN brands b ON mo.brand_id = b.id
            " . ($filter === 'maintenance' || $filter === '' ? '' : 'WHERE 1=0') . "

            UNION ALL

            SELECT c.id, 'customer' as type
            FROM customers c
            " . ($filter === 'customer' || $filter === '' ? '' : 'WHERE 1=0') . "
        ) as activity_count
    ";
    $stmt = $db->query($countSql);
    $totalRecords = (int)$stmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);

    // Get activities
    $sql = "
        SELECT 
            'vehicle_added' as type,
            v.created_at as activity_date,
            CONCAT(b.name, ' ', mo.name, ' ', COALESCE(v.year, '')) as title,
            CONCAT('Mobil baru ditambahkan ke stok — ', v.license_plate) as description,
            v.id as ref_id,
            'vehicles' as ref_module
        FROM vehicles v
        JOIN models mo ON v.model_id = mo.id
        JOIN brands b ON mo.brand_id = b.id
        " . ($filter === 'vehicle' || $filter === '' ? '' : 'WHERE 1=0') . "

        UNION ALL

        SELECT 
            'transaction' as type,
            t.created_at as activity_date,
            CONCAT('Transaksi ', t.transaction_code) as title,
            CONCAT(c.name, ' membeli ', b.name, ' ', mo.name, ' — Rp ', FORMAT(t.total_amount, 0)) as description,
            t.id as ref_id,
            'transactions' as ref_module
        FROM transactions t
        JOIN customers c ON t.customer_id = c.id
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN models mo ON v.model_id = mo.id
        JOIN brands b ON mo.brand_id = b.id
        " . ($filter === 'transaction' || $filter === '' ? '' : 'WHERE 1=0') . "

        UNION ALL

        SELECT 
            'maintenance' as type,
            mt.created_at as activity_date,
            CONCAT('Perawatan ', b.name, ' ', mo.name) as title,
            CONCAT('Biaya: Rp ', FORMAT(mt.cost, 0), ' — ', v.license_plate) as description,
            mt.id as ref_id,
            'maintenance' as ref_module
        FROM maintenance mt
        JOIN vehicles v ON mt.vehicle_id = v.id
        JOIN models mo ON v.model_id = mo.id
        JOIN brands b ON mo.brand_id = b.id
        " . ($filter === 'maintenance' || $filter === '' ? '' : 'WHERE 1=0') . "

        UNION ALL

        SELECT 
            'customer' as type,
            c.created_at as activity_date,
            CONCAT('Pelanggan baru: ', c.name) as title,
            CONCAT('No. HP: ', c.phone) as description,
            c.id as ref_id,
            'customers' as ref_module
        FROM customers c
        " . ($filter === 'customer' || $filter === '' ? '' : 'WHERE 1=0') . "

        ORDER BY activity_date DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->query($sql);
    $activities = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Gagal memuat aktivitas: " . $e->getMessage();
    $activities = [];
    $totalRecords = 0;
    $totalPages = 0;
}

$typeConfig = [
    'vehicle_added' => ['icon' => 'fas fa-car', 'color' => '#3b82f6', 'bg' => 'rgba(59,130,246,0.1)', 'label' => 'Stok Baru'],
    'transaction'   => ['icon' => 'fas fa-shopping-cart', 'color' => '#10b981', 'bg' => 'rgba(16,185,129,0.1)', 'label' => 'Penjualan'],
    'maintenance'   => ['icon' => 'fas fa-wrench', 'color' => '#f59e0b', 'bg' => 'rgba(245,158,11,0.1)', 'label' => 'Perawatan'],
    'customer'      => ['icon' => 'fas fa-user-plus', 'color' => '#8b5cf6', 'bg' => 'rgba(139,92,246,0.1)', 'label' => 'Pelanggan Baru'],
];
?>

<style>
.activity-page-header { margin-bottom: 2rem; padding-top: 1rem; }
.activity-page-header h1 { font-weight: 800; color: #1e293b; font-size: 1.6rem; letter-spacing: -0.025em; }
.activity-page-header .sub { font-size: 0.9rem; color: #64748b; margin-top: 0.25rem; }

.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 1.5rem; }
.filter-pill { padding: 6px 16px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; text-decoration: none; border: 1px solid #e2e8f0; color: #64748b; background: #fff; transition: all 0.2s; }
.filter-pill:hover { border-color: #cbd5e1; color: #334155; }
.filter-pill.active { background: #1e293b; color: #fff; border-color: #1e293b; }

.timeline { position: relative; padding-left: 32px; }
.timeline::before { content: ''; position: absolute; left: 15px; top: 0; bottom: 0; width: 2px; background: #e2e8f0; }

.timeline-item { position: relative; margin-bottom: 0; padding: 16px 0; }
.timeline-item:last-child { margin-bottom: 0; }
.timeline-dot { position: absolute; left: -32px; top: 20px; width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; z-index: 1; }
.timeline-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; transition: all 0.2s; }
.timeline-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.06); border-color: #cbd5e1; }
.timeline-title { font-weight: 600; font-size: 0.9rem; color: #1e293b; margin-bottom: 2px; }
.timeline-desc { font-size: 0.8rem; color: #64748b; margin-bottom: 4px; }
.timeline-meta { display: flex; align-items: center; gap: 12px; font-size: 0.7rem; color: #94a3b8; }
.timeline-badge { padding: 2px 8px; border-radius: 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }

.pagination-wrap { display: flex; justify-content: center; margin-top: 2rem; }
.pagination-wrap .page-link { border-radius: 8px; margin: 0 3px; font-size: 0.85rem; color: #64748b; border: 1px solid #e2e8f0; }
.pagination-wrap .page-item.active .page-link { background: #1e293b; border-color: #1e293b; }

.empty-state { text-align: center; padding: 60px 20px; }
.empty-state i { font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem; }
.empty-state p { color: #94a3b8; font-size: 0.9rem; }

@media (max-width: 576px) {
    .timeline { padding-left: 24px; }
    .timeline::before { left: 11px; }
    .timeline-dot { left: -24px; width: 24px; height: 24px; font-size: 0.6rem; }
    .timeline-card { padding: 12px 14px; }
    .activity-page-header h1 { font-size: 1.3rem; }
}
</style>

<div class="activity-page-header">
    <div class="row align-items-center">
        <div class="col">
            <h1>Riwayat Aktivitas</h1>
            <div class="sub">Semua aktivitas terbaru di showroom</div>
        </div>
        <div class="col-auto">
            <span class="badge bg-white text-dark shadow-sm px-3 py-2 fw-normal">
                <i class="fas fa-list me-1"></i> <?php echo number_format($totalRecords); ?> aktivitas
            </span>
        </div>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?></div>
<?php endif; ?>

<!-- Filter Pills -->
<div class="filter-pills">
    <a href="<?php echo BASE_URL; ?>activity/" class="filter-pill <?php echo $filter === '' ? 'active' : ''; ?>">Semua</a>
    <a href="<?php echo BASE_URL; ?>activity/?filter=vehicle" class="filter-pill <?php echo $filter === 'vehicle' ? 'active' : ''; ?>"><i class="fas fa-car me-1"></i> Stok Baru</a>
    <a href="<?php echo BASE_URL; ?>activity/?filter=transaction" class="filter-pill <?php echo $filter === 'transaction' ? 'active' : ''; ?>"><i class="fas fa-shopping-cart me-1"></i> Penjualan</a>
    <a href="<?php echo BASE_URL; ?>activity/?filter=maintenance" class="filter-pill <?php echo $filter === 'maintenance' ? 'active' : ''; ?>"><i class="fas fa-wrench me-1"></i> Perawatan</a>
    <a href="<?php echo BASE_URL; ?>activity/?filter=customer" class="filter-pill <?php echo $filter === 'customer' ? 'active' : ''; ?>"><i class="fas fa-user me-1"></i> Pelanggan</a>
</div>

<?php if (!empty($activities)): ?>
<div class="timeline">
    <?php 
    $prevDate = '';
    foreach ($activities as $act): 
        $cfg = $typeConfig[$act['type']] ?? $typeConfig['vehicle_added'];
        $actDate = date('Y-m-d', strtotime($act['activity_date']));
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        if ($actDate !== $prevDate) {
            if ($actDate === $today) {
                $dateLabel = 'Hari Ini';
            } elseif ($actDate === $yesterday) {
                $dateLabel = 'Kemarin';
            } else {
                $dateLabel = formatDate($actDate, 'd M Y');
            }
            $prevDate = $actDate;
    ?>
    <div style="margin-left: -32px; margin-bottom: 8px; <?php echo $act !== $activities[0] ? 'margin-top: 12px;' : ''; ?>">
        <small class="fw-bold text-muted text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.08em;"><?php echo $dateLabel; ?></small>
    </div>
    <?php } ?>

    <div class="timeline-item">
        <div class="timeline-dot" style="background: <?php echo $cfg['bg']; ?>; color: <?php echo $cfg['color']; ?>;">
            <i class="<?php echo $cfg['icon']; ?>"></i>
        </div>
        <div class="timeline-card">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="timeline-title"><?php echo htmlspecialchars($act['title']); ?></div>
                    <div class="timeline-desc"><?php echo htmlspecialchars($act['description']); ?></div>
                </div>
                <span class="timeline-badge" style="background: <?php echo $cfg['bg']; ?>; color: <?php echo $cfg['color']; ?>;"><?php echo $cfg['label']; ?></span>
            </div>
            <div class="timeline-meta">
                <span><i class="far fa-clock me-1"></i><?php echo formatDate($act['activity_date'], 'd M Y H:i'); ?></span>
                <?php if ($act['ref_module'] === 'transactions'): ?>
                <a href="<?php echo BASE_URL . 'transactions/view.php?id=' . $act['ref_id']; ?>" class="text-decoration-none" style="color: <?php echo $cfg['color']; ?>;">Lihat Detail →</a>
                <?php elseif ($act['ref_module'] === 'vehicles'): ?>
                <a href="<?php echo BASE_URL . 'vehicles/view.php?id=' . $act['ref_id']; ?>" class="text-decoration-none" style="color: <?php echo $cfg['color']; ?>;">Lihat Detail →</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="pagination-wrap">
    <ul class="pagination pagination-sm">
        <?php if ($page > 1): ?>
        <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?>&filter=<?php echo urlencode($filter); ?>">←</a></li>
        <?php endif; ?>
        
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
            <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo urlencode($filter); ?>"><?php echo $i; ?></a>
        </li>
        <?php endfor; ?>
        
        <?php if ($page < $totalPages): ?>
        <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?>&filter=<?php echo urlencode($filter); ?>">→</a></li>
        <?php endif; ?>
    </ul>
</nav>
<?php endif; ?>

<?php else: ?>
<div class="empty-state">
    <i class="fas fa-clock"></i>
    <p>Belum ada aktivitas yang tercatat</p>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>
