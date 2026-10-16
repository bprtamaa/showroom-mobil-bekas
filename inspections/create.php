<?php
// inspections/create.php - Formulir Inspeksi Kendaraan & Scoring Grade
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

$preselectedVehicleId = (int)($_GET['vehicle_id'] ?? 0);
$checklistMaster = getInspectionChecklistMaster();

// Handle POST Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_inspection') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token keamanan tidak valid (CSRF). Silakan ulangi pengiriman formulir.';
    } else {
        $vehicle_id = (int)($_POST['vehicle_id'] ?? 0);
        $inspector_name = sanitizeInput($_POST['inspector_name'] ?? '');
        $inspection_date = $_POST['inspection_date'] ?? date('Y-m-d');
        $summary_notes = sanitizeInput($_POST['summary_notes'] ?? '');
        
        $majorChecks = [
            'flood_free' => isset($_POST['flood_free']) && $_POST['flood_free'] == '1' ? 1 : 0,
            'accident_free' => isset($_POST['accident_free']) && $_POST['accident_free'] == '1' ? 1 : 0,
            'odometer_tampered' => isset($_POST['odometer_tampered']) && $_POST['odometer_tampered'] == '1' ? 1 : 0,
            'fire_free' => isset($_POST['fire_free']) && $_POST['fire_free'] == '1' ? 1 : 0,
        ];

        // Gather all checklist answers
        $checklistAnswers = [];
        foreach ($checklistMaster as $catKey => $catData) {
            foreach ($catData['items'] as $itemKey => $itemLabel) {
                $checklistAnswers[$itemKey] = $_POST['check_' . $itemKey] ?? 'good';
            }
        }

        if (empty($vehicle_id)) {
            $error = 'Silakan pilih unit kendaraan yang akan diinspeksi.';
        } elseif (empty($inspector_name)) {
            $error = 'Nama inspektor harus diisi.';
        } else {
            try {
                // Calculate scores and grade using engine
                $scoreResults = calculateInspectionScoreAndGrade($checklistAnswers, $majorChecks);

                $stmt = $db->prepare("
                    INSERT INTO vehicle_inspections (
                        vehicle_id, inspector_id, inspector_name, inspection_date,
                        overall_grade, overall_score,
                        flood_free, accident_free, odometer_tampered, fire_free,
                        frame_score, engine_score, exterior_score, interior_score, road_test_score,
                        summary_notes, checklist_data, status, created_at, updated_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, 'completed', NOW(), NOW()
                    )
                ");

                $inspectorId = $_SESSION['user_id'] ?? null;
                $checklistJson = json_encode($checklistAnswers);

                $stmt->execute([
                    $vehicle_id,
                    $inspectorId,
                    $inspector_name,
                    $inspection_date,
                    $scoreResults['overall_grade'],
                    $scoreResults['overall_score'],
                    $scoreResults['flood_free'],
                    $scoreResults['accident_free'],
                    $scoreResults['odometer_tampered'],
                    $scoreResults['fire_free'],
                    $scoreResults['frame_score'],
                    $scoreResults['engine_score'],
                    $scoreResults['exterior_score'],
                    $scoreResults['interior_score'],
                    $scoreResults['road_test_score'],
                    $summary_notes,
                    $checklistJson
                ]);

                $newInspectionId = $db->lastInsertId();
                showAlert('Inspeksi kendaraan berhasil disimpan dengan hasil Grade ' . $scoreResults['overall_grade'] . ' (Skor: ' . number_format($scoreResults['overall_score'], 1) . ').', 'success');
                redirect('inspections/view.php?id=' . $newInspectionId);

            } catch (PDOException $e) {
                $error = 'Gagal menyimpan hasil inspeksi: ' . $e->getMessage();
            }
        }
    }
}

