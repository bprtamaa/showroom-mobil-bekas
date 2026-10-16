<?php
// reports/leasing_cooperation.php - Laporan Kerja Sama Mitra Leasing
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

$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

$years = [];
try {
    $stmt = $conn->query("SELECT DISTINCT YEAR(transaction_date) AS year FROM transactions WHERE leasing_company_id IS NOT NULL AND payment_method = 'installment' ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $years = [];
}
if (empty($years)) {
    $years[] = ['year' => $year];
}

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

$stmt = $conn->prepare(" 
    SELECT 
        lc.id,
        lc.name AS leasing_name,
        lc.status,
        COUNT(t.id) AS total_transactions,
        COALESCE(SUM(t.total_amount), 0) AS total_amount,
        SUM(CASE WHEN t.payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN t.payment_status != 'paid' AND t.payment_status != 'cancelled' THEN 1 ELSE 0 END) AS installment_count
    FROM leasing lc
    LEFT JOIN transactions t ON t.leasing_company_id = lc.id 
        AND t.payment_method = 'installment'
        AND YEAR(t.transaction_date) = ?
    GROUP BY lc.id, lc.name, lc.status
    ORDER BY total_amount DESC, leasing_name ASC
");
$stmt->execute([$year]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grandTotalAmount = 0;
$grandTotalTransactions = 0;
$activePartners = 0;
foreach ($rows as $row) {
    $grandTotalTransactions += (int)$row['total_transactions'];
    $grandTotalAmount += (float)$row['total_amount'];
    if (isset($row['status']) && $row['status'] === 'active') {
        $activePartners++;
    }
}

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
        grid-template-columns: repeat(3, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    @media (max-width: 768px) {
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
        min-width: 160px;
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

    .price-text {
        font-weight: 700;
        color: #166534;
        font-size: 0.88rem;
        white-space: nowrap;
    }
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
    [data-theme="dark"] .btn-search { background: #0f172a; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .vehicles-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .vehicles-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .vehicles-table tbody tr:hover { background: rgba(59,130,246,0.06); }
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
        <h1>Laporan Kerja Sama Leasing</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>reports/" class="text-primary text-decoration-underline">Laporan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Kerja Sama Leasing</li>
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
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($grandTotalTransactions); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Tx</span></div>
            <div class="summary-label">Total Transaksi Kredit</div>
            <small class="text-muted" style="font-size: 0.72rem;">Tahun <?php echo $year; ?></small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon emerald">
            <i class="fas fa-money-bill-wave"></i>
        </div>
        <div>
            <div class="summary-value" style="font-size: 1.05rem;"><?php echo formatCurrency($grandTotalAmount); ?></div>
            <div class="summary-label">Total Nilai Pembiayaan</div>
            <small class="text-muted" style="font-size: 0.72rem;">Akumulasi pembiayaan kredit</small>
        </div>
    </div>

    <div class="summary-card">
        <div class="summary-icon blue">
            <i class="fas fa-building"></i>
        </div>
        <div>
            <div class="summary-value"><?php echo number_format($activePartners); ?> <span style="font-size:0.75rem;font-weight:600;color:#64748b;">Mitra</span></div>
            <div class="summary-label">Mitra Leasing Aktif</div>
            <small class="text-muted" style="font-size: 0.72rem;">Perusahaan leasing rekanan</small>
        </div>
    </div>
</div>

<!-- Filter Card -->
<div class="search-card card">
    <div class="card-body">
        <form method="GET">
            <div class="search-row">
                <select name="year" class="filter-select" onchange="this.form.submit()">
                    <?php foreach ($years as $y): ?>
                        <option value="<?php echo (int)$y['year']; ?>" <?php echo (int)$y['year'] === $year ? 'selected' : ''; ?>>
                            Tahun <?php echo (int)$y['year']; ?>
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

<!-- Table Card -->
<div class="table-card">
    <div class="table-card-header">
        <h5 class="table-card-title">
            <i class="fas fa-handshake text-primary"></i> Ringkasan Kemitraan Leasing - Tahun <?php echo htmlspecialchars($year); ?>
        </h5>
        <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
            <?php echo count($rows); ?> Rekanan
        </span>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($rows)): ?>
        <div class="table-responsive">
            <table class="vehicles-table">
                <thead>
                    <tr>
                        <th style="width: 45px;" class="text-center">NO</th>
                        <th style="min-width: 180px;">NAMA LEASING</th>
                        <th style="min-width: 120px;" class="text-center">TOTAL APLIKASI</th>
                        <th style="min-width: 120px;" class="text-center">LUNAS</th>
                        <th style="min-width: 120px;" class="text-center">BERJALAN</th>
                        <th style="min-width: 160px;" class="text-end">TOTAL PEMBIAYAAN</th>
                        <th style="width: 70px;" class="text-center">AKSI</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $no = 1;
                    foreach ($rows as $row): 
                    ?>
                    <tr>
                        <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                        <td>
                            <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($row['leasing_name']); ?></div>
                            <small class="text-muted text-capitalize"><?php echo htmlspecialchars($row['status'] ?? 'active'); ?></small>
                        </td>
                        <td class="text-center fw-semibold text-dark"><?php echo number_format($row['total_transactions']); ?> Unit</td>
                        <td class="text-center">
                            <span class="badge-modern bg-success bg-opacity-10 text-success fw-bold">
                                <?php echo number_format($row['paid_count']); ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <span class="badge-modern bg-warning bg-opacity-10 text-warning fw-bold">
                                <?php echo number_format($row['installment_count']); ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <div class="price-text"><?php echo formatCurrency($row['total_amount']); ?></div>
                        </td>
                        <td class="text-center">
                            <div class="action-btns">
                                <a href="<?php echo BASE_URL; ?>leasing/view.php?id=<?php echo $row['id']; ?>" class="action-btn" title="Lihat Detail Leasing">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#fafbfc;border-top:1.5px solid #e2e8f0;">
                        <th colspan="2" class="py-3 text-end fw-bold">TOTAL PEMBIAYAAN TAHUN <?php echo $year; ?>:</th>
                        <th class="py-3 text-center fw-bold"><?php echo number_format($grandTotalTransactions); ?> Unit</th>
                        <th colspan="2"></th>
                        <th class="py-3 text-end price-text" style="font-size: 1rem;">
                            <?php echo formatCurrency($grandTotalAmount); ?>
                        </th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-handshake fa-2x text-muted mb-2 d-block"></i>
            <div class="fw-semibold">Tidak ada transaksi kredit leasing pada tahun <?php echo $year; ?>.</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print Only Section -->
<div class="print-only">
    <div class="print-header" style="text-align: center; margin-bottom: 20px;">
        <h3 style="margin: 5px 0; font-size: 16px; font-weight: bold;"><?php echo htmlspecialchars($printCompanyName); ?></h3>
        <h3 style="margin: 8px 0; font-size: 15px; font-weight: bold;">Laporan Kerja Sama Leasing</h3>
        <p style="margin: 4px 0; font-size: 13px;">Tahun <?php echo $year; ?></p>
    </div>
    <table style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <thead>
            <tr style="background-color: #f5f5f5; border-bottom: 2px solid #333;">
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">No</th>
                <th style="padding: 6px; text-align: left; border: 1px solid #ddd; font-size: 11px;">Nama Leasing</th>
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">Total Transaksi</th>
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">Lunas</th>
                <th style="padding: 6px; text-align: center; border: 1px solid #ddd; font-size: 11px;">Cicilan</th>
                <th style="padding: 6px; text-align: right; border: 1px solid #ddd; font-size: 11px;">Total Nilai Pembiayaan</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $pNo = 1;
            foreach ($rows as $row): 
            ?>
            <tr>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo $pNo++; ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; font-size: 11px;"><?php echo htmlspecialchars($row['leasing_name']); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo number_format($row['total_transactions']); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo number_format($row['paid_count']); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: center; font-size: 11px;"><?php echo number_format($row['installment_count']); ?></td>
                <td style="padding: 6px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($row['total_amount'], 0, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f5f5f5; font-weight: bold;">
                <td colspan="5" style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Total Pembiayaan:</td>
                <td style="padding: 8px; border: 1px solid #ddd; text-align: right; font-size: 11px;">Rp <?php echo number_format($grandTotalAmount, 0, ',', '.'); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php include '../includes/footer.php'; ?>
