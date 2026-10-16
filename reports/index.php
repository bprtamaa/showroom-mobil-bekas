<?php
// reports/index.php - Pusat Laporan Manajemen Showroom
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

// Get statistics
$db = new Database();
$conn = $db->connect();

// 1. Rata-rata penjualan per bulan (berdasarkan bulan yang memiliki transaksi)
$stmt = $conn->query("SELECT 
                        COUNT(*) as total_transactions,
                        COUNT(DISTINCT DATE_FORMAT(transaction_date, '%Y-%m')) as total_months,
                        COALESCE(SUM(total_amount), 0) as total_revenue
                      FROM transactions 
                      WHERE payment_status = 'paid'");
$avgData = $stmt->fetch(PDO::FETCH_ASSOC);
$avgTransactionsPerMonth = $avgData['total_months'] > 0 ? round($avgData['total_transactions'] / $avgData['total_months'], 1) : 0;
$avgRevenuePerMonth = $avgData['total_months'] > 0 ? $avgData['total_revenue'] / $avgData['total_months'] : 0;

// 2. Transaksi terbesar sepanjang waktu
$stmt = $conn->query("SELECT MAX(total_amount) as highest FROM transactions WHERE payment_status = 'paid'");
$highestEver = $stmt->fetch(PDO::FETCH_ASSOC)['highest'] ?? 0;

// 3. Tingkat konversi - persentase stok yang terjual
$stmt = $conn->query("SELECT COUNT(*) as total FROM vehicles");
$totalVehicles = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$stmt = $conn->query("SELECT COUNT(*) as total FROM transactions WHERE payment_status = 'paid'");
$soldVehicles = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$conversionRate = $totalVehicles > 0 ? ($soldVehicles / $totalVehicles) * 100 : 0;

// 4. Total pendapatan keseluruhan dan pertumbuhan YoY
$currentYear = date('Y');
$lastYear = $currentYear - 1;

$stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as tx_count FROM transactions WHERE YEAR(transaction_date) = ? AND payment_status = 'paid'");
$stmt->execute([$currentYear]);
$thisYearData = $stmt->fetch(PDO::FETCH_ASSOC);
$thisYearRevenue = $thisYearData['total'] ?? 0;
$thisYearTxCount = $thisYearData['tx_count'] ?? 0;

$stmt->execute([$lastYear]);
$lastYearRevenue = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$yoyGrowth = $lastYearRevenue > 0 ? (($thisYearRevenue - $lastYearRevenue) / $lastYearRevenue) * 100 : 0;

// 5. Live Quick Insights for Each Module
// Month to date
$stmtMonth = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) as total, COUNT(*) as tx_count FROM transactions WHERE YEAR(transaction_date) = ? AND MONTH(transaction_date) = ? AND payment_status = 'paid'");
$stmtMonth->execute([$currentYear, date('m')]);
$thisMonthData = $stmtMonth->fetch(PDO::FETCH_ASSOC);

// Total Purchase Cost
$stmtPurchases = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(purchase_price), 0) as total_cost FROM vehicles WHERE purchase_price > 0");
$purchaseData = $stmtPurchases->fetch(PDO::FETCH_ASSOC);

// Inventory Available Value
$stmtInventory = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(selling_price), 0) as total_val FROM vehicles WHERE status = 'available'");
$inventoryData = $stmtInventory->fetch(PDO::FETCH_ASSOC);

// Leasing stats
$stmtLeasing = $conn->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total_amount FROM transactions WHERE payment_method = 'credit' AND payment_status = 'paid'");
$leasingData = $stmtLeasing->fetch(PDO::FETCH_ASSOC);

