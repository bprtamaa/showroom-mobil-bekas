<?php
// transactions/billing.php - Tagihan Cicilan Leasing Showroom
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

if (!defined('RECORDS_PER_PAGE')) {
    define('RECORDS_PER_PAGE', 10);
}

// Handle POST request for updating billing status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_billing') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan tidak valid (CSRF)', 'danger');
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $billing_status = $_POST['billing_status'] ?? '';
        
        if (!$id || empty($billing_status)) {
            showAlert('Data tidak lengkap', 'danger');
        } else {
            try {
                // Determine payment_status based on billing_status
                $payment_status_update = "";
                if ($billing_status === 'paid') {
                    $payment_status_update = ", payment_status = 'paid'";
                } else {
                    // Revert to installment if not paid
                    $payment_status_update = ", payment_status = 'installment'";
                }

                $stmt = $db->prepare("UPDATE transactions 
                                     SET billing_status = ?, 
                                         updated_at = NOW() 
                                         $payment_status_update
                                     WHERE id = ? AND payment_method = 'installment'");
                
                if ($stmt->execute([$billing_status, $id])) {
                    showAlert('Status tagihan berhasil diperbarui!', 'success');
                    redirect('transactions/billing.php');
                } else {
                    showAlert('Gagal memperbarui status tagihan', 'danger');
                }
            } catch (PDOException $e) {
                showAlert('Error: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Handle search, pagination, and sorting
$search = trim($_GET['search'] ?? '');
$leasing_filter = trim($_GET['leasing'] ?? '');
$billing_status_filter = trim($_GET['billing_status'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : RECORDS_PER_PAGE;
if ($limit < 1) $limit = 10;
$offset = ($page - 1) * $limit;

// Sort parameters
$sort_column = $_GET['sort'] ?? 't.transaction_date';
$sort_order = strtoupper($_GET['order'] ?? 'DESC');
if (!in_array($sort_order, ['ASC', 'DESC'])) $sort_order = 'DESC';

$allowed_sort_cols = [
    'transaction_code' => 't.transaction_code',
    'transaction_date' => 't.transaction_date',
    't.transaction_date' => 't.transaction_date',
    'customer_name' => 'c.name',
    'total_amount' => 't.total_amount',
    'billing_status' => 't.billing_status'
];

$db_sort_column = $allowed_sort_cols[$sort_column] ?? 't.transaction_date';
$db_sort_order = $sort_order;

try {
    // KPI Stats (Summary for installment transactions)
    $kpiStmt = $db->query("
        SELECT 
            COUNT(DISTINCT t.id) as total_installments,
            SUM(CASE WHEN t.billing_status = 'pending' OR t.billing_status IS NULL OR t.billing_status = '' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN t.billing_status = 'billed' THEN 1 ELSE 0 END) as billed_count,
            SUM(CASE WHEN t.billing_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
            COALESCE(SUM(t.total_amount), 0) as total_amount
        FROM transactions t
        WHERE t.payment_method = 'installment'
    ");
    $kpi = $kpiStmt->fetch(PDO::FETCH_ASSOC);

    // Prepare query parameters
    $whereClauses = ["t.payment_method = 'installment'"];
    $params = [];
    
    if (!empty($search)) {
        $whereClauses[] = "(t.transaction_code LIKE ? OR c.name LIKE ? OR l.name LIKE ? OR v.license_plate LIKE ? OR v.chassis_number LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($leasing_filter)) {
        $whereClauses[] = "t.leasing_company_id = ?";
        $params[] = $leasing_filter;
    }
    
    if (!empty($billing_status_filter)) {
        if ($billing_status_filter === 'pending') {
            $whereClauses[] = "(t.billing_status = 'pending' OR t.billing_status IS NULL OR t.billing_status = '')";
        } else {
            $whereClauses[] = "t.billing_status = ?";
            $params[] = $billing_status_filter;
        }
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereClauses);
    
    // Get total count
    $countQuery = "SELECT COUNT(DISTINCT t.id) as total 
                   FROM transactions t
                   LEFT JOIN customers c ON t.customer_id = c.id
                   LEFT JOIN leasing l ON t.leasing_company_id = l.id
                   LEFT JOIN vehicles v ON t.vehicle_id = v.id
                   $whereClause";
    $stmt = $db->prepare($countQuery);
    $stmt->execute($params);
    $totalRecords = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalRecords / $limit);
    
    // Get records with vehicle and leasing info
    $query = "SELECT t.*,
                     c.name as customer_name, 
                     c.phone as customer_phone,
                     l.name as leasing_name,
                     l.code as leasing_code,
                     l.contact_person as leasing_contact,
                     l.contact_phone as leasing_phone,
                     u.full_name as user_name,
                     v.id as vehicle_id,
                     v.license_plate,
                     v.year as vehicle_year,
                     b.name as brand_name,
                     m.name as model_name
              FROM transactions t
              LEFT JOIN customers c ON t.customer_id = c.id
              LEFT JOIN leasing l ON t.leasing_company_id = l.id
              LEFT JOIN users u ON t.user_id = u.id
              LEFT JOIN vehicles v ON t.vehicle_id = v.id
              LEFT JOIN models m ON v.model_id = m.id
              LEFT JOIN brands b ON m.brand_id = b.id
              $whereClause 
              GROUP BY t.id
              ORDER BY $db_sort_column $db_sort_order 
              LIMIT $limit OFFSET $offset";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get leasing companies for filter
    $leasingStmt = $db->query("SELECT id, name FROM leasing WHERE status = 'active' ORDER BY name ASC");
    $leasingCompanies = $leasingStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
    $transactions = [];
    $leasingCompanies = [];
    $totalRecords = 0;
    $totalPages = 0;
}
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

    /* ===== Search Card ===== */
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
        width: 170px;
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

    /* ===== Table Card & Styling ===== */
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

    /* Table layout */
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
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Badges & Text */
    .status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 4px 12px;
        border-radius: 6px;
        font-size: 0.73rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .status-completed, .status-paid { background: #dcfce7; color: #166534; }
    .status-billed { background: #e0f2fe; color: #0369a1; }
    .status-pending { background: #fef3c7; color: #92400e; }

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

    /* Action Buttons Column */
    .action-btns {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        white-space: nowrap;
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
    .action-btn-print:hover { background: #f8fafc; border-color: #cbd5e1; color: #334155; }

    /* Modal styling */
    .custom-modal-header {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .custom-modal-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .modal-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .btn-submit-modal {
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        padding: 8px 18px;
        border-radius: 8px;
        border: none;
        transition: all 0.15s;
    }
    .btn-submit-modal:hover {
        background: #1e272e;
        color: #fff;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-icon.slate { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select,
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { background: #162032; border-color: #60a5fa; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search,
    [data-theme="dark"] .btn-reset-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .modal-content { background: #1e293b; color: #f1f5f9; }
    [data-theme="dark"] .custom-modal-header { border-bottom-color: #334155; }
    [data-theme="dark"] .custom-modal-title { color: #f1f5f9; }
    [data-theme="dark"] .modal-info-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .form-select,
    [data-theme="dark"] .form-control { background: #0f172a; border-color: #334155; color: #f1f5f9; }

    /* Responsive */
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 120px; }
    }
    @media (max-width: 576px) {
        .vehicles-page-header { flex-direction: column; gap: 10px; }
        .vehicles-header-actions { width: 100%; justify-content: flex-start; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .vehicles-table thead th,
        .vehicles-table tbody td { padding: 10px 8px; }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Tagihan Cicilan Leasing</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>transactions/" class="text-primary text-decoration-underline">Transaksi Penjualan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Tagihan Cicilan Leasing</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>transactions/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Transaksi Penjualan
        </a>
        <a href="<?php echo BASE_URL; ?>transactions/create.php" class="btn-add-vehicle">
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
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['total_installments'] ?? 0); ?></div>
            <div class="summary-label">Total Tagihan Cicilan</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-clock"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['pending_count'] ?? 0); ?></div>
            <div class="summary-label">Menunggu Tagihan</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-paper-plane"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($kpi['billed_count'] ?? 0); ?></div>
            <div class="summary-label">Sudah Ditagih</div>
        </div>
    </div>
    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-check-circle"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($kpi['total_amount'] ?? 0); ?></div>
            <div class="summary-label">Total Nilai Tagihan</div>
        </div>
    </div>
</div>

<!-- Search & Filter Card (Matching Stok Mobil layout) -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <div class="search-input-group">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Cari kode transaksi, pelanggan, leasing, atau plat mobil...">
                </div>
                
                <select name="leasing" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Leasing</option>
                    <?php foreach ($leasingCompanies as $leasing): ?>
                        <option value="<?php echo $leasing['id']; ?>" <?php echo $leasing_filter == $leasing['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($leasing['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select name="billing_status" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="pending" <?php echo $billing_status_filter === 'pending' ? 'selected' : ''; ?>>Menunggu Tagihan</option>
                    <option value="billed" <?php echo $billing_status_filter === 'billed' ? 'selected' : ''; ?>>Sudah Ditagih</option>
                    <option value="paid" <?php echo $billing_status_filter === 'paid' ? 'selected' : ''; ?>>Lunas</option>
                </select>

                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($leasing_filter) || !empty($billing_status_filter)): ?>
                    <a href="billing.php" class="btn-reset-search" title="Reset filter">
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
        <h5 class="table-card-title">Daftar Tagihan Cicilan</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                <?php echo number_format($totalRecords); ?> Transaksi
            </span>
            <select class="limit-select" onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&leasing=<?php echo urlencode($leasing_filter); ?>&billing_status=<?php echo urlencode($billing_status_filter); ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>'">
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
                        <th style="min-width: 140px;">KODE & TANGGAL</th>
                        <th style="min-width: 150px;">PELANGGAN</th>
                        <th style="min-width: 160px;">UNIT MOBIL</th>
                        <th style="min-width: 150px;">LEASING</th>
                        <th style="min-width: 130px;">TOTAL TAGIHAN</th>
                        <th style="min-width: 130px;" class="text-center">STATUS TAGIHAN</th>
                        <th style="width: 120px; min-width: 120px;" class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($transactions as $index => $tx): 
                        $bStatus = $tx['billing_status'] ?? 'pending';
                        if (empty($bStatus)) $bStatus = 'pending';
                        
                        $statusBadgeClass = 'status-pending';
                        $statusLabelText = 'Menunggu';
                        
                        switch($bStatus) {
                            case 'paid':
                                $statusBadgeClass = 'status-completed';
                                $statusLabelText = 'Lunas';
                                break;
                            case 'billed':
                                $statusBadgeClass = 'status-billed';
                                $statusLabelText = 'Sudah Ditagih';
                                break;
                            case 'pending':
                            default:
                                $statusBadgeClass = 'status-pending';
                                $statusLabelText = 'Menunggu Tagihan';
                                break;
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
                            <div class="text-muted small text-nowrap">
                                <?php if (!empty($tx['customer_phone'])): ?>
                                    <i class="fas fa-phone-alt me-1" style="font-size:0.7rem;"></i><?php echo htmlspecialchars($tx['customer_phone']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap">
                                <?php echo htmlspecialchars(trim(($tx['brand_name'] ?? '') . ' ' . ($tx['model_name'] ?? ''))); ?>
                            </div>
                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($tx['license_plate'] ?: 'No Plat'); ?></span>
                                <?php if (!empty($tx['vehicle_year'])): ?>
                                <span class="text-muted small text-nowrap ms-1">• <?php echo $tx['vehicle_year']; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap">
                                <?php echo htmlspecialchars($tx['leasing_name'] ?: ($tx['leasing_code'] ?: '-')); ?>
                            </div>
                            <?php if (!empty($tx['leasing_contact']) || !empty($tx['leasing_phone'])): ?>
                            <div class="text-muted small text-nowrap">
                                <?php echo htmlspecialchars($tx['leasing_contact'] ?: $tx['leasing_phone']); ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($tx['total_amount']); ?></div>
                            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Cicilan</small>
                        </td>
                        <td class="text-center">
                            <span class="status-badge <?php echo $statusBadgeClass; ?>">
                                <?php echo $statusLabelText; ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="action-btns">
                                <button type="button" 
                                        class="action-btn action-btn-edit btn-edit-billing" 
                                        title="Update Status Tagihan"
                                        data-id="<?php echo $tx['id']; ?>"
                                        data-code="<?php echo htmlspecialchars($tx['transaction_code']); ?>"
                                        data-customer="<?php echo htmlspecialchars($tx['customer_name']); ?>"
                                        data-leasing="<?php echo htmlspecialchars($tx['leasing_name'] ?: '-'); ?>"
                                        data-amount="<?php echo $tx['total_amount']; ?>"
                                        data-status="<?php echo $tx['billing_status'] ?? 'pending'; ?>">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="view.php?id=<?php echo $tx['id']; ?>" class="action-btn action-btn-view" title="Lihat Detail Transaksi">
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
                        <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&leasing=<?php echo urlencode($leasing_filter); ?>&billing_status=<?php echo urlencode($billing_status_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&leasing=<?php echo urlencode($leasing_filter); ?>&billing_status=<?php echo urlencode($billing_status_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <?php echo $i; ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&leasing=<?php echo urlencode($leasing_filter); ?>&billing_status=<?php echo urlencode($billing_status_filter); ?>&limit=<?php echo $limit; ?>&sort=<?php echo $sort_column; ?>&order=<?php echo $sort_order; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-file-invoice-dollar fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada data tagihan cicilan ditemukan.</div>
            <small class="text-muted">Gunakan filter pencarian atau buat transaksi cicilan baru.</small>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Edit Billing Modal -->
<div class="modal fade" id="editBillingModal" tabindex="-1" aria-labelledby="editBillingModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="custom-modal-header">
                <h5 class="custom-modal-title" id="editBillingModalLabel">
                    <i class="fas fa-edit me-2 text-primary"></i>Update Status Tagihan
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" id="editBillingForm">
                <div class="modal-body p-4">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_billing">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <!-- Transaction Info Card -->
                    <div class="modal-info-box mb-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <small class="text-muted d-block" style="font-size:0.75rem;">Kode Transaksi</small>
                                <strong id="edit_transaction_code" class="text-dark"></strong>
                            </div>
                            <div class="col-6">
                                <small class="text-muted d-block" style="font-size:0.75rem;">Pelanggan</small>
                                <strong id="edit_customer_name" class="text-dark"></strong>
                            </div>
                            <div class="col-6 mt-2">
                                <small class="text-muted d-block" style="font-size:0.75rem;">Leasing</small>
                                <strong id="edit_leasing_name" class="text-dark"></strong>
                            </div>
                            <div class="col-6 mt-2">
                                <small class="text-muted d-block" style="font-size:0.75rem;">Total Tagihan</small>
                                <strong class="text-success" id="edit_total_amount"></strong>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Form Fields -->
                    <div>
                        <label class="form-label fw-bold small text-muted text-uppercase mb-1">Status Tagihan <span class="text-danger">*</span></label>
                        <select name="billing_status" id="edit_billing_status" class="form-select" required>
                            <option value="">-- Pilih Status --</option>
                            <option value="pending">Menunggu Tagihan</option>
                            <option value="billed">Sudah Ditagih</option>
                            <option value="paid">Lunas</option>
                        </select>
                        <small class="text-muted mt-1 d-block" style="font-size: 0.75rem;">
                            * Memilih "Lunas" akan otomatis mengupdate status pembayaran transaksi menjadi lunas.
                        </small>
                    </div>
                </div>
                <div class="modal-footer border-top bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3 rounded-2" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-submit-modal">
                        <i class="fas fa-save me-1"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Event delegation for edit buttons
    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-edit-billing');
        if (btn) {
            const id = btn.getAttribute('data-id');
            const code = btn.getAttribute('data-code');
            const customer = btn.getAttribute('data-customer');
            const leasing = btn.getAttribute('data-leasing');
            const amount = parseFloat(btn.getAttribute('data-amount')) || 0;
            const status = btn.getAttribute('data-status');
            
            // Populate form fields
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_transaction_code').textContent = code;
            document.getElementById('edit_customer_name').textContent = customer;
            document.getElementById('edit_leasing_name').textContent = leasing;
            
            // Format currency for display
            const formatter = new Intl.NumberFormat('id-ID', {
                style: 'currency',
                currency: 'IDR',
                minimumFractionDigits: 0
            });
            document.getElementById('edit_total_amount').textContent = formatter.format(amount);
            
            // Set form values
            document.getElementById('edit_billing_status').value = status || 'pending';
            
            // Show modal
            const modalElement = document.getElementById('editBillingModal');
            let modal = bootstrap.Modal.getInstance(modalElement);
            if (!modal) {
                modal = new bootstrap.Modal(modalElement);
            }
            modal.show();
        }
    });
});
</script>

<?php include '../includes/footer.php'; ?>
