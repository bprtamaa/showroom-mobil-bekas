<?php
// reports/finance.php - Laporan Laba Kotor & Keuangan Showroom
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

$pageTitle = 'Laporan Laba Kotor';

$db = new Database();
$conn = $db->connect();

// Get filter parameters
$reportType = isset($_GET['report_type']) && in_array($_GET['report_type'], ['yearly', 'monthly']) ? $_GET['report_type'] : 'yearly';
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// Get available years - Only from PAID transactions
$stmt = $conn->query("SELECT DISTINCT YEAR(transaction_date) as year 
                      FROM transactions 
                      WHERE payment_status = 'paid'
                      ORDER BY year DESC");
$years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure current year is included
$currentYear = (int)date('Y');
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

// Month names
$monthNames = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret',
    4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September',
    10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];

$reportData = [];
$totals = [
    'transactions' => 0,
    'sales' => 0,
    'discount' => 0,
    'tax' => 0,
    'revenue' => 0,
    'cogs' => 0,
    'profit' => 0,
    'expenses' => 0,
    'net_profit' => 0
];

if ($reportType === 'yearly') {
    // Annual summary (Group by Month)
    $stmt = $conn->prepare("SELECT 
                            MONTH(t.transaction_date) as time_unit,
                            COUNT(*) as total_transactions,
                            COALESCE(SUM(t.sale_price), 0) as total_sales,
                            COALESCE(SUM(t.discount), 0) as total_discount,
                            COALESCE(SUM(t.tax), 0) as total_tax,
                            COALESCE(SUM(t.total_amount), 0) as total_revenue,
                            COALESCE(SUM(v.purchase_price), 0) as total_purchase_cost
                          FROM transactions t
                          LEFT JOIN vehicles v ON t.vehicle_id = v.id
                          WHERE YEAR(t.transaction_date) = ?
                          AND t.payment_status = 'paid'
                          GROUP BY MONTH(t.transaction_date)
                          ORDER BY time_unit ASC");
    $stmt->execute([$year]);
    $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Maintenance costs by month
    $stmt = $conn->prepare("SELECT 
                            MONTH(end_date) as time_unit,
                            COALESCE(SUM(cost), 0) as total_maintenance
                          FROM maintenance
                          WHERE status = 'completed' 
                            AND end_date IS NOT NULL
                            AND YEAR(end_date) = ?
                          GROUP BY MONTH(end_date)");
    $stmt->execute([$year]);
    $maintenanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Operational expenses by month
    $stmt = $conn->prepare("SELECT 
                            MONTH(expense_date) as time_unit,
                            COALESCE(SUM(amount), 0) as total_expenses
                          FROM expenses
                          WHERE YEAR(expense_date) = ?
                          GROUP BY MONTH(expense_date)");
    $stmt->execute([$year]);
    $expensesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $maintByMonth = [];
    foreach ($maintenanceData as $m) {
        $maintByMonth[$m['time_unit']] = (float)$m['total_maintenance'];
    }

    $expByMonth = [];
    foreach ($expensesData as $e) {
        $expByMonth[$e['time_unit']] = (float)$e['total_expenses'];
    }

    $salesByMonth = [];
    foreach ($salesData as $s) {
        $salesByMonth[$s['time_unit']] = $s;
    }

    for ($m = 1; $m <= 12; $m++) {
        $s = $salesByMonth[$m] ?? [
            'time_unit' => $m,
            'total_transactions' => 0,
            'total_sales' => 0,
            'total_discount' => 0,
            'total_tax' => 0,
            'total_revenue' => 0,
            'total_purchase_cost' => 0
        ];
        
        $maintCost = $maintByMonth[$m] ?? 0;
        $expCost = $expByMonth[$m] ?? 0;
        $total_cogs = $s['total_purchase_cost'] + $maintCost;
        $profit = ($s['total_sales'] - $s['total_discount']) - $total_cogs;
        $netProfit = $profit - $expCost;
        
        $reportData[] = [
            'label' => $monthNames[$m],
            'transactions' => $s['total_transactions'],
            'sales' => $s['total_sales'],
            'discount' => $s['total_discount'],
            'tax' => $s['total_tax'],
            'revenue' => $s['total_revenue'],
            'cogs' => $total_cogs,
            'purchase_cost' => $s['total_purchase_cost'],
            'maintenance_cost' => $maintCost,
            'expenses' => $expCost,
            'profit' => $profit,
            'net_profit' => $netProfit
        ];
        
        $totals['transactions'] += $s['total_transactions'];
        $totals['sales'] += $s['total_sales'];
        $totals['discount'] += $s['total_discount'];
        $totals['tax'] += $s['total_tax'];
        $totals['revenue'] += $s['total_revenue'];
        $totals['cogs'] += $total_cogs;
        $totals['profit'] += $profit;
        $totals['expenses'] += $expCost;
        $totals['net_profit'] += $netProfit;
    }
} else {
    // Monthly breakdown (Group by Day)
    $stmt = $conn->prepare("SELECT 
                            DATE(t.transaction_date) as transaction_date,
                            COUNT(*) as total_transactions,
                            COALESCE(SUM(t.sale_price), 0) as total_sales,
                            COALESCE(SUM(t.discount), 0) as total_discount,
                            COALESCE(SUM(t.tax), 0) as total_tax,
                            COALESCE(SUM(t.total_amount), 0) as total_revenue,
                            COALESCE(SUM(v.purchase_price), 0) as total_purchase_cost
                          FROM transactions t
                          LEFT JOIN vehicles v ON t.vehicle_id = v.id
                          WHERE YEAR(t.transaction_date) = ? AND MONTH(t.transaction_date) = ?
                          AND t.payment_status = 'paid'
                          GROUP BY DATE(t.transaction_date)
                          ORDER BY transaction_date ASC");
    $stmt->execute([$year, $month]);
    $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT 
                            DATE(end_date) as maint_date,
                            COALESCE(SUM(cost), 0) as total_maintenance
                          FROM maintenance
                          WHERE status = 'completed' 
                            AND end_date IS NOT NULL
                            AND YEAR(end_date) = ? AND MONTH(end_date) = ?
                          GROUP BY DATE(end_date)");
    $stmt->execute([$year, $month]);
    $maintenanceData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $conn->prepare("SELECT 
                            DATE(expense_date) as exp_date,
                            COALESCE(SUM(amount), 0) as total_expenses
                          FROM expenses
                          WHERE YEAR(expense_date) = ? AND MONTH(expense_date) = ?
                          GROUP BY DATE(expense_date)");
    $stmt->execute([$year, $month]);
    $expensesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mergedData = [];
    foreach ($salesData as $sale) {
        $date = $sale['transaction_date'];
        $mergedData[$date] = [
            'time_unit' => (int)date('d', strtotime($date)),
            'transaction_date' => $date,
            'total_transactions' => $sale['total_transactions'],
            'total_sales' => $sale['total_sales'],
            'total_discount' => $sale['total_discount'],
            'total_tax' => $sale['total_tax'],
            'total_revenue' => $sale['total_revenue'],
            'total_purchase_cost' => $sale['total_purchase_cost'],
            'total_maintenance_cost' => 0,
            'total_expenses' => 0
        ];
    }
    
    foreach ($maintenanceData as $maint) {
        $date = $maint['maint_date'];
        if (isset($mergedData[$date])) {
            $mergedData[$date]['total_maintenance_cost'] = (float)$maint['total_maintenance'];
        } else {
            $mergedData[$date] = [
                'time_unit' => (int)date('d', strtotime($date)),
                'transaction_date' => $date,
                'total_transactions' => 0,
                'total_sales' => 0,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_revenue' => 0,
                'total_purchase_cost' => 0,
                'total_maintenance_cost' => (float)$maint['total_maintenance'],
                'total_expenses' => 0
            ];
        }
    }

    foreach ($expensesData as $exp) {
        $date = $exp['exp_date'];
        if (isset($mergedData[$date])) {
            $mergedData[$date]['total_expenses'] = (float)$exp['total_expenses'];
        } else {
            $mergedData[$date] = [
                'time_unit' => (int)date('d', strtotime($date)),
                'transaction_date' => $date,
                'total_transactions' => 0,
                'total_sales' => 0,
                'total_discount' => 0,
                'total_tax' => 0,
                'total_revenue' => 0,
                'total_purchase_cost' => 0,
                'total_maintenance_cost' => 0,
                'total_expenses' => (float)$exp['total_expenses']
            ];
        }
    }
    
    ksort($mergedData);

    foreach ($mergedData as $data) {
        $total_cogs = $data['total_purchase_cost'] + $data['total_maintenance_cost'];
        $profit = ($data['total_sales'] - $data['total_discount']) - $total_cogs;
        $expCost = $data['total_expenses'] ?? 0;
        $netProfit = $profit - $expCost;
        
        $reportData[] = [
            'label' => date('d', strtotime($data['transaction_date'])) . ' ' . $monthNames[$month],
            'transactions' => $data['total_transactions'],
            'sales' => $data['total_sales'],
            'discount' => $data['total_discount'],
            'tax' => $data['total_tax'],
            'revenue' => $data['total_revenue'],
            'cogs' => $total_cogs,
            'purchase_cost' => $data['total_purchase_cost'],
            'maintenance_cost' => $data['total_maintenance_cost'],
            'expenses' => $expCost,
            'profit' => $profit,
            'net_profit' => $netProfit
        ];
        
        $totals['transactions'] += $data['total_transactions'];
        $totals['sales'] += $data['total_sales'];
        $totals['discount'] += $data['total_discount'];
        $totals['tax'] += $data['total_tax'];
        $totals['revenue'] += $data['total_revenue'];
        $totals['cogs'] += $total_cogs;
        $totals['profit'] += $profit;
        $totals['expenses'] += $expCost;
        $totals['net_profit'] += $netProfit;
    }
}

$net_sales = $totals['sales'] - $totals['discount'];
$overall_margin = $net_sales > 0 ? ($totals['profit'] / $net_sales) * 100 : 0;
$overall_net_margin = $net_sales > 0 ? ($totals['net_profit'] / $net_sales) * 100 : 0;
$total_purchase_cost = array_sum(array_column($reportData, 'purchase_cost'));
$total_maintenance_cost = array_sum(array_column($reportData, 'maintenance_cost'));
$total_expenses_cost = array_sum(array_column($reportData, 'expenses'));

// Prepare chart datasets
$financeChartLabels = array_column($reportData, 'label');
$financeChartNetSales = array_map(function($d) { return (float)($d['sales'] - $d['discount']); }, $reportData);
$financeChartCOGS = array_map(function($d) { return (float)$d['cogs']; }, $reportData);
$financeChartExpenses = array_map(function($d) { return (float)$d['expenses']; }, $reportData);
$financeChartProfit = array_map(function($d) { return (float)$d['profit']; }, $reportData);
$financeChartNetProfit = array_map(function($d) { return (float)$d['net_profit']; }, $reportData);

// Donut chart data: Cost & Profit structure
$compTotal = $net_sales > 0 ? $net_sales : ($total_purchase_cost + $total_maintenance_cost + $total_expenses_cost + max(0, $totals['net_profit']));
$compData = [
    [
        'label' => 'Modal Beli Unit',
        'value' => (float)$total_purchase_cost,
        'color' => '#6366f1',
        'hover' => '#4f46e5',
        'pct' => $compTotal > 0 ? round(($total_purchase_cost / $compTotal) * 100, 1) : 0
    ],
    [
        'label' => 'Biaya Perawatan',
        'value' => (float)$total_maintenance_cost,
        'color' => '#f59e0b',
        'hover' => '#d97706',
        'pct' => $compTotal > 0 ? round(($total_maintenance_cost / $compTotal) * 100, 1) : 0
    ],
    [
        'label' => 'Kas Operasional',
        'value' => (float)$total_expenses_cost,
        'color' => '#ef4444',
        'hover' => '#dc2626',
        'pct' => $compTotal > 0 ? round(($total_expenses_cost / $compTotal) * 100, 1) : 0
    ],
    [
        'label' => 'Laba Bersih',
        'value' => (float)max(0, $totals['net_profit']),
        'color' => '#10b981',
        'hover' => '#059669',
        'pct' => $compTotal > 0 ? round((max(0, $totals['net_profit']) / $compTotal) * 100, 1) : 0
    ]
];

$printCompanyName = defined('APP_NAME') ? APP_NAME : 'Mulya Motor Showroom';
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
        grid-template-columns: repeat(5, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }
    @media (max-width: 1300px) {
        .summary-grid { grid-template-columns: repeat(3, 1fr); }
    }
    @media (max-width: 860px) {
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 576px) {
        .summary-grid { grid-template-columns: 1fr; }
    }
    .summary-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        transition: all 0.2s;
        min-width: 0;
    }
    .summary-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        transform: translateY(-1px);
    }
    .summary-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .summary-icon.slate { background: #f1f5f9; color: #475569; }
    .summary-icon.emerald { background: #dcfce7; color: #166534; }
    .summary-icon.blue { background: #eff6ff; color: #2563eb; }
    .summary-icon.amber { background: #fef3c7; color: #b45309; }
    .summary-icon.rose { background: #fff1f2; color: #e11d48; }

    .summary-info {
        min-width: 0;
        flex: 1;
        overflow: hidden;
    }
    .summary-value {
        font-size: 1.02rem;
        font-weight: 800;
        color: #1e293b;
        line-height: 1.25;
        letter-spacing: -0.02em;
        white-space: nowrap !important;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .summary-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-top: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
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
    @media (max-width: 992px) {
        .search-row { flex-wrap: wrap; }
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

    /* ===== Table Card ===== */
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

    .vehicles-table {
        width: 100%;
        min-width: 870px;
        border-collapse: collapse;
    }
    .vehicles-table thead th {
        padding: 12px 10px;
        font-size: 0.76rem;
        font-weight: 700;
        color: #475569;
        text-transform: none;
        letter-spacing: 0.01em;
        border-top: 1px solid #edf2f7;
        border-bottom: 2px solid #e2e8f0;
        background: #f8fafc;
        white-space: nowrap !important;
        vertical-align: middle;
    }
    .vehicles-table tbody td {
        padding: 9px 8px;
        font-size: 0.81rem;
        color: #334155;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        white-space: nowrap !important;
        font-variant-numeric: tabular-nums;
    }
    .vehicles-table tbody tr {
        transition: background 0.15s;
    }
    .vehicles-table tbody tr:hover {
        background: #f8fafc;
    }
    .vehicles-table tfoot td {
        padding: 11px 8px;
        white-space: nowrap !important;
        font-variant-numeric: tabular-nums;
    }
    .price-text {
        font-weight: 700;
        font-size: 0.88rem;
        white-space: nowrap !important;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card,
    [data-theme="dark"] .search-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .filter-select { background: #0f172a; border-color: #334155; color: #f1f5f9; }
    [data-theme="dark"] .btn-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-top-color: #334155; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .vehicles-table tfoot { background: #1a2332 !important; border-top-color: #334155 !important; }
    [data-theme="dark"] .vehicles-table tfoot td { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table tfoot td.text-dark { color: #f1f5f9 !important; }
    [data-theme="dark"] .vehicles-table tfoot td.text-muted { color: #94a3b8 !important; }

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
        <h1>Laporan Laba Kotor & Keuangan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>reports/" class="text-primary text-decoration-underline">Laporan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Laba Kotor</li>
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

<?php 
$net_sales = $totals['sales'] - $totals['discount'];
$overall_margin = $net_sales > 0 ? ($totals['profit'] / $net_sales) * 100 : 0;
?>

<!-- Summary Grid -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-wallet"></i>
        </div>
        <div class="summary-info">
            <div class="summary-value"><?php echo formatCurrency($net_sales); ?></div>
            <div class="summary-label">Penjualan Bersih</div>
            <small class="text-muted text-nowrap d-block" style="font-size: 0.72rem;">Setelah diskon penjualan</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-shopping-bag"></i>
        </div>
        <div class="summary-info">
            <div class="summary-value text-danger"><?php echo formatCurrency($totals['cogs']); ?></div>
            <div class="summary-label">Total HPP (Beli + Rawat)</div>
            <small class="text-muted text-nowrap d-block" style="font-size: 0.72rem;">Beban modal unit</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon rose">
            <i class="fas fa-receipt"></i>
        </div>
        <div class="summary-info">
            <div class="summary-value text-danger"><?php echo formatCurrency($totals['expenses']); ?></div>
            <div class="summary-label">Kas Operasional</div>
            <small class="text-muted text-nowrap d-block" style="font-size: 0.72rem;">Beban overhead showroom</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="summary-info">
            <div class="summary-value text-primary"><?php echo formatCurrency($totals['profit']); ?></div>
            <div class="summary-label">Total Laba Kotor</div>
            <small class="text-muted text-nowrap d-block" style="font-size: 0.72rem;">Margin: <?php echo number_format($overall_margin, 1); ?>%</small>
        </div>
    </div>

    <div class="summary-card" style="border: 2px solid <?php echo $totals['net_profit'] >= 0 ? '#10b981' : '#ef4444'; ?>;">
        <div class="summary-icon emerald">
            <i class="fas fa-coins"></i>
        </div>
        <div class="summary-info">
            <div class="summary-value text-success"><?php echo formatCurrency($totals['net_profit']); ?></div>
            <div class="summary-label fw-bold text-dark">Total Laba Bersih</div>
            <small class="text-success fw-bold text-nowrap d-block" style="font-size: 0.72rem;">Net Margin: <?php echo number_format($overall_net_margin, 1); ?>%</small>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <select name="report_type" id="reportTypeSelect" class="filter-select" onchange="this.form.submit()">
                    <option value="yearly" <?php echo $reportType == 'yearly' ? 'selected' : ''; ?>>Laporan Tahunan</option>
                    <option value="monthly" <?php echo $reportType == 'monthly' ? 'selected' : ''; ?>>Laporan Bulanan</option>
                </select>

                <select name="year" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y['year']; ?>" <?php echo $y['year'] == $year ? 'selected' : ''; ?>>
                        Tahun <?php echo $y['year']; ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($reportType == 'monthly'): ?>
                <select name="month" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($monthNames as $mNum => $mName): ?>
                    <option value="<?php echo $mNum; ?>" <?php echo $mNum == $month ? 'selected' : ''; ?>>
                        Bulan <?php echo $mName; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

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
                    <i class="fas fa-chart-area text-primary"></i> Performa Penjualan, Beban & Laba Bersih
                </h5>
                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold px-2.5 py-1" style="font-size:0.75rem; border-radius: 6px;">
                    <?php echo ($reportType == 'monthly') ? $monthNames[$month] . ' ' . $year : 'Tahun ' . $year; ?>
                </span>
            </div>
            <div class="card-body p-3 p-md-4 flex-grow-1 d-flex flex-column justify-content-center">
                <div style="position: relative; height: 280px; width: 100%;">
                    <canvas id="financeChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="table-card h-100 mb-0 d-flex flex-column">
            <div class="table-card-header d-flex align-items-center justify-content-between">
                <h5 class="table-card-title mb-0">
                    <i class="fas fa-chart-pie text-info"></i> Struktur Alokasi Penjualan
                </h5>
                <span class="badge bg-success bg-opacity-10 text-success fw-semibold px-2.5 py-1" style="font-size:0.75rem; border-radius: 6px;">
                    Net Margin <?php echo number_format($overall_net_margin, 1); ?>%
                </span>
            </div>
            <div class="card-body p-3 p-md-4 flex-grow-1 d-flex flex-column justify-content-between">
                <?php if ($net_sales > 0 || $totals['cogs'] > 0 || $totals['expenses'] > 0): ?>
                <div class="d-flex align-items-center justify-content-center my-auto" style="position: relative; height: 160px; width: 100%;">
                    <canvas id="financeDonutChart"></canvas>
                </div>
                <!-- Custom Legend / Mini Summary -->
                <div class="mt-3 pt-2 border-top border-light-subtle">
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($compData as $cd): ?>
                        <div class="d-flex align-items-center justify-content-between small gap-2">
                            <div class="d-flex align-items-center gap-2 text-truncate" style="min-width: 0;">
                                <span style="width: 10px; height: 10px; border-radius: 50%; background-color: <?php echo $cd['color']; ?>; display: inline-block; flex-shrink: 0;"></span>
                                <span class="fw-medium text-dark-emphasis text-truncate" style="font-size: 0.8rem;"><?php echo htmlspecialchars($cd['label']); ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 text-nowrap flex-shrink-0">
                                <span class="text-muted fw-semibold text-nowrap" style="font-size:0.78rem;"><?php echo formatCurrency($cd['value']); ?></span>
                                <span class="badge bg-light text-dark border px-2 py-0.5" style="font-size: 0.7rem; font-weight: 600; min-width: 42px; text-align: center;"><?php echo $cd['pct']; ?>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted my-auto">
                    <i class="fas fa-chart-pie fa-2x mb-2 text-muted opacity-50"></i>
                    <div class="small fw-semibold">Belum ada data keuangan pada periode ini.</div>
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
            <i class="fas fa-table text-primary"></i> Rincian Laba Rugi & Laba Bersih <?php echo ($reportType == 'monthly') ? 'Harian (' . $monthNames[$month] . ' ' . $year . ')' : 'Bulanan (' . $year . ')'; ?>
        </h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th class="ps-3" style="min-width: 85px;"><?php echo ($reportType == 'monthly') ? 'Tanggal' : 'Bulan'; ?></th>
                        <th style="min-width: 105px;" class="text-end">Penjualan</th>
                        <th style="min-width: 100px;" class="text-end">Modal Beli</th>
                        <th style="min-width: 90px;" class="text-end">Biaya Rawat</th>
                        <th style="min-width: 100px;" class="text-end">Total HPP</th>
                        <th style="min-width: 95px;" class="text-end">Laba Kotor</th>
                        <th style="min-width: 110px;" class="text-end">Kas Operasional</th>
                        <th style="min-width: 105px;" class="text-end">Laba Bersih</th>
                        <th style="min-width: 80px;" class="text-center pe-3">Net Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach ($reportData as $row): 
                        $row_net = $row['sales'] - $row['discount'];
                        $net_margin = $row_net > 0 ? ($row['net_profit'] / $row_net) * 100 : 0;
                    ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($row['label']); ?></div>
                        </td>
                        <td class="text-end fw-semibold text-dark"><?php echo formatCurrency($row_net); ?></td>
                        <td class="text-end text-muted"><?php echo formatCurrency($row['purchase_cost']); ?></td>
                        <td class="text-end text-muted"><?php echo formatCurrency($row['maintenance_cost']); ?></td>
                        <td class="text-end fw-semibold text-danger"><?php echo formatCurrency($row['cogs']); ?></td>
                        <td class="text-end fw-semibold <?php echo $row['profit'] >= 0 ? 'text-primary' : 'text-danger'; ?>">
                            <?php echo formatCurrency($row['profit']); ?>
                        </td>
                        <td class="text-end text-danger"><?php echo formatCurrency($row['expenses']); ?></td>
                        <td class="text-end fw-bold <?php echo $row['net_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo formatCurrency($row['net_profit']); ?>
                        </td>
                        <td class="text-center pe-3">
                            <span class="badge <?php echo $net_margin >= 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'; ?>" style="font-size: 0.72rem;">
                                <?php echo number_format($net_margin, 1); ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background: #f8fafc; border-top: 2px solid #cbd5e1;">
                    <tr class="fw-bold">
                        <td class="ps-3 text-dark font-monospace" style="font-size: 0.78rem;">TOTAL</td>
                        <td class="text-end text-dark"><?php echo formatCurrency($net_sales); ?></td>
                        <td class="text-end text-muted"><?php echo formatCurrency($total_purchase_cost); ?></td>
                        <td class="text-end text-muted"><?php echo formatCurrency($total_maintenance_cost); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totals['cogs']); ?></td>
                        <td class="text-end text-primary"><?php echo formatCurrency($totals['profit']); ?></td>
                        <td class="text-end text-danger"><?php echo formatCurrency($totals['expenses']); ?></td>
                        <td class="text-end text-success fw-bold"><?php echo formatCurrency($totals['net_profit']); ?></td>
                        <td class="text-center pe-3">
                            <span class="badge bg-success text-white px-2 py-1" style="font-size: 0.75rem; font-weight: 700;">
                                <?php echo number_format($overall_net_margin, 1); ?>%
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Print-Only Layout -->
<div class="print-only">
    <div style="text-align: center; margin-bottom: 20px;">
        <h2 style="margin: 0; font-size: 18px;"><?php echo htmlspecialchars($printCompanyName); ?></h2>
        <h3 style="margin: 5px 0; font-size: 15px;">LAPORAN LABA RUGI & LABA BERSIH</h3>
        <p style="margin: 0; font-size: 12px; color: #666;">
            Periode: <?php echo ($reportType == 'monthly') ? $monthNames[$month] . ' ' . $year : 'Tahun ' . $year; ?>
        </p>
    </div>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <thead>
            <tr style="background-color: #f5f5f5;">
                <th style="padding: 6px; border: 1px solid #ddd; font-size: 11px;">Periode</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Penjualan</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Total HPP</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Laba Kotor</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Kas Operasional</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Laba Bersih</th>
                <th style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;">Net Margin</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($reportData as $row): 
                $row_net = $row['sales'] - $row['discount'];
                $net_margin = $row_net > 0 ? ($row['net_profit'] / $row_net) * 100 : 0;
            ?>
            <tr>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($row['label']); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row_net, 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row['cogs'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row['profit'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row['expenses'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row['net_profit'], 0, ',', '.'); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo number_format($net_margin, 1); ?>%</td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f5f5f5; font-weight: bold;">
                <td style="padding: 8px; border: 1px solid #ddd; font-size: 11px;">Total:</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($net_sales, 0, ',', '.'); ?></td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($totals['cogs'], 0, ',', '.'); ?></td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($totals['profit'], 0, ',', '.'); ?></td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($totals['expenses'], 0, ',', '.'); ?></td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($totals['net_profit'], 0, ',', '.'); ?></td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo number_format($overall_net_margin, 1); ?>%</td>
            </tr>
        </tbody>
    </table>
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
            profitGradientTop: isDark ? 'rgba(16, 185, 129, 0.35)' : 'rgba(16, 185, 129, 0.20)',
            profitGradientBottom: isDark ? 'rgba(16, 185, 129, 0.00)' : 'rgba(16, 185, 129, 0.00)',
            donutBorder: isDark ? '#1e293b' : '#ffffff'
        };
    }

    let colors = getThemeColors();
    let financeChart = null;
    let financeDonutChart = null;

    // 1. Finance Main Combo Chart (Penjualan, HPP, Kas Operasional, Laba Bersih)
    const finCanvas = document.getElementById('financeChart');
    if (finCanvas) {
        const finCtx = finCanvas.getContext('2d');
        const labels = <?php echo json_encode($financeChartLabels); ?>;
        const netSalesData = <?php echo json_encode($financeChartNetSales); ?>;
        const cogsData = <?php echo json_encode($financeChartCOGS); ?>;
        const expensesData = <?php echo json_encode($financeChartExpenses); ?>;
        const profitData = <?php echo json_encode($financeChartProfit); ?>;
        const netProfitData = <?php echo json_encode($financeChartNetProfit); ?>;

        function createNetProfitGradient() {
            const gradient = finCtx.createLinearGradient(0, 0, 0, 260);
            gradient.addColorStop(0, colors.profitGradientTop);
            gradient.addColorStop(1, colors.profitGradientBottom);
            return gradient;
        }

        financeChart = new Chart(finCtx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Penjualan Bersih',
                        data: netSalesData,
                        backgroundColor: 'rgba(79, 70, 229, 0.85)',
                        hoverBackgroundColor: '#4338ca',
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7,
                        order: 3
                    },
                    {
                        type: 'bar',
                        label: 'Total HPP (Modal)',
                        data: cogsData,
                        backgroundColor: 'rgba(244, 63, 94, 0.85)',
                        hoverBackgroundColor: '#e11d48',
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7,
                        order: 4
                    },
                    {
                        type: 'bar',
                        label: 'Kas Operasional',
                        data: expensesData,
                        backgroundColor: 'rgba(239, 68, 68, 0.65)',
                        hoverBackgroundColor: '#dc2626',
                        borderRadius: 6,
                        borderSkipped: false,
                        barPercentage: 0.55,
                        categoryPercentage: 0.7,
                        order: 5
                    },
                    {
                        type: 'line',
                        label: 'Laba Kotor',
                        data: profitData,
                        borderColor: '#3b82f6',
                        borderWidth: 2,
                        borderDash: [5, 5],
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#3b82f6',
                        pointBorderWidth: 2,
                        pointRadius: 3.5,
                        fill: false,
                        tension: 0.35,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Laba Bersih (Net Profit)',
                        data: netProfitData,
                        borderColor: '#10b981',
                        backgroundColor: createNetProfitGradient(),
                        borderWidth: 3.5,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#10b981',
                        pointBorderWidth: 2.5,
                        pointRadius: 4.5,
                        pointHoverRadius: 7,
                        pointHoverBackgroundColor: '#10b981',
                        pointHoverBorderColor: '#ffffff',
                        pointHoverBorderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
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
                            font: { size: 11, weight: '600' }
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
                            label: function(context) {
                                let label = context.dataset.label || '';
                                let val = context.parsed.y;
                                return ' ' + label + ': Rp ' + Number(val || 0).toLocaleString('id-ID');
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
                    y: {
                        type: 'linear',
                        display: true,
                        beginAtZero: true,
                        grid: {
                            color: colors.gridColor,
                            borderDash: [4, 4],
                            drawBorder: false
                        },
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

    // 2. Finance Structure Donut Chart
    const donutCanvas = document.getElementById('financeDonutChart');
    if (donutCanvas) {
        const donutCtx = donutCanvas.getContext('2d');
        const donutLabels = <?php echo json_encode(array_column($compData, 'label')); ?>;
        const donutValues = <?php echo json_encode(array_column($compData, 'value')); ?>;
        const donutColors = <?php echo json_encode(array_column($compData, 'color')); ?>;
        const donutHovers = <?php echo json_encode(array_column($compData, 'hover')); ?>;
        const overallMarginText = '<?php echo number_format($overall_margin, 1); ?>%';

        const centerTextPlugin = {
            id: 'financeCenterText',
            beforeDraw: function(chart) {
                const width = chart.width;
                const height = chart.height;
                const ctx = chart.ctx;
                ctx.restore();

                const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                
                // Margin Value
                ctx.font = 'bold 22px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = isDark ? '#f1f5f9' : '#0f172a';
                const textX = Math.round((width - ctx.measureText(overallMarginText).width) / 2);
                const textY = height / 2 - 8;
                ctx.fillText(overallMarginText, textX, textY);

                // Subtitle text
                ctx.font = '600 10px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
                ctx.fillStyle = isDark ? '#94a3b8' : '#64748b';
                const subText = 'MARGIN LABA';
                const subTextX = Math.round((width - ctx.measureText(subText).width) / 2);
                const subTextY = height / 2 + 12;
                ctx.fillText(subText, subTextX, subTextY);

                ctx.save();
            }
        };

        financeDonutChart = new Chart(donutCtx, {
            type: 'doughnut',
            data: {
                labels: donutLabels,
                datasets: [{
                    data: donutValues,
                    backgroundColor: donutColors,
                    hoverBackgroundColor: donutHovers,
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
                                const total = donutValues.reduce((a, b) => a + b, 0);
                                const pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                                return ` ${context.label}: Rp ${Number(val).toLocaleString('id-ID')} (${pct}%)`;
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
        if (financeChart) {
            financeChart.options.scales.x.ticks.color = colors.textColor;
            financeChart.options.scales.y.ticks.color = colors.textColor;
            financeChart.options.scales.y.grid.color = colors.gridColor;
            financeChart.options.plugins.legend.labels.color = colors.textColor;
            financeChart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
            if (financeChart.data.datasets[2]) {
                const ctx = finCanvas.getContext('2d');
                const gradient = ctx.createLinearGradient(0, 0, 0, 260);
                gradient.addColorStop(0, colors.profitGradientTop);
                gradient.addColorStop(1, colors.profitGradientBottom);
                financeChart.data.datasets[2].backgroundColor = gradient;
            }
            financeChart.update();
        }
        if (financeDonutChart) {
            financeDonutChart.data.datasets[0].borderColor = colors.donutBorder;
            financeDonutChart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
            financeDonutChart.update();
        }
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme']
    });
});
</script>

<?php include '../includes/footer.php'; ?>
