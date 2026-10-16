<?php
// dashboard/index.php
$pageTitle = 'Aplikasi Manajemen Showroom';

include '../includes/header.php';
requireLogin();

// Definisikan BASE_URL jika belum ada
if (!defined('BASE_URL')) {
    define('BASE_URL', '/showroom/');
}

// Helper functions loaded via header.php

// Get dashboard statistics
try {
    // Vehicle status breakdown
    $stmt = $db->query("SELECT status, COUNT(*) as count FROM vehicles GROUP BY status");
    $vehicleCounts = [];
    while ($row = $stmt->fetch()) {
        $vehicleCounts[$row['status']] = (int)$row['count'];
    }
    $totalVehicles = array_sum($vehicleCounts);
    $availableVehicles = $vehicleCounts['available'] ?? 0;
    $soldVehicles = $vehicleCounts['sold'] ?? 0;
    $maintenanceVehicles = $vehicleCounts['maintenance'] ?? 0;
    $reservedVehicles = $vehicleCounts['reserved'] ?? 0;
    $availablePercent = $totalVehicles > 0 ? round(($availableVehicles / $totalVehicles) * 100, 1) : 0;
    
    // Vehicles sold this month (Only count PAID transactions)
    $stmt = $db->query("
        SELECT COUNT(*) as total 
        FROM transactions 
        WHERE MONTH(transaction_date) = MONTH(CURDATE()) 
        AND YEAR(transaction_date) = YEAR(CURDATE())
        AND payment_status = 'paid'
    ");
    $soldThisMonth = $stmt->fetch()['total'];

    // Total revenue this calendar month (Only count PAID transactions)
    $stmt = $db->query("
        SELECT COALESCE(SUM(total_amount), 0) as total 
        FROM transactions 
        WHERE MONTH(transaction_date) = MONTH(CURDATE()) 
        AND YEAR(transaction_date) = YEAR(CURDATE())
        AND payment_status = 'paid'
    ");
    $revenueThisMonth = $stmt->fetch()['total'];
    
    // Recent transactions with full metadata
    $stmt = $db->query("
        SELECT t.*, v.license_plate, v.year as vehicle_year,
               CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name,
               b.name as brand_name,
               c.name as customer_name, c.phone as customer_phone
        FROM transactions t
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        JOIN customers c ON t.customer_id = c.id
        ORDER BY t.transaction_date DESC, t.id DESC
        LIMIT 6
    ");
    $recentTransactions = $stmt->fetchAll();
    
    // Active inventory valuation & stock distribution
    $stmt = $db->query("
        SELECT 
            COALESCE(SUM(purchase_price), 0) as total_modal_available,
            COALESCE(SUM(selling_price), 0) as total_omset_potential
        FROM vehicles 
        WHERE status = 'available'
    ");
    $availStats = $stmt->fetch();
    $activeAssetValue = (float)($availStats['total_modal_available'] ?? 0);
    $activePotentialValue = (float)($availStats['total_omset_potential'] ?? 0);

    // Car Type distribution for available stock
    $stmt = $db->query("
        SELECT ct.name as type_name, COUNT(v.id) as count
        FROM car_types ct
        JOIN models m ON ct.id = m.car_type_id
        JOIN vehicles v ON m.id = v.model_id AND v.status = 'available'
        GROUP BY ct.id, ct.name
        ORDER BY count DESC
        LIMIT 4
    ");
    $carTypeDistribution = $stmt->fetchAll();
    
    // Sales performance data (last 6 continuous months) - Only PAID transactions
    $monthlyDataMap = [];
    for ($i = 5; $i >= 0; $i--) {
        $mKey = date('Y-m', strtotime("-$i months"));
        $monthlyDataMap[$mKey] = [
            'month' => $mKey,
            'label' => formatDate($mKey . '-01', 'M Y'),
            'sales' => 0,
            'revenue' => 0
        ];
    }

    $stmt = $db->query("
        SELECT 
            DATE_FORMAT(t.transaction_date, '%Y-%m') as month,
            COUNT(t.id) as total_sales,
            COALESCE(SUM(t.total_amount), 0) as total_revenue
        FROM transactions t
        WHERE t.transaction_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
        AND t.payment_status = 'paid'
        GROUP BY DATE_FORMAT(t.transaction_date, '%Y-%m')
        ORDER BY month ASC
    ");
    while ($row = $stmt->fetch()) {
        if (isset($monthlyDataMap[$row['month']])) {
            $monthlyDataMap[$row['month']]['sales'] = (int)$row['total_sales'];
            $monthlyDataMap[$row['month']]['revenue'] = (float)$row['total_revenue'];
        }
    }

    $chartLabels = [];
    $chartSales = [];
    $chartRevenue = [];
    $chartTotalRevenue6M = 0;
    $chartTotalSales6M = 0;

    foreach ($monthlyDataMap as $m) {
        $chartLabels[] = $m['label'];
        $chartSales[] = $m['sales'];
        $chartRevenue[] = $m['revenue'];
        $chartTotalRevenue6M += $m['revenue'];
        $chartTotalSales6M += $m['sales'];
    }

    // Monthly growth comparison (current month vs previous month)
    $currentMonthKey = date('Y-m');
    $prevMonthKey = date('Y-m', strtotime('-1 month'));
    $currRev = $monthlyDataMap[$currentMonthKey]['revenue'] ?? 0;
    $prevRev = $monthlyDataMap[$prevMonthKey]['revenue'] ?? 0;
    $revGrowthPct = $prevRev > 0 ? round((($currRev - $prevRev) / $prevRev) * 100, 1) : ($currRev > 0 ? 100 : 0);

    // Aging Stock Breakdown for available vehicles
    $stmt = $db->query("
        SELECT 
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) <= 30 THEN 1 ELSE 0 END) as fresh_count,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) BETWEEN 31 AND 60 THEN 1 ELSE 0 END) as warning_count,
            SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) > 60 THEN 1 ELSE 0 END) as critical_count,
            COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), COALESCE(purchase_date, DATE(created_at))) > 60 THEN purchase_price ELSE 0 END), 0) as critical_capital_locked
        FROM vehicles 
        WHERE status = 'available'
    ");
    $agingSummary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'fresh_count' => 0,
        'warning_count' => 0,
        'critical_count' => 0,
        'critical_capital_locked' => 0
    ];

    // List of Critical / Stalled vehicles (>60 days) in stock
    $stmt = $db->query("
        SELECT v.*, b.name as brand_name, m.name as model_name,
               DATEDIFF(CURDATE(), COALESCE(v.purchase_date, DATE(v.created_at))) as days_in_stock
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        WHERE v.status = 'available'
        AND DATEDIFF(CURDATE(), COALESCE(v.purchase_date, DATE(v.created_at))) > 60
        ORDER BY days_in_stock DESC
        LIMIT 5
    ");
    $criticalVehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Finance Summary (Admin only) - Berdasarkan Tahun Berjalan
    $financeData = null;
    if (isAdmin()) {
        $currentYear = (int)date('Y');

        // Total modal (harga beli kendaraan yang terjual pada tahun berjalan)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(v.purchase_price), 0) as total_modal
            FROM transactions t
            JOIN vehicles v ON t.vehicle_id = v.id
            WHERE t.payment_status = 'paid'
            AND YEAR(t.transaction_date) = ?
        ");
        $stmt->execute([$currentYear]);
        $totalModal = (float)$stmt->fetch()['total_modal'];

        // Total pendapatan (dari transaksi paid pada tahun berjalan)
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(t.total_amount), 0) as total_pendapatan
            FROM transactions t
            WHERE t.payment_status = 'paid'
            AND YEAR(t.transaction_date) = ?
        ");
        $stmt->execute([$currentYear]);
        $totalPendapatan = (float)$stmt->fetch()['total_pendapatan'];

        // Total biaya perawatan pada tahun berjalan
        $totalPerawatan = 0;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(cost), 0) as total 
                FROM maintenance 
                WHERE YEAR(COALESCE(start_date, created_at)) = ?
            ");
            $stmt->execute([$currentYear]);
            $totalPerawatan = (float)$stmt->fetch()['total'];
        } catch (PDOException $e) {}

        // Total Kas Operasional / Beban Overhead pada tahun berjalan
        $totalExpenses = 0;
        try {
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(amount), 0) as total 
                FROM expenses 
                WHERE YEAR(expense_date) = ?
            ");
            $stmt->execute([$currentYear]);
            $totalExpenses = (float)$stmt->fetch()['total'];
        } catch (PDOException $e) {}

        // Laba kotor
        $labaKotor = $totalPendapatan - $totalModal - $totalPerawatan;

        // Laba Bersih (Net Profit)
        $labaBersih = $labaKotor - $totalExpenses;

        // Rata-rata margin per unit pada tahun berjalan
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT t.id) as total_unit
            FROM transactions t
            WHERE t.payment_status = 'paid'
            AND YEAR(t.transaction_date) = ?
        ");
        $stmt->execute([$currentYear]);
        $totalUnitTerjual = (int)$stmt->fetch()['total_unit'];
        $avgMargin = $totalUnitTerjual > 0 ? $labaKotor / $totalUnitTerjual : 0;
        $avgNetMargin = $totalUnitTerjual > 0 ? $labaBersih / $totalUnitTerjual : 0;

        $marginPercent = ($totalPendapatan > 0) ? round(($labaKotor / $totalPendapatan) * 100, 1) : 0;
        $netMarginPercent = ($totalPendapatan > 0) ? round(($labaBersih / $totalPendapatan) * 100, 1) : 0;

        $financeData = [
            'modal' => $totalModal,
            'pendapatan' => $totalPendapatan,
            'perawatan' => $totalPerawatan,
            'operasional' => $totalExpenses,
            'laba' => $labaKotor,
            'laba_bersih' => $labaBersih,
            'unit_terjual' => $totalUnitTerjual,
            'avg_margin' => $avgMargin,
            'avg_net_margin' => $avgNetMargin,
            'margin_percent' => $marginPercent,
            'net_margin_percent' => $netMarginPercent
        ];
    }
    
} catch (PDOException $e) {
    $error = "Gagal memuat data beranda: " . $e->getMessage();
}
?>