// Gross Profit estimate
$stmtGross = $conn->query("
    SELECT 
        COALESCE(SUM(t.total_amount - v.purchase_price), 0) as total_gross,
        COALESCE(AVG(CASE WHEN v.purchase_price > 0 THEN ((t.total_amount - v.purchase_price) / t.total_amount) * 100 ELSE NULL END), 0) as avg_margin
    FROM transactions t
    JOIN vehicles v ON t.vehicle_id = v.id
    WHERE t.payment_status = 'paid'
");
$grossData = $stmtGross->fetch(PDO::FETCH_ASSOC);
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

    /* ===== Summary Grid (Preserved UI) ===== */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 28px;
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
        padding: 16px 18px;
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
    .summary-icon.rose { background: #ffe4e6; color: #be123c; }
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

    /* ===== Modern Executive Report Hub Section ===== */
    /* ===== Enterprise Reports Hub Section ===== */
    .reports-hub-container {
        display: flex;
        flex-direction: column;
        gap: 32px;
        margin-bottom: 40px;
    }

    .report-category-group {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    /* Section Group Header */
    .section-header-wrap {
        display: flex;
        align-items: center;
        gap: 12px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
    }
    .section-header-icon {
        width: 36px;
        height: 36px;
        border-radius: 9px;
        background: #f1f5f9;
        color: #334155;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.95rem;
        flex-shrink: 0;
        border: 1px solid #e2e8f0;
    }
    .section-header-text {
        flex: 1;
        min-width: 0;
    }
    .section-header-text h3 {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0 0 2px 0;
        letter-spacing: -0.01em;
    }
    .section-header-text p {
        font-size: 0.78rem;
        color: #64748b;
        margin: 0;
    }
    .section-header-count {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        background: #f1f5f9;
        padding: 4px 10px;
        border-radius: 20px;
        white-space: nowrap;
    }

    /* Grid Layout */
    .hub-reports-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    @media (max-width: 768px) {
        .hub-reports-grid { grid-template-columns: 1fr; }
    }

    /* Modern Report Card */
    .hub-report-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 22px 24px;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        text-decoration: none;
        color: inherit;
        transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        box-shadow: 0 1px 3px rgba(15, 23, 42, 0.02);
    }
    .hub-report-card:hover {
        border-color: #cbd5e1;
        box-shadow: 0 10px 24px -4px rgba(15, 23, 42, 0.07), 0 2px 6px -1px rgba(15, 23, 42, 0.03);
        transform: translateY(-2px);
        text-decoration: none;
        color: inherit;
    }

    .hub-card-header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin-bottom: 12px;
    }
    .hub-card-icon-box {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        color: #334155;
        flex-shrink: 0;
        transition: all 0.2s ease;
    }
    .hub-report-card:hover .hub-card-icon-box {
        background: #0f172a;
        color: #ffffff;
        border-color: #0f172a;
    }
    .hub-card-heading {
        flex: 1;
        min-width: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
    }
    .hub-card-title {
        font-size: 1rem;
        font-weight: 700;
        color: #0f172a;
        margin: 0;
        line-height: 1.3;
        letter-spacing: -0.01em;
    }
    .hub-card-tag {
        font-size: 0.7rem;
        font-weight: 600;
        color: #475569;
        background: #f1f5f9;
        border: 1px solid #e2e8f0;
        padding: 3px 9px;
        border-radius: 6px;
        white-space: nowrap;
    }
    .hub-card-description {
        font-size: 0.83rem;
        color: #64748b;
        line-height: 1.5;
        margin: 0 0 16px 0;
    }
    .hub-card-metrics {
        background: #f8fafc;
        border: 1px solid #edf2f7;
        border-radius: 10px;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-top: auto;
        margin-bottom: 14px;
    }
    .hub-metric-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .hub-metric-label {
        font-size: 0.72rem;
        font-weight: 600;
        color: #64748b;
        text-transform: capitalize;
    }
    .hub-metric-val {
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        font-variant-numeric: tabular-nums;
    }
    .hub-metric-badge {
        font-size: 0.75rem;
        font-weight: 600;
        color: #334155;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 4px 10px;
        border-radius: 20px;
        white-space: nowrap;
    }

    .hub-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-top: 1px solid #f1f5f9;
        padding-top: 12px;
    }
    .hub-card-action {
        font-size: 0.8rem;
        font-weight: 600;
        color: #2563eb;
    }
    .hub-action-arrow {
        font-size: 0.8rem;
        color: #2563eb;
        transition: transform 0.2s ease;
    }
    .hub-report-card:hover .hub-action-arrow {
        transform: translateX(4px);
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-secondary { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-secondary:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .summary-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .summary-value { color: #f1f5f9; }
    [data-theme="dark"] .section-header-wrap { border-bottom-color: #334155; }
    [data-theme="dark"] .section-header-icon { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .section-header-text h3 { color: #f1f5f9; }
    [data-theme="dark"] .section-header-text p { color: #94a3b8; }
    [data-theme="dark"] .section-header-count { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .hub-report-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .hub-report-card:hover { border-color: #475569; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4); }
    [data-theme="dark"] .hub-card-icon-box { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .hub-report-card:hover .hub-card-icon-box { background: #3b82f6; color: #ffffff; border-color: #3b82f6; }
    [data-theme="dark"] .hub-card-title { color: #f1f5f9; }
    [data-theme="dark"] .hub-card-tag { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .hub-card-description { color: #94a3b8; }
    [data-theme="dark"] .hub-card-metrics { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .hub-metric-label { color: #94a3b8; }
    [data-theme="dark"] .hub-metric-val { color: #f1f5f9; }
    [data-theme="dark"] .hub-metric-badge { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .hub-card-footer { border-top-color: #334155; }
    [data-theme="dark"] .hub-card-action { color: #60a5fa; }
    [data-theme="dark"] .hub-action-arrow { color: #60a5fa; }
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Pusat Laporan Showroom</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item active" aria-current="page">Pusat Laporan</li>
            </ol>
        </nav>
    </div>
    <div class="vehicles-header-actions">
        <a href="<?php echo BASE_URL; ?>dashboard/" class="btn-header-secondary">
            <i class="fas fa-arrow-left"></i> Kembali ke Dashboard
        </a>
    </div>
</div>

<!-- Summary Grid (Preserved UI) -->
<div class="summary-grid">
    <div class="summary-card">
        <div class="summary-icon slate">
            <i class="fas fa-chart-bar"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo $avgTransactionsPerMonth; ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Tx</span></div>
            <div class="summary-label">Rata-rata per Bulan</div>
            <small class="text-success fw-bold" style="font-size: 0.75rem;">Rp <?php echo number_format($avgRevenuePerMonth, 0, ',', '.'); ?></small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon amber">
            <i class="fas fa-trophy"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($highestEver); ?></div>
            <div class="summary-label">Transaksi Terbesar</div>
            <small class="text-muted" style="font-size: 0.72rem;">Sepanjang Waktu</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-percentage"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($conversionRate, 1); ?>%</div>
            <div class="summary-label">Tingkat Konversi</div>
            <small class="text-muted" style="font-size: 0.72rem;"><?php echo $soldVehicles; ?> dari <?php echo $totalVehicles; ?> unit terjual</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-wallet"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($thisYearRevenue); ?></div>
            <div class="summary-label">Pendapatan <?php echo date('Y'); ?></div>
            <?php if ($yoyGrowth != 0): ?>
                <small class="<?php echo $yoyGrowth >= 0 ? 'text-success' : 'text-danger'; ?> fw-bold" style="font-size: 0.72rem;">
                    <i class="fas fa-<?php echo $yoyGrowth >= 0 ? 'arrow-up' : 'arrow-down'; ?> me-1"></i><?php echo ($yoyGrowth >= 0 ? '+' : '') . number_format($yoyGrowth, 1); ?>% YoY
                </small>
            <?php else: ?>
                <small class="text-muted" style="font-size: 0.72rem;">Tahun berjalan</small>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Enterprise Reports Hub -->
<div class="reports-hub-container">

    <!-- Group 1: Penjualan & Pendapatan -->
    <div class="report-category-group">
        <div class="section-header-wrap">
            <div class="section-header-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="section-header-text">
                <h3>Penjualan & Pendapatan</h3>
                <p>Rekapitulasi performa omset, transaksi berkala, dan pertumbuhan tahunan</p>
            </div>
            <span class="section-header-count">2 Laporan</span>
        </div>
        <div class="hub-reports-grid">
            <!-- 1. Laporan Penjualan Bulanan -->
            <a href="monthly_sales.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Penjualan Bulanan</h4>
                            <span class="hub-card-tag">Bulanan</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Analisis rincian transaksi harian, perbandingan metode pembayaran tunai vs kredit, serta tren omset bulan berjalan.</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Bulan Ini (<?php echo date('M Y'); ?>)</span>
                            <span class="hub-metric-val">Rp <?php echo number_format($thisMonthData['total'] ?? 0, 0, ',', '.'); ?></span>
                        </div>
                        <div class="hub-metric-badge">
                            <?php echo $thisMonthData['tx_count'] ?? 0; ?> Unit Terjual
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Bulanan</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>

            <!-- 2. Laporan Penjualan Tahunan -->
            <a href="yearly_sales.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Penjualan Tahunan</h4>
                            <span class="hub-card-tag">Tahunan</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Rekapitulasi performa penjualan tahunan, pertumbuhan omset per kuartal, dan perbandingan pertumbuhan Year-over-Year (YoY).</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Total Omset <?php echo date('Y'); ?></span>
                            <span class="hub-metric-val">Rp <?php echo number_format($thisYearRevenue, 0, ',', '.'); ?></span>
                        </div>
                        <div class="hub-metric-badge">
                            <?php echo $thisYearTxCount; ?> Transaksi
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Tahunan</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Group 2: Keuangan, Profitabilitas & Pengadaan -->
    <div class="report-category-group">
        <div class="section-header-wrap">
            <div class="section-header-icon">
                <i class="fas fa-file-invoice-dollar"></i>
            </div>
            <div class="section-header-text">
                <h3>Keuangan & Pengadaan Unit</h3>
                <p>Analisis margin laba bersih, beban operasional, serta realisasi modal belanja mobil</p>
            </div>
            <span class="section-header-count">2 Laporan</span>
        </div>
        <div class="hub-reports-grid">
            <!-- 3. Laporan Laba Kotor & Finansial -->
            <a href="finance.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-wallet"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Laba Rugi & Finansial</h4>
                            <span class="hub-card-tag">Keuangan</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Rincian pendapatan bersih, beban kas operasional showroom, laba kotor unit, dan persentase laba bersih final.</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Estimasi Margin Kotor</span>
                            <span class="hub-metric-val"><?php echo number_format($grossData['avg_margin'] ?? 0, 1); ?>%</span>
                        </div>
                        <div class="hub-metric-badge">
                            Laba Kotor: Rp <?php echo number_format($grossData['total_gross'] ?? 0, 0, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Laba Rugi</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>

            <!-- 4. Laporan Pembelian Mobil -->
            <a href="vehicle_purchases.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-truck-moving"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Pembelian Mobil</h4>
                            <span class="hub-card-tag">Pengadaan</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Rincian pengeluaran modal pembelian unit mobil masuk, sumber pengadaan stok, serta evaluasi harga modal per unit.</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Total Belanja Modal</span>
                            <span class="hub-metric-val">Rp <?php echo number_format($purchaseData['total_cost'] ?? 0, 0, ',', '.'); ?></span>
                        </div>
                        <div class="hub-metric-badge">
                            <?php echo $purchaseData['count'] ?? 0; ?> Unit Masuk
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Pembelian</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- Group 3: Inventori Aset & Pembiayaan Rekanan -->
    <div class="report-category-group">
        <div class="section-header-wrap">
            <div class="section-header-icon">
                <i class="fas fa-warehouse"></i>
            </div>
            <div class="section-header-text">
                <h3>Inventori & Pembiayaan Rekanan</h3>
                <p>Monitoring perputaran stok armada showroom dan realisasi kredit mitra leasing</p>
            </div>
            <span class="section-header-count">2 Laporan</span>
        </div>
        <div class="hub-reports-grid">
            <!-- 5. Laporan Inventori Stok -->
            <a href="inventory.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-boxes-stacked"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Inventori Stok</h4>
                            <span class="hub-card-tag">Inventori</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Status ketersediaan armada, durasi mengendap (aging stock), perputaran unit, dan total valuasi aset showroom.</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Stok Siap Jual</span>
                            <span class="hub-metric-val"><?php echo $inventoryData['count'] ?? 0; ?> Unit Ready</span>
                        </div>
                        <div class="hub-metric-badge">
                            Valuasi: Rp <?php echo number_format($inventoryData['total_val'] ?? 0, 0, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Inventori</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>

            <!-- 6. Laporan Kerja Sama Leasing -->
            <a href="leasing_cooperation.php" class="hub-report-card">
                <div>
                    <div class="hub-card-header">
                        <div class="hub-card-icon-box">
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="hub-card-heading">
                            <h4 class="hub-card-title">Laporan Kerja Sama Leasing</h4>
                            <span class="hub-card-tag">Leasing</span>
                        </div>
                    </div>
                    <p class="hub-card-description">Performa kerja sama pembiayaan kredit leasing rekanan, jumlah aplikasi disetujui, dan total pembiayaan yang disalurkan.</p>
                </div>
                <div>
                    <div class="hub-card-metrics">
                        <div class="hub-metric-item">
                            <span class="hub-metric-label">Aplikasi Disetujui</span>
                            <span class="hub-metric-val"><?php echo $leasingData['count'] ?? 0; ?> Aplikasi</span>
                        </div>
                        <div class="hub-metric-badge">
                            Penyaluran: Rp <?php echo number_format($leasingData['total_amount'] ?? 0, 0, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="hub-card-footer">
                        <span class="hub-card-action">Buka Laporan Leasing</span>
                        <i class="fas fa-arrow-right hub-action-arrow"></i>
                    </div>
                </div>
            </a>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
