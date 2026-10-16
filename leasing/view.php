<?php
// leasing/view.php - Detail Profil Perusahaan Leasing & Riwayat Pembiayaan Kredit
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: ../dashboard/');
    exit();
}
include '../includes/header.php';
requireLogin();
requireAdmin();

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount, $prefix = 'Rp ') {
        return $prefix . number_format((float)$amount, 0, ',', '.');
    }
}

// Get leasing company ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    showAlert('ID perusahaan leasing tidak valid!', 'danger');
    redirect('leasing/index.php');
}

// Fetch leasing company data
$stmt = $db->prepare("SELECT * FROM leasing WHERE id = ?");
$stmt->execute([$id]);
$leasing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leasing) {
    showAlert('Perusahaan leasing tidak ditemukan di database!', 'danger');
    redirect('leasing/index.php');
}

// Handle Edit Leasing POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_leasing') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
         showAlert('Token keamanan tidak valid (CSRF). Silakan muat ulang halaman.', 'danger');
    } else {
        $lid = (int)($_POST['id'] ?? 0);
        $name = sanitizeInput($_POST['name'] ?? '');
        $code = sanitizeInput($_POST['code'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $website = sanitizeInput($_POST['website'] ?? '');
        $contact_person = sanitizeInput($_POST['contact_person'] ?? '');
        $contact_phone = sanitizeInput($_POST['contact_phone'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($lid) || empty($name) || empty($code)) {
             showAlert('Nama perusahaan dan kode wajib diisi.', 'danger');
        } else {
            try {
                 // Check duplicate code (excluding self)
                 $checkStmt = $db->prepare("SELECT id FROM leasing WHERE code = ? AND id != ?");
                 $checkStmt->execute([$code, $lid]);
                 if ($checkStmt->rowCount() > 0) {
                     showAlert('Kode perusahaan leasing sudah digunakan oleh data mitra lain!', 'danger');
                 } else {
                     $stmt = $db->prepare("UPDATE leasing SET name = ?, code = ?, address = ?, phone = ?, website = ?, contact_person = ?, contact_phone = ?, status = ?, updated_at = NOW() WHERE id = ?");
                     if ($stmt->execute([$name, $code, $address, $phone, $website, $contact_person, $contact_phone, $status, $lid])) {
                         showAlert('Data perusahaan leasing berhasil diperbarui.', 'success');
                         redirect('leasing/view.php?id=' . $lid);
                     } else {
                         showAlert('Gagal memperbarui data leasing ke sistem.', 'danger');
                     }
                 }
            } catch (PDOException $e) {
                showAlert('Database Error: ' . $e->getMessage(), 'danger');
            }
        }
    }
}

// Fetch transactions history associated with this leasing company
$transactions = [];
$totalCreditAmount = 0;
$totalCreditCount = 0;

try {
    $tstmt = $db->prepare("
        SELECT 
            t.id,
            t.transaction_code,
            t.transaction_date,
            t.total_amount,
            t.payment_status,
            t.payment_method,
            c.name AS customer_name,
            c.phone AS customer_phone,
            b.name AS brand_name,
            m.name AS model_name,
            v.year AS vehicle_year,
            v.license_plate
        FROM transactions t
        JOIN customers c ON t.customer_id = c.id
        JOIN vehicles v ON t.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        WHERE t.leasing_company_id = ? AND t.payment_method = 'installment'
        ORDER BY t.transaction_date DESC
    ");
    $tstmt->execute([$id]);
    $transactions = $tstmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($transactions as $tx) {
        $totalCreditAmount += (float)$tx['total_amount'];
        $totalCreditCount++;
    }
} catch (PDOException $e) {
    $transactions = [];
}
?>

<style>
    /* ===== Consistent Master Data Page Styles ===== */
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
    .header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
    }
    .btn-header-action {
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
    .btn-header-action:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
        color: #1e293b;
        transform: translateY(-1px);
    }
    .btn-header-primary {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }
    .btn-header-primary:hover {
        background: #1e272e;
        color: #fff;
        border-color: #1e272e;
    }
    .btn-header-danger {
        color: #dc2626;
        border-color: #fecaca;
        background: #fff;
    }
    .btn-header-danger:hover {
        background: #fee2e2;
        color: #b91c1c;
        border-color: #fca5a5;
    }

    /* Cards */
    .view-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
        overflow: hidden;
    }
    .view-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .view-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .view-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .view-card-body {
        padding: 20px;
    }

    /* Avatar & Brand Badges */
    .leasing-avatar-box {
        width: 64px;
        height: 64px;
        border-radius: 14px;
        background: #f1f5f9;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.6rem;
        margin-bottom: 12px;
        border: 1px solid #e2e8f0;
    }

    .detail-section-title {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #64748b;
        letter-spacing: 0.05em;
        margin-bottom: 12px;
    }

    .contact-item-row {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 12px;
    }
    .contact-item-row:last-child {
        margin-bottom: 0;
    }
    .contact-item-icon {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.82rem;
        flex-shrink: 0;
        margin-top: 2px;
    }
    .contact-item-label {
        font-size: 0.72rem;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .contact-item-val {
        font-size: 0.88rem;
        font-weight: 600;
        color: #1e293b;
    }

    /* Table Card */
    .table-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 20px;
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
        padding: 10px 10px;
        font-size: 0.68rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .vehicles-table tbody td {
        padding: 10px 10px;
        font-size: 0.82rem;
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
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        letter-spacing: 0.02em;
        white-space: nowrap;
    }
    .status-active, .status-completed, .status-paid { background: #dcfce7; color: #166534; }
    .status-inactive, .status-cancelled, .status-pending { background: #fee2e2; color: #991b1b; }
    .status-installment, .status-in_progress, .status-partial { background: #fef3c7; color: #92400e; }
    .status-scheduled, .status-code { background: #e0f2fe; color: #075985; }

    .plate-badge {
        display: inline-block;
        padding: 2px 6px;
        background: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.74rem;
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

    /* Dark Mode Overrides */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .view-card,
    [data-theme="dark"] .table-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .view-card-header,
    [data-theme="dark"] .table-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .view-card-title,
    [data-theme="dark"] .table-card-title { color: #f1f5f9; }
    [data-theme="dark"] .leasing-avatar-box { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .contact-item-icon { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .contact-item-val { color: #f1f5f9; }
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
        <div class="d-flex align-items-center gap-2">
            <h1>Detail Mitra Leasing</h1>
            <span class="status-badge status-code fw-bold">#<?php echo htmlspecialchars($leasing['code']); ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>leasing/" class="text-primary text-decoration-underline">Data Master Leasing</a></li>
                <li class="breadcrumb-item active" aria-current="page">Detail #<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>leasing/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-header-action btn-header-primary" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($leasing), ENT_QUOTES, "UTF-8"); ?>)'>
            <i class="fas fa-edit"></i> Edit Data
        </button>
        <button type="button" class="btn-header-action btn-header-danger" onclick="deleteLeasing(<?php echo $leasing['id']; ?>)">
            <i class="fas fa-trash"></i> Hapus
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Leasing Profile & Contacts -->
    <div class="col-12 col-xl-4 col-lg-5">
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-building text-primary"></i> Profil Perusahaan
                </h5>
            </div>
            <div class="view-card-body">
                <!-- Avatar & Identity -->
                <div class="text-center pb-3 border-bottom">
                    <div class="leasing-avatar-box">
                        <i class="fas fa-handshake"></i>
                    </div>
                    <h4 class="fw-bold text-dark fs-5 mb-1"><?php echo htmlspecialchars($leasing['name']); ?></h4>
                    <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
                        <span class="status-badge status-code"><?php echo htmlspecialchars($leasing['code']); ?></span>
                        <?php if ($leasing['status'] === 'active'): ?>
                            <span class="status-badge status-active">Mitra Aktif</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">Non-Aktif</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Contact Person -->
                <div class="pt-3 pb-3 border-bottom">
                    <div class="detail-section-title">Narahubung (PIC)</div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Nama Narahubung</div>
                            <div class="contact-item-val"><?php echo !empty($leasing['contact_person']) ? htmlspecialchars($leasing['contact_person']) : '-'; ?></div>
                        </div>
                    </div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-phone-alt"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">No. Telepon / WhatsApp</div>
                            <div class="contact-item-val"><?php echo !empty($leasing['contact_phone']) ? htmlspecialchars($leasing['contact_phone']) : '-'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Company Details -->
                <div class="pt-3 pb-3 border-bottom">
                    <div class="detail-section-title">Kontak Kantor & Alamat</div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-phone-volume"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Telepon Kantor</div>
                            <div class="contact-item-val"><?php echo !empty($leasing['phone']) ? htmlspecialchars($leasing['phone']) : '-'; ?></div>
                        </div>
                    </div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-globe"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Website Perusahaan</div>
                            <div class="contact-item-val">
                                <?php if (!empty($leasing['website'])): ?>
                                    <a href="<?php echo htmlspecialchars($leasing['website']); ?>" target="_blank" class="text-primary text-decoration-none">
                                        <?php echo htmlspecialchars($leasing['website']); ?> <i class="fas fa-external-link-alt small ms-1" style="font-size:0.7rem;"></i>
                                    </a>
                                <?php else: ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Alamat Kantor</div>
                            <div class="contact-item-val small" style="line-height:1.4;">
                                <?php echo !empty($leasing['address']) ? nl2br(htmlspecialchars($leasing['address'])) : '-'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Metadata -->
                <div class="pt-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>Terdaftar:</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($leasing['created_at'], 'd M Y'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Pembaruan:</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($leasing['updated_at'], 'd M Y'); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Transactions History -->
    <div class="col-12 col-xl-8 col-lg-7">
        <div class="table-card card">
            <div class="table-card-header">
                <h5 class="table-card-title">Riwayat Transaksi Penjualan Kredit</h5>
                <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                    <?php echo number_format($totalCreditCount); ?> Transaksi
                </span>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($transactions)): ?>
                <div class="table-responsive">
                    <table class="vehicles-table">
                        <thead>
                            <tr>
                                <th style="width: 32px;" class="text-center">NO</th>
                                <th>TANGGAL & KODE</th>
                                <th>PELANGGAN</th>
                                <th>UNIT MOBIL</th>
                                <th>TOTAL</th>
                                <th>STATUS</th>
                                <th class="text-center" style="width: 48px;">AKSI</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $no = 1;
                            foreach ($transactions as $tx): 
                                $status = $tx['payment_status'] ?? 'pending';
                                if (empty($status)) $status = 'pending';
                                if (($status === 'pending' || empty($status)) && $tx['payment_method'] === 'installment') {
                                    $status = 'installment';
                                }

                                $statusBadgeClass = 'status-scheduled';
                                $statusLabelText = 'Cicilan';
                                switch($status) {
                                    case 'paid': $statusBadgeClass = 'status-completed'; $statusLabelText = 'Lunas'; break;
                                    case 'installment':
                                    case 'partial': $statusBadgeClass = 'status-installment'; $statusLabelText = 'Cicilan'; break;
                                    case 'pending': $statusBadgeClass = 'status-cancelled'; $statusLabelText = 'Pending'; break;
                                }
                            ?>
                            <tr>
                                <td class="text-muted fw-bold text-center"><?php echo $no++; ?></td>
                                <td>
                                    <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tx['transaction_code']); ?></div>
                                    <div class="text-muted small text-nowrap"><?php echo formatDate($tx['transaction_date'], 'd M Y'); ?></div>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark text-nowrap"><?php echo htmlspecialchars($tx['customer_name']); ?></div>
                                    <div class="text-muted small text-nowrap"><?php echo htmlspecialchars($tx['customer_phone'] ?: '-'); ?></div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tx['brand_name'] . ' ' . $tx['model_name']); ?></div>
                                    <div class="d-flex align-items-center gap-1.5 mt-1 flex-nowrap">
                                        <span class="plate-badge"><?php echo htmlspecialchars($tx['license_plate'] ?: 'No Plat'); ?></span>
                                        <?php if (!empty($tx['vehicle_year'])): ?>
                                        <span class="text-muted small text-nowrap">• <?php echo $tx['vehicle_year']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="price-text"><?php echo formatCurrency($tx['total_amount']); ?></td>
                                <td>
                                    <span class="status-badge <?php echo $statusBadgeClass; ?>">
                                        <?php echo $statusLabelText; ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <a href="<?php echo BASE_URL; ?>transactions/view.php?id=<?php echo $tx['id']; ?>" class="action-btn action-btn-view mx-auto" title="Lihat Detail Transaksi">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-receipt fa-2x text-muted mb-2 d-block"></i>
                    <div class="fw-semibold">Belum ada riwayat transaksi pembiayaan.</div>
                    <small class="text-muted">Transaksi penjualan dengan metode kredit melalui mitra leasing ini akan tampil otomatis di sini.</small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Edit Leasing Modal -->
<div class="modal fade" id="editLeasingModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden;">
            <div class="modal-header border-bottom py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <div class="modal-title-icon">
                        <i class="fas fa-edit"></i>
                    </div>
                    <h5 class="modal-title fw-bold fs-6">Edit Data Perusahaan Leasing</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <div class="modal-body p-4">
                    <input type="hidden" name="action" value="edit_leasing">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="id" id="edit_id" value="<?php echo $leasing['id']; ?>">
                    
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label for="edit_name" class="form-label fw-bold small text-secondary">Nama Perusahaan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required style="border-radius:9px;">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="edit_code" class="form-label fw-bold small text-secondary">Kode Singkatan <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_code" name="code" required style="border-radius:9px;">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_contact_person" class="form-label fw-bold small text-secondary">Nama Narahubung (PIC)</label>
                            <input type="text" class="form-control" id="edit_contact_person" name="contact_person" style="border-radius:9px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_contact_phone" class="form-label fw-bold small text-secondary">No. Telepon Narahubung</label>
                            <input type="text" class="form-control" id="edit_contact_phone" name="contact_phone" style="border-radius:9px;">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="edit_phone" class="form-label fw-bold small text-secondary">Telepon Kantor</label>
                            <input type="text" class="form-control" id="edit_phone" name="phone" style="border-radius:9px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="edit_website" class="form-label fw-bold small text-secondary">Website Perusahaan</label>
                            <input type="text" class="form-control" id="edit_website" name="website" placeholder="https://" style="border-radius:9px;">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="edit_address" class="form-label fw-bold small text-secondary">Alamat Kantor</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3" style="border-radius:9px;"></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="edit_status" class="form-label fw-bold small text-secondary">Status Kemitraan</label>
                        <select class="form-select" id="edit_status" name="status" style="border-radius:9px;">
                            <option value="active">Mitra Aktif</option>
                            <option value="inactive">Non-Aktif</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top py-3 px-4">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius:8px;">Batal</button>
                    <button type="submit" class="btn btn-primary" style="border-radius:8px;background:#2d3436;border:none;">
                        <i class="fas fa-save me-1"></i>Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function deleteLeasing(id) {
    if (confirm('Apakah Anda yakin ingin menghapus data perusahaan leasing ini?')) {
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

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.name || '';
    document.getElementById('edit_code').value = data.code || '';
    document.getElementById('edit_contact_person').value = data.contact_person || '';
    document.getElementById('edit_contact_phone').value = data.contact_phone || '';
    document.getElementById('edit_phone').value = data.phone || '';
    document.getElementById('edit_website').value = data.website || '';
    document.getElementById('edit_address').value = data.address || '';
    document.getElementById('edit_status').value = data.status || 'active';
    
    var modal = new bootstrap.Modal(document.getElementById('editLeasingModal'));
    modal.show();
}
</script>

<?php include '../includes/footer.php'; ?>