<style>
/* Modern Executive Dashboard Styling */
:root {
    --stat-bg: #ffffff;
    --stat-border: #e2e8f0;
    --stat-border-hover: #cbd5e1;
    --stat-text-title: #64748b;
    --stat-text-val: #0f172a;
    --stat-shadow: 0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.02);
    --stat-shadow-hover: 0 8px 16px -4px rgba(15, 23, 42, 0.07), 0 2px 4px -2px rgba(15, 23, 42, 0.03);
    --primary-color: #4361ee;
    --success-color: #10b981;
    --info-color: #3b82f6;
    --warning-color: #f59e0b;
    --text-dark: #1e293b;
    --text-muted: #64748b;
    --card-bg: #ffffff;
    --card-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    --hover-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
}

body {
    background-color: #f8fafc;
}

/* Page Header */
.page-header {
    margin-bottom: 2rem;
    padding-top: 1rem;
}

.page-header h1 {
    font-weight: 800;
    color: var(--text-dark);
    font-size: 1.75rem;
    letter-spacing: -0.025em;
    display: flex;
    flex-direction: column;
}

.page-header h1 i {
    display: none;
}

.page-header .welcome-sub {
    font-size: 0.925rem;
    color: var(--text-muted);
    font-weight: 400;
    margin-top: 0.2rem;
    margin-left: 0 !important;
}

/* ========================================================
   Modern Professional Summary & Metric Cards
   ======================================================== */
.summary-card {
    background: var(--stat-bg);
    border: 1px solid var(--stat-border);
    border-radius: 12px;
    padding: 1.15rem 1.25rem;
    box-shadow: var(--stat-shadow);
    transition: transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s cubic-bezier(0.16, 1, 0.3, 1), border-color 0.2s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--stat-shadow-hover);
    border-color: var(--stat-border-hover);
}

.summary-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.75rem;
}

.summary-title-group {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.summary-label {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--stat-text-title);
    letter-spacing: 0.01em;
    margin: 0;
    line-height: 1.3;
}

.summary-sublabel {
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 2px;
    line-height: 1.2;
}

/* Minimalist Icon Badge */
.summary-icon {
    width: 34px;
    height: 34px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.875rem;
    flex-shrink: 0;
    transition: all 0.2s ease;
}

.summary-card:hover .summary-icon {
    color: #0f172a;
    border-color: #cbd5e1;
    background: #f1f5f9;
}

/* Value & Typography */
.summary-body {
    margin: 0.75rem 0 0.4rem;
}

.summary-value-wrap {
    display: flex;
    align-items: baseline;
    gap: 0.35rem;
    flex-wrap: wrap;
}

.summary-value {
    font-size: 1.625rem;
    font-weight: 700;
    color: var(--stat-text-val);
    line-height: 1.15;
    letter-spacing: -0.03em;
    font-variant-numeric: tabular-nums;
    font-feature-settings: "tnum";
}

.summary-value-sm {
    font-size: 1.35rem;
}

.summary-unit {
    font-size: 0.8125rem;
    font-weight: 500;
    color: #64748b;
}

/* Footer & Breakdown */
.summary-footer {
    border-top: 1px solid #f1f5f9;
    padding-top: 0.65rem;
    margin-top: 0.4rem;
    font-size: 0.75rem;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: space-between;
    line-height: 1.4;
}

.summary-meta-text {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: #64748b;
    font-size: 0.75rem;
}

.stat-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
    flex-shrink: 0;
}