// Fetch available vehicles list for dropdown
try {
    $vehiclesStmt = $db->query("
        SELECT v.id, v.license_plate, v.year, v.color, v.status, b.name as brand_name, m.name as model_name
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        ORDER BY v.status = 'available' DESC, v.created_at DESC
    ");
    $vehiclesList = $vehiclesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vehiclesList = [];
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

    /* Sticky Live Scoring Top Bar */
    .insp-sticky-summary {
        position: sticky;
        top: 75px;
        z-index: 100;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        padding: 14px 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        margin-bottom: 20px;
        backdrop-filter: blur(10px);
    }

    .form-card-clean {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        position: relative;
    }
    .form-card-clean.card-has-dropdown {
        overflow: visible !important;
        z-index: 30;
    }
    .form-card-clean .card-header-clean {
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .header-title-group {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .header-icon-badge {
        width: 32px;
        height: 32px;
        border-radius: 8px;
        background: #f1f5f9;
        color: #475569;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 0.85rem;
    }
    .form-card-clean h5 {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
    }
    .form-card-clean .card-body {
        padding: 20px;
    }

    /* 4 Safety Pillars Cards */
    .major-cert-card {
        background: #f8fafc;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 16px;
        text-align: center;
        transition: all 0.2s ease;
    }
    .major-cert-card.checked-safe {
        background: #f0fdf4;
        border-color: #86efac;
    }
    .major-cert-card.checked-warn {
        background: #fef2f2;
        border-color: #fca5a5;
    }
    .major-cert-icon {
        font-size: 1.5rem;
        margin-bottom: 8px;
        display: block;
    }
    .major-cert-card.checked-safe .major-cert-icon { color: #16a34a; }
    .major-cert-card.checked-warn .major-cert-icon { color: #dc2626; }

    /* Checklist Row Items */
    .check-item-row {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 10px 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 8px;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    .check-item-row:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
    }
    .check-item-title {
        font-size: 0.84rem;
        font-weight: 600;
        color: #1e293b;
    }
    .check-btn-group .btn {
        font-size: 0.76rem;
        font-weight: 600;
        padding: 4px 10px;
    }

    /* Badges */
    .cert-guarantee-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        font-size: 0.72rem;
        font-weight: 600;
        padding: 3px 8px;
        border-radius: 6px;
    }
    .cert-guarantee-badge.success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
    .cert-guarantee-badge.danger { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

    /* Submit Action Bar */
    .btn-submit-inspection {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 10px 22px;
        background: #2d3436;
        color: #ffffff;
        font-size: 0.86rem;
        font-weight: 600;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
        text-decoration: none;
    }
    .btn-submit-inspection:hover {
        background: #1e272e;
        color: #ffffff;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* Dark Mode */
    [data-theme="dark"] .vehicles-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #f8fafc; }
    [data-theme="dark"] .insp-sticky-summary { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-clean { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .form-card-clean .card-header-clean { border-bottom-color: #334155; }
    [data-theme="dark"] .form-card-clean h5 { color: #f1f5f9; }
    [data-theme="dark"] .header-icon-badge { background: #0f172a; color: #94a3b8; }
    [data-theme="dark"] .major-cert-card { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .check-item-row { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .check-item-row:hover { background: #162032; border-color: #475569; }
    [data-theme="dark"] .check-item-title { color: #f1f5f9; }
    [data-theme="dark"] .btn-submit-inspection { background: #3b82f6; }
    [data-theme="dark"] .btn-submit-inspection:hover { background: #2563eb; }
</style>

<!-- Choices.js CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />

<style>
/* Custom Choices.js Styling */
.choices {
    position: relative;
    margin-bottom: 0;
}
.choices.is-open {
    z-index: 1050 !important;
}
.choices__inner {
    min-height: 42px !important;
    padding: 4px 12px !important;
    border: 1px solid #e2e8f0 !important;
    border-radius: 10px !important;
    background-color: #f8fafc !important;
    font-size: 0.86rem !important;
    display: flex;
    align-items: center;
}
.choices__list--dropdown,
.choices__list[aria-expanded] {
    position: absolute !important;
    top: 100% !important;
    left: 0 !important;
    width: 100% !important;
    z-index: 1050 !important;
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: 10px !important;
    box-shadow: 0 12px 28px -4px rgba(15, 23, 42, 0.18) !important;
    max-height: 280px !important;
    overflow-y: auto !important;
}
.choices__list--dropdown .choices__item {
    padding: 10px 14px;
    font-size: 0.86rem;
}
.choices__list--dropdown .choices__item--selectable.is-highlighted {
    background-color: #f1f5f9 !important;
    color: #0f172a !important;
}
.choices__input {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 6px 10px;
    margin: 6px;
    width: calc(100% - 12px) !important;
}
.choices__input:focus {
    border-color: #3b82f6;
    outline: none;
}
[data-theme="dark"] .choices__inner {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .choices__list--single .choices__item { color: #f1f5f9 !important; }
[data-theme="dark"] .choices__list--dropdown,
[data-theme="dark"] .choices__list[aria-expanded] {
    background-color: #1e293b !important;
    border-color: #334155 !important;
    box-shadow: 0 12px 28px -4px rgba(0, 0, 0, 0.5) !important;
}
[data-theme="dark"] .choices__list--dropdown .choices__item { color: #cbd5e1 !important; }
[data-theme="dark"] .choices__list--dropdown .choices__item--selectable.is-highlighted {
    background-color: #334155 !important;
    color: #f1f5f9 !important;
}
[data-theme="dark"] .choices__input {
    background-color: #0f172a !important;
    border-color: #334155 !important;
    color: #f1f5f9 !important;
}
</style>

<!-- Page Header -->
<div class="vehicles-page-header">
    <div>
        <h1>Mulai Inspeksi Kendaraan</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="index.php" class="text-primary text-decoration-underline">Inspeksi Kendaraan</a></li>
                <li class="breadcrumb-item active" aria-current="page">Formulir 100+ Titik</li>
            </ol>
        </nav>
    </div>
    <div class="header-actions">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-arrow-left"></i> Kembali
        </a>
    </div>
</div>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show border-0 shadow-sm mb-4" style="border-radius:12px;background:#fef2f2;color:#991b1b;border-left:4px solid #ef4444 !important;">
    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="POST" id="inspectionForm">
    <input type="hidden" name="action" value="save_inspection">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

    <!-- Top Sticky Live Score Bar -->
    <div class="insp-sticky-summary">
        <div class="row align-items-center g-3">
            <div class="col-12 col-md-4">
                <div class="small text-muted text-uppercase fw-bold" style="font-size:0.72rem;letter-spacing:0.04em;">Skor Hasil Inspeksi</div>
                <div class="d-flex align-items-center gap-2 mt-1">
                    <span id="liveGradeBadge" class="badge bg-success px-3 py-1.5 fw-bold fs-6">Grade A+</span>
                    <span class="fs-4 fw-bold text-dark" id="liveScoreNum">100.0</span>
                    <span class="text-muted small">/ 100 poin</span>
                </div>
            </div>

            <div class="col-12 col-md-5">
                <div class="d-flex flex-wrap gap-1">
                    <span id="liveBadgeFlood" class="cert-guarantee-badge success"><i class="fas fa-check"></i> Bebas Banjir</span>
                    <span id="liveBadgeAccident" class="cert-guarantee-badge success"><i class="fas fa-check"></i> Bebas Tabrakan</span>
                    <span id="liveBadgeOdo" class="cert-guarantee-badge success"><i class="fas fa-check"></i> Odometer Asli</span>
                </div>
            </div>

            <div class="col-12 col-md-3 text-md-end">
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" id="btnMarkAllGood" title="Set semua checklist menjadi BAIK" style="border-radius:8px;font-size:0.78rem;">
                    <i class="fas fa-check-double me-1"></i>Semua Baik
                </button>
                <button type="submit" class="btn-submit-inspection py-1.5 px-3">
                    <i class="fas fa-save"></i>
                    <span>Simpan Hasil</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Section 1: Informasi Unit & Inspektor -->
    <div class="form-card-clean card-has-dropdown">
        <div class="card-header-clean">
            <div class="header-title-group">
                <div class="header-icon-badge">
                    <i class="fas fa-car"></i>
                </div>
                <h5>1. Informasi Kendaraan & Inspektor</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label for="vehicle_id" class="form-label fw-semibold small text-secondary">Unit Mobil <span class="text-danger">*</span></label>
                    <select id="vehicle_id" name="vehicle_id" required>
                        <option value="">Pilih Mobil yang Diinspeksi...</option>
                        <?php foreach ($vehiclesList as $v): ?>
                        <option value="<?php echo $v['id']; ?>" <?php echo ($preselectedVehicleId === (int)$v['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ') - ' . ($v['license_plate'] ?: 'No Plat') . ' [' . strtoupper($v['status']) . ']'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-3">
                    <label for="inspector_name" class="form-label fw-semibold small text-secondary">Nama Inspektor <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="inspector_name" name="inspector_name" 
                           value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Inspektor Showroom'); ?>" required style="height:42px;border-radius:10px;">
                </div>

                <div class="col-12 col-md-3">
                    <label for="inspection_date" class="form-label fw-semibold small text-secondary">Tanggal Inspeksi <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="inspection_date" name="inspection_date" 
                           value="<?php echo date('Y-m-d'); ?>" required style="height:42px;border-radius:10px;">
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Jaminan Keselamatan Kritis (4 Pilar) -->
    <div class="form-card-clean">
        <div class="card-header-clean">
            <div class="header-title-group">
                <div class="header-icon-badge">
                    <i class="fas fa-shield-alt"></i>
                </div>
                <h5>2. Jaminan Keselamatan Kritis (4 Pilar Sertifikasi)</h5>
            </div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-6 col-md-3">
                    <div class="major-cert-card checked-safe" id="card_flood">
                        <i class="fas fa-water major-cert-icon"></i>
                        <div class="fw-bold text-dark small mb-2">Bebas Banjir</div>
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input major-toggle" type="checkbox" id="flood_free" name="flood_free" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="flood_free">Lolos Uji</label>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="major-cert-card checked-safe" id="card_accident">
                        <i class="fas fa-car-crash major-cert-icon"></i>
                        <div class="fw-bold text-dark small mb-2">Bebas Tabrakan Berat</div>
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input major-toggle" type="checkbox" id="accident_free" name="accident_free" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="accident_free">Lolos Uji</label>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="major-cert-card checked-safe" id="card_odo">
                        <i class="fas fa-tachometer-alt major-cert-icon"></i>
                        <div class="fw-bold text-dark small mb-2">Odometer Asli</div>
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input major-toggle" type="checkbox" id="odometer_tampered" name="odometer_tampered" value="0">
                            <label class="form-check-label small fw-semibold" for="odometer_tampered">Tidak Diputar</label>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="major-cert-card checked-safe" id="card_fire">
                        <i class="fas fa-fire-extinguisher major-cert-icon"></i>
                        <div class="fw-bold text-dark small mb-2">Bebas Bekas Terbakar</div>
                        <div class="form-check form-switch d-inline-block">
                            <input class="form-check-input major-toggle" type="checkbox" id="fire_free" name="fire_free" value="1" checked>
                            <label class="form-check-label small fw-semibold" for="fire_free">Lolos Uji</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Lembar Checklist 100+ Titik Pemeriksaan -->
    <div class="form-card-clean">
        <div class="card-header-clean">
            <div class="header-title-group">
                <div class="header-icon-badge">
                    <i class="fas fa-list-check"></i>
                </div>
                <h5>3. Lembar Checklist 100+ Titik Pemeriksaan</h5>
            </div>
            <span class="badge bg-light text-dark border px-2 py-1">115 Titik Pengujian</span>
        </div>
        <div class="card-body">
            <div class="accordion" id="inspectionAccordion">
                <?php 
                $catIndex = 0;
                foreach ($checklistMaster as $catKey => $catData): 
                    $catIndex++;
                    $collapseId = 'collapseCat_' . $catKey;
                    $itemCount = count($catData['items']);
                ?>
                <div class="accordion-item border rounded-3 mb-3 overflow-hidden shadow-none">
                    <h2 class="accordion-header" id="heading_<?php echo $catKey; ?>">
                        <button class="accordion-button <?php echo $catIndex > 1 ? 'collapsed' : ''; ?> py-3 px-4 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo $collapseId; ?>" aria-expanded="<?php echo $catIndex === 1 ? 'true' : 'false'; ?>">
                            <div class="d-flex align-items-center justify-content-between w-100 me-3">
                                <div class="d-flex align-items-center gap-2">
                                    <span><?php echo htmlspecialchars($catData['name']); ?></span>
                                    <span class="badge bg-light text-dark border ms-2"><?php echo $itemCount; ?> Titik</span>
                                </div>
                                <div class="small text-muted fw-semibold">
                                    Bobot: <?php echo ($catData['weight'] * 100); ?>%
                                </div>
                            </div>
                        </button>
                    </h2>
                    <div id="<?php echo $collapseId; ?>" class="accordion-collapse collapse <?php echo $catIndex === 1 ? 'show' : ''; ?>" data-bs-parent="#inspectionAccordion">
                        <div class="accordion-body p-3 p-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <small class="text-muted">Tentukan kondisi titik uji:</small>
                                <button type="button" class="btn btn-sm btn-outline-success btn-cat-good" data-category="<?php echo $catKey; ?>" style="border-radius:8px;font-size:0.75rem;">
                                    <i class="fas fa-check me-1"></i>Set Semua Baik di Modul Ini
                                </button>
                            </div>

                            <div class="row g-2">
                                <?php foreach ($catData['items'] as $itemKey => $itemLabel): ?>
                                <div class="col-12 col-lg-6">
                                    <div class="check-item-row" data-cat="<?php echo $catKey; ?>">
                                        <div class="check-item-title flex-grow-1">
                                            <?php echo htmlspecialchars($itemLabel); ?>
                                        </div>
                                        <div class="btn-group btn-group-sm check-btn-group" role="group">
                                            <input type="radio" class="btn-check check-input" name="check_<?php echo $itemKey; ?>" id="chk_<?php echo $itemKey; ?>_good" value="good" checked autocomplete="off" data-cat="<?php echo $catKey; ?>">
                                            <label class="btn btn-outline-success" for="chk_<?php echo $itemKey; ?>_good">Baik</label>

                                            <input type="radio" class="btn-check check-input" name="check_<?php echo $itemKey; ?>" id="chk_<?php echo $itemKey; ?>_minor" value="minor" autocomplete="off" data-cat="<?php echo $catKey; ?>">
                                            <label class="btn btn-outline-warning" for="chk_<?php echo $itemKey; ?>_minor">Minor</label>

                                            <input type="radio" class="btn-check check-input" name="check_<?php echo $itemKey; ?>" id="chk_<?php echo $itemKey; ?>_poor" value="poor" autocomplete="off" data-cat="<?php echo $catKey; ?>">
                                            <label class="btn btn-outline-danger" for="chk_<?php echo $itemKey; ?>_poor">Perlu Servis</label>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Section 4: Catatan & Rekomendasi -->
    <div class="form-card-clean">
        <div class="card-header-clean">
            <div class="header-title-group">
                <div class="header-icon-badge">
                    <i class="fas fa-comment-dots"></i>
                </div>
                <h5>4. Catatan Kesimpulan & Rekomendasi</h5>
            </div>
        </div>
        <div class="card-body">
            <div>
                <label for="summary_notes" class="form-label fw-semibold small text-secondary">Catatan Rangkuman untuk Calon Pembeli</label>
                <textarea class="form-control" id="summary_notes" name="summary_notes" rows="4" 
                          placeholder="Kondisi fisik dan mesin terawat, seluruh instrumen kelistrikan berfungsi normal, rekomendasi servis berkala..." style="border-radius:10px;"></textarea>
            </div>
        </div>
    </div>

    <!-- Bottom Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-5 pb-4">
        <a href="index.php" class="btn-header-action">
            <i class="fas fa-times"></i> Batal
        </a>
        <button type="submit" class="btn-submit-inspection">
            <i class="fas fa-certificate"></i>
            <span>Terbitkan Sertifikat & Simpan Hasil</span>
        </button>
    </div>
</form>

<!-- Choices.js JS -->
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>

<script>
const categoryWeights = {
    'frame': 0.30,
    'engine': 0.30,
    'exterior': 0.15,
    'interior': 0.15,
    'road_test': 0.10
};

const scoreMap = {
    'good': 100.0,
    'minor': 70.0,
    'poor': 30.0
};

function updateLiveScore() {
    let categorySums = {};
    let categoryCounts = {};

    for (let cat in categoryWeights) {
        categorySums[cat] = 0;
        categoryCounts[cat] = 0;
    }

    document.querySelectorAll('.check-input:checked').forEach(radio => {
        const cat = radio.getAttribute('data-cat');
        const val = radio.value;
        if (categoryWeights[cat] !== undefined) {
            categorySums[cat] += scoreMap[val] || 100;
            categoryCounts[cat] += 1;
        }
    });

    let overallScore = 0.0;
    for (let cat in categoryWeights) {
        const count = categoryCounts[cat] || 1;
        const avg = categorySums[cat] / count;
        overallScore += (avg * categoryWeights[cat]);
    }

    const floodFree = document.getElementById('flood_free').checked;
    const accidentFree = document.getElementById('accident_free').checked;
    const odoTampered = document.getElementById('odometer_tampered').checked;
    const fireFree = document.getElementById('fire_free').checked;

    let grade = 'B';
    let badgeClass = 'bg-primary';

    if (!floodFree || !accidentFree || !fireFree) {
        grade = 'D';
        badgeClass = 'bg-danger';
    } else if (overallScore >= 95.0 && !odoTampered) {
        grade = 'A+';
        badgeClass = 'bg-success';
    } else if (overallScore >= 88.0) {
        grade = 'A';
        badgeClass = 'bg-info text-white';
    } else if (overallScore >= 75.0) {
        grade = 'B';
        badgeClass = 'bg-primary';
    } else if (overallScore >= 60.0) {
        grade = 'C';
        badgeClass = 'bg-warning text-dark';
    } else {
        grade = 'D';
        badgeClass = 'bg-danger';
    }

    document.getElementById('liveScoreNum').textContent = overallScore.toFixed(1);
    const gradeBadge = document.getElementById('liveGradeBadge');
    gradeBadge.className = 'badge ' + badgeClass + ' px-3 py-1.5 fw-bold fs-6';
    gradeBadge.innerHTML = 'Grade ' + grade;

    // Update safety cards & pills
    const badgeFlood = document.getElementById('liveBadgeFlood');
    const cardFlood = document.getElementById('card_flood');
    if (floodFree) {
        badgeFlood.className = 'cert-guarantee-badge success';
        badgeFlood.innerHTML = '<i class="fas fa-check"></i> Bebas Banjir';
        cardFlood.className = 'major-cert-card checked-safe';
    } else {
        badgeFlood.className = 'cert-guarantee-badge danger';
        badgeFlood.innerHTML = '<i class="fas fa-times"></i> Bekas Banjir';
        cardFlood.className = 'major-cert-card checked-warn';
    }

    const badgeAccident = document.getElementById('liveBadgeAccident');
    const cardAccident = document.getElementById('card_accident');
    if (accidentFree) {
        badgeAccident.className = 'cert-guarantee-badge success';
        badgeAccident.innerHTML = '<i class="fas fa-check"></i> Bebas Tabrakan';
        cardAccident.className = 'major-cert-card checked-safe';
    } else {
        badgeAccident.className = 'cert-guarantee-badge danger';
        badgeAccident.innerHTML = '<i class="fas fa-times"></i> Pernah Tabrak';
        cardAccident.className = 'major-cert-card checked-warn';
    }

    const badgeOdo = document.getElementById('liveBadgeOdo');
    const cardOdo = document.getElementById('card_odo');
    if (!odoTampered) {
        badgeOdo.className = 'cert-guarantee-badge success';
        badgeOdo.innerHTML = '<i class="fas fa-check"></i> Odometer Asli';
        cardOdo.className = 'major-cert-card checked-safe';
    } else {
        badgeOdo.className = 'cert-guarantee-badge danger';
        badgeOdo.innerHTML = '<i class="fas fa-times"></i> Odo Diputar';
        cardOdo.className = 'major-cert-card checked-warn';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const vSelect = document.getElementById('vehicle_id');
    if (vSelect && typeof Choices !== 'undefined') {
        new Choices(vSelect, {
            searchEnabled: true,
            searchPlaceholderValue: 'Ketik plat nomor atau merek...',
            noResultsText: 'Unit mobil tidak ditemukan',
            itemSelectText: '',
            shouldSort: false,
            position: 'bottom'
        });
    }

    document.querySelectorAll('.check-input').forEach(input => {
        input.addEventListener('change', updateLiveScore);
    });

    document.querySelectorAll('.major-toggle').forEach(input => {
        input.addEventListener('change', updateLiveScore);
    });

    // Mark category good
    document.querySelectorAll('.btn-cat-good').forEach(btn => {
        btn.addEventListener('click', function() {
            const cat = this.getAttribute('data-category');
            document.querySelectorAll('.check-input[data-cat="' + cat + '"][value="good"]').forEach(radio => {
                radio.checked = true;
            });
            updateLiveScore();
        });
    });

    // Mark all good
    const btnAllGood = document.getElementById('btnMarkAllGood');
    if (btnAllGood) {
        btnAllGood.addEventListener('click', function() {
            document.querySelectorAll('.check-input[value="good"]').forEach(radio => {
                radio.checked = true;
            });
            updateLiveScore();
        });
    }

    updateLiveScore();
});
</script>

<?php include '../includes/footer.php'; ?>
