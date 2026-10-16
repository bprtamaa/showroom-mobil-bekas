<?php
// expenses/index.php - Daftar & Manajemen Buku Kas Operasional Showroom
require_once '../config/config.php';
require_once '../includes/header.php';
require_once '../includes/aging_helpers.php';
requireLogin();

$pageTitle = 'Kas Operasional';
$categories = getExpenseCategories();

// Generate auto expense code: EXP-YYYYMM-XXXX
$currentYearMonth = date('Ym');
try {
    $stmtCode = $db->prepare("SELECT expense_code FROM expenses WHERE expense_code LIKE ? ORDER BY id DESC LIMIT 1");
    $stmtCode->execute(["EXP-{$currentYearMonth}-%"]);
    $lastCode = $stmtCode->fetchColumn();

    if ($lastCode) {
        $parts = explode('-', $lastCode);
        $seq = (int)end($parts) + 1;
    } else {
        $seq = 1;
    }
    $autoCode = sprintf("EXP-%s-%03d", $currentYearMonth, $seq);
} catch (Exception $e) {
    $autoCode = "EXP-" . date('YmdHis');
}

// Handle POST actions: Create & Update via Pop-up Modal
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        showAlert('Token keamanan CSRF tidak valid. Silakan muat ulang halaman.', 'danger');
        redirect('expenses/');
    }

    $formAction = $_POST['form_action'] ?? 'create';

    if ($formAction === 'create') {
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $category = trim($_POST['category'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $rawAmount = str_replace(['Rp', '.', ',', ' '], '', $_POST['amount'] ?? '0');
        $amount = (float)$rawAmount;
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $notes = trim($_POST['notes'] ?? '');

        if (empty($title)) {
            showAlert('Judul pengeluaran wajib diisi.', 'danger');
            redirect('expenses/');
        }
        if (empty($category) || !array_key_exists($category, $categories)) {
            showAlert('Kategori pengeluaran tidak valid.', 'danger');
            redirect('expenses/');
        }
        if ($amount <= 0) {
            showAlert('Nominal pengeluaran harus lebih besar dari 0.', 'danger');
            redirect('expenses/');
        }

        // Upload receipt file if present
        $receiptFileName = null;
        if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $fileExt = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));

            if (!in_array($fileExt, $allowedExts)) {
                showAlert('Format berkas bukti tidak didukung. Gunakan JPG, PNG, atau PDF.', 'danger');
                redirect('expenses/');
            } elseif ($_FILES['receipt_file']['size'] > 5 * 1024 * 1024) {
                showAlert('Ukuran berkas bukti maksimal 5MB.', 'danger');
                redirect('expenses/');
            } else {
                $receiptFileName = 'receipt_' . uniqid() . '.' . $fileExt;
                $destination = $uploadDir . $receiptFileName;
                move_uploaded_file($_FILES['receipt_file']['tmp_name'], $destination);
            }
        }

        try {
            $stmt = $db->prepare("
                INSERT INTO expenses (expense_code, expense_date, category, title, amount, payment_method, receipt_file, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $autoCode,
                $expenseDate,
                $category,
                $title,
                $amount,
                $paymentMethod,
                $receiptFileName,
                $notes,
                $_SESSION['user_id'] ?? null
            ]);

            showAlert('Pengeluaran kas berhasil dicatat ke sistem.', 'success');
            redirect('expenses/');
        } catch (PDOException $e) {
            showAlert('Gagal menyimpan pengeluaran: ' . $e->getMessage(), 'danger');
            redirect('expenses/');
        }
    } elseif ($formAction === 'update') {
        $editId = (int)($_POST['edit_id'] ?? 0);
        $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $category = trim($_POST['category'] ?? '');
        $title = trim($_POST['title'] ?? '');
        $rawAmount = str_replace(['Rp', '.', ',', ' '], '', $_POST['amount'] ?? '0');
        $amount = (float)$rawAmount;
        $paymentMethod = trim($_POST['payment_method'] ?? 'cash');
        $notes = trim($_POST['notes'] ?? '');

        if ($editId <= 0 || empty($title) || empty($category) || $amount <= 0) {
            showAlert('Data pengeluaran tidak valid atau belum lengkap.', 'danger');
            redirect('expenses/');
        }

        // Check existing receipt
        $stmtEx = $db->prepare("SELECT receipt_file FROM expenses WHERE id = ?");
        $stmtEx->execute([$editId]);
        $existingReceipt = $stmtEx->fetchColumn();

        $receiptFileName = $existingReceipt;
        if (!empty($_FILES['receipt_file']['name']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../uploads/expenses/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
            $fileExt = strtolower(pathinfo($_FILES['receipt_file']['name'], PATHINFO_EXTENSION));

            if (in_array($fileExt, $allowedExts) && $_FILES['receipt_file']['size'] <= 5 * 1024 * 1024) {
                if (!empty($existingReceipt) && file_exists($uploadDir . $existingReceipt)) {
                    @unlink($uploadDir . $existingReceipt);
                }
                $receiptFileName = 'receipt_' . uniqid() . '.' . $fileExt;
                move_uploaded_file($_FILES['receipt_file']['tmp_name'], $uploadDir . $receiptFileName);
            }
        }

        try {
            $stmt = $db->prepare("
                UPDATE expenses 
                SET expense_date = ?, category = ?, title = ?, amount = ?, payment_method = ?, receipt_file = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $expenseDate,
                $category,
                $title,
                $amount,
                $paymentMethod,
                $receiptFileName,
                $notes,
                $editId
            ]);

            showAlert('Data pengeluaran berhasil diperbarui.', 'success');
            redirect('expenses/');
        } catch (PDOException $e) {
            showAlert('Gagal memperbarui pengeluaran: ' . $e->getMessage(), 'danger');
            redirect('expenses/');
        }
    }
}

