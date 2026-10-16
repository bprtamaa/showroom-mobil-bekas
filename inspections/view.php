<?php
// inspections/view.php - Sertifikat & Laporan Hasil Inspeksi Kendaraan
$pageTitle = 'Aplikasi Manajemen Showroom';
require_once '../config/database.php';
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login.php');
    exit();
}
include '../includes/header.php';
require_once '../includes/inspection_helpers.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    showAlert('ID laporan inspeksi tidak valid.', 'danger');
    redirect('inspections/');
}

try {
    $stmt = $db->prepare("
        SELECT 
            i.*,
            v.license_plate, v.chassis_number, v.engine_number, v.year, v.color, v.mileage, 
            v.transmission, v.fuel_type, v.selling_price, v.status as vehicle_status, v.images,
            b.name as brand_name,
            m.name as model_name,
            ct.name as car_type_name
        FROM vehicle_inspections i
        JOIN vehicles v ON i.vehicle_id = v.id
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        WHERE i.id = ?
    ");
    $stmt->execute([$id]);
    $inspection = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$inspection) {
        showAlert('Laporan inspeksi tidak ditemukan.', 'danger');
        redirect('inspections/');
    }

    $checklistMaster = getInspectionChecklistMaster();
    $checklistAnswers = !empty($inspection['checklist_data']) ? json_decode($inspection['checklist_data'], true) : [];
    $gradeConfig = getGradeConfig($inspection['overall_grade']);

} catch (PDOException $e) {
    showAlert('Gagal memuat laporan: ' . $e->getMessage(), 'danger');
    redirect('inspections/');
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

    /* Modern Certificate Container */
    .cert-container {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .cert-hero {
        background: #1e293b;
        color: #ffffff;
        padding: 2.2rem 2rem;
        position: relative;
    }

    .cert-grade-seal {
        width: 105px;
        height: 105px;
        border-radius: 50%;
        background: <?php echo $gradeConfig['badge_bg']; ?>;
        color: #ffffff;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        border: 3px solid rgba(255, 255, 255, 0.25);
        box-shadow: 0 6px 20px rgba(0,0,0,0.25);
        margin: 0 auto;
    }
    .cert-grade-letter {
        font-size: 2.2rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -0.03em;
    }
    .cert-grade-sub {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        opacity: 0.9;
        margin-top: 2px;
    }

    .guarantee-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 14px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .guarantee-icon {
        width: 38px;
        height: 38px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex-shrink: 0;
    }
    .guarantee-icon.safe { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .guarantee-icon.warn { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

    .score-progress-bar {
        height: 8px;
        border-radius: 6px;
        background: #f1f5f9;
        overflow: hidden;
    }
    .score-progress-fill {
        height: 100%;
        border-radius: 6px;
        transition: width 0.6s ease;
    }
    .score-progress-fill.frame { background: #4f46e5; }
    .score-progress-fill.engine { background: #0284c7; }
    .score-progress-fill.exterior { background: #16a34a; }
    .score-progress-fill.interior { background: #d97706; }
    .score-progress-fill.road_test { background: #8b5cf6; }

    /* Dark Mode */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .cert-container { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .guarantee-card { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .score-progress-bar { background: #0f172a; }

    /* Print Styles */
    @media print {
        .navbar, .sidebar, .page-header, .no-print, #themeToggleBtn {
            display: none !important;
        }
        body, .main-content {
            background: #ffffff !important;
            color: #000000 !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        .cert-container {
            box-shadow: none !important;
            border: 1px solid #ccc !important;
        }
        .cert-hero {
            background: #0f172a !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    }
</style>

<!-- Page Header -->
<div class="vehicles-page-header no-print">
    <div>
        <div class="d-flex align-items-center gap-2">
            <h1>Sertifikat & Hasil Inspeksi</h1>
            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($inspection['license_plate']); ?></span>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-primary text-decoration-underline">Inspeksi Kendaraan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Sertifikat #<?php echo $id; ?></li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
        <a href="edit.php?id=<?php echo $inspection['id']; ?>" class="btn-header-action">
            <i class="fas fa-edit"></i> Edit Laporan
        </a>
        <button type="button" class="btn-header-action btn-header-primary" onclick="window.print()">
            <i class="fas fa-print"></i> Cetak Sertifikat
        </button>
    </div>
</div>

<!-- Main Certificate Document -->
<div class="cert-container">
    <!-- Hero Banner -->
    <div class="cert-hero">
        <div class="row align-items-center g-4">
            <div class="col-12 col-md-8">
                <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge bg-primary px-3 py-1 text-uppercase fw-bold" style="letter-spacing: 0.05em;font-size:0.72rem;">Sertifikat Resmi Showroom</span>
                    <span class="badge bg-light bg-opacity-25 text-white" style="font-size:0.72rem;">No. Ins-<?php echo str_pad($inspection['id'], 6, '0', STR_PAD_LEFT); ?></span>
                </div>
                <h2 class="fw-bold mb-1 text-white">
                    <?php echo htmlspecialchars($inspection['brand_name'] . ' ' . $inspection['model_name']); ?>
                </h2>
                <div class="d-flex flex-wrap align-items-center gap-3 text-white-50 small mt-2">
                    <span><i class="fas fa-car me-1 text-white"></i> Plat: <strong class="text-white"><?php echo htmlspecialchars($inspection['license_plate'] ?: 'No Plat'); ?></strong></span>
                    <span>&bull;</span>
                    <span><i class="far fa-calendar-alt me-1 text-white"></i> Tahun: <strong class="text-white"><?php echo (int)$inspection['year']; ?></strong></span>
                    <span>&bull;</span>
                    <span><i class="fas fa-tachometer-alt me-1 text-white"></i> Odo: <strong class="text-white"><?php echo number_format($inspection['mileage']); ?> KM</strong></span>
                    <span>&bull;</span>
                    <span><i class="fas fa-palette me-1 text-white"></i> Warna: <strong class="text-white"><?php echo htmlspecialchars($inspection['color']); ?></strong></span>
                </div>
            </div>

            <div class="col-12 col-md-4 text-center">
                <div class="cert-grade-seal">
                    <div class="cert-grade-letter"><?php echo htmlspecialchars($inspection['overall_grade']); ?></div>
                    <div class="cert-grade-sub">Grade</div>
                </div>
                <div class="mt-2 text-white fw-bold fs-6">
                    Skor: <?php echo number_format($inspection['overall_score'], 1); ?> / 100
                </div>
                <div class="small text-white-50"><?php echo htmlspecialchars($gradeConfig['subtext']); ?></div>
            </div>
        </div>
    </div>

    <!-- Guarantees & 4 Critical Pillars -->
    <div class="p-4 border-bottom">
        <h6 class="fw-bold text-dark text-uppercase small mb-3" style="letter-spacing: 0.04em;font-size:0.75rem;">
            <i class="fas fa-shield-alt text-primary me-2"></i>Hasil 4 Jaminan Keselamatan Showroom
        </h6>
        <div class="row g-3">
            <div class="col-6 col-lg-3">
                <div class="guarantee-card">
                    <div class="guarantee-icon <?php echo $inspection['flood_free'] ? 'safe' : 'warn'; ?>">
                        <i class="fas fa-water"></i>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size: 0.72rem;">Uji Banjir</div>
                        <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo $inspection['flood_free'] ? 'Bebas Banjir' : 'Bekas Banjir'; ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="guarantee-card">
                    <div class="guarantee-icon <?php echo $inspection['accident_free'] ? 'safe' : 'warn'; ?>">
                        <i class="fas fa-car-crash"></i>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size: 0.72rem;">Uji Tabrakan</div>
                        <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo $inspection['accident_free'] ? 'Bebas Tabrakan' : 'Pernah Tabrak'; ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="guarantee-card">
                    <div class="guarantee-icon <?php echo !$inspection['odometer_tampered'] ? 'safe' : 'warn'; ?>">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size: 0.72rem;">Keaslian Odo</div>
                        <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo !$inspection['odometer_tampered'] ? 'Odometer Asli' : 'Indikasi Putar'; ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="guarantee-card">
                    <div class="guarantee-icon <?php echo $inspection['fire_free'] ? 'safe' : 'warn'; ?>">
                        <i class="fas fa-fire-extinguisher"></i>
                    </div>
                    <div>
                        <div class="text-muted" style="font-size: 0.72rem;">Uji Kebakaran</div>
                        <div class="fw-bold text-dark" style="font-size:0.85rem;"><?php echo $inspection['fire_free'] ? 'Bebas Terbakar' : 'Pernah Terbakar'; ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Scores Progress Breakdown -->
    <div class="p-4 border-bottom">
        <h6 class="fw-bold text-dark text-uppercase small mb-3" style="letter-spacing: 0.04em;font-size:0.75rem;">
            <i class="fas fa-chart-pie text-primary me-2"></i>Rincian Nilai 5 Kategori Pemeriksaan
        </h6>
        <div class="row g-3">
            <div class="col-12 col-md-6 col-lg-4">
                <div class="p-3 rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <span class="small fw-semibold text-dark"><i class="fas fa-shield-alt text-primary me-1"></i> Rangka & Struktur</span>
                        <span class="fw-bold text-primary"><?php echo number_format($inspection['frame_score'], 1); ?>%</span>
                    </div>
                    <div class="score-progress-bar">
                        <div class="score-progress-fill frame" style="width: <?php echo $inspection['frame_score']; ?>%;"></div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <div class="p-3 rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <span class="small fw-semibold text-dark"><i class="fas fa-cogs text-info me-1"></i> Mesin & Transmisi</span>
                        <span class="fw-bold text-info"><?php echo number_format($inspection['engine_score'], 1); ?>%</span>
                    </div>
                    <div class="score-progress-bar">
                        <div class="score-progress-fill engine" style="width: <?php echo $inspection['engine_score']; ?>%;"></div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-4">
                <div class="p-3 rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <span class="small fw-semibold text-dark"><i class="fas fa-car-side text-success me-1"></i> Eksterior & Bodi</span>
                        <span class="fw-bold text-success"><?php echo number_format($inspection['exterior_score'], 1); ?>%</span>
                    </div>
                    <div class="score-progress-bar">
                        <div class="score-progress-fill exterior" style="width: <?php echo $inspection['exterior_score']; ?>%;"></div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-6">
                <div class="p-3 rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <span class="small fw-semibold text-dark"><i class="fas fa-couch text-warning me-1"></i> Interior & Kabin</span>
                        <span class="fw-bold text-warning"><?php echo number_format($inspection['interior_score'], 1); ?>%</span>
                    </div>
                    <div class="score-progress-bar">
                        <div class="score-progress-fill interior" style="width: <?php echo $inspection['interior_score']; ?>%;"></div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-12 col-lg-6">
                <div class="p-3 rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-1.5">
                        <span class="small fw-semibold text-dark"><i class="fas fa-road text-primary me-1"></i> Kaki-Kaki & Uji Jalan</span>
                        <span class="fw-bold text-primary"><?php echo number_format($inspection['road_test_score'], 1); ?>%</span>
                    </div>
                    <div class="score-progress-bar">
                        <div class="score-progress-fill road_test" style="width: <?php echo $inspection['road_test_score']; ?>%;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Inspector Notes -->
    <?php if (!empty($inspection['summary_notes'])): ?>
    <div class="p-4 border-bottom">
        <h6 class="fw-bold text-dark text-uppercase small mb-2" style="letter-spacing: 0.04em;font-size:0.75rem;">
            <i class="fas fa-comment-dots text-primary me-2"></i>Catatan & Rekomendasi Teknisi
        </h6>
        <p class="text-dark mb-0 small" style="line-height: 1.6;">
            <?php echo nl2br(htmlspecialchars($inspection['summary_notes'])); ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- Full 100+ Points Item Breakdown (Accordion) -->
    <div class="p-4 border-bottom">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold text-dark text-uppercase small mb-0" style="letter-spacing: 0.04em;font-size:0.75rem;">
                <i class="fas fa-list-check text-primary me-2"></i>Detail Lembar Checklist 100+ Titik
            </h6>
            <button class="btn btn-sm btn-outline-secondary no-print" type="button" data-bs-toggle="collapse" data-bs-target="#checklistDetailWrap" style="border-radius:8px;font-size:0.75rem;">
                Buka / Tutup Rincian
            </button>
        </div>

        <div class="collapse show" id="checklistDetailWrap">
            <?php foreach ($checklistMaster as $catKey => $catData): ?>
            <div class="mb-4">
                <div class="fw-bold text-dark small mb-2 d-flex align-items-center gap-2">
                    <i class="fas <?php echo $catData['icon']; ?> text-primary"></i>
                    <span><?php echo htmlspecialchars($catData['name']); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0" style="font-size: 0.8rem;">
                        <thead>
                            <tr>
                                <th style="width: 40px;" class="text-center text-muted">No</th>
                                <th>Item Pemeriksaan</th>
                                <th style="width: 140px;" class="text-center">Status Kondisi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $itemIdx = 0;
                            foreach ($catData['items'] as $itemKey => $itemLabel): 
                                $itemIdx++;
                                $ans = $checklistAnswers[$itemKey] ?? 'good';
                            ?>
                            <tr>
                                <td class="text-center text-muted"><?php echo $itemIdx; ?></td>
                                <td><?php echo htmlspecialchars($itemLabel); ?></td>
                                <td class="text-center">
                                    <?php if ($ans === 'good'): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success fw-bold px-2 py-1"><i class="fas fa-check me-1"></i>Baik</span>
                                    <?php elseif ($ans === 'minor'): ?>
                                    <span class="badge bg-warning bg-opacity-10 text-warning fw-bold px-2 py-1"><i class="fas fa-exclamation me-1"></i>Minor</span>
                                    <?php else: ?>
                                    <span class="badge bg-danger bg-opacity-10 text-danger fw-bold px-2 py-1"><i class="fas fa-wrench me-1"></i>Perlu Servis</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Inspector Signoff Footer -->
    <div class="p-4">
        <div class="row align-items-center text-center text-md-start g-3">
            <div class="col-12 col-md-8">
                <div class="small text-muted">Tanggal Inspeksi: <strong><?php echo formatDate($inspection['inspection_date'], 'd F Y'); ?></strong></div>
                <div class="small text-muted mt-0.5">Disertifikasi oleh: <strong class="text-dark"><?php echo htmlspecialchars($inspection['inspector_name']); ?></strong></div>
                <div class="small text-muted mt-1" style="font-size: 0.72rem;">
                    * Laporan inspeksi ini diterbitkan berdasarkan pengujian fisik standar profesional 100+ titik pada saat tanggal inspeksi dilakukan.
                </div>
            </div>
            <div class="col-12 col-md-4 text-md-end">
                <div class="d-inline-block text-center border-top border-dark pt-2" style="width: 180px;">
                    <div class="small fw-bold text-dark"><?php echo htmlspecialchars($inspection['inspector_name']); ?></div>
                    <div class="small text-muted" style="font-size: 0.72rem;">Lead Inspector</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
