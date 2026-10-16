<?php
// customers/index.php - Data Pelanggan Showroom
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
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 'c.created_at';
$sort_order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

$db_sort_clause = "c.created_at DESC";
if ($sort_column === 'name') {
    $db_sort_clause = "c.name $sort_order";
} elseif ($sort_column === 'c.created_at') {
    $db_sort_clause = "c.created_at $sort_order";
}

try {
    $whereClauses = [];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.id_card LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $whereClause = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    // KPI Stats
    $kpiStmt = $db->query("
        SELECT 
            COUNT(DISTINCT c.id) as total_customers,
            COUNT(DISTINCT t.customer_id) as active_customers,
            COALESCE(SUM(t.total_amount), 0) as total_revenue,
            COALESCE(AVG(t.total_amount), 0) as avg_transaction
        FROM customers c
        LEFT JOIN transactions t ON c.id = t.customer_id
    ");
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    // Get total records
    $countSql = "SELECT COUNT(*) as total FROM customers c $whereClause";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetch()['total'];
    $totalPages = ceil($totalRecords / $limit);
    
    // Get customers with transaction count
    $sql = "
        SELECT c.*,
               COUNT(t.id) as transaction_count,
               COALESCE(SUM(t.total_amount), 0) as total_purchases
        FROM customers c
        LEFT JOIN transactions t ON c.id = t.customer_id
        $whereClause
        GROUP BY c.id
        ORDER BY $db_sort_clause
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid', 'danger');
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $id_card = sanitizeInput($_POST['id_card'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        
        if (empty($name) || empty($phone)) {
            showAlert('Nama dan Nomor Telepon wajib diisi', 'danger');
        } else {
            try {
                $stmt = $db->prepare("INSERT INTO customers (name, phone, id_card, address, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
                if ($stmt->execute([$name, $phone, $id_card, $address])) {
                    showAlert('Pelanggan berhasil ditambahkan');
                    redirect('customers/');
                } else {
                    showAlert('Gagal menambahkan pelanggan', 'danger');
                }
            } catch (PDOException $e) {
                showAlert('Error: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Handle Edit Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_customer') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid', 'danger');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = sanitizeInput($_POST['name'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $id_card = sanitizeInput($_POST['id_card'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        
        if (empty($id) || empty($name) || empty($phone)) {
            showAlert('Nama dan Nomor Telepon wajib diisi', 'danger');
        } else {
            try {
                $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, id_card = ?, address = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $phone, $id_card, $address, $id])) {
                    showAlert('Data pelanggan berhasil diperbarui');
                    redirect('customers/');
                } else {
                    showAlert('Gagal memperbarui data', 'danger');
                }
            } catch (PDOException $e) {
                showAlert('Error: ' . $e->getMessage(), 'danger');
            }
        }
    }
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
    .status-in_progress { background: #fef3c7; color: #92400e; }
    .status-scheduled { background: #e0f2fe; color: #075985; }
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
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
    .action-btn-delete:hover { background: #fee2e2; border-color: #fecaca; color: #dc2626; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-input-group input,
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
        <h1>Data Pelanggan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Pelanggan</li>
            </ol>
        </nav>
    </div>
    <div>
        <button type="button" class="btn-add-vehicle" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
            <i class="fas fa-plus"></i> Tambah Pelanggan
        </button>
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
            <i class="fas fa-users"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['total_customers'] ?? 0); ?></div>
            <div class="summary-label">Total Pelanggan</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-user-check"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['active_customers'] ?? 0); ?></div>
            <div class="summary-label">Pelanggan Aktif</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-wallet"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['total_revenue'] ?? 0); ?></div>
            <div class="summary-label">Total Transaksi</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['avg_transaction'] ?? 0); ?></div>
            <div class="summary-label">Rata-rata Pembelian</div>
        </div>
    </div>
</div>

<!-- Search Card -->
<div class="search-card">
    <form method="GET" action="">
        <div class="search-row">
            <div class="search-input-group">
                <i class="fas fa-search"></i>
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari nama pelanggan, nomor telepon, atau No. KTP...">
            </div>
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Cari
            </button>
            <?php if (!empty($search)): ?>
                <a href="index.php" class="btn-reset-search" title="Reset pencarian">
                    <i class="fas fa-times"></i>
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Pelanggan Showroom</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                <?php echo number_format($totalRecords); ?> Pelanggan
            </span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($customers)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th>NAMA PELANGGAN</th>
                        <th>NOMOR KONTAK</th>
                        <th>NOMOR KTP</th>
                        <th>TOTAL TRANSAKSI</th>
                        <th>TOTAL PEMBELIAN</th>
                        <th>TERDAFTAR</th>
                        <th class="text-end" style="width: 100px;">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $index => $customer): ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $offset + $index + 1; ?></td>
                        <td>
                            <a href="view.php?id=<?php echo $customer['id']; ?>" class="fw-bold text-dark text-decoration-none">
                                <?php echo htmlspecialchars($customer['name']); ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($customer['phone']); ?></div>
                        </td>
                        <td>
                            <?php if (!empty($customer['id_card'])): ?>
                                <span class="plate-badge"><?php echo htmlspecialchars($customer['id_card']); ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge <?php echo $customer['transaction_count'] > 0 ? 'status-completed' : 'status-scheduled'; ?>">
                                <?php echo number_format($customer['transaction_count']); ?> Unit
                            </span>
                        </td>
                        <td>
                            <span class="price-text"><?php echo formatCurrency($customer['total_purchases']); ?></span>
                        </td>
                        <td class="text-muted small text-nowrap"><?php echo formatDate($customer['created_at'], 'd M Y'); ?></td>
                        <td class="text-end">
                            <div class="action-btns">
                                <a href="view.php?id=<?php echo $customer['id']; ?>" class="action-btn action-btn-view" title="Lihat Profil">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" class="action-btn action-btn-edit" title="Edit Data" 
                                        data-customer="<?php echo htmlspecialchars(json_encode($customer), ENT_QUOTES, 'UTF-8'); ?>"
                                        onclick="openEditModal(this)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button type="button" class="action-btn action-btn-delete" title="Hapus Data" onclick="deleteCustomer(<?php echo $customer['id']; ?>)">
                                    <i class="fas fa-trash"></i>
                                </button>
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
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-users fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada data pelanggan ditemukan.</div>
            <small class="text-muted">Klik tombol Tambah Pelanggan di atas untuk mendaftarkan data konsumen baru.</small>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Customer Modal -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">Tambah Pelanggan Baru</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="add_customer">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    
                    <div class="mb-3">
                        <label for="add_name" class="form-label fw-bold small text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_name" name="name" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_phone" class="form-label fw-bold small text-secondary">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="add_phone" name="phone" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_id_card" class="form-label fw-bold small text-secondary">Nomor KTP (NIK)</label>
                        <input type="text" class="form-control" id="add_id_card" name="id_card" style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="add_address" class="form-label fw-bold small text-secondary">Alamat Domisili</label>
                        <textarea class="form-control" id="add_address" name="address" rows="3" style="border-radius:9px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i> Simpan Pelanggan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Customer Modal -->
<div class="modal fade" id="editCustomerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <h5 class="modal-title fw-bold fs-6">Edit Data Pelanggan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_customer">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold small text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label fw-bold small text-secondary">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_phone" name="phone" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_id_card" class="form-label fw-bold small text-secondary">Nomor KTP (NIK)</label>
                        <input type="text" class="form-control" id="edit_id_card" name="id_card" style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_address" class="form-label fw-bold small text-secondary">Alamat Domisili</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3" style="border-radius:9px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i> Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function deleteCustomer(id) {
    if (confirm('Apakah Anda yakin ingin menghapus pelanggan ini?')) {
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

function openEditModal(button) {
    const customer = JSON.parse(button.getAttribute('data-customer'));
    document.getElementById('edit_id').value = customer.id;
    document.getElementById('edit_name').value = customer.name;
    document.getElementById('edit_phone').value = customer.phone;
    document.getElementById('edit_id_card').value = customer.id_card || '';
    document.getElementById('edit_address').value = customer.address || '';
    
    var modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>