.dot-emerald { background-color: #10b981; }
.dot-slate { background-color: #94a3b8; }
.dot-amber { background-color: #f59e0b; }
.dot-blue { background-color: #3b82f6; }

.stat-sep {
    color: #cbd5e1;
    margin: 0 2px;
}

/* Minimalist Clean Progress Track */
.summary-progress-track {
    height: 4px;
    background: #f1f5f9;
    border-radius: 99px;
    overflow: hidden;
    margin-top: 0.4rem;
    width: 100%;
}

.summary-progress-fill {
    height: 100%;
    border-radius: 99px;
    background: #10b981;
    transition: width 0.6s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Margin Badge for Profit Card */
.badge-margin {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 0.72rem;
    font-weight: 600;
    padding: 0.2rem 0.5rem;
    border-radius: 6px;
    line-height: 1.2;
    letter-spacing: 0.01em;
}

.badge-margin-positive {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}

.badge-margin-negative {
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
}

/* Highlight for Financial Profit */
.summary-card-profit {
    border-color: #d1fae5;
    background: linear-gradient(180deg, #ffffff 0%, #f7fef9 100%);
}

.summary-card-profit:hover {
    border-color: #a7f3d0;
    box-shadow: 0 8px 20px -4px rgba(16, 185, 129, 0.1);
}

.summary-card-loss {
    border-color: #ffe4e6;
    background: linear-gradient(180deg, #ffffff 0%, #fff7f7 100%);
}

.summary-card-loss:hover {
    border-color: #fecdd3;
    box-shadow: 0 8px 20px -4px rgba(225, 29, 72, 0.1);
}

.text-emerald { color: #059669 !important; }
.text-rose { color: #e11d48 !important; }

/* Card General */
.card {
    border: 1px solid var(--stat-border);
    border-radius: 14px;
    box-shadow: var(--stat-shadow);
    background: #ffffff;
    margin-bottom: 1.75rem;
    overflow: hidden;
}

.card-header {
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    padding: 1.15rem 1.35rem;
}

.card-header h5 {
    font-weight: 700;
    color: #0f172a;
    font-size: 1rem;
    letter-spacing: -0.01em;
    margin: 0;
}

.card-body {
    padding: 1.25rem 1.35rem;
}

/* ========================================================
   Recent Transactions Table
   ======================================================== */
.tx-table-wrap {
    overflow-x: auto;
}

.tx-table {
    width: 100%;
    margin-bottom: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.tx-table thead th {
    background: #f8fafc;
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.85rem 1rem;
    border-top: 1px solid #f1f5f9;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}

.tx-table tbody td {
    padding: 0.85rem 1rem;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9;
    font-size: 0.875rem;
    color: #1e293b;
    background: #ffffff;
    transition: background 0.15s ease;
}

.tx-table tbody tr:hover td {
    background: #f8fafc;
}

.tx-table tbody tr:last-child td {
    border-bottom: none;
}

/* Invoice Code Badge */
.invoice-code-badge {
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    font-size: 0.775rem;
    font-weight: 600;
    color: #334155;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    padding: 0.2rem 0.45rem;
    border-radius: 6px;
    display: inline-block;
    text-decoration: none;
    transition: all 0.15s ease;
}

.invoice-code-badge:hover {
    background: #e2e8f0;
    color: #0f172a;
}

/* Customer Avatar Initial */
.avatar-initial-sm {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #eef2ff;
    color: #4f46e5;
    font-weight: 700;
    font-size: 0.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    border: 1px solid #e0e7ff;
}

/* Vehicle Plate Tag */
.plate-badge-sm {
    font-family: ui-monospace, monospace;
    font-size: 0.7rem;
    font-weight: 600;
    background: #0f172a;
    color: #f8fafc;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    letter-spacing: 0.05em;
    display: inline-block;
}

/* Payment Method Tag */
.pay-tag {
    font-size: 0.7rem;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 2px;
}

/* Status Indicator Pills */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.725rem;
    font-weight: 600;
    padding: 0.25rem 0.65rem;
    border-radius: 99px;
    letter-spacing: 0.01em;
    white-space: nowrap;
}

.status-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    display: inline-block;
}

.status-pill-paid {
    background: #ecfdf5;
    color: #047857;
    border: 1px solid #a7f3d0;
}
.status-pill-paid .status-dot { background: #10b981; }

.status-pill-inst {
    background: #fffbeb;
    color: #b45309;
    border: 1px solid #fde68a;
}
.status-pill-inst .status-dot { background: #f59e0b; }

.status-pill-pending {
    background: #fff1f2;
    color: #be123c;
    border: 1px solid #fecdd3;
}
.status-pill-pending .status-dot { background: #f43f5e; }

/* Table Action Button */
.btn-action-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    transition: all 0.15s ease;
    text-decoration: none;
}

.btn-action-icon:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
}

/* ========================================================
   Inventory Health & Stock Distribution Card
   ======================================================== */
.inventory-widget-body {
    padding: 1.15rem 1.25rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.inventory-asset-banner {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 0.9rem 1rem;
}

.inventory-asset-label {
    font-size: 0.7rem;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    margin-bottom: 0.2rem;
}

.inventory-asset-val {
    font-size: 1.35rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.025em;
    font-variant-numeric: tabular-nums;
    line-height: 1.2;
}

.inventory-asset-sub {
    font-size: 0.72rem;
    color: #64748b;
    margin-top: 0.35rem;
    border-top: 1px dashed #e2e8f0;
    padding-top: 0.35rem;
}

/* Status Breakdown Grid */
.inventory-status-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.55rem;
}

.inventory-status-box {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.65rem 0.75rem;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    transition: all 0.15s ease;
}

.inventory-status-box:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}

.inventory-status-box-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.72rem;
    color: #64748b;
    font-weight: 500;
}

.inventory-status-box-val {
    font-size: 1.1rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
}

/* Distribution List */
.inventory-type-item {
    margin-bottom: 0.55rem;
}

.inventory-type-item:last-child {
    margin-bottom: 0;
}

.inventory-type-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.75rem;
    margin-bottom: 0.25rem;
}

.inventory-type-title {
    font-weight: 600;
    color: #1e293b;
}

.inventory-type-count {
    color: #64748b;
    font-weight: 500;
    font-size: 0.72rem;
}

.inventory-type-track {
    height: 4px;
    background: #f1f5f9;
    border-radius: 99px;
    overflow: hidden;
}

.inventory-type-fill {
    height: 100%;
    border-radius: 99px;
    background: #4361ee;
    transition: width 0.5s ease;
}



/* Quick Actions */
.action-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 1.25rem 1rem;
    text-align: center;
    transition: all 0.2s ease;
    height: 100%;
    color: #0f172a;
    text-decoration: none;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.action-card:hover {
    border-color: #4f46e5;
    background: #ffffff;
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.08);
}

.action-icon-wrapper {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.65rem;
    font-size: 1.1rem;
}

.action-title {
    display: block;
    font-weight: 600;
    font-size: 0.875rem;
    color: #1e293b;
}

.progress {
    background-color: #f1f5f9;
    border-radius: 999px;
    overflow: hidden;
}
.progress-bar {
    border-radius: 999px;
}

/* ========================================================
   Modern Chart Card & Visualizations
   ======================================================== */
.chart-card {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: var(--stat-shadow);
    transition: box-shadow 0.2s ease, border-color 0.2s ease;
    overflow: hidden;
}

.chart-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 1rem;
}

.chart-header-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #eef2ff 0%, #e0e7ff 100%);
    color: #4f46e5;
    border: 1px solid #c7d2fe;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
}

.chart-title {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.015em;
    margin: 0;
    line-height: 1.3;
}

.chart-subtitle {
    font-size: 0.775rem;
    color: #64748b;
    margin: 0;
}

/* Interactive View Mode Filter Pills */
.chart-view-toggle {
    display: inline-flex;
    background: #f1f5f9;
    padding: 3px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    gap: 2px;
}

.chart-view-btn {
    border: none;
    background: transparent;
    padding: 5px 12px;
    font-size: 0.775rem;
    font-weight: 600;
    color: #64748b;
    border-radius: 7px;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.chart-view-btn:hover {
    color: #0f172a;
}

.chart-view-btn.active {
    background: #ffffff;
    color: #4f46e5;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* KPI Ribbon Bar inside Chart Card */
.chart-kpi-ribbon {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 1rem;
    padding: 1rem 1.5rem;
    background: #f8fafc;
    border-bottom: 1px solid #f1f5f9;
}

.chart-kpi-item {
    display: flex;
    flex-direction: column;
}

.chart-kpi-label {
    font-size: 0.725rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    margin-bottom: 0.2rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.chart-kpi-val {
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    letter-spacing: -0.02em;
    font-variant-numeric: tabular-nums;
    line-height: 1.2;
}

.chart-canvas-wrap {
    position: relative;
    padding: 1.25rem 1.5rem;
    min-height: 310px;
    width: 100%;
}

/* ========================================================
   Dashboard Dark Mode Overrides
   ======================================================== */
[data-theme="dark"] body {
    background-color: #0f172a;
}

/* Override dashboard :root vars */
html[data-theme="dark"] {
    --stat-bg: #1e293b;
    --stat-border: #334155;
    --stat-border-hover: #475569;
    --stat-text-title: #94a3b8;
    --stat-text-val: #f1f5f9;
    --stat-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.3), 0 1px 2px -1px rgba(0, 0, 0, 0.2);
    --stat-shadow-hover: 0 8px 16px -4px rgba(0, 0, 0, 0.4), 0 2px 4px -2px rgba(0, 0, 0, 0.3);
    --text-dark: #f1f5f9;
    --text-muted: #94a3b8;
    --card-bg: #1e293b;
}

/* Page Header */
[data-theme="dark"] .page-header h1 {
    color: #f1f5f9;
}

[data-theme="dark"] .page-header .welcome-sub {
    color: #94a3b8;
}

/* Date Badge */
[data-theme="dark"] .badge.bg-white {
    background-color: #1e293b !important;
    color: #e2e8f0 !important;
    border-color: #334155 !important;
}

/* Summary Cards Dark Mode */
[data-theme="dark"] .summary-card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .summary-card:hover {
    border-color: #475569;
}

[data-theme="dark"] .summary-label {
    color: #94a3b8;
}

[data-theme="dark"] .summary-sublabel {
    color: #64748b;
}

[data-theme="dark"] .summary-value {
    color: #f1f5f9;
}

[data-theme="dark"] .summary-unit {
    color: #94a3b8;
}

[data-theme="dark"] .summary-icon {
    background: #0f172a;
    border-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] .summary-card:hover .summary-icon {
    color: #f1f5f9;
    border-color: #475569;
    background: #1e293b;
}

[data-theme="dark"] .summary-footer {
    border-top-color: #334155;
    color: #94a3b8;
}

[data-theme="dark"] .summary-meta-text {
    color: #94a3b8;
}

[data-theme="dark"] .stat-sep {
    color: #475569;
}

[data-theme="dark"] .summary-progress-track {
    background: #334155;
}

/* Margin Badges Dark Mode */
[data-theme="dark"] .badge-margin-positive {
    background: rgba(5, 150, 105, 0.15);
    color: #6ee7b7;
    border-color: rgba(5, 150, 105, 0.3);
}

[data-theme="dark"] .badge-margin-negative {
    background: rgba(225, 29, 72, 0.15);
    color: #fda4af;
    border-color: rgba(225, 29, 72, 0.3);
}

/* Profit/Loss Highlight Dark Mode */
[data-theme="dark"] .summary-card-profit {
    border-color: rgba(16, 185, 129, 0.3);
    background: linear-gradient(180deg, #1e293b 0%, rgba(16, 185, 129, 0.06) 100%);
}

[data-theme="dark"] .summary-card-profit:hover {
    border-color: rgba(16, 185, 129, 0.5);
    box-shadow: 0 8px 20px -4px rgba(16, 185, 129, 0.15);
}

[data-theme="dark"] .summary-card-loss {
    border-color: rgba(239, 68, 68, 0.3);
    background: linear-gradient(180deg, #1e293b 0%, rgba(239, 68, 68, 0.06) 100%);
}

[data-theme="dark"] .summary-card-loss:hover {
    border-color: rgba(239, 68, 68, 0.5);
    box-shadow: 0 8px 20px -4px rgba(239, 68, 68, 0.15);
}

/* Cards */
[data-theme="dark"] .card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .card-header {
    background: #1e293b;
    border-bottom-color: #334155;
}

[data-theme="dark"] .card-header h5,
[data-theme="dark"] .card-section-title {
    color: #f1f5f9;
}

/* Chart Card Dark Mode */
[data-theme="dark"] .chart-card {
    background: #1e293b;
    border-color: #334155;
}

[data-theme="dark"] .chart-card-header {
    background: #1e293b;
    border-bottom-color: #334155;
}

[data-theme="dark"] .chart-header-icon {
    background: rgba(79, 70, 229, 0.2);
    border-color: rgba(79, 70, 229, 0.4);
    color: #a5b4fc;
}

[data-theme="dark"] .chart-title {
    color: #f1f5f9;
}

[data-theme="dark"] .chart-subtitle {
    color: #94a3b8;
}

[data-theme="dark"] .chart-view-toggle {
    background: #0f172a;
    border-color: #334155;
}

[data-theme="dark"] .chart-view-btn {
    color: #94a3b8;
}

[data-theme="dark"] .chart-view-btn:hover {
    color: #f1f5f9;
}

[data-theme="dark"] .chart-view-btn.active {
    background: #334155;
    color: #818cf8;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3);
}

[data-theme="dark"] .chart-kpi-ribbon {
    background: #162032;
    border-bottom-color: #334155;
}

[data-theme="dark"] .chart-kpi-label {
    color: #94a3b8;
}

[data-theme="dark"] .chart-kpi-val {
    color: #f1f5f9;
}

/* Transaction Table */
[data-theme="dark"] .tx-table thead th {
    background: #162032;
    color: #94a3b8;
    border-top-color: #334155;
    border-bottom-color: #334155;
}

[data-theme="dark"] .tx-table tbody td {
    color: #e2e8f0;
    background: #1e293b;
    border-bottom-color: #334155;
}

[data-theme="dark"] .tx-table tbody tr:hover td {
    background: #334155;
}

/* Invoice Badge */
[data-theme="dark"] .invoice-code-badge {
    color: #e2e8f0;
    background: #334155;
    border-color: #475569;
}

[data-theme="dark"] .invoice-code-badge:hover {
    background: #475569;
    color: #f1f5f9;
}

/* Customer Avatar */
[data-theme="dark"] .avatar-initial-sm {
    background: rgba(79, 70, 229, 0.15);
    color: #a5b4fc;
    border-color: rgba(79, 70, 229, 0.3);
}

/* Action Button */
[data-theme="dark"] .btn-action-icon {
    border-color: #334155;
    background: #1e293b;
    color: #94a3b8;
}

[data-theme="dark"] .btn-action-icon:hover {
    background: #4f46e5;
    color: #ffffff;
    border-color: #4f46e5;
}

/* Inventory Widget Dark Mode */
[data-theme="dark"] .inventory-asset-banner {
    background: #162032;
    border-color: #334155;
}

[data-theme="dark"] .inventory-asset-label {
    color: #94a3b8;
}

[data-theme="dark"] .inventory-asset-val {
    color: #f1f5f9;
}

[data-theme="dark"] .inventory-asset-sub {
    color: #94a3b8;
    border-top-color: #334155;
}

[data-theme="dark"] .inventory-status-box {
    background: #162032;
    border-color: #334155;
}

[data-theme="dark"] .inventory-status-box:hover {
    background: #1e293b;
    border-color: #475569;
}

[data-theme="dark"] .inventory-status-box-header {
    color: #94a3b8;
}

[data-theme="dark"] .inventory-status-box-val {
    color: #f1f5f9;
}

[data-theme="dark"] .inventory-type-title {
    color: #f1f5f9;
}

[data-theme="dark"] .inventory-type-count {
    color: #94a3b8;
}

[data-theme="dark"] .inventory-type-track {
    background: #334155;
}

/* Quick Action Cards */
[data-theme="dark"] .action-card {
    background: #1e293b;
    border-color: #334155;
    color: #e2e8f0;
}

[data-theme="dark"] .action-card:hover {
    border-color: #6366f1;
    background: #334155;
    box-shadow: 0 6px 16px rgba(99, 102, 241, 0.15);
}

[data-theme="dark"] .action-title {
    color: #e2e8f0;
}

/* Progress Bar */
[data-theme="dark"] .progress {
    background-color: #334155;
}

/* Status Pill dark mode */
[data-theme="dark"] .status-pill-paid {
    background: rgba(16, 185, 129, 0.15);
    color: #6ee7b7;
    border-color: rgba(16, 185, 129, 0.3);
}

[data-theme="dark"] .status-pill-inst {
    background: rgba(245, 158, 11, 0.15);
    color: #fcd34d;
    border-color: rgba(245, 158, 11, 0.3);
}

[data-theme="dark"] .status-pill-pending {
    background: rgba(244, 63, 94, 0.15);
    color: #fda4af;
    border-color: rgba(244, 63, 94, 0.3);
}

/* Pay tag */
[data-theme="dark"] .pay-tag {
    color: #94a3b8;
}

/* Mobile table card view */
@media (max-width: 767.98px) {
    [data-theme="dark"] .table-responsive tr {
        background: #1e293b;
        border-color: #334155;
    }
    [data-theme="dark"] .table-responsive td {
        border-bottom-color: #334155;
    }
}
</style>

<style>
/* CSS Tambahan untuk Tampilan Mobile yang Rapih */
@media (max-width: 767.98px) {
    /* Transformasi Tabel menjadi Kartu */
    .table-responsive table, 
    .table-responsive tbody, 
    .table-responsive tr, 
    .table-responsive td {
        display: block;
        width: 100%;
    }
    
    .table-responsive thead {
        display: none; /* Sembunyikan header tabel */
    }
    
    .table-responsive tr {
        background: #ffffff;
        margin-bottom: 1rem;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        padding: 1.25rem;
        position: relative;
    }
    
    .table-responsive td {
        padding: 0.6rem 0 !important; /* Override padding bootstrap */
        border: none;
        border-bottom: 1px solid #f8fafc;
        display: flex;
        justify-content: space-between;
        align-items: center;
        text-align: right;
        min-height: 2.5rem;
    }
    
    .table-responsive td:last-child {
        border-bottom: none;
        padding-bottom: 0 !important;
    }
    
    .table-responsive td:first-child {
        padding-top: 0 !important;
    }
    
    /* Label untuk setiap baris data */
    .table-responsive td::before {
        content: attr(data-label);
        font-weight: 600;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: var(--text-muted);
        margin-right: 1.5rem;
        text-align: left;
        flex-shrink: 0;
    }
    
    /* Penyesuaian khusus konten */
    .table-responsive td[data-label="Mobil"] .d-flex {
        align-items: flex-end !important;
        text-align: right;
    }
    
    /* Highlight Total Amount */
    .table-responsive td[data-label="Total"] span {
        font-size: 1.1em;
    }
}
</style>


<div class="page-header">
    <div class="row align-items-center">
        <div class="col">
            <?php $displayName = htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['full_name'] ?? 'Pengguna'); ?>
            <h1>
                <span>Beranda</span>
                <span class="welcome-sub">Selamat datang kembali, <strong><?php echo $displayName; ?>!</strong></span>
            </h1>
        </div>
        <div class="col-auto">
             <span class="badge bg-white text-dark shadow-sm px-3 py-2 fw-normal"><i class="far fa-calendar-alt me-2 text-primary"></i> <?php echo date('d M Y'); ?></span>
        </div>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    <?php echo $error; ?>
</div>
<?php endif; ?>

<!-- Summary Metric Cards -->
<div class="row mb-4 g-3 g-xl-4">
    <!-- Total Armada -->
    <div class="col-xl-3 col-md-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Total Armada</span>
                    <span class="summary-sublabel">Seluruh inventaris showroom</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-car-side"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($totalVehicles); ?></span>
                    <span class="summary-unit">unit</span>
                </div>
            </div>
            <div class="summary-footer">
                <div class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span>
                    <span><?php echo $availableVehicles; ?> Siap</span>
                    <span class="stat-sep">·</span>
                    <span class="text-muted"><?php echo $soldVehicles; ?> Terjual</span>
                    <?php if ($maintenanceVehicles > 0): ?>
                    <span class="stat-sep">·</span>
                    <span class="text-muted"><?php echo $maintenanceVehicles; ?> Servis</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobil Siap Jual -->
    <div class="col-xl-3 col-md-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Mobil Siap Jual</span>
                    <span class="summary-sublabel">Unit ready di showroom</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-warehouse"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($availableVehicles); ?></span>
                    <span class="summary-unit">unit</span>
                </div>
            </div>
            <div class="summary-footer flex-column align-items-stretch">
                <div class="d-flex align-items-center justify-content-between text-muted" style="font-size: 0.73rem;">
                    <span>Rasio stok siap</span>
                    <span class="fw-semibold text-emerald"><?php echo $availablePercent; ?>%</span>
                </div>
                <div class="summary-progress-track">
                    <div class="summary-progress-fill" style="width: <?php echo min(100, $availablePercent); ?>%;"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Terjual Bulan Ini -->
    <div class="col-xl-3 col-md-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Terjual Bulan Ini</span>
                    <span class="summary-sublabel">Periode <?php echo date('M Y'); ?></span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-clipboard-check"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value"><?php echo number_format($soldThisMonth); ?></span>
                    <span class="summary-unit">unit</span>
                </div>
            </div>
            <div class="summary-footer">
                <div class="summary-meta-text">
                    <span class="stat-dot dot-emerald"></span>
                    <span>Transaksi lunas periode ini</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pendapatan Bulan Ini -->
    <div class="col-xl-3 col-md-6">
        <div class="summary-card">
            <div class="summary-card-header">
                <div class="summary-title-group">
                    <span class="summary-label">Pendapatan Bulan Ini</span>
                    <span class="summary-sublabel">Omset lunas berjalan</span>
                </div>
                <div class="summary-icon">
                    <i class="fas fa-arrow-trend-up"></i>
                </div>
            </div>
            <div class="summary-body">
                <div class="summary-value-wrap">
                    <span class="summary-value summary-value-sm"><?php echo formatCurrency($revenueThisMonth); ?></span>
                </div>
            </div>
            <div class="summary-footer">
                <div class="summary-meta-text">
                    <span class="text-muted">Akumulasi <?php echo date('F Y'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (isAdmin() && $financeData): ?>
<!-- Finance Summary Section -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h6 class="fw-bold text-dark mb-0 d-flex align-items-center gap-2" style="font-size: 0.95rem; letter-spacing: -0.01em;">
                Ringkasan Finansial & Profitabilitas
                <span class="badge bg-light text-secondary border fw-normal" style="font-size: 0.7rem;">Tahun <?php echo date('Y'); ?></span>
            </h6>
            <p class="text-muted small mb-0 mt-0.5" style="font-size: 0.75rem;">Rekapitulasi modal, omset, kas operasional, laba kotor, dan laba bersih</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo BASE_URL; ?>expenses/" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-wallet me-1"></i> Kelola Kas
            </a>
            <a href="<?php echo BASE_URL; ?>reports/finance.php" class="btn btn-sm btn-outline-primary">
                <i class="fas fa-file-invoice-dollar me-1"></i> Laporan Lengkap
            </a>
        </div>
    </div>

    <div class="row g-3 g-xl-4 mb-2">
        <!-- Total Modal -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label">Total Modal Unit</span>
                        <span class="summary-sublabel">Harga beli unit terjual</span>
                    </div>
                    <div class="summary-icon">
                        <i class="fas fa-vault"></i>
                    </div>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm"><?php echo formatCurrency($financeData['modal']); ?></span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="stat-dot dot-slate"></span>
                        <span><?php echo $financeData['unit_terjual']; ?> unit telah terjual</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Total Pendapatan -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label">Total Pendapatan</span>
                        <span class="summary-sublabel">Akumulasi penjualan lunas</span>
                    </div>
                    <div class="summary-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm"><?php echo formatCurrency($financeData['pendapatan']); ?></span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="stat-dot dot-emerald"></span>
                        <span class="text-emerald fw-medium">Transaksi lunas terverifikasi</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Biaya Perawatan Unit -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label">Biaya Rekondisi & Servis</span>
                        <span class="summary-sublabel">Perbaikan & salon unit</span>
                    </div>
                    <div class="summary-icon">
                        <i class="fas fa-wrench"></i>
                    </div>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm"><?php echo formatCurrency($financeData['perawatan']); ?></span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="stat-dot dot-amber"></span>
                        <span>Total biaya perbaikan unit</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Kas Operasional Showroom -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label">Kas Operasional Showroom</span>
                        <span class="summary-sublabel">Listrik, sewa, gaji, bensin, dll</span>
                    </div>
                    <div class="summary-icon text-danger bg-danger bg-opacity-10">
                        <i class="fas fa-receipt"></i>
                    </div>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm text-danger"><?php echo formatCurrency($financeData['operasional']); ?></span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="stat-dot dot-rose"></span>
                        <span>Beban operasional tahun ini</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Laba Kotor (Gross Profit) -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card <?php echo $financeData['laba'] >= 0 ? 'summary-card-profit' : 'summary-card-loss'; ?>">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label">Laba Kotor (Gross Profit)</span>
                        <span class="summary-sublabel">Omset - (Modal + Servis)</span>
                    </div>
                    <span class="badge-margin <?php echo $financeData['laba'] >= 0 ? 'badge-margin-positive' : 'badge-margin-negative'; ?>">
                        <i class="fas <?php echo $financeData['laba'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?>"></i>
                        <?php echo ($financeData['margin_percent'] >= 0 ? '+' : '') . $financeData['margin_percent']; ?>%
                    </span>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm <?php echo $financeData['laba'] >= 0 ? 'text-emerald' : 'text-danger'; ?>">
                            <?php echo formatCurrency($financeData['laba']); ?>
                        </span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="text-muted">Rata-rata: <strong class="<?php echo $financeData['laba'] >= 0 ? 'text-dark' : 'text-danger'; ?>"><?php echo formatCurrency($financeData['avg_margin']); ?></strong> / unit</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Laba Bersih (Net Profit) -->
        <div class="col-xl-4 col-md-6">
            <div class="summary-card <?php echo $financeData['laba_bersih'] >= 0 ? 'summary-card-profit' : 'summary-card-loss'; ?>" style="border: 2px solid <?php echo $financeData['laba_bersih'] >= 0 ? '#10b981' : '#ef4444'; ?>;">
                <div class="summary-card-header">
                    <div class="summary-title-group">
                        <span class="summary-label text-dark fw-bold">Laba Bersih (Net Profit)</span>
                        <span class="summary-sublabel">Laba Kotor - Kas Operasional</span>
                    </div>
                    <span class="badge-margin <?php echo $financeData['laba_bersih'] >= 0 ? 'badge-margin-positive' : 'badge-margin-negative'; ?>">
                        <i class="fas <?php echo $financeData['laba_bersih'] >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?>"></i>
                        <?php echo ($financeData['net_margin_percent'] >= 0 ? '+' : '') . $financeData['net_margin_percent']; ?>%
                    </span>
                </div>
                <div class="summary-body">
                    <div class="summary-value-wrap">
                        <span class="summary-value summary-value-sm <?php echo $financeData['laba_bersih'] >= 0 ? 'text-emerald fw-bold' : 'text-danger fw-bold'; ?>">
                            <?php echo formatCurrency($financeData['laba_bersih']); ?>
                        </span>
                    </div>
                </div>
                <div class="summary-footer">
                    <div class="summary-meta-text">
                        <span class="text-muted">Bersih per unit: <strong class="<?php echo $financeData['laba_bersih'] >= 0 ? 'text-emerald' : 'text-danger'; ?>"><?php echo formatCurrency($financeData['avg_net_margin']); ?></strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modern Sales & Revenue Performance Analytics Card -->
<div class="row mb-4">
    <div class="col-12">
        <div class="chart-card">
            <!-- Header -->
            <div class="chart-card-header">
                <div class="d-flex align-items-center gap-3">
                    <div class="chart-header-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h5 class="chart-title">Tren Penjualan & Pendapatan</h5>
                        <p class="chart-subtitle">
                            <i class="far fa-calendar-alt me-1 text-primary"></i> Periode 6 Bulan Terakhir (<?php echo reset($chartLabels); ?> &ndash; <?php echo end($chartLabels); ?>)
                        </p>
                    </div>
                </div>

                <!-- Interactive View Mode Pills -->
                <div class="d-flex align-items-center gap-2">
                    <div class="chart-view-toggle" id="chartViewToggle">
                        <button type="button" class="chart-view-btn active" data-view="all">
                            <i class="fas fa-layer-group"></i> Semua
                        </button>
                        <button type="button" class="chart-view-btn" data-view="revenue">
                            <i class="fas fa-money-bill-wave"></i> Pendapatan
                        </button>
                        <button type="button" class="chart-view-btn" data-view="units">
                            <i class="fas fa-car-side"></i> Unit Mobil
                        </button>
                    </div>
                </div>
            </div>

            <!-- KPI Ribbon Bar -->
            <div class="chart-kpi-ribbon">
                <div class="chart-kpi-item">
                    <span class="chart-kpi-label"><i class="fas fa-coins text-primary"></i> Total Omset (6 Bln)</span>
                    <div class="d-flex align-items-center gap-2">
                        <span class="chart-kpi-val"><?php echo formatCurrency($chartTotalRevenue6M); ?></span>
                        <?php if ($revGrowthPct != 0): ?>
                        <span class="stat-chip <?php echo $revGrowthPct > 0 ? 'stat-chip-emerald' : 'stat-chip-rose'; ?>" style="font-size: 0.68rem; padding: 0.15rem 0.45rem;">
                            <i class="fas <?php echo $revGrowthPct > 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down'; ?> me-1"></i>
                            <?php echo ($revGrowthPct > 0 ? '+' : '') . $revGrowthPct; ?>% MoM
                        </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="chart-kpi-item">
                    <span class="chart-kpi-label"><i class="fas fa-car text-success"></i> Total Unit Terjual</span>
                    <span class="chart-kpi-val"><?php echo number_format($chartTotalSales6M); ?> <small class="text-muted fw-normal" style="font-size: 0.8rem;">Unit</small></span>
                </div>

                <div class="chart-kpi-item">
                    <span class="chart-kpi-label"><i class="fas fa-calculator text-info"></i> Rata-Rata / Bulan</span>
                    <span class="chart-kpi-val"><?php echo formatCurrency($chartTotalRevenue6M / 6); ?></span>
                </div>
            </div>

            <!-- Canvas Wrap -->
            <div class="chart-canvas-wrap">
                <canvas id="salesChart" style="min-height: 310px; max-height: 340px; width: 100%;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Content Row -->
<div class="row">
    <!-- Recent Transactions -->
    <div class="col-xl-8 col-lg-7 mb-4">
        <div class="card h-100 mb-0">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="section-badge-icon"><i class="fas fa-receipt"></i></div>
                    <div>
                        <h5 class="card-section-title mb-0">Transaksi Penjualan Terbaru</h5>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>transactions/" class="btn btn-sm btn-outline-secondary px-3" style="border-radius: 8px; font-size: 0.8rem; font-weight: 500;">
                    Lihat Semua <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentTransactions)): ?>
                <div class="table-responsive tx-table-wrap">
                    <table class="table tx-table align-middle">
                        <thead>
                            <tr>
                                <th class="ps-3 ps-xl-4">INVOICE & TANGGAL</th>
                                <th>KENDARAAN</th>
                                <th>PELANGGAN</th>
                                <th>NILAI TRANSAKSI</th>
                                <th>STATUS</th>
                                <th class="text-end pe-3 pe-xl-4">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): 
                                $status = $transaction['payment_status'] ?? 'pending';
                                if (($status == 'pending' || empty($status)) && isset($transaction['payment_method']) && $transaction['payment_method'] == 'installment') {
                                    $status = 'installment';
                                }
                                
                                $statusClass = [
                                    'paid' => 'success',
                                    'installment' => 'warning',
                                    'pending' => 'danger'
                                ];
                                $statusLabel = [
                                    'paid' => 'Lunas',
                                    'installment' => 'Cicilan',
                                    'pending' => 'Pending'
                                ];
                                $currentStatusClass = $statusClass[$status] ?? 'secondary';
                                $currentStatusLabel = $statusLabel[$status] ?? ucfirst($status);

                                $payMethod = strtolower($transaction['payment_method'] ?? 'cash');
                                $payMethodLabel = 'Tunai';
                                $payMethodIcon = 'fa-money-bill-wave';
                                if ($payMethod === 'transfer') {
                                    $payMethodLabel = 'Transfer';
                                    $payMethodIcon = 'fa-building-columns';
                                } elseif ($payMethod === 'installment' || $payMethod === 'credit' || $payMethod === 'leasing') {
                                    $payMethodLabel = 'Kredit / Cicilan';
                                    $payMethodIcon = 'fa-credit-card';
                                }

                                $custInitial = mb_substr($transaction['customer_name'] ?? 'P', 0, 1, 'UTF-8');
                            ?>
                            <tr>
                                <td class="ps-3 ps-xl-4" data-label="Invoice">
                                    <div class="d-flex flex-column">
                                        <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $transaction['id']; ?>" class="invoice-code-badge">
                                            <?php echo htmlspecialchars($transaction['transaction_code']); ?>
                                        </a>
                                        <span class="text-muted small mt-1" style="font-size: 0.75rem;">
                                            <i class="far fa-calendar me-1"></i><?php echo formatDate($transaction['transaction_date'], 'd M Y'); ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Mobil">
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold text-dark" style="font-size: 0.9rem"><?php echo htmlspecialchars($transaction['vehicle_name']); ?></span>
                                        <div class="d-flex align-items-center gap-1 mt-1">
                                            <span class="plate-badge-sm"><?php echo htmlspecialchars($transaction['license_plate']); ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Pelanggan">
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-initial-sm"><?php echo strtoupper($custInitial); ?></div>
                                        <div class="d-flex flex-column">
                                            <span class="fw-semibold text-dark" style="font-size: 0.85rem;"><?php echo htmlspecialchars($transaction['customer_name']); ?></span>
                                            <?php if (!empty($transaction['customer_phone'])): ?>
                                            <span class="text-muted small" style="font-size: 0.725rem;"><?php echo htmlspecialchars($transaction['customer_phone']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Total">
                                    <div class="d-flex flex-column">
                                        <span class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo formatCurrency($transaction['total_amount']); ?></span>
                                        <span class="pay-tag">
                                            <i class="fas <?php echo $payMethodIcon; ?> me-1"></i><?php echo $payMethodLabel; ?>
                                        </span>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <span class="badge-modern bg-soft-<?php echo $currentStatusClass; ?>">
                                        <?php echo $currentStatusLabel; ?>
                                    </span>
                                </td>
                                <td class="text-end pe-3 pe-xl-4" data-label="Aksi">
                                    <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $transaction['id']; ?>" class="btn-action-icon ms-auto" data-bs-toggle="tooltip" title="Lihat Detail Transaksi">
                                        <i class="fas fa-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5 px-3">
                    <div class="section-badge-icon mx-auto mb-3" style="width: 48px; height: 48px; font-size: 1.25rem;">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <h6 class="fw-bold text-dark mb-1">Belum Ada Transaksi</h6>
                    <p class="text-muted small mb-3">Transaksi penjualan yang dibuat akan muncul di tabel ini.</p>
                    <a href="<?php echo BASE_URL; ?>transactions/create.php" class="btn btn-sm btn-primary">
                        <i class="fas fa-plus me-1"></i> Buat Transaksi Baru
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Showroom Inventory Health & Distribution -->
    <div class="col-xl-4 col-lg-5 mb-4">
        <div class="card h-100 mb-0">
            <div class="card-header d-flex justify-content-between align-items-center py-3">
                <div class="d-flex align-items-center gap-2">
                    <div class="section-badge-icon"><i class="fas fa-warehouse"></i></div>
                    <div>
                        <h5 class="card-section-title mb-0">Status Inventaris</h5>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>vehicles/" class="btn btn-sm btn-outline-secondary px-2.5 py-1" style="border-radius: 8px; font-size: 0.75rem; font-weight: 500;">
                    Kelola Stok <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="inventory-widget-body">
                <!-- Asset Valuation Strip -->
                <div class="inventory-asset-banner">
                    <div class="inventory-asset-label">Valuasi Stok Tersedia (Modal)</div>
                    <div class="inventory-asset-val"><?php echo formatCurrency($activeAssetValue); ?></div>
                    <div class="inventory-asset-sub d-flex align-items-center justify-content-between">
                        <span>Potensi Penjualan:</span>
                        <strong class="text-dark"><?php echo formatCurrency($activePotentialValue); ?></strong>
                    </div>
                </div>

                <!-- Status 2x2 Breakdown Grid -->
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.04em;">Kondisi Unit Showroom</span>
                        <span class="text-muted small" style="font-size: 0.7rem;">Total <?php echo $totalVehicles; ?> Unit</span>
                    </div>
                    <div class="inventory-status-grid">
                        <div class="inventory-status-box">
                            <div class="inventory-status-box-header">
                                <span>Siap Jual</span>
                                <span class="stat-dot dot-emerald"></span>
                            </div>
                            <div class="inventory-status-box-val text-emerald"><?php echo $availableVehicles; ?> <span class="text-muted fw-normal small" style="font-size: 0.7rem;">unit</span></div>
                        </div>

                        <div class="inventory-status-box">
                            <div class="inventory-status-box-header">
                                <span>Perawatan</span>
                                <span class="stat-dot dot-amber"></span>
                            </div>
                            <div class="inventory-status-box-val"><?php echo $maintenanceVehicles; ?> <span class="text-muted fw-normal small" style="font-size: 0.7rem;">unit</span></div>
                        </div>

                        <div class="inventory-status-box">
                            <div class="inventory-status-box-header">
                                <span>Terpesan</span>
                                <span class="stat-dot dot-blue"></span>
                            </div>
                            <div class="inventory-status-box-val"><?php echo $reservedVehicles; ?> <span class="text-muted fw-normal small" style="font-size: 0.7rem;">unit</span></div>
                        </div>

                        <div class="inventory-status-box">
                            <div class="inventory-status-box-header">
                                <span>Terjual</span>
                                <span class="stat-dot dot-slate"></span>
                            </div>
                            <div class="inventory-status-box-val"><?php echo $soldVehicles; ?> <span class="text-muted fw-normal small" style="font-size: 0.7rem;">unit</span></div>
                        </div>
                    </div>
                </div>

                <!-- Car Types Breakdown (Ready Stock) -->
                <?php if (!empty($carTypeDistribution)): ?>
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-2">
                        <span class="text-muted small fw-semibold text-uppercase" style="font-size: 0.7rem; letter-spacing: 0.04em;">Komposisi Tipe (Siap Jual)</span>
                    </div>
                    <div class="inventory-type-list">
                        <?php foreach ($carTypeDistribution as $type): 
                            $typeCount = (int)$type['count'];
                            $typePct = $availableVehicles > 0 ? round(($typeCount / $availableVehicles) * 100) : 0;
                        ?>
                        <div class="inventory-type-item">
                            <div class="inventory-type-header">
                                <span class="inventory-type-title"><?php echo htmlspecialchars($type['type_name']); ?></span>
                                <span class="inventory-type-count"><?php echo $typeCount; ?> unit (<?php echo $typePct; ?>%)</span>
                            </div>
                            <div class="inventory-type-track">
                                <div class="inventory-type-fill" style="width: <?php echo min(100, max(6, $typePct)); ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Aging Stock Warning & Holding Cost Widget -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card mb-0">
            <div class="card-header d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 py-3">
                <div>
                    <h5 class="mb-1 fw-bold text-dark" style="font-size: 1rem;">Peringatan Umur Stok</h5>
                    <p class="text-muted small mb-0" style="font-size: 0.8rem;">Monitoring durasi unit mengendap untuk mencegah beban biaya penyimpanan dan depresiasi nilai</p>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ((int)$agingSummary['critical_count'] > 0): ?>
                    <a href="<?php echo BASE_URL; ?>vehicles/?aging=critical" class="btn btn-sm btn-outline-danger px-3 py-1.5" style="border-radius: 8px; font-size: 0.775rem; font-weight: 600;">
                        <?php echo (int)$agingSummary['critical_count']; ?> Unit Perlu Tindakan
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo BASE_URL; ?>reports/inventory.php" class="btn btn-sm btn-outline-secondary px-3 py-1.5" style="border-radius: 8px; font-size: 0.775rem; font-weight: 500;">
                        Laporan Umur Stok
                    </a>
                </div>
            </div>
            <div class="card-body p-3 p-xl-4">
                <!-- 3-Segment KPI Cards using standard summary-card -->
                <div class="row g-3 mb-4">
                    <!-- Fresh -->
                    <div class="col-12 col-md-4">
                        <div class="summary-card">
                            <div class="summary-card-header">
                                <div class="summary-title-group">
                                    <span class="summary-label">Stok Lancar</span>
                                    <span class="summary-sublabel">Durasi sampai 30 hari</span>
                                </div>
                            </div>
                            <div class="summary-body">
                                <div class="summary-value-wrap">
                                    <span class="summary-value text-success"><?php echo (int)$agingSummary['fresh_count']; ?></span>
                                    <span class="summary-unit">unit</span>
                                </div>
                            </div>
                            <div class="summary-footer">
                                <span class="text-muted" style="font-size: 0.75rem;">Perputaran stok optimal & cepat</span>
                            </div>
                        </div>
                    </div>

                    <!-- Perhatian -->
                    <div class="col-12 col-md-4">
                        <div class="summary-card">
                            <div class="summary-card-header">
                                <div class="summary-title-group">
                                    <span class="summary-label">Stok Perhatian</span>
                                    <span class="summary-sublabel">Durasi 31 - 60 hari</span>
                                </div>
                            </div>
                            <div class="summary-body">
                                <div class="summary-value-wrap">
                                    <span class="summary-value text-warning"><?php echo (int)$agingSummary['warning_count']; ?></span>
                                    <span class="summary-unit">unit</span>
                                </div>
                            </div>
                            <div class="summary-footer">
                                <span class="text-muted" style="font-size: 0.75rem;">Mendekati batas ideal showroom</span>
                            </div>
                        </div>
                    </div>

                    <!-- Stok Macet -->
                    <div class="col-12 col-md-4">
                        <div class="summary-card summary-card-loss">
                            <div class="summary-card-header">
                                <div class="summary-title-group">
                                    <span class="summary-label text-danger">Stok Macet</span>
                                    <span class="summary-sublabel">Durasi lebih dari 60 hari</span>
                                </div>
                            </div>
                            <div class="summary-body">
                                <div class="summary-value-wrap">
                                    <span class="summary-value text-danger"><?php echo (int)$agingSummary['critical_count']; ?></span>
                                    <span class="summary-unit">unit</span>
                                </div>
                            </div>
                            <div class="summary-footer">
                                <div class="d-flex align-items-center justify-content-between w-100" style="font-size: 0.75rem;">
                                    <span class="text-muted">Modal Tertahan:</span>
                                    <strong class="text-danger" style="font-variant-numeric: tabular-nums;"><?php echo formatCurrency($agingSummary['critical_capital_locked']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Critical Stock Units Table -->
                <?php if (!empty($criticalVehicles)): ?>
                    <div class="d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-2 mb-3">
                        <h6 class="fw-bold text-dark mb-0" style="font-size: 0.875rem;">Daftar Unit Mengendap Lebih Dari 60 Hari</h6>
                        <span class="text-muted small" style="font-size: 0.75rem;">Top 5 unit paling lama di showroom</span>
                    </div>

                    <div class="table-responsive border rounded-3">
                        <table class="table tx-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th class="ps-3 ps-xl-4">KENDARAAN</th>
                                    <th>NO. POLISI</th>
                                    <th>TANGGAL MASUK</th>
                                    <th>LAMA MENGENDAP</th>
                                    <th class="text-end">HARGA BELI (HPP)</th>
                                    <th class="text-end">HARGA JUAL</th>
                                    <th class="text-end pe-3 pe-xl-4" style="min-width: 120px;">AKSI</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($criticalVehicles as $cv): 
                                    $agingCv = calculateVehicleAging($cv['purchase_date'], null, $cv['created_at'], $cv['purchase_price']);
                                ?>
                                    <tr>
                                        <td class="ps-3 ps-xl-4" data-label="Kendaraan">
                                            <div class="d-flex flex-column">
                                                <a href="<?php echo BASE_URL; ?>vehicles/view.php?id=<?php echo $cv['id']; ?>" class="fw-bold text-dark text-decoration-none" style="font-size: 0.875rem;">
                                                    <?php echo htmlspecialchars($cv['brand_name'] . ' ' . $cv['model_name'] . ' ' . $cv['year']); ?>
                                                </a>
                                                <?php if (!empty($cv['color'])): ?>
                                                <span class="text-muted small" style="font-size: 0.725rem;"><?php echo htmlspecialchars($cv['color']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td data-label="No. Polisi">
                                            <span class="plate-badge-sm"><?php echo htmlspecialchars($cv['license_plate'] ?: '-'); ?></span>
                                        </td>
                                        <td data-label="Tanggal Masuk">
                                            <span class="text-muted small" style="font-size: 0.8rem;">
                                                <?php echo formatDate($cv['purchase_date'] ?: $cv['created_at'], 'd M Y'); ?>
                                            </span>
                                        </td>
                                        <td data-label="Lama Mengendap">
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2.5 py-1 fw-semibold" style="font-size: 0.75rem;">
                                                <?php echo $cv['days_in_stock']; ?> hari
                                            </span>
                                        </td>
                                        <td class="text-end" data-label="Harga Beli">
                                            <span class="text-muted" style="font-size: 0.85rem; font-variant-numeric: tabular-nums; font-weight: 500;">
                                                <?php echo formatCurrency($cv['purchase_price']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end" data-label="Harga Jual">
                                            <span class="fw-bold text-dark" style="font-size: 0.875rem; font-variant-numeric: tabular-nums;">
                                                <?php echo formatCurrency($cv['selling_price']); ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-3 pe-xl-4" data-label="Aksi">
                                            <a href="<?php echo BASE_URL; ?>vehicles/edit.php?id=<?php echo $cv['id']; ?>" class="btn btn-sm btn-outline-primary px-3 py-1" style="font-size: 0.775rem; border-radius: 6px; font-weight: 500;">
                                                Ubah Harga
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-4 text-muted small">
                        <div class="fw-semibold text-dark mb-1">Seluruh Unit Dalam Kondisi Lancar</div>
                        <span>Tidak ada unit stok yang mengendap lebih dari 60 hari.</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Aksi Cepat</h5>
            </div>
            <div class="card-body pt-0">
                <div class="row g-3 justify-content-center">
                    <div class="<?php echo isAdmin() ? 'col-md-3' : 'col-md-4'; ?> col-6">
                        <a href="<?php echo BASE_URL; ?>vehicles/create.php" class="action-card">
                            <div class="action-icon-wrapper icon-soft-primary">
                                <i class="fas fa-plus"></i>
                            </div>
                            <span class="action-title">Tambah Mobil</span>
                        </a>
                    </div>
                    <div class="<?php echo isAdmin() ? 'col-md-3' : 'col-md-4'; ?> col-6">
                        <a href="<?php echo BASE_URL; ?>transactions/create.php" class="action-card">
                            <div class="action-icon-wrapper icon-soft-success">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <span class="action-title">Transaksi Baru</span>
                        </a>
                    </div>
                    <div class="<?php echo isAdmin() ? 'col-md-3' : 'col-md-4'; ?> col-6">
                        <a href="<?php echo BASE_URL; ?>vehicles/" class="action-card">
                            <div class="action-icon-wrapper icon-soft-info">
                                <i class="fas fa-search"></i>
                            </div>
                            <span class="action-title">Cari Mobil</span>
                        </a>
                    </div>
                    <?php if (isAdmin()): ?>
                    <div class="col-md-3 col-6">
                        <a href="<?php echo BASE_URL; ?>reports/" class="action-card">
                            <div class="action-icon-wrapper icon-soft-warning">
                                <i class="fas fa-chart-pie"></i>
                            </div>
                            <span class="action-title">Laporan</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const canvas = document.getElementById('salesChart');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const labels = <?php echo json_encode($chartLabels); ?>;
    const salesUnits = <?php echo json_encode($chartSales); ?>;
    const revenueData = <?php echo json_encode($chartRevenue); ?>;

    function getThemeColors() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        return {
            isDark: isDark,
            textColor: isDark ? '#94a3b8' : '#64748b',
            titleColor: isDark ? '#f1f5f9' : '#0f172a',
            gridColor: isDark ? 'rgba(51, 65, 85, 0.45)' : 'rgba(226, 232, 240, 0.7)',
            tooltipBg: isDark ? 'rgba(15, 23, 42, 0.95)' : 'rgba(15, 23, 42, 0.92)',
            revGradientTop: isDark ? 'rgba(99, 102, 241, 0.40)' : 'rgba(79, 70, 229, 0.25)',
            revGradientBottom: isDark ? 'rgba(99, 102, 241, 0.00)' : 'rgba(79, 70, 229, 0.00)',
            unitBarBg: isDark ? 'rgba(16, 185, 129, 0.85)' : 'rgba(16, 185, 129, 0.85)',
            unitBarHover: isDark ? '#34d399' : '#059669'
        };
    }

    let colors = getThemeColors();

    // Create Revenue Area Gradient
    function createRevenueGradient() {
        const gradient = ctx.createLinearGradient(0, 0, 0, 320);
        gradient.addColorStop(0, colors.revGradientTop);
        gradient.addColorStop(1, colors.revGradientBottom);
        return gradient;
    }

    const salesChart = new Chart(ctx, {
        type: 'bar', // combo chart: bar + line
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Unit Terjual',
                    data: salesUnits,
                    backgroundColor: colors.unitBarBg,
                    hoverBackgroundColor: colors.unitBarHover,
                    borderRadius: 6,
                    borderSkipped: false,
                    barPercentage: 0.45,
                    maxBarThickness: 36,
                    yAxisID: 'yUnits',
                    order: 2
                },
                {
                    type: 'line',
                    label: 'Total Pendapatan',
                    data: revenueData,
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
                    tension: 0.38,
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
                intersect: false,
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 16,
                        boxWidth: 8,
                        boxHeight: 8,
                        color: colors.textColor,
                        font: {
                            family: "'Inter', sans-serif",
                            size: 12,
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
                    padding: {
                        top: 10,
                        bottom: 10,
                        left: 14,
                        right: 14
                    },
                    titleFont: {
                        family: "'Inter', sans-serif",
                        size: 13,
                        weight: '700'
                    },
                    bodyFont: {
                        family: "'Inter', sans-serif",
                        size: 12,
                        weight: '500'
                    },
                    boxPadding: 6,
                    usePointStyle: true,
                    callbacks: {
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
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: colors.textColor,
                        font: {
                            family: "'Inter', sans-serif",
                            size: 12,
                            weight: '500'
                        },
                        padding: 8
                    }
                },
                yUnits: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    suggestedMax: 4,
                    grid: {
                        color: colors.gridColor,
                        borderDash: [4, 4],
                        drawBorder: false
                    },
                    ticks: {
                        precision: 0,
                        stepSize: 1,
                        color: colors.textColor,
                        font: {
                            family: "'Inter', sans-serif",
                            size: 11,
                            weight: '500'
                        },
                        callback: function(val) {
                            if (Number.isInteger(val)) {
                                return val + ' unit';
                            }
                            return null;
                        }
                    },
                    title: {
                        display: false
                    }
                },
                yRevenue: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false,
                        display: false
                    },
                    ticks: {
                        color: colors.textColor,
                        font: {
                            family: "'Inter', sans-serif",
                            size: 11,
                            weight: '500'
                        },
                        callback: function(val) {
                            if (val === 0) return 'Rp 0';
                            if (val >= 1000000000) {
                                return 'Rp ' + (val / 1000000000).toFixed(1).replace('.0', '') + ' M';
                            }
                            if (val >= 1000000) {
                                return 'Rp ' + (val / 1000000).toFixed(0) + ' Jt';
                            }
                            return 'Rp ' + val;
                        }
                    },
                    title: {
                        display: false
                    }
                }
            }
        }
    });

    // View Mode Toggle Handler (Semua / Pendapatan / Unit Mobil)
    const toggleContainer = document.getElementById('chartViewToggle');
    if (toggleContainer) {
        toggleContainer.addEventListener('click', function(e) {
            const btn = e.target.closest('.chart-view-btn');
            if (!btn) return;

            toggleContainer.querySelectorAll('.chart-view-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const view = btn.getAttribute('data-view');

            if (view === 'revenue') {
                salesChart.getDatasetMeta(0).hidden = true;  // Hide units
                salesChart.getDatasetMeta(1).hidden = false; // Show revenue
                salesChart.options.scales.yUnits.display = false;
                salesChart.options.scales.yRevenue.display = true;
                salesChart.options.scales.yRevenue.position = 'left';
                salesChart.options.scales.yRevenue.grid.drawOnChartArea = true;
                salesChart.options.scales.yRevenue.grid.display = true;
                salesChart.options.scales.yRevenue.grid.color = colors.gridColor;
                salesChart.options.scales.yRevenue.grid.borderDash = [4, 4];
            } else if (view === 'units') {
                salesChart.getDatasetMeta(0).hidden = false; // Show units
                salesChart.getDatasetMeta(1).hidden = true;  // Hide revenue
                salesChart.options.scales.yUnits.display = true;
                salesChart.options.scales.yUnits.position = 'left';
                salesChart.options.scales.yRevenue.display = false;
                salesChart.options.scales.yRevenue.grid.drawOnChartArea = false;
            } else {
                // All
                salesChart.getDatasetMeta(0).hidden = false;
                salesChart.getDatasetMeta(1).hidden = false;
                salesChart.options.scales.yUnits.display = true;
                salesChart.options.scales.yUnits.position = 'left';
                salesChart.options.scales.yRevenue.display = true;
                salesChart.options.scales.yRevenue.position = 'right';
                salesChart.options.scales.yRevenue.grid.drawOnChartArea = false;
            }

            salesChart.update();
        });
    }

    // Dynamic Dark/Light Mode Theme Change Sync
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function() {
            setTimeout(function() {
                colors = getThemeColors();

                salesChart.data.datasets[1].backgroundColor = createRevenueGradient();
                salesChart.data.datasets[0].backgroundColor = colors.unitBarBg;
                salesChart.data.datasets[0].hoverBackgroundColor = colors.unitBarHover;

                salesChart.options.plugins.legend.labels.color = colors.textColor;
                salesChart.options.plugins.tooltip.backgroundColor = colors.tooltipBg;
                salesChart.options.plugins.tooltip.borderColor = colors.isDark ? '#334155' : 'rgba(255,255,255,0.1)';

                salesChart.options.scales.x.ticks.color = colors.textColor;
                salesChart.options.scales.yUnits.ticks.color = colors.textColor;
                salesChart.options.scales.yUnits.grid.color = colors.gridColor;
                salesChart.options.scales.yRevenue.ticks.color = colors.textColor;

                if (salesChart.options.scales.yRevenue.grid.drawOnChartArea) {
                    salesChart.options.scales.yRevenue.grid.color = colors.gridColor;
                }

                salesChart.update();
            }, 250);
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>