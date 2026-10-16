<?php
// customers/view.php - Detail Profil & Riwayat Transaksi Pelanggan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID pelanggan tidak valid', 'danger');
    redirect('customers/');
}

try {
    // Get customer with statistics
    $stmt = $db->prepare("
        SELECT c.*,
               COUNT(t.id) as transaction_count,
               COALESCE(SUM(t.total_amount), 0) as total_purchases,
               MAX(t.transaction_date) as last_transaction_date
        FROM customers c
        LEFT JOIN transactions t ON c.id = t.customer_id
        WHERE c.id = ?
        GROUP BY c.id
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$customer) {
        showAlert('Pelanggan tidak ditemukan di database.', 'danger');
        redirect('customers/');
    }
    
    // Get transactions history
    $stmt = $db->prepare("
        SELECT t.*,
               v.license_plate,
               CONCAT(b.name, ' ', m.name, ' ', COALESCE(v.year, '')) as vehicle_name
        FROM transactions t
        LEFT JOIN vehicles v ON t.vehicle_id = v.id
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        WHERE t.customer_id = ?
        ORDER BY t.transaction_date DESC
        LIMIT 25
    ");
    $stmt->execute([$id]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    showAlert('Error: ' . $e->getMessage(), 'danger');
    redirect('customers/');
}

// Handle Edit Customer POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_customer') {
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
                $stmt = $db->prepare("UPDATE customers SET name = ?, phone = ?, id_card = ?, address = ?, updated_at = NOW() WHERE id = ?");
                if ($stmt->execute([$name, $phone, $id_card, $address, $id])) {
                    showAlert('Data pelanggan berhasil diperbarui', 'success');
                    redirect('customers/view.php?id=' . $id);
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
    /* Consistent Header */
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

    /* Cards */
    .view-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
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

    /* Avatar & Contact rows */
    .customer-avatar-box {
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

    /* Vehicles Table */
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

    /* Badges */
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
    .status-completed, .status-paid { background: #dcfce7; color: #166534; }
    .status-in_progress, .status-installment { background: #fef3c7; color: #92400e; }
    .status-scheduled { background: #e0f2fe; color: #075985; }
    .status-cancelled, .status-pending { background: #fee2e2; color: #991b1b; }

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
    [data-theme="dark"] .customer-avatar-box { background: #0f172a; border-color: #334155; color: #94a3b8; }
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
            <h1>Detail Pelanggan</h1>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>customers/" class="text-primary text-decoration-underline">Pelanggan</a></li>
                <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($customer['name']); ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="<?php echo BASE_URL; ?>customers/" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <button type="button" class="btn-header-action btn-header-primary" data-bs-toggle="modal" data-bs-target="#editCustomerModal">
            <i class="fas fa-edit"></i> Edit Pelanggan
        </button>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column: Customer Profile -->
    <div class="col-12 col-xl-4 col-lg-5">
        <div class="view-card card">
            <div class="view-card-header">
                <h5 class="view-card-title">
                    <i class="fas fa-user text-primary"></i> Profil Pelanggan
                </h5>
            </div>
            <div class="view-card-body">
                <!-- Avatar & Identity -->
                <div class="text-center pb-3 border-bottom">
                    <div class="customer-avatar-box">
                        <i class="fas fa-user-circle"></i>
                    </div>
                    <h4 class="fw-bold text-dark fs-5 mb-1"><?php echo htmlspecialchars($customer['name']); ?></h4>
                    <div class="d-flex justify-content-center align-items-center gap-2 mt-2">
                        <span class="status-badge status-completed"><?php echo number_format($customer['transaction_count']); ?> Transaksi</span>
                        <span class="status-badge status-scheduled"><?php echo formatCurrency($customer['total_purchases']); ?></span>
                    </div>
                </div>

                <!-- Contact Info -->
                <div class="pt-3 pb-3 border-bottom">
                    <div class="detail-section-title">Informasi Kontak & Identitas</div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Nomor Telepon / WhatsApp</div>
                            <div class="contact-item-val"><?php echo htmlspecialchars($customer['phone']); ?></div>
                        </div>
                    </div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-id-card"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Nomor KTP (NIK)</div>
                            <div class="contact-item-val">
                                <?php if (!empty($customer['id_card'])): ?>
                                    <span class="plate-badge"><?php echo htmlspecialchars($customer['id_card']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="contact-item-row">
                        <div class="contact-item-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <div>
                            <div class="contact-item-label">Alamat Domisili</div>
                            <div class="contact-item-val small" style="line-height: 1.4;">
                                <?php echo !empty($customer['address']) ? nl2br(htmlspecialchars($customer['address'])) : '-'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Metadata -->
                <div class="pt-3">
                    <div class="d-flex justify-content-between text-muted small mb-1">
                        <span>Terdaftar:</span>
                        <span class="fw-semibold text-dark"><?php echo formatDate($customer['created_at'], 'd M Y'); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-muted small">
                        <span>Transaksi Terakhir:</span>
                        <span class="fw-semibold text-dark"><?php echo $customer['last_transaction_date'] ? formatDate($customer['last_transaction_date'], 'd M Y') : 'Belum Ada'; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Transactions History -->
    <div class="col-12 col-xl-8 col-lg-7">
        <div class="table-card card">
            <div class="table-card-header">
                <h5 class="table-card-title">Riwayat Transaksi Pembelian</h5>
                <span class="record-count badge-modern bg-primary bg-opacity-10 text-primary">
                    <?php echo count($transactions); ?> Transaksi
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
                                <th>UNIT MOBIL</th>
                                <th>TOTAL PEMBELIAN</th>
                                <th>METODE</th>
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
                                if (($status === 'pending' || empty($status)) && ($tx['payment_method'] ?? '') === 'installment') {
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
                                    <div class="fw-bold text-dark text-nowrap"><?php echo htmlspecialchars($tx['vehicle_name'] ?: 'Unit Mobil'); ?></div>
                                    <div class="mt-0.5">
                                        <span class="plate-badge"><?php echo htmlspecialchars($tx['license_plate'] ?: 'No Plat'); ?></span>
                                    </div>
                                </td>
                                <td class="price-text"><?php echo formatCurrency($tx['total_amount']); ?></td>
                                <td>
                                    <span class="text-muted small text-uppercase fw-semibold"><?php echo htmlspecialchars($tx['payment_method']); ?></span>
                                </td>
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
                    <div class="fw-semibold">Belum ada riwayat transaksi pembelian.</div>
                    <small class="text-muted">Transaksi penjualan yang terdaftar atas nama pelanggan ini akan tampil otomatis di sini.</small>
                </div>
                <?php endif; ?>
            </div>
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
                    
                    <div class="mb-3">
                        <label for="edit_name" class="form-label fw-bold small text-secondary">Nama Lengkap <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" value="<?php echo htmlspecialchars($customer['name']); ?>" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_phone" class="form-label fw-bold small text-secondary">Nomor Telepon / WhatsApp <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_phone" name="phone" value="<?php echo htmlspecialchars($customer['phone']); ?>" required style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_id_card" class="form-label fw-bold small text-secondary">Nomor KTP (NIK)</label>
                        <input type="text" class="form-control" id="edit_id_card" name="id_card" value="<?php echo htmlspecialchars($customer['id_card'] ?? ''); ?>" style="border-radius:9px;">
                    </div>
                    
                    <div class="mb-3">
                        <label for="edit_address" class="form-label fw-bold small text-secondary">Alamat Domisili</label>
                        <textarea class="form-control" id="edit_address" name="address" rows="3" style="border-radius:9px;"><?php echo htmlspecialchars($customer['address'] ?? ''); ?></textarea>
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

<?php include '../includes/footer.php'; ?>