// Filter parameters
$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$paymentMethodFilter = trim($_GET['payment_method'] ?? '');
$selectedMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selectedYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$limit = isset($_GET['limit']) && in_array((int)$_GET['limit'], [10, 15, 25, 50, 100]) ? (int)$_GET['limit'] : 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Fetch summary metrics
try {
    // 1. Total pengeluaran bulan terpilih
    $stmtMonth = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_month, COUNT(*) as count_month
        FROM expenses 
        WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?
    ");
    $stmtMonth->execute([$selectedMonth, $selectedYear]);
    $monthStat = $stmtMonth->fetch(PDO::FETCH_ASSOC);
    $totalMonth = (float)$monthStat['total_month'];
    $countMonth = (int)$monthStat['count_month'];

    // 2. Total pengeluaran hari ini
    $stmtToday = $db->query("
        SELECT COALESCE(SUM(amount), 0) as total_today, COUNT(*) as count_today
        FROM expenses 
        WHERE expense_date = CURDATE()
    ");
    $todayStat = $stmtToday->fetch(PDO::FETCH_ASSOC);
    $totalToday = (float)$todayStat['total_today'];

    // 3. Total pengeluaran tahun berjalan
    $stmtYear = $db->prepare("
        SELECT COALESCE(SUM(amount), 0) as total_year
        FROM expenses 
        WHERE YEAR(expense_date) = ?
    ");
    $stmtYear->execute([$selectedYear]);
    $totalYear = (float)$stmtYear->fetch(PDO::FETCH_ASSOC)['total_year'];

    // 4. Kategori terbesar di bulan ini
    $stmtTopCat = $db->prepare("
        SELECT category, SUM(amount) as total_cat
        FROM expenses 
        WHERE MONTH(expense_date) = ? AND YEAR(expense_date) = ?
        GROUP BY category
        ORDER BY total_cat DESC
        LIMIT 1
    ");
    $stmtTopCat->execute([$selectedMonth, $selectedYear]);
    $topCat = $stmtTopCat->fetch(PDO::FETCH_ASSOC);

    // Build filter query for expenses list
    $where = ["1=1"];
    $params = [];

    if ($selectedMonth > 0) {
        $where[] = "MONTH(e.expense_date) = ?";
        $params[] = $selectedMonth;
    }
    if ($selectedYear > 0) {
        $where[] = "YEAR(e.expense_date) = ?";
        $params[] = $selectedYear;
    }
    if (!empty($categoryFilter)) {
        $where[] = "e.category = ?";
        $params[] = $categoryFilter;
    }
    if (!empty($paymentMethodFilter)) {
        $where[] = "e.payment_method = ?";
        $params[] = $paymentMethodFilter;
    }
    if (!empty($search)) {
        $where[] = "(e.expense_code LIKE ? OR e.title LIKE ? OR e.notes LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSql = implode(' AND ', $where);

    // Count records
    $countStmt = $db->prepare("SELECT COUNT(*) FROM expenses e WHERE $whereSql");
    $countStmt->execute($params);
    $totalRecords = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRecords / $limit));

    // Get paginated expenses
    $sql = "
        SELECT e.*, u.full_name as creator_name, u.username as creator_username
        FROM expenses e
        LEFT JOIN users u ON e.created_by = u.id
        WHERE $whereSql
        ORDER BY e.expense_date DESC, e.id DESC
        LIMIT $limit OFFSET $offset
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // List of available years for filter
    $yearStmt = $db->query("SELECT DISTINCT YEAR(expense_date) as y FROM expenses ORDER BY y DESC");
    $availableYears = $yearStmt->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array(date('Y'), $availableYears)) {
        $availableYears[] = date('Y');
        rsort($availableYears);
    }
} catch (PDOException $e) {
    $expenses = [];
    $totalRecords = 0;
    $totalPages = 1;
    $availableYears = [date('Y')];
    $totalMonth = 0;
    $countMonth = 0;
    $totalToday = 0;
    $totalYear = 0;
    $topCat = null;
}

$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
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

    /* ===== Summary Cards Grid ===== */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 16px;
        margin-bottom: 24px;
    }
    .summary-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        gap: 16px;
        transition: box-shadow 0.2s, transform 0.15s;
    }
    .summary-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transform: translateY(-2px);
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
    .summary-icon.emerald { background: #ecfdf5; color: #059669; }
    .summary-icon.blue { background: #eff6ff; color: #2563eb; }
    .summary-icon.amber { background: #fffbeb; color: #d97706; }
    .summary-icon.rose { background: #fff1f2; color: #e11d48; }
    .summary-value {
        font-size: 1.25rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.2;
    }
    .summary-label {
        font-size: 0.75rem;
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
    .search-card .card-body {
        padding: 14px 18px;
    }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    .search-input-group {
        position: relative;
        flex: 1;
        min-width: 200px;
    }
    .search-input-group i {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 0.85rem;
        pointer-events: none;
    }
    .search-input-group input {
        width: 100%;
        padding: 9px 14px 9px 38px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        color: #334155;
        background: #f8fafc;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .search-input-group input:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(148,163,184,0.15);
        outline: none;
    }
    .search-input-group input::placeholder {
        color: #94a3b8;
        font-size: 0.82rem;
    }
    .filter-select {
        min-width: 140px;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        cursor: pointer;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .filter-select:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(148,163,184,0.15);
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
    .btn-search:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
    }
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
        flex-shrink: 0;
    }
    .btn-reset-search:hover {
        background: #fef2f2;
        border-color: #fecaca;
        color: #ef4444;
    }

    /* ===== Table Card ===== */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 24px;
    }
    .table-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
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
        font-size: 0.75rem;
        font-weight: 600;
        color: #64748b;
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 20px;
    }
    .limit-select {
        padding: 5px 10px;
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
        min-width: 1050px;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 12px 14px;
        font-size: 0.72rem;
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
        white-space: nowrap;
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

    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
        display: inline-block;
    }

    /* No Column */
    .col-no { width: 50px; text-align: center; }
    .col-no-val { color: #94a3b8; font-weight: 500; font-size: 0.8rem; text-align: center; white-space: nowrap; }

    /* Action Buttons */
    .action-btns {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        justify-content: flex-end;
        white-space: nowrap;
        flex-wrap: nowrap;
    }
    .action-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 6px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        font-size: 0.75rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        flex-shrink: 0;
    }
    .action-btn:hover { transform: translateY(-1px); }
    .action-btn-view:hover { background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }
    .action-btn-edit:hover { background: #eff6ff; border-color: #bfdbfe; color: #2563eb; }
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

    /* Form & Modal Styling */
    .form-label-custom {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .form-label-custom .req { color: #ef4444; }
    .form-control-custom,
    .form-select-custom {
        width: 100%;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        transition: all 0.15s ease;
    }
    .form-control-custom:focus,
    .form-select-custom:focus {
        border-color: #3b82f6;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        outline: none;
    }
    .btn-submit-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 22px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
    }
    .btn-submit-action:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-cancel-action {
        display: inline-flex;
        align-items: center;
        padding: 9px 18px;
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #64748b;
        font-weight: 600;
        font-size: 0.85rem;
        border-radius: 10px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .btn-cancel-action:hover {
        background: #f8fafc;
        color: #334155;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 48px 24px;
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
    .btn-cancel {
        padding: 9px 16px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
        color: #64748b;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-cancel:hover { background: #f1f5f9; color: #334155; }

    /* Modal Title Icon */
    .modal-title-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
    }
    .modal-title-icon.primary { background: #eff6ff; color: #2563eb; }
    .modal-title-icon.success { background: #ecfdf5; color: #059669; }
    .modal-title-icon.danger { background: #fef2f2; color: #dc2626; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .modal-title-icon.primary { background: rgba(59,130,246,0.15); color: #93c5fd; }
    [data-theme="dark"] .modal-title-icon.danger { background: rgba(239,68,68,0.15); color: #fca5a5; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-icon.slate { background: rgba(148,163,184,0.15); color: #cbd5e1; }
    [data-theme="dark"] .summary-icon.emerald { background: rgba(34,197,94,0.15); color: #86efac; }
    [data-theme="dark"] .summary-icon.blue { background: rgba(2,132,199,0.15); color: #7dd3fc; }
    [data-theme="dark"] .summary-icon.amber { background: rgba(245,158,11,0.15); color: #fcd34d; }
    [data-theme="dark"] .summary-icon.rose { background: rgba(244,63,94,0.15); color: #fda4af; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .summary-label { color: #94a3b8; }
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #fff; }
    [data-theme="dark"] .btn-add-vehicle { background: #3b82f6; }
    [data-theme="dark"] .btn-add-vehicle:hover { background: #2563eb; }
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .search-input-group input,
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .search-input-group input:focus,
    [data-theme="dark"] .filter-select:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 0 0 3px rgba(96,165,250,0.12); }
    [data-theme="dark"] .search-input-group input::placeholder { color: #64748b; }
    [data-theme="dark"] .btn-search { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-search:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-reset-search { background: #1e293b; border-color: #334155; color: #64748b; }
    [data-theme="dark"] .btn-reset-search:hover { background: rgba(239,68,68,0.15); color: #fca5a5; border-color: rgba(239,68,68,0.25); }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .limit-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .action-btn-view:hover { background: rgba(34,197,94,0.15); border-color: rgba(34,197,94,0.3); color: #86efac; }
    [data-theme="dark"] .action-btn-edit:hover { background: rgba(59,130,246,0.15); border-color: rgba(59,130,246,0.3); color: #93c5fd; }
    [data-theme="dark"] .action-btn-delete:hover { background: rgba(239,68,68,0.15); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
    [data-theme="dark"] .pagination-list li a,
    [data-theme="dark"] .pagination-list li span { background: #1e293b; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .pagination-list li a:hover { background: #334155; }
    [data-theme="dark"] .pagination-list li.active a { background: #3b82f6; border-color: #3b82f6; }
    [data-theme="dark"] .pagination-list li.disabled a { background: #0f172a; border-color: #1e293b; color: #475569; }
    [data-theme="dark"] .col-no-val { color: #64748b; }
    [data-theme="dark"] .empty-state-icon { color: #334155; }
    [data-theme="dark"] .empty-state h5 { color: #e2e8f0; }
    [data-theme="dark"] .empty-state p { color: #94a3b8; }
    [data-theme="dark"] .btn-cancel { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .record-count { background-color: rgba(59,130,246,0.15) !important; color: #93c5fd !important; }
    [data-theme="dark"] .modal-content { background: #1e293b; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .modal-header { border-bottom-color: #334155; }
    [data-theme="dark"] .modal-footer { background: #162032 !important; border-top-color: #334155; }
    [data-theme="dark"] .modal-title { color: #f1f5f9 !important; }
    [data-theme="dark"] .form-label-custom { color: #cbd5e1; }
    [data-theme="dark"] .form-control-custom,
    [data-theme="dark"] .form-select-custom { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-submit-action { background: #3b82f6; }
    [data-theme="dark"] .btn-submit-action:hover { background: #2563eb; }
    [data-theme="dark"] .btn-cancel-action { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-cancel-action:hover { background: #1e293b; color: #f1f5f9; }

    /* Responsive */
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
        .search-input-group { width: 100%; min-width: 0; }
        .filter-select { flex: 1; min-width: 120px; }
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .vehicles-page-header { flex-direction: column; gap: 10px; }
        .vehicles-header-actions { width: 100%; justify-content: flex-start; }
        .table-card-header { flex-direction: column; align-items: flex-start; gap: 10px; }
        .summary-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Kas Operasional</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Kas Operasional</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>reports/finance.php" class="btn-header-secondary">
            <i class="fas fa-file-invoice-dollar"></i> Laporan Laba Bersih
        </a>
        <button type="button" class="btn-add-vehicle" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
            <i class="fas fa-plus"></i> Catat Pengeluaran
        </button>
    </div>
</div>

<!-- Summary Cards Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-calendar-alt"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo formatCurrency($totalMonth); ?></div>
            <div class="summary-label">Bulan Ini</div>
            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $countMonth; ?> transaksi kas keluar</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-calendar-day"></i>
        </div>
        <div>
            <div class="summary-value text-success"><?php echo formatCurrency($totalToday); ?></div>
            <div class="summary-label">Hari Ini</div>
            <small class="text-muted" style="font-size: 0.72rem;">Per <?php echo formatDate(date('Y-m-d'), 'd M Y'); ?></small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-chart-line"></i>
        </div>
        <div>
            <div class="summary-value text-primary"><?php echo formatCurrency($totalYear); ?></div>
            <div class="summary-label">Tahun <?php echo $selectedYear; ?></div>
            <small class="text-muted" style="font-size: 0.72rem;">Akumulasi tahunan</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon rose">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="summary-value text-danger" style="font-size: 0.95rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;">
                <?php echo $topCat ? htmlspecialchars($categories[$topCat['category']]['label'] ?? $topCat['category']) : '-'; ?>
            </div>
            <div class="summary-label">Beban Terbesar</div>
            <small class="text-danger fw-semibold" style="font-size: 0.72rem;">
                <?php echo $topCat ? formatCurrency($topCat['total_cat']) : 'Rp 0'; ?>
            </small>
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
                    <input type="text" name="search" placeholder="Cari kode transaksi, judul, atau catatan..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <select name="month" class="filter-select" onchange="this.form.submit()">
                    <option value="0">Semua Bulan</option>
                    <?php foreach ($monthNames as $mNum => $mName): ?>
                        <option value="<?php echo $mNum; ?>" <?php echo $selectedMonth == $mNum ? 'selected' : ''; ?>>
                            Bulan <?php echo $mName; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="year" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($availableYears as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $selectedYear == $y ? 'selected' : ''; ?>>
                            Tahun <?php echo $y; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="category" class="filter-select" onchange="this.form.submit()">
                    <option value="">Semua Kategori</option>
                    <?php foreach ($categories as $catKey => $catInfo): ?>
                        <option value="<?php echo $catKey; ?>" <?php echo $categoryFilter === $catKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($catInfo['label']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-search">
                    <i class="fas fa-search"></i> Cari
                </button>
                <?php if (!empty($search) || !empty($categoryFilter) || $selectedMonth != (int)date('m') || $selectedYear != (int)date('Y')): ?>
                <a href="index.php" class="btn-reset-search" title="Reset filter">
                    <i class="fas fa-times"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Expenses Table Card -->
<div class="table-card card">
    <div class="table-card-header">
        <h5 class="table-card-title">Daftar Kas & Pengeluaran Operasional</h5>
        <div class="table-card-controls">
            <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary"><?php echo number_format($totalRecords); ?> Transaksi</span>
            <select class="limit-select" 
                    onchange="window.location.href='?limit='+this.value+'&search=<?php echo urlencode($search); ?>&month=<?php echo $selectedMonth; ?>&year=<?php echo $selectedYear; ?>&category=<?php echo urlencode($categoryFilter); ?>'">
                <option value="10" <?php echo $limit == 10 ? 'selected' : ''; ?>>10</option>
                <option value="15" <?php echo $limit == 15 ? 'selected' : ''; ?>>15</option>
                <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25</option>
                <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50</option>
                <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100</option>
            </select>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($expenses)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th class="col-no" style="width: 50px;">No</th>
                        <th style="min-width: 140px;">Kode & Tanggal</th>
                        <th style="min-width: 140px;">Kategori</th>
                        <th style="min-width: 240px;">Judul & Keterangan</th>
                        <th style="min-width: 120px;">Metode Bayar</th>
                        <th class="text-end" style="min-width: 140px;">Nominal (Rp)</th>
                        <th class="text-center" style="min-width: 80px;">Bukti</th>
                        <th class="text-end" style="min-width: 100px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expenses as $idx => $row): ?>
                        <tr>
                            <td class="col-no-val"><?php echo $offset + $idx + 1; ?></td>
                            <td class="text-nowrap">
                                <div class="fw-bold text-dark font-monospace" style="font-size: 0.8rem;">
                                    <?php echo htmlspecialchars($row['expense_code']); ?>
                                </div>
                                <small class="text-muted">
                                    <i class="fas fa-calendar-alt me-1"></i><?php echo formatDate($row['expense_date'], 'd M Y'); ?>
                                </small>
                            </td>
                            <td class="text-nowrap">
                                <?php echo renderExpenseCategoryBadge($row['category']); ?>
                            </td>
                            <td style="white-space: normal; min-width: 220px;">
                                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($row['title']); ?></div>
                                <?php if (!empty($row['notes'])): ?>
                                    <small class="text-muted d-block text-truncate" style="max-width: 320px;">
                                        <?php echo htmlspecialchars($row['notes']); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <?php 
                                $methodBadges = [
                                    'cash' => '<span class="badge bg-light text-dark border"><i class="fas fa-money-bill me-1"></i>Tunai</span>',
                                    'bank_transfer' => '<span class="badge bg-light text-dark border"><i class="fas fa-university me-1"></i>Transfer Bank</span>',
                                    'petty_cash' => '<span class="badge bg-light text-dark border"><i class="fas fa-coins me-1"></i>Kas Kecil</span>'
                                ];
                                echo $methodBadges[$row['payment_method']] ?? htmlspecialchars($row['payment_method']);
                                ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <span class="price-text text-danger">
                                    <?php echo formatCurrency($row['amount']); ?>
                                </span>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php if (!empty($row['receipt_file'])): ?>
                                    <button type="button" class="action-btn action-btn-view" 
                                            onclick="viewReceipt('<?php echo BASE_URL . 'uploads/expenses/' . htmlspecialchars($row['receipt_file']); ?>', '<?php echo htmlspecialchars(addslashes($row['title'])); ?>')"
                                            data-bs-toggle="tooltip" title="Lihat Bukti Kwitansi/Nota">
                                        <i class="fas fa-paperclip"></i>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <div class="action-btns">
                                    <button type="button" class="action-btn action-btn-edit" 
                                            onclick='openEditExpenseModal(<?php echo htmlspecialchars(json_encode([
                                                "id" => $row["id"],
                                                "expense_code" => $row["expense_code"],
                                                "expense_date" => $row["expense_date"],
                                                "category" => $row["category"],
                                                "payment_method" => $row["payment_method"],
                                                "title" => $row["title"],
                                                "amount" => $row["amount"],
                                                "notes" => $row["notes"],
                                                "receipt_file" => $row["receipt_file"]
                                            ]), ENT_QUOTES, "UTF-8"); ?>)'
                                            data-bs-toggle="tooltip" title="Edit Transaksi">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button type="button" class="action-btn action-btn-delete" 
                                            onclick="confirmDelete(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['title'])); ?>', '<?php echo number_format($row['amount'], 0, ',', '.'); ?>')" 
                                            data-bs-toggle="tooltip" title="Hapus Transaksi">
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
        <div class="pagination-wrapper">
            <ul class="pagination-list">
                <li class="<?php echo $page <= 1 ? 'disabled' : ''; ?>">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>">
                        <i class="fas fa-chevron-left" style="font-size:0.65rem"></i>
                    </a>
                </li>
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                for ($p = $startPage; $p <= $endPage; $p++): 
                ?>
                    <li class="<?php echo $p === $page ? 'active' : ''; ?>">
                        <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $p])); ?>">
                            <?php echo $p; ?>
                        </a>
                    </li>
                <?php endfor; ?>
                <li class="<?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])); ?>">
                        <i class="fas fa-chevron-right" style="font-size:0.65rem"></i>
                    </a>
                </li>
            </ul>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">
                <i class="fas fa-receipt"></i>
            </div>
            <h5>Belum Ada Catatan Kas Operasional</h5>
            <p>Tidak ada transaksi pengeluaran yang sesuai dengan kriteria filter yang dipilih.</p>
            <button type="button" class="btn-cancel text-primary border-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                <i class="fas fa-plus me-1"></i> Catat Pengeluaran Baru
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Pop-up: Tambah Catatan Pengeluaran Baru -->
<div class="modal fade" id="addExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-title-icon primary">
                        <i class="fas fa-plus"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6 mb-0">Catat Pengeluaran Baru</h5>
                        <small class="text-muted" style="font-size: 0.72rem;">Catat kas kecil atau beban operasional showroom</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="form_action" value="create">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Kode Transaksi</label>
                            <input type="text" class="form-control-custom" value="<?php echo htmlspecialchars($autoCode); ?>" readonly style="background-color: #f1f5f9; cursor: not-allowed;">
                            <small class="text-muted" style="font-size: 0.72rem;">Digenerate otomatis oleh sistem.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Tanggal Pengeluaran <span class="req">*</span></label>
                            <input type="date" name="expense_date" class="form-control-custom" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Kategori Pengeluaran <span class="req">*</span></label>
                            <select name="category" class="form-select-custom" required>
                                <option value="">-- Pilih Kategori Pengeluaran --</option>
                                <?php foreach ($categories as $catKey => $catInfo): ?>
                                    <option value="<?php echo $catKey; ?>">
                                        <?php echo htmlspecialchars($catInfo['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Metode Pembayaran <span class="req">*</span></label>
                            <select name="payment_method" class="form-select-custom" required>
                                <option value="cash" selected>Tunai (Cash)</option>
                                <option value="bank_transfer">Transfer Bank</option>
                                <option value="petty_cash">Kas Kecil (Petty Cash)</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Judul / Keperluan Pengeluaran <span class="req">*</span></label>
                            <input type="text" name="title" class="form-control-custom" placeholder="Contoh: Pembayaran Listrik PLN, BBM Operasional Ertiga, Cuci Mobil Display, dll." required>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Nominal / Jumlah Biaya (Rp) <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold border-end-0" style="border-radius: 10px 0 0 10px; border-color: #e2e8f0; color: #475569;">Rp</span>
                                <input type="text" id="add_expense_amount" name="amount" class="form-control form-control-lg fw-bold text-danger border-start-0" placeholder="0" required style="border-radius: 0 10px 10px 0; border-color: #e2e8f0;" oninput="formatExpenseAmount(this)">
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Unggah Bukti Kwitansi / Struk / Nota (Opsional)</label>
                            <input type="file" name="receipt_file" class="form-control-custom" accept="image/png, image/jpeg, image/webp, application/pdf">
                            <small class="text-muted" style="font-size: 0.72rem;">Format berkas: JPG, PNG, WEBP, atau PDF. Maksimal 5MB.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Catatan / Rincian Tambahan (Opsional)</label>
                            <textarea name="notes" rows="2" class="form-control-custom" placeholder="Keterangan tambahan, nomor nota, atau rincian jika ada..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4 py-3 bg-light border-top">
                    <button type="button" class="btn-cancel-action" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-submit-action">
                        <i class="fas fa-save me-1"></i> Simpan Pengeluaran
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Pop-up: Edit Pengeluaran -->
<div class="modal fade" id="editExpenseModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-title-icon primary">
                        <i class="fas fa-edit"></i>
                    </div>
                    <div>
                        <h5 class="modal-title fw-bold text-dark fs-6 mb-0">Edit Transaksi Pengeluaran</h5>
                        <small class="text-muted" style="font-size: 0.72rem;">Perbarui data atau lampiran bukti kwitansi</small>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="index.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="form_action" value="update">
                <input type="hidden" name="edit_id" id="edit_expense_id" value="">

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Kode Transaksi</label>
                            <input type="text" id="edit_expense_code" class="form-control-custom" readonly style="background-color: #f1f5f9; cursor: not-allowed;">
                            <small class="text-muted" style="font-size: 0.72rem;">Kode referensi permanen.</small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Tanggal Pengeluaran <span class="req">*</span></label>
                            <input type="date" id="edit_expense_date" name="expense_date" class="form-control-custom" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Kategori Pengeluaran <span class="req">*</span></label>
                            <select id="edit_expense_category" name="category" class="form-select-custom" required>
                                <option value="">-- Pilih Kategori Pengeluaran --</option>
                                <?php foreach ($categories as $catKey => $catInfo): ?>
                                    <option value="<?php echo $catKey; ?>">
                                        <?php echo htmlspecialchars($catInfo['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label-custom">Metode Pembayaran <span class="req">*</span></label>
                            <select id="edit_expense_payment_method" name="payment_method" class="form-select-custom" required>
                                <option value="cash">Tunai (Cash)</option>
                                <option value="bank_transfer">Transfer Bank</option>
                                <option value="petty_cash">Kas Kecil (Petty Cash)</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Judul / Keperluan Pengeluaran <span class="req">*</span></label>
                            <input type="text" id="edit_expense_title" name="title" class="form-control-custom" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Nominal / Jumlah Biaya (Rp) <span class="req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light fw-bold border-end-0" style="border-radius: 10px 0 0 10px; border-color: #e2e8f0; color: #475569;">Rp</span>
                                <input type="text" id="edit_expense_amount" name="amount" class="form-control form-control-lg fw-bold text-danger border-start-0" required style="border-radius: 0 10px 10px 0; border-color: #e2e8f0;" oninput="formatExpenseAmount(this)">
                            </div>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Ganti Bukti Kwitansi / Struk / Nota (Opsional)</label>
                            <div id="edit_receipt_preview_box" class="mb-2 p-2 bg-light rounded border d-none align-items-center justify-content-between">
                                <span class="small text-muted"><i class="fas fa-paperclip me-1 text-primary"></i> Berkas bukti saat ini tersedia</span>
                                <a id="edit_receipt_link" href="#" target="_blank" class="btn btn-xs btn-outline-primary py-1 px-2" style="font-size: 0.75rem;">Lihat Bukti</a>
                            </div>
                            <input type="file" name="receipt_file" class="form-control-custom" accept="image/png, image/jpeg, image/webp, application/pdf">
                            <small class="text-muted" style="font-size: 0.72rem;">Format: JPG, PNG, WEBP, atau PDF. Maksimal 5MB. Kosongkan jika tidak ingin mengganti berkas.</small>
                        </div>

                        <div class="col-12">
                            <label class="form-label-custom">Catatan / Rincian Tambahan (Opsional)</label>
                            <textarea id="edit_expense_notes" name="notes" rows="2" class="form-control-custom"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer px-4 py-3 bg-light border-top">
                    <button type="button" class="btn-cancel-action" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-submit-action">
                        <i class="fas fa-save me-1"></i> Perbarui Pengeluaran
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Bukti Struk/Kwitansi -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3 px-4">
                <h6 class="modal-title fw-bold text-dark mb-0" id="receiptModalTitle">Bukti Pengeluaran</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-3">
                <img id="receiptModalImage" src="" alt="Bukti Kwitansi" class="img-fluid rounded shadow-sm" style="max-height: 500px; object-fit: contain;">
            </div>
        </div>
    </div>
</div>

<!-- Modal Konfirmasi Hapus -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header py-3 px-4">
                <h6 class="modal-title fw-bold text-danger mb-0">
                    <i class="fas fa-exclamation-triangle me-2"></i>Konfirmasi Hapus Pengeluaran
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="delete.php" method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="id" id="deleteId" value="">
                <div class="modal-body p-4">
                    <p class="mb-2">Apakah Anda yakin ingin menghapus catatan pengeluaran berikut?</p>
                    <div class="p-3 bg-light rounded border">
                        <div class="fw-bold text-dark" id="deleteTitle">-</div>
                        <div class="text-danger fw-bold mt-1">Rp <span id="deleteAmount">0</span></div>
                    </div>
                    <small class="text-muted d-block mt-2">Tindakan ini tidak dapat dibatalkan dan akan mempengaruhi laporan laba bersih.</small>
                </div>
                <div class="modal-footer px-4 py-3 bg-light border-top">
                    <button type="button" class="btn-cancel-action" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-danger px-4" style="border-radius: 10px; font-weight: 600; font-size: 0.85rem;">Hapus Pengeluaran</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function formatExpenseAmount(input) {
    let value = input.value.replace(/[^0-9]/g, '');
    if (value) {
        input.value = new Intl.NumberFormat('id-ID').format(parseInt(value, 10));
    } else {
        input.value = '';
    }
}

function viewReceipt(imgSrc, title) {
    document.getElementById('receiptModalTitle').innerText = 'Bukti Pengeluaran: ' + title;
    document.getElementById('receiptModalImage').src = imgSrc;
    var modal = new bootstrap.Modal(document.getElementById('receiptModal'));
    modal.show();
}

function openEditExpenseModal(data) {
    document.getElementById('edit_expense_id').value = data.id;
    document.getElementById('edit_expense_code').value = data.expense_code;
    document.getElementById('edit_expense_date').value = data.expense_date;
    document.getElementById('edit_expense_category').value = data.category;
    document.getElementById('edit_expense_payment_method').value = data.payment_method;
    document.getElementById('edit_expense_title').value = data.title;
    document.getElementById('edit_expense_amount').value = data.amount ? new Intl.NumberFormat('id-ID').format(Math.round(data.amount)) : '';
    document.getElementById('edit_expense_notes').value = data.notes || '';

    var previewBox = document.getElementById('edit_receipt_preview_box');
    var receiptLink = document.getElementById('edit_receipt_link');
    if (data.receipt_file) {
        previewBox.classList.remove('d-none');
        previewBox.classList.add('d-flex');
        receiptLink.href = '<?php echo BASE_URL; ?>uploads/expenses/' + encodeURIComponent(data.receipt_file);
    } else {
        previewBox.classList.remove('d-flex');
        previewBox.classList.add('d-none');
        receiptLink.href = '#';
    }

    var editModal = new bootstrap.Modal(document.getElementById('editExpenseModal'));
    editModal.show();
}

function confirmDelete(id, title, amount) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteTitle').innerText = title;
    document.getElementById('deleteAmount').innerText = amount;
    var modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_GET['action']) && $_GET['action'] === 'create'): ?>
        var addModal = new bootstrap.Modal(document.getElementById('addExpenseModal'));
        addModal.show();
    <?php endif; ?>
});
</script>

<?php include '../includes/footer.php'; ?>
