<?php
// reports/monthly_sales.php - Laporan Penjualan Bulanan
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit;
}
$role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
if ($role !== 'admin') {
    header('Location: ../dashboard/');
    exit;
}
include '../includes/header.php';
requireLogin();

$pageTitle = 'Aplikasi Manajemen Showroom';

$db = new Database();
$conn = $db->connect();

// Get filter parameters
$year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month = isset($_GET['month']) ? $_GET['month'] : date('m');

// Get available years - Only from PAID transactions
$stmt = $conn->query("SELECT DISTINCT YEAR(transaction_date) as year 
                      FROM transactions 
                      WHERE payment_status = 'paid'
                      ORDER BY year DESC");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure current year is included
$currentYear = date('Y');
$hasCurrentYear = false;
foreach ($years as $y) {
    if ($y['year'] == $currentYear) {
        $hasCurrentYear = true;
        break;
    }
}

if (!$hasCurrentYear) {
    $years[] = ['year' => $currentYear];
    usort($years, function($a, $b) {
        return $b['year'] - $a['year'];
    });
}

// Get monthly sales data - Only PAID transactions
$stmt = $conn->prepare("SELECT 
                        DATE_FORMAT(t.transaction_date, '%Y-%m-%d %H:%i') as datetime,
                        DATE_FORMAT(t.transaction_date, '%Y-%m-%d') as date,
                        t.transaction_code,
                        c.name as customer_name,
                        c.phone as customer_phone,
                        CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle,
                        v.license_plate,
                        v.year as vehicle_year,
                        b.name as brand_name,
                        m.name as model_name,
                        t.total_amount,
                        t.payment_status,
                        t.payment_method,
                        u.full_name as seller,
                        t.id
                      FROM transactions t
                      JOIN customers c ON t.customer_id = c.id
                      LEFT JOIN vehicles v ON t.vehicle_id = v.id
                      LEFT JOIN models m ON v.model_id = m.id
                      LEFT JOIN brands b ON m.brand_id = b.id
                      JOIN users u ON t.user_id = u.id
                      WHERE YEAR(t.transaction_date) = ? 
                      AND MONTH(t.transaction_date) = ?
                      AND t.payment_status = 'paid'
                      GROUP BY t.id
                      ORDER BY t.transaction_date DESC");
$stmt->execute([$year, $month]);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily sales data for chart
$stmt = $conn->prepare("SELECT 
                        DATE(t.transaction_date) as date,
                        COUNT(*) as count,
                        COALESCE(SUM(t.total_amount), 0) as total
                      FROM transactions t
                      WHERE YEAR(t.transaction_date) = ? 
                      AND MONTH(t.transaction_date) = ?
                      AND t.payment_status = 'paid'
                      GROUP BY DATE(t.transaction_date)
                      ORDER BY date ASC");
$stmt->execute([$year, $month]);
$dailySales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment method distribution
$stmt = $conn->prepare("SELECT 
                        payment_method,
                        COUNT(*) as count,
                        COALESCE(SUM(t.total_amount), 0) as total_amount
                      FROM transactions t
                      WHERE YEAR(t.transaction_date) = ? 
                      AND MONTH(t.transaction_date) = ?
                      AND t.payment_status = 'paid'
                      GROUP BY payment_method");
$stmt->execute([$year, $month]);
$paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format daily sales for chart
$dailyChartLabels = [];
$dailyChartCounts = [];
$dailyChartTotals = [];
$dailyChartDates = [];
foreach ($dailySales as $ds) {
    $dailyChartLabels[] = date('d M', strtotime($ds['date']));
    $dailyChartCounts[] = (int)$ds['count'];
    $dailyChartTotals[] = (float)$ds['total'];
    $dailyChartDates[] = date('d M Y', strtotime($ds['date']));
}

// Payment method labels and colors mapping
$methodMap = [
    'cash' => ['label' => 'Tunai (Cash)', 'color' => '#10b981', 'hover' => '#059669'],
    'bank_transfer' => ['label' => 'Transfer Bank', 'color' => '#6366f1', 'hover' => '#4f46e5'],
    'transfer' => ['label' => 'Transfer Bank', 'color' => '#6366f1', 'hover' => '#4f46e5'],
    'installment' => ['label' => 'Kredit / Leasing', 'color' => '#f59e0b', 'hover' => '#d97706'],
    'credit' => ['label' => 'Kredit / Leasing', 'color' => '#f59e0b', 'hover' => '#d97706'],
    'leasing' => ['label' => 'Kredit / Leasing', 'color' => '#f59e0b', 'hover' => '#d97706'],
];

$paymentChartLabels = [];
$paymentChartData = [];
$paymentChartColors = [];
$paymentChartHover = [];
$paymentSummary = [];

$defaultPalette = ['#10b981', '#6366f1', '#f59e0b', '#06b6d4', '#8b5cf6', '#ec4899', '#3b82f6'];
$idx = 0;
$totalPayCount = 0;
foreach ($paymentMethods as $pm) {
    $totalPayCount += (int)$pm['count'];
}

foreach ($paymentMethods as $pm) {
    $rawKey = strtolower(trim((string)$pm['payment_method']));
    if (isset($methodMap[$rawKey])) {
        $lbl = $methodMap[$rawKey]['label'];
        $clr = $methodMap[$rawKey]['color'];
        $hvr = $methodMap[$rawKey]['hover'];
    } else {
        $lbl = ucwords(str_replace('_', ' ', $rawKey ?: 'Lainnya'));
        $clr = $defaultPalette[$idx % count($defaultPalette)];
        $hvr = $clr;
    }
    $idx++;
    
    $cnt = (int)$pm['count'];
    $paymentChartLabels[] = $lbl;
    $paymentChartData[] = $cnt;
    $paymentChartColors[] = $clr;
    $paymentChartHover[] = $hvr;
    
    $pct = $totalPayCount > 0 ? round(($cnt / $totalPayCount) * 100, 1) : 0;
    $paymentSummary[] = [
        'label' => $lbl,
        'count' => $cnt,
        'total' => (float)$pm['total_amount'],
        'color' => $clr,
        'percentage' => $pct
    ];
}

// Calculate totals
$totalTransactions = count($transactions);
$totalRevenue = 0;
$completedTransactions = 0;
$printTotal = 0;

foreach ($transactions as $transaction) {
    $printTotal += $transaction['total_amount'];
    $totalRevenue += $transaction['total_amount'];
    $completedTransactions++;
}

// Calculate highest transaction
$highestTransaction = 0;
foreach ($transactions as $transaction) {
    if ($transaction['total_amount'] > $highestTransaction) {
        $highestTransaction = $transaction['total_amount'];
    }
}

// Get best seller this month
$stmt = $conn->prepare("SELECT 
                        u.full_name as seller_name,
                        COUNT(*) as total_sales,
                        COALESCE(SUM(t.total_amount), 0) as total_revenue
                      FROM transactions t
                      JOIN users u ON t.user_id = u.id
                      WHERE YEAR(t.transaction_date) = ? 
                      AND MONTH(t.transaction_date) = ?
                      AND t.payment_status = 'paid'
                      GROUP BY u.id, u.full_name
                      ORDER BY total_sales DESC, total_revenue DESC
                      LIMIT 1");
$stmt->execute([$year, $month]);
$bestSeller = $stmt->fetch(PDO::FETCH_ASSOC);

// Get previous month data for comparison
$prevMonth = $month == '01' ? '12' : str_pad((int)$month - 1, 2, '0', STR_PAD_LEFT);
$prevYear = $month == '01' ? (int)$year - 1 : $year;
$stmt = $conn->prepare("SELECT 
                        COUNT(*) as total_transactions,
                        COALESCE(SUM(total_amount), 0) as total_revenue
                      FROM transactions 
                      WHERE YEAR(transaction_date) = ? 
                      AND MONTH(transaction_date) = ?
                      AND payment_status = 'paid'");
$stmt->execute([$prevYear, $prevMonth]);
$prevMonthData = $stmt->fetch(PDO::FETCH_ASSOC);

// Calculate growth percentage
$revenueGrowth = 0;
if ($prevMonthData && $prevMonthData['total_revenue'] > 0) {
    $revenueGrowth = (($totalRevenue - $prevMonthData['total_revenue']) / $prevMonthData['total_revenue']) * 100;
}

$monthName = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April', '05' => 'Mei', '06' => 'Juni',
    '07' => 'Juli', '08' => 'Agustus', '09' => 'September',
    '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

// Get company logo and name for print
$printLogo = '';
$printCompanyName = defined('APP_NAME') ? APP_NAME : 'Auto Dealer Management System';
$printAddress = '';
try {
    $sstmt = $conn->query("SELECT company_logo, company_name, company_address FROM settings WHERE id = 1");
    $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
    if ($srow) {
        if (!empty($srow['company_logo']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['company_logo'])) {
            $printLogo = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['company_logo'];
        }
        if (!empty($srow['company_name'])) {
            $printCompanyName = $srow['company_name'];
        }
        if (!empty($srow['company_address'])) {
            $printAddress = $srow['company_address'];
        }
    }
} catch (Exception $e) {}
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
    .search-card .card-body { padding: 14px 18px; }
    .search-row {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
    }
    .filter-select {
        min-width: 150px;
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

    /* ===== Chart & Table Cards ===== */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 20px;
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
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .record-count {
        padding: 4px 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 0.75rem;
        letter-spacing: 0.02em;
    }

    /* Table Styling */
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
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
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
        white-space: nowrap;
    }
    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
    }
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        white-space: nowrap;
    }
    .status-completed, .status-paid { background: #dcfce7; color: #166534; }
    .status-installment { background: #fef3c7; color: #92400e; }
    .status-pending { background: #fee2e2; color: #991b1b; }

    .action-btns {
        display: inline-flex;
        align-items: center;
        gap: 5px;
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
    .action-btn:hover { transform: translateY(-1px); background: #f0fdf4; border-color: #bbf7d0; color: #16a34a; }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .filter-select:focus { background: #162032; border-color: #60a5fa; }
    [data-theme="dark"] .btn-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .plate-badge { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .price-text { color: #4ade80; }
    [data-theme="dark"] .action-btn { background: #0f172a; border-color: #334155; color: #94a3b8; }

    @media print {
        .btn, .sidebar, .navbar, .card-header, form, .no-print, .vehicles-page-header, 
        .summary-grid, .search-card, .table-card, footer, .footer, #footer {
            display: none !important;
        }
        .print-only { display: block !important; }
        body { margin: 0; padding: 10px; background-color: white; color: black; }
        @page { margin: 1cm; size: A4; }
    }
    .print-only { display: none; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Laporan Penjualan Bulanan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>reports/" class="text-primary text-decoration-underline">Laporan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Penjualan Bulanan</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>reports/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" onclick="window.print()" class="btn-add-vehicle">
            <i class="fas fa-print"></i> Cetak Laporan
        </button>
    </div>
</div>

<!-- Summary Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-receipt"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($totalTransactions); ?></div>
            <div class="summary-label">Total Transaksi</div>
            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $monthName[$month] . ' ' . $year; ?></small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($totalRevenue); ?></div>
            <div class="summary-label">Total Pendapatan</div>
            <?php if ($revenueGrowth != 0): ?>
                <small class="<?php echo $revenueGrowth >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold" style="font-size: 0.72rem;">
                    <i class="fas fa-<?php echo $revenueGrowth >= 0 ? 'arrow-up' : 'arrow-down'; ?> me-1"></i><?php echo ($revenueGrowth >= 0 ? '+' : '') . number_format($revenueGrowth, 1); ?>% MoM
                </small>
            <?php else: ?>
                <small class="text-muted" style="font-size: 0.72rem;">Bulan berjalan</small>
            <?php endif; ?>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-trophy"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($highestTransaction); ?></div>
            <div class="summary-label">Transaksi Tertinggi</div>
            <small class="text-muted" style="font-size: 0.72rem;">Rekor bulan ini</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-user-tie"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1rem;"><?php echo htmlspecialchars($bestSeller['seller_name'] ?? '-'); ?></div>
            <div class="summary-label">Sales Terbaik</div>
            <small class="text-muted" style="font-size: 0.72rem;"><?php echo number_format($bestSeller['total_sales'] ?? 0); ?> Unit Terjual</small>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <select name="year" class="filter-select" onchange="this.form.submit()">
                    <?php 
                    if (empty($years)) {
                        echo '<option value="' . date('Y') . '">' . date('Y') . '</option>';
                    } else {
                        foreach ($years as $y): 
                    ?>
                        <option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $year ? 'selected' : ''; ?>>
                            Tahun <?php echo $y['year']; ?>
                        </option>
                    <?php 
                        endforeach;
                    }
                    ?>
                </select>

                <select name="month" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($monthName as $key => $name): ?>
                        <option value="<?php echo $key; ?>" <?php echo $key == $month ? 'selected' : ''; ?>>
                            Bulan <?php echo $name; ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn-search">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="table-card h-100 mb-0 d-flex flex-column">
            <div class="table-card-header d-flex align-items-center justify-content-between">
                <h5 class="table-card-title mb-0">
                    <i class="fas fa-chart-line text-primary"></i> Tren Penjualan Harian
                </h5>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2.5 py-1" style="font-size:0.75rem; border-radius: 6px;">
                    <?php echo $monthName[$month] . ' ' . $year; ?>
                </span>
            </div>
            <div class="card-body p-3 p-md-4 flex-grow-1 d-flex flex-column justify-content-center">
                <?php if (!empty($dailySales)): ?>
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="dailySalesChart"></canvas>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted my-auto">
                    <i class="fas fa-chart-line fa-2x mb-2 text-muted opacity-50"></i>
                    <div class="small fw-semibold">Belum ada data grafik penjualan pada periode ini.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="table-card h-100 mb-0 d-flex flex-column">
            <div class="table-card-header d-flex align-items-center justify-content-between">
                <h5 class="table-card-title mb-0">
                    <i class="fas fa-chart-pie text-info"></i> Metode Pembayaran
                </h5>
                <span class="badge bg-info bg-opacity-10 text-info fw-semibold px-2.5 py-1" style="font-size:0.75rem; border-radius: 6px;">
                    <?php echo $totalPayCount; ?> Transaksi
                </span>
            </div>
            <div class="card-body p-3 p-md-4 flex-grow-1 d-flex flex-column justify-content-between">
                <?php if (!empty($paymentMethods)): ?>
                <div class="d-flex align-items-center justify-content-center my-auto" style="position: relative; height: 160px; width: 100%;">
                    <canvas id="paymentStatusChart"></canvas>
                </div>
                <!-- Custom Legend / Mini Summary -->
                <div class="mt-3 pt-2 border-top border-light-subtle">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($paymentSummary as $ps): ?>
                        <div class="d-flex align-items-center justify-content-between small">
                            <div class="d-flex align-items-center gap-2">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background-color: <?php echo $ps['color']; ?>; display: inline-block; flex-shrink: 0;"></span>
                                <span class="fw-medium text-dark-emphasis"><?php echo htmlspecialchars($ps['label']); ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted fw-semibold"><?php echo $ps['count']; ?> unit</span>
                                <span class="badge bg-light text-dark border px-2 py-0.5" style="font-size: 0.7rem; font-weight: 600;"><?php echo $ps['percentage']; ?>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted my-auto">
                    <i class="fas fa-chart-pie fa-2x mb-2 text-muted opacity-50"></i>
                    <div class="small fw-semibold">Belum ada data metode pembayaran.</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">
            <i class="fas fa-list text-primary"></i> Daftar Transaksi Penjualan - <?php echo $monthName[$month] . ' ' . $year; ?>
        </h5>
        <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
            <?php echo count($transactions); ?> Transaksi
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (count($transactions) > 0): ?>
        <div class="table-responsive">
            <table class="vehicles-table" id="reportTable">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th style="min-width: 140px;">KODE & TANGGAL</th>
                        <th style="min-width: 150px;">PELANGGAN</th>
                        <th style="min-width: 160px;">UNIT MOBIL</th>
                        <th style="min-width: 130px;">TOTAL HARGA</th>
                        <th style="min-width: 120px;" class="text-center">STATUS</th>
                        <th style="min-width: 120px;">SALES</th>
                        <th style="width: 80px;" class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($transactions as $trans): 
                        $statusKey = $trans['payment_status'] ?: 'pending';
                        $method = $trans['payment_method'];
                        if (($method == 'installment' || $method == 'credit') && $statusKey != 'paid') {
                            $statusKey = 'installment';
                        }
                    ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($trans['transaction_code']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo date('d M Y', strtotime($trans['date'])); ?></div>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($trans['customer_name']); ?></div>
                            <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($trans['customer_phone'] ?? '-'); ?></div>
                        </td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars(trim(($trans['brand_name'] ?? '') . ' ' . ($trans['model_name'] ?? ''))); ?></div>
                            <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                <span class="plate-badge"><?php echo htmlspecialchars($trans['license_plate'] ?: 'No Plat'); ?></span>
                                <?php if (!empty($trans['vehicle_year'])): ?>
                                <span class="text-muted small text-nowrap ms-1">• <?php echo $trans['vehicle_year']; ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="price-text"><?php echo formatCurrency($trans['total_amount']); ?></div>
                            <small class="text-muted text-uppercase fw-semibold" style="font-size:0.72rem;">Via <?php echo htmlspecialchars($trans['payment_method'] ?: 'Cash'); ?></small>
                        </td>
                        <td class="text-center">
                            <span class="status-badge status-completed">
                                Lunas
                            </span>
                        </td>
                        <td>
                            <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($trans['seller']); ?></div>
                        </td>
                        <td class="text-center">
                            <div class="action-btns">
                                <a href="../transactions/view.php?id=<?php echo $trans['id']; ?>" class="action-btn" title="Lihat Detail Transaksi">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#fafbfc;border-top:1.5px solid #e2e8f0;">
                        <th colspan="4" class="text-end py-3 fw-bold">TOTAL PENDAPATAN BULANAN:</th>
                        <th colspan="4" class="py-3 price-text" style="font-size: 1rem;">
                            <?php echo formatCurrency($totalRevenue); ?>
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-receipt fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada transaksi penjualan pada bulan <?php echo $monthName[$month] . ' ' . $year; ?>.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print Only Section -->
<div class="print-only">
    <div class="print-header" style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 5px 0; font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($printCompanyName); ?></h3>
        <?php if (!empty($printAddress)): ?>
            <p style="margin: 2px 0; font-size: 12px;"><?php echo htmlspecialchars($printAddress); ?></p>
        <?php endif; ?>
        <h3 style="margin: 8px 0; font-size: 15px; font-weight: bold;">Laporan Penjualan Bulanan</h3>
        <p style="margin: 4px 0; font-size: 13px;">Periode <?php echo $monthName[$month] . ' ' . $year; ?></p>
    </div>
    
    <?php if (count($transactions) > 0): ?>
        <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
                <tr style="background-color: #f5f5f5; border-bottom: 2px solid #333;">
                    <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">No</th>
                    <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Tanggal</th>
                    <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Kode</th>
                    <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Pelanggan</th>
                    <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Mobil</th>
                    <th style="padding: 6px; text-align: right; border: 1px solid #ddd; font-size: 11px;">Total Harga</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $pNo = 1;
                foreach ($transactions as $trans): 
                ?>
                    <tr>
                        <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo $pNo++; ?></td>
                        <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo date('d/m/Y', strtotime($trans['date'])); ?></td>
                        <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($trans['transaction_code']); ?></td>
                        <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($trans['customer_name']); ?></td>
                        <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;">
                            <?php echo htmlspecialchars(trim(($trans['brand_name'] ?? '') . ' ' . ($trans['model_name'] ?? ''))); ?>
                            (<?php echo htmlspecialchars($trans['license_plate'] ?: '-'); ?>)
                        </td>
                        <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">
                            Rp <?php echo number_format($trans['total_amount'], 0, ',', '.'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr style="background-color: #f5f5f5; font-weight: bold;">
                    <td colspan="5" style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Total Penjualan:</td>
                    <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">
                        Rp <?php echo number_format($printTotal, 0, ',', '.'); ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    function getThemeColors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            isDark: isDark,
            textColor: isDark ? '#94a3b8' : '#64748b',
            titleColor: isDark ? '#f1f5f9' : '#0f172a',
            gridColor: isDark ? 'rgba(51, 65, 85, 0.45)' : 'rgba(226, 232, 240, 0.7)',
            tooltipBg: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(15, 23, 42, 0.92)',
            revGradientTop: isDark ? 'rgba(99, 102, 241, 0.35)' : 'rgba(79, 70, 229, 0.20)',
            revGradientBottom: isDark ? 'rgba(99, 102, 241, 0.00)' : 'rgba(79, 70, 229, 0.00)',
            unitBarBg: isDark ? 'rgba(16, 185, 129, 0.85)' : 'rgba(16, 185, 129, 0.85)',
            unitBarHover: isDark ? '#34d399' : '#059669',
            donutBorder: isDark ? '#1e293b' : '#ffffff'
        };
    }

    let colors = getThemeColors();
    let dailySalesChart = null;
    let paymentChart = null;

    // 1. Daily Sales Chart
    const dailyCanvas = document.getElementById('dailySalesChart');
    if (dailyCanvas) {
        const dailyCtx = dailyCanvas.getContext('2d');
        const dailyLabels = <?php echo json_encode($dailyChartLabels); ?>;
        const dailyCounts = <?php echo json_encode($dailyChartCounts); ?>;
        const dailyTotals = <?php echo json_encode($dailyChartTotals); ?>;
        const dailyDates = <?php echo json_encode($dailyChartDates); ?>;

        function createRevenueGradient() {
            const gradient = dailyCtx.createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, colors.revGradientTop);
            gradient.addColorStop(1, colors.revGradientBottom);
            return gradient;
        }

        dailySalesChart = new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: dailyLabels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Unit Terjual',
                        data: dailyCounts,
                        backgroundColor: colors.unitBarBg,
                        hoverBackgroundColor: colors.unitBarHover,
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.38,
                        maxBarThickness: 32,
                        yAxisID: 'yUnits',
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Total Pendapatan',
                        data: dailyTotals,
                        borderColor: '#4f46e5',
                        backgroundColor: createRevenueGradient(),
                        borderWidth: 3,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#4f46e5',
                        pointBorderWidth: 2.5,
                        pointRadius: 4.5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#4f46e5',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'yRevenue',
                        order: 1
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            boxWidth: 7,
                            boxHeight: 7,
                            color: colors.textColor,
                            font: {
                                size: 11,
                                weight: '600'
                            }
                        }
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: colors.tooltipBg,
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        borderColor: colors.isDark ? '#334155' : 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        cornerRadius: 10,
                        padding: { top: 8, bottom: 8, left: 12, right: 12 },
                        titleFont: { size: 12, weight: '700' },
                        bodyFont: { size: 11, weight: '500' },
                        boxPadding: 5,
                        usePointStyle: true,
                        callbacks: {
                            title: function(context) {
                                if (context.length > 0) {
                                    const idx = context[0].dataIndex;
                                    return dailyDates[idx] || context[0].label;
                                }
                                return '';
                            },
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let val = context.parsed.y;
                                if (context.dataset.type === 'line' || context.dataset.yAxisID === 'yRevenue') {
                                    return ' ' + label + ': Rp ' + Number(val || 0).toLocaleString('id-ID');
                                } else {
                                    return ' ' + label + ': ' + Number(val || 0).toLocaleString('id-ID') + ' unit';
                                }
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            color: colors.textColor,
                            font: { size: 11, weight: '500' },
                            padding: 6
                        }
                    },
                    yUnits: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        grid: {
                            color: colors.gridColor,
                            borderDash: [4, 4],
                            drawBorder: false
                        },
                        ticks: {
                            precision: 0,
                            stepSize: 1,
                            color: colors.textColor,
                            font: { size: 11, weight: '500' },
                            callback: function(val) {
                                if (Number.isInteger(val)) {
                                    return val + ' unit';
                                }
                                return null;
                            }
                        }
                    },
                    yRevenue: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        grid: { display: false },
                        ticks: {
                            color: colors.textColor,
                            font: { size: 10, weight: '500' },
                            callback: function(val) {
                                if (val >= 1000000000) {
                                    return 'Rp ' + (val / 1000000000).toFixed(1) + 'M';
                                } else if (val >= 1000000) {
                                    return 'Rp ' + (val / 1000000).toFixed(0) + 'Jt';
                                }
                                return 'Rp ' + Number(val).toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    }

    // 2. Payment Method Donut Chart with Center Text Plugin
    const paymentCanvas = document.getElementById('paymentStatusChart');
    if (paymentCanvas) {
        const paymentCtx = paymentCanvas.getContext('2d');
        const paymentLabels = <?php echo json_encode($paymentChartLabels); ?>;
        const paymentData = <?php echo json_encode($paymentChartData); ?>;
        const paymentColors = <?php echo json_encode($paymentChartColors); ?>;
        const paymentHovers = <?php echo json_encode($paymentChartHover); ?>;
        const totalCount = <?php echo (int)$totalPayCount; ?>;

        const centerTextPlugin = {
            id: 'centerText',
            beforeDraw: function(chart) {
                const width = chart.width;
                const height = chart.height;
                const ctx = chart.ctx;
                ctx.restore();

                const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                
                // Value text
                ctx.font = 'bold 24px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#f1f5f9' : '#0f172a';
                const text = String(totalCount);
                const textX = Math.round((width - ctx.measureText(text).width) / 2);
                const textY = height / 2 - 8;
                ctx.fillText(text, textX, textY);

                // Subtitle text
                ctx.font = '600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                ctx.fillStyle = isDark ? '#94a3b8' : '#64748b';
                const subText = 'TRANSAKSI';
                const subTextX = Math.round((width - ctx.measureText(subText).width) / 2);
                const subTextY = height / 2 + 12;
                ctx.fillText(subText, subTextX, subTextY);

                ctx.save();
            }
        };

        paymentChart = new Chart(paymentCtx, {
            type: 'doughnut',
            data: {
                labels: paymentLabels,
                datasets: [{
                    data: paymentData,
                    backgroundColor: paymentColors,
                    hoverBackgroundColor: paymentHovers,
                    borderWidth: 2,
                    borderColor: colors.donutBorder,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        enabled: true,
                        backgroundColor: colors.tooltipBg,
                        titleColor: '#f8fafc',
                        bodyColor: '#e2e8f0',
                        borderColor: colors.isDark ? '#334155' : 'rgba(255,255,255,0.1)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        padding: 10,
                        callbacks: {
                            label: function(context) {
                                const val = context.parsed || 0;
                                const pct = totalCount > 0 ? ((val / totalCount) * 100).toFixed(1) : 0;
                                return ` ${context.label}: ${val} unit (${pct}%)`;
                            }
                        }
                    }
                }
            },
            plugins: [centerTextPlugin]
        });
    }

    // Dynamic Theme Observer (Dark / Light Mode)
    const observer = new MutationObserver(function() {
        colors = getThemeColors();
        if (dailySalesChart) {
            dailySalesChart.options.scales.x.ticks.color = colors.textColor;
            dailySalesChart.options.scales.yUnits.ticks.color = colors.textColor;
            dailySalesChart.options.scales.yUnits.grid.color = colors.gridColor;
            dailySalesChart.options.scales.yRevenue.ticks.color = colors.textColor;
            dailySalesChart.options.plugins.legend.labels.color = colors.textColor;
            dailySalesChart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
            if (dailySalesChart.data.datasets[1]) {
                const ctx = dailyCanvas.getContext('2d');
                const gradient = ctx.createLinearGradient(0, 0, 0, 260);
                gradient.addColorStop(0, colors.revGradientTop);
                gradient.addColorStop(1, colors.revGradientBottom);
                dailySalesChart.data.datasets[1].backgroundColor = gradient;
            }
            dailySalesChart.update();
        }
        if (paymentChart) {
            paymentChart.data.datasets[0].borderColor = colors.donutBorder;
            paymentChart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
            paymentChart.update();
        }
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme']
    });
});
</script>

<?php include '../includes/footer.php'; ?>
