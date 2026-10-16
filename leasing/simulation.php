<?php
// leasing/simulation.php - Simulasi Kredit Mobil Showroom
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

// Fetch available vehicles for dropdown
$availableVehicles = [];
try {
    $stmtVehicles = $db->query("
        SELECT v.id, v.selling_price, v.year, v.color, v.mileage, v.transmission, v.fuel_type, v.license_plate, v.images, v.status,
               m.name AS model_name,
               b.name AS brand_name,
               ct.name AS car_type_name
        FROM vehicles v
        LEFT JOIN models m ON v.model_id = m.id
        LEFT JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        ORDER BY CASE WHEN v.status = 'available' THEN 1 ELSE 2 END, v.created_at DESC
    ");
    $availableVehicles = $stmtVehicles->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $availableVehicles = [];
}

// Fetch active leasing partners
$leasingPartners = [];
try {
    $stmtLeasing = $db->query("
        SELECT id, name, code, contact_person, phone 
        FROM leasing 
        WHERE status = 'active' 
        ORDER BY name ASC
    ");
    $leasingPartners = $stmtLeasing->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $leasingPartners = [];
}

// Check if preselected vehicle via GET parameter
$selectedVehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$preselectedVehicle = null;

if ($selectedVehicleId > 0) {
    foreach ($availableVehicles as $veh) {
        if ((int)$veh['id'] === $selectedVehicleId) {
            $preselectedVehicle = $veh;
            break;
        }
    }
}
?>

<style>
    /* ===== Simulation Page Specific Styles (Matching Brands Design) ===== */
    .sim-page-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 24px;
        flex-wrap: wrap;
    }
    .sim-page-header h1 {
        font-size: 1.5rem;
        font-weight: 800;
        color: #1e293b;
        margin: 0 0 2px 0;
        letter-spacing: -0.025em;
    }
    .sim-page-header .breadcrumb {
        margin: 0;
        font-size: 0.8rem;
    }
    .sim-header-actions {
        display: flex;
        align-items: center;
        gap: 8px;
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
        font-size: 0.8rem;
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
    .sim-card {
        border-radius: 14px;
        border: 1px solid #e2e8f0;
        background: #fff;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: box-shadow 0.2s;
        overflow: hidden;
    }
    .sim-card:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.06);
    }
    .sim-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px 20px;
        border-bottom: 1px solid #f1f5f9;
        background: transparent;
    }
    .sim-card-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .sim-card-body {
        padding: 20px;
    }

    /* Segmented Mode Control */
    .segmented-control {
        display: inline-flex;
        background: #f1f5f9;
        padding: 3px;
        border-radius: 10px;
        border: 1px solid #e2e8f0;
    }
    .segmented-control button {
        padding: 5px 12px;
        font-size: 0.78rem;
        font-weight: 600;
        border: none;
        background: transparent;
        color: #64748b;
        border-radius: 7px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .segmented-control button.active {
        background: #fff;
        color: #1e293b;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }

    /* Form Elements */
    .sim-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 6px;
        display: block;
    }
    .sim-input, .sim-select {
        width: 100%;
        padding: 9px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 500;
        color: #334155;
        background: #f8fafc;
        transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }
    .sim-input:focus, .sim-select:focus {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
        outline: none;
    }
    .sim-input-group {
        display: flex;
        border-radius: 10px;
        overflow: hidden;
        border: 1px solid #e2e8f0;
        background: #f8fafc;
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    .sim-input-group:focus-within {
        border-color: #94a3b8;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(45,52,54,0.08);
    }
    .sim-input-prefix {
        padding: 9px 14px;
        font-size: 0.85rem;
        font-weight: 700;
        color: #64748b;
        background: #f1f5f9;
        border-right: 1px solid #e2e8f0;
        display: flex;
        align-items: center;
    }
    .sim-input-group input {
        border: none !important;
        background: transparent !important;
        box-shadow: none !important;
        padding: 9px 14px;
        width: 100%;
        font-size: 0.85rem;
        font-weight: 600;
        color: #1e293b;
        outline: none;
    }

    /* Selected Vehicle Box */
    .vehicle-preview-box {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 16px;
    }
    .vehicle-preview-thumb {
        width: 70px;
        height: 52px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        object-fit: cover;
        flex-shrink: 0;
    }
    .vehicle-preview-placeholder {
        width: 70px;
        height: 52px;
        border-radius: 8px;
        border: 1px solid #e2e8f0;
        background: #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #94a3b8;
        flex-shrink: 0;
    }

    /* DP Chips */
    .dp-chip-container {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }
    .dp-chip {
        padding: 4px 10px;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #475569;
        font-size: 0.75rem;
        font-weight: 600;
        border-radius: 7px;
        cursor: pointer;
        transition: all 0.15s;
    }
    .dp-chip:hover {
        background: #f1f5f9;
        border-color: #cbd5e1;
    }
    .dp-chip.active {
        background: #2d3436;
        color: #fff;
        border-color: #2d3436;
    }

    /* Tenor Grid */
    .tenor-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 8px;
    }
    .tenor-btn {
        padding: 10px 8px;
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 10px;
        text-align: center;
        cursor: pointer;
        transition: all 0.15s;
    }
    .tenor-btn:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        transform: translateY(-1px);
    }
    .tenor-btn.active {
        background: #2d3436;
        border-color: #2d3436;
        color: #fff;
        box-shadow: 0 2px 6px rgba(45,52,54,0.15);
    }
    .tenor-btn .tenor-year {
        display: block;
        font-weight: 700;
        font-size: 0.85rem;
        line-height: 1.2;
    }
    .tenor-btn .tenor-month {
        font-size: 0.72rem;
        color: #94a3b8;
    }
    .tenor-btn.active .tenor-month {
        color: rgba(255,255,255,0.8);
    }

    /* Payment Scheme Card */
    .scheme-option {
        padding: 12px 14px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #f8fafc;
        cursor: pointer;
        transition: all 0.15s;
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    .scheme-option:hover {
        background: #f1f5f9;
    }
    .scheme-option input[type="radio"] {
        margin-top: 2px;
        cursor: pointer;
    }

    /* Result Highlight Box */
    .result-hero {
        text-align: center;
        padding: 20px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 20px;
    }
    .result-hero-title {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #64748b;
        margin-bottom: 4px;
    }
    .result-hero-val {
        font-size: 1.8rem;
        font-weight: 800;
        color: #166534;
        letter-spacing: -0.02em;
        margin-bottom: 2px;
    }
    .result-hero-sub {
        font-size: 0.78rem;
        color: #94a3b8;
    }

    /* Result Stat Grid */
    .result-stats-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
        margin-bottom: 20px;
    }
    .result-stat-box {
        padding: 10px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        background: #fff;
    }
    .result-stat-label {
        font-size: 0.7rem;
        color: #94a3b8;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .result-stat-value {
        font-size: 0.88rem;
        font-weight: 700;
        color: #1e293b;
    }

    /* Breakdown List */
    .breakdown-table {
        width: 100%;
        margin-bottom: 20px;
    }
    .breakdown-table tr td {
        padding: 8px 0;
        font-size: 0.82rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .breakdown-table tr:last-child td {
        border-bottom: none;
    }
    .breakdown-total-row td {
        padding: 12px 14px !important;
        background: #f8fafc;
        border-radius: 8px;
        font-weight: 700;
    }

    /* Matrix Table (Matching Brands table styling) */
    .sim-table {
        width: 100%;
        border-collapse: collapse;
    }
    .sim-table thead th {
        padding: 10px 16px;
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #f1f5f9;
        background: #fafbfc;
        white-space: nowrap;
    }
    .sim-table tbody td {
        padding: 12px 16px;
        font-size: 0.85rem;
        color: #334155;
        border-bottom: 1px solid #f8fafc;
        vertical-align: middle;
    }
    .sim-table tbody tr {
        transition: background 0.15s;
    }
    .sim-table tbody tr:hover {
        background: #f8fafc;
    }
    .sim-table tbody tr.row-selected {
        background: #eff6ff;
        font-weight: 600;
    }

    /* Button Primary CTA */
    .btn-cta-save {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 10px 20px;
        background: #2d3436;
        color: #fff;
        font-weight: 600;
        font-size: 0.82rem;
        border-radius: 10px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
    }
    .btn-cta-save:hover {
        background: #1e272e;
        color: #fff;
        transform: translateY(-1px);
    }

    /* Accordion Modern */
    .sim-accordion .accordion-item {
        border-radius: 10px !important;
        border: 1px solid #e2e8f0;
        overflow: hidden;
        margin-bottom: 12px;
    }
    .sim-accordion .accordion-button {
        padding: 12px 16px;
        font-size: 0.85rem;
        font-weight: 600;
        color: #334155;
        background: #f8fafc;
    }
    .sim-accordion .accordion-button:not(.collapsed) {
        background: #f1f5f9;
        color: #1e293b;
        box-shadow: none;
    }

    /* Dark Mode Overrides */
    [data-theme="dark"] .sim-page-header h1 { color: #f1f5f9; }
    [data-theme="dark"] .btn-header-action { background: #1e293b; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .btn-header-action:hover { background: #334155; color: #e2e8f0; }
    [data-theme="dark"] .btn-header-primary { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .btn-header-primary:hover { background: #2563eb; }
    [data-theme="dark"] .sim-card { background: #1e293b; border-color: #334155; }
    [data-theme="dark"] .sim-card-header { border-bottom-color: #334155; }
    [data-theme="dark"] .sim-card-title { color: #f1f5f9; }
    [data-theme="dark"] .segmented-control { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .segmented-control button { color: #94a3b8; }
    [data-theme="dark"] .segmented-control button.active { background: #1e293b; color: #f1f5f9; }
    [data-theme="dark"] .sim-label { color: #cbd5e1; }
    [data-theme="dark"] .sim-input, [data-theme="dark"] .sim-select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .sim-input-group { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .sim-input-prefix { background: #162032; border-right-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .sim-input-group input { color: #e2e8f0; }
    [data-theme="dark"] .vehicle-preview-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .vehicle-preview-thumb { border-color: #334155; background: #1e293b; }
    [data-theme="dark"] .dp-chip { background: #0f172a; border-color: #334155; color: #94a3b8; }
    [data-theme="dark"] .dp-chip:hover { background: #1e293b; color: #e2e8f0; }
    [data-theme="dark"] .dp-chip.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .tenor-btn { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .tenor-btn:hover { background: #1e293b; }
    [data-theme="dark"] .tenor-btn.active { background: #3b82f6; border-color: #3b82f6; color: #fff; }
    [data-theme="dark"] .scheme-option { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    [data-theme="dark"] .scheme-option:hover { background: #162032; }
    [data-theme="dark"] .result-hero { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .result-hero-val { color: #4ade80; }
    [data-theme="dark"] .result-stat-box { background: #0f172a; border-color: #334155; }
    [data-theme="dark"] .result-stat-value { color: #f1f5f9; }
    [data-theme="dark"] .breakdown-table tr td { border-bottom-color: #334155; color: #cbd5e1; }
    [data-theme="dark"] .breakdown-total-row td { background: #0f172a; }
    [data-theme="dark"] .sim-table thead th { background: #1a2332; color: #94a3b8; border-bottom-color: #334155; }
    [data-theme="dark"] .sim-table tbody td { color: #e2e8f0; border-bottom-color: #1e293b; }
    [data-theme="dark"] .sim-table tbody tr:hover { background: rgba(59,130,246,0.06); }
    [data-theme="dark"] .sim-table tbody tr.row-selected { background: rgba(59,130,246,0.15); }
    [data-theme="dark"] .btn-cta-save { background: #3b82f6; }
    [data-theme="dark"] .btn-cta-save:hover { background: #2563eb; }
    [data-theme="dark"] .sim-accordion .accordion-item { border-color: #334155; }
    [data-theme="dark"] .sim-accordion .accordion-button { background: #0f172a; color: #e2e8f0; }
    [data-theme="dark"] .sim-accordion .accordion-button:not(.collapsed) { background: #162032; color: #f1f5f9; }
    [data-theme="dark"] .sim-accordion .accordion-collapse { background: #1e293b; }

    /* Print Styles */
    @media print {
        body { background: #fff !important; font-size: 10pt; }
        .sidebar, .topbar, .sim-page-header, .btn, .segmented-control, .dp-chip-container, #accAdvanced, .sim-accordion, .position-fixed { display: none !important; }
        .main-content { margin-left: 0 !important; padding: 0 !important; }
        .sim-card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; margin-bottom: 1rem !important; }
        .d-print-block { display: block !important; }
    }

    /* Responsive */
    @media (max-width: 576px) {
        .sim-page-header { flex-direction: column; gap: 10px; }
        .sim-header-actions { width: 100%; justify-content: flex-start; }
        .tenor-grid { grid-template-columns: repeat(2, 1fr); }
        .result-stats-grid { grid-template-columns: 1fr; }
    }
</style>

<!-- Page Header -->
<div class="sim-page-header">
    <div>
        <h1>Simulasi Kredit Mobil</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>dashboard/" class="text-primary text-decoration-underline">Beranda</a></li>
                <li class="breadcrumb-item"><a href="<?php echo BASE_URL; ?>leasing/" class="text-primary text-decoration-underline">Leasing</a></li>
                <li class="breadcrumb-item active" aria-current="page">Simulasi Kredit</li>
            </ol>
        </nav>
    </div>
    <div class="sim-header-actions">
        <button type="button" class="btn-header-action" onclick="resetCalculator()">
            <i class="fas fa-redo-alt"></i> Reset
        </button>
    </div>
</div>

<!-- Main Row: Form & Results -->
<div class="row">
    <!-- Left Column: Input Form -->
    <div class="col-lg-6">
        <div class="sim-card card">
            <div class="sim-card-header">
                <h5 class="sim-card-title">
                    <i class="fas fa-calculator text-primary"></i> Parameter Kredit
                </h5>
                <div class="segmented-control">
                    <button type="button" class="active" id="btnModeStok" onclick="switchMode('stok')">
                        <i class="fas fa-car me-1"></i> Dari Stok
                    </button>
                    <button type="button" id="btnModeManual" onclick="switchMode('manual')">
                        <i class="fas fa-edit me-1"></i> Input Bebas
                    </button>
                </div>
            </div>
            <div class="sim-card-body">
                <form id="creditSimForm" onsubmit="return false;">
                    <!-- Mode 1: Pilih dari Stok Mobil -->
                    <div id="sectionVehicleSelect" class="mb-3">
                        <label for="vehicleSelect" class="sim-label">Pilih Mobil dari Stok</label>
                        <select id="vehicleSelect" class="sim-select" onchange="onVehicleSelected(this)">
                            <option value="">-- Pilih Unit Mobil --</option>
                            <?php foreach ($availableVehicles as $veh): 
                                $imgArr = !empty($veh['images']) ? json_decode($veh['images'], true) : [];
                                $thumbImg = !empty($imgArr) && is_array($imgArr) ? $imgArr[0] : '';
                                $isSel = ($preselectedVehicle && (int)$preselectedVehicle['id'] === (int)$veh['id']) ? 'selected' : '';
                                $statusBadge = $veh['status'] === 'available' ? 'Tersedia' : 'Terjual/Booked';
                            ?>
                            <option value="<?php echo $veh['id']; ?>" 
                                    data-price="<?php echo (float)$veh['selling_price']; ?>"
                                    data-title="<?php echo htmlspecialchars($veh['brand_name'] . ' ' . $veh['model_name']); ?>"
                                    data-year="<?php echo htmlspecialchars($veh['year'] ?? '-'); ?>"
                                    data-plate="<?php echo htmlspecialchars($veh['license_plate'] ?? '-'); ?>"
                                    data-trans="<?php echo htmlspecialchars(ucfirst($veh['transmission'] ?? '-')); ?>"
                                    data-fuel="<?php echo htmlspecialchars(ucfirst($veh['fuel_type'] ?? '-')); ?>"
                                    data-image="<?php echo htmlspecialchars($thumbImg); ?>"
                                    data-status="<?php echo $veh['status']; ?>"
                                    <?php echo $isSel; ?>>
                                <?php echo htmlspecialchars($veh['brand_name'] . ' ' . $veh['model_name'] . ' (' . ($veh['year'] ?? '-') . ') - ' . formatCurrency($veh['selling_price']) . ' [' . $statusBadge . ']'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Selected Vehicle Preview Box -->
                    <div id="vehiclePreviewCard" class="vehicle-preview-box d-none">
                        <img id="prevImg" src="#" alt="Thumbnail" class="vehicle-preview-thumb d-none" onerror="this.classList.add('d-none'); document.getElementById('prevImgPlaceholder').classList.remove('d-none');">
                        <div id="prevImgPlaceholder" class="vehicle-preview-placeholder">
                            <i class="fas fa-car fa-lg"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div id="prevTitle" class="fw-bold text-dark text-truncate" style="font-size: 0.9rem;">-</div>
                            <div class="d-flex flex-wrap gap-2 text-muted mt-1" style="font-size: 0.75rem;">
                                <span id="prevYear"><i class="fas fa-calendar-alt me-1"></i>-</span>
                                <span id="prevPlate"><i class="fas fa-id-card me-1"></i>-</span>
                                <span id="prevTrans"><i class="fas fa-cog me-1"></i>-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Mode 2: Input Manual -->
                    <div id="sectionManualCar" class="mb-3 d-none">
                        <label for="customCarName" class="sim-label">Nama / Model Kendaraan</label>
                        <input type="text" id="customCarName" class="sim-input" placeholder="Contoh: Toyota Innova Zenix 2.0 V 2023" oninput="calculateCredit()">
                    </div>

                    <!-- Harga Kendaraan (OTR) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label for="carPriceInput" class="sim-label mb-0">Harga Kendaraan (OTR)</label>
                            <span class="text-primary fw-bold" style="font-size: 0.8rem;" id="formattedPriceBadge">Rp 200.000.000</span>
                        </div>
                        <div class="sim-input-group">
                            <span class="sim-input-prefix">Rp</span>
                            <input type="text" id="carPriceInput" value="200.000.000" placeholder="Masukkan harga mobil" oninput="onPriceInput(this)">
                        </div>
                    </div>

                    <!-- Mitra Leasing -->
                    <div class="mb-3">
                        <label for="leasingSelect" class="sim-label">Mitra Leasing</label>
                        <select id="leasingSelect" class="sim-select" onchange="onLeasingChange(this)">
                            <option value="general" data-rate="7.5" data-admin="2500000">Umum / Standar Leasing (Bunga ~7.5% Flat/Th)</option>
                            <?php if (!empty($leasingPartners)): ?>
                                <?php foreach ($leasingPartners as $lp): ?>
                                    <option value="<?php echo $lp['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($lp['name']); ?>"
                                            data-code="<?php echo htmlspecialchars($lp['code']); ?>"
                                            data-rate="7.8" 
                                            data-admin="3000000">
                                        <?php echo htmlspecialchars($lp['name'] . ' (' . $lp['code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Uang Muka (DP) -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="sim-label mb-0">Uang Muka (DP)</label>
                            <span class="badge bg-primary px-2 py-1" id="dpPercentBadge">20%</span>
                        </div>
                        <div class="sim-input-group mb-2">
                            <span class="sim-input-prefix">Rp</span>
                            <input type="text" id="dpAmountInput" value="40.000.000" placeholder="Nominal Uang Muka" oninput="onDpAmountInput(this)">
                        </div>
                        <div class="px-1 mb-2">
                            <input type="range" class="form-range" id="dpSlider" min="10" max="80" step="1" value="20" oninput="onDpSliderInput(this.value)">
                            <div class="d-flex justify-content-between text-muted" style="font-size: 0.7rem;">
                                <span>10%</span>
                                <span>20%</span>
                                <span>30%</span>
                                <span>50%</span>
                                <span>80%</span>
                            </div>
                        </div>
                        <div class="dp-chip-container">
                            <button type="button" class="dp-chip" onclick="setDpPercent(15)">15%</button>
                            <button type="button" class="dp-chip active" onclick="setDpPercent(20)">20%</button>
                            <button type="button" class="dp-chip" onclick="setDpPercent(25)">25%</button>
                            <button type="button" class="dp-chip" onclick="setDpPercent(30)">30%</button>
                            <button type="button" class="dp-chip" onclick="setDpPercent(40)">40%</button>
                            <button type="button" class="dp-chip" onclick="setDpPercent(50)">50%</button>
                        </div>
                    </div>

                    <!-- Tenor Selector -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="sim-label mb-0">Jangka Waktu (Tenor)</label>
                            <span class="text-muted" style="font-size: 0.78rem;" id="tenorLabelInfo">36 Bulan (3 Tahun)</span>
                        </div>
                        <div class="tenor-grid">
                            <div class="tenor-btn" data-months="12" onclick="selectTenor(12)">
                                <span class="tenor-year">1 Tahun</span>
                                <span class="tenor-month">12 Bln</span>
                            </div>
                            <div class="tenor-btn" data-months="24" onclick="selectTenor(24)">
                                <span class="tenor-year">2 Tahun</span>
                                <span class="tenor-month">24 Bln</span>
                            </div>
                            <div class="tenor-btn active" data-months="36" onclick="selectTenor(36)">
                                <span class="tenor-year">3 Tahun</span>
                                <span class="tenor-month">36 Bln</span>
                            </div>
                            <div class="tenor-btn" data-months="48" onclick="selectTenor(48)">
                                <span class="tenor-year">4 Tahun</span>
                                <span class="tenor-month">48 Bln</span>
                            </div>
                            <div class="tenor-btn" data-months="60" onclick="selectTenor(60)">
                                <span class="tenor-year">5 Tahun</span>
                                <span class="tenor-month">60 Bln</span>
                            </div>
                            <div class="tenor-btn" data-months="72" onclick="selectTenor(72)">
                                <span class="tenor-year">6 Tahun</span>
                                <span class="tenor-month">72 Bln</span>
                            </div>
                        </div>
                    </div>

                    <!-- Skema Pembayaran: ADDM vs ADDB -->
                    <div class="mb-3">
                        <label class="sim-label mb-2">Skema Pembayaran</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="scheme-option" for="schemeADDM">
                                    <input type="radio" name="paymentScheme" id="schemeADDM" value="ADDM" checked onchange="calculateCredit()">
                                    <div>
                                        <div class="fw-bold" style="font-size: 0.85rem;">ADDM</div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Angsuran ke-1 masuk TDP</div>
                                    </div>
                                </label>
                            </div>
                            <div class="col-6">
                                <label class="scheme-option" for="schemeADDB">
                                    <input type="radio" name="paymentScheme" id="schemeADDB" value="ADDB" onchange="calculateCredit()">
                                    <div>
                                        <div class="fw-bold" style="font-size: 0.85rem;">ADDB</div>
                                        <div class="text-muted" style="font-size: 0.72rem;">Angsuran ke-1 bulan depan</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Opsi Lanjutan: Suku Bunga, Asuransi, Admin -->
                    <div class="accordion sim-accordion" id="accAdvanced">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingAdv">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdv" aria-expanded="false" aria-controls="collapseAdv">
                                    <i class="fas fa-sliders-h text-muted me-2"></i>Pengaturan Bunga & Asuransi Tambahan
                                </button>
                            </h2>
                            <div id="collapseAdv" class="accordion-collapse collapse p-3" aria-labelledby="headingAdv" data-bs-parent="#accAdvanced">
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label for="interestRateInput" class="sim-label">Suku Bunga (% Flat / Thn)</label>
                                        <div class="sim-input-group">
                                            <input type="number" step="0.1" min="0" max="30" id="interestRateInput" value="7.5" oninput="calculateCredit()">
                                            <span class="sim-input-prefix border-0 border-start">%</span>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="insuranceTypeSelect" class="sim-label">Jenis Asuransi</label>
                                        <select id="insuranceTypeSelect" class="sim-select" onchange="calculateCredit()">
                                            <option value="all_risk" data-rate="2.5">All Risk (~2.5%/thn)</option>
                                            <option value="tlo" data-rate="0.9" selected>TLO (~0.9%/thn)</option>
                                            <option value="combination" data-rate="1.7">Kombinasi (All Risk + TLO)</option>
                                            <option value="none" data-rate="0">Tanpa Asuransi</option>
                                        </select>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="adminFeeInput" class="sim-label">Biaya Administrasi</label>
                                        <div class="sim-input-group">
                                            <span class="sim-input-prefix">Rp</span>
                                            <input type="text" id="adminFeeInput" value="2.500.000" oninput="onFormattedInput(this, calculateCredit)">
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label for="provisionFeeInput" class="sim-label">Biaya Provisi (% Plafon)</label>
                                        <div class="sim-input-group">
                                            <input type="number" step="0.1" min="0" max="10" id="provisionFeeInput" value="0.5" oninput="calculateCredit()">
                                            <span class="sim-input-prefix border-0 border-start">%</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Results & TDP Breakdown -->
    <div class="col-lg-6">
        <!-- Result Summary Card -->
        <div class="sim-card card">
            <div class="sim-card-header">
                <h5 class="sim-card-title">
                    <i class="fas fa-money-bill-wave text-success"></i> Hasil Estimasi Kredit
                </h5>
                <span class="status-badge status-active" id="resSchemeBadge">Skema ADDM</span>
            </div>
            <div class="sim-card-body">
                <div class="result-hero">
                    <div class="result-hero-title">Estimasi Angsuran Bulanan</div>
                    <div class="result-hero-val" id="resMonthlyInstallment">Rp 0</div>
                    <div class="result-hero-sub" id="resInstallmentNotes">Flat per bulan selama 36 bulan</div>
                </div>

                <div class="result-stats-grid">
                    <div class="result-stat-box">
                        <div class="result-stat-label">Plafon Pinjaman</div>
                        <div class="result-stat-value" id="resLoanPrincipal">Rp 0</div>
                    </div>
                    <div class="result-stat-box">
                        <div class="result-stat-label">Total Bunga</div>
                        <div class="result-stat-value" id="resTotalInterest">Rp 0</div>
                    </div>
                    <div class="result-stat-box">
                        <div class="result-stat-label">Suku Bunga</div>
                        <div class="result-stat-value" id="resInterestRate">7.5% Flat/Th</div>
                    </div>
                    <div class="result-stat-box">
                        <div class="result-stat-label">Total Bayar Pertama (TDP)</div>
                        <div class="result-stat-value text-primary" id="resTdpAmount">Rp 0</div>
                    </div>
                </div>

                <!-- TDP Breakdown List -->
                <div class="fw-bold text-dark mb-2 pb-1 border-bottom" style="font-size: 0.85rem;">
                    <i class="fas fa-receipt text-primary me-1"></i> Rincian Total Uang Muka (TDP)
                </div>
                <table class="breakdown-table">
                    <tbody>
                        <tr>
                            <td>
                                <span class="fw-semibold">Uang Muka Murni (DP)</span>
                                <div class="text-muted" style="font-size: 0.72rem;" id="detDpNote">20% dari OTR</div>
                            </td>
                            <td class="text-end fw-bold" id="detDpAmount">Rp 0</td>
                        </tr>
                        <tr id="rowFirstInstallment">
                            <td>
                                <span class="fw-semibold">Angsuran Pertama (ADDM)</span>
                                <div class="text-muted" style="font-size: 0.72rem;">Dibayar langsung di awal</div>
                            </td>
                            <td class="text-end fw-bold" id="detFirstInstallment">Rp 0</td>
                        </tr>
                        <tr>
                            <td>
                                <span class="fw-semibold">Biaya Asuransi</span>
                                <div class="text-muted" style="font-size: 0.72rem;" id="detInsuranceNote">TLO</div>
                            </td>
                            <td class="text-end fw-bold" id="detInsuranceCost">Rp 0</td>
                        </tr>
                        <tr>
                            <td>
                                <span class="fw-semibold">Biaya Administrasi & Polis</span>
                                <div class="text-muted" style="font-size: 0.72rem;">Pengurusan dokumen pembiayaan</div>
                            </td>
                            <td class="text-end fw-bold" id="detAdminCost">Rp 0</td>
                        </tr>
                        <tr id="rowProvisionCost">
                            <td>
                                <span class="fw-semibold">Biaya Provisi</span>
                                <div class="text-muted" style="font-size: 0.72rem;" id="detProvisionNote">0.5% plafon</div>
                            </td>
                            <td class="text-end fw-bold" id="detProvisionCost">Rp 0</td>
                        </tr>
                        <tr class="breakdown-total-row">
                            <td class="text-primary">TOTAL BAYAR PERTAMA (TDP)</td>
                            <td class="text-end text-primary" id="detTotalTdp">Rp 0</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Action Buttons -->
                <div class="d-flex flex-wrap gap-2 pt-2 border-top">
                    <button type="button" class="btn-header-action" onclick="copySimulationText()">
                        <i class="fas fa-copy"></i> Salin Rincian
                    </button>
                    <a id="btnCreateTransaction" href="<?php echo BASE_URL; ?>transactions/create.php" class="btn-cta-save ms-auto">
                        <i class="fas fa-shopping-cart"></i> Ajukan Transaksi
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Comparison Matrix Table -->
<div class="sim-card card mb-4">
    <div class="sim-card-header">
        <h5 class="sim-card-title">
            <i class="fas fa-table text-primary"></i> Matriks Perbandingan Tenor (1 s/d 6 Tahun)
        </h5>
        <span class="text-muted" style="font-size: 0.78rem;">Simulasi cicilan berbagai pilihan tenor</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="sim-table">
                <thead>
                    <tr>
                        <th class="ps-3">Tenor</th>
                        <th>Bunga / Th</th>
                        <th>Angsuran / Bulan</th>
                        <th>Estimasi TDP</th>
                        <th>Total Pembayaran</th>
                        <th style="text-align:right">Aksi</th>
                    </tr>
                </thead>
                <tbody id="matrixTableBody">
                    <!-- Populated by JavaScript -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Monthly Amortization Table Accordion -->
<div class="accordion sim-accordion mb-4" id="accAmortization">
    <div class="accordion-item">
        <h2 class="accordion-header" id="headingAmort">
            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAmort" aria-expanded="false" aria-controls="collapseAmort">
                <i class="fas fa-calendar-alt text-primary me-2"></i>Tabel Jadwal Angsuran & Pelunasan Bulanan (Amortisasi)
            </button>
        </h2>
        <div id="collapseAmort" class="accordion-collapse collapse" aria-labelledby="headingAmort" data-bs-parent="#accAmortization">
            <div class="p-4 border-top">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted" style="font-size: 0.8rem;">Rincian pokok hutang, porsi angsuran pokok, dan bunga setiap bulan hingga lunas.</span>
                    <span class="badge bg-secondary">Total: <span id="amortTotalMonths">36</span> Bulan</span>
                </div>
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="sim-table">
                        <thead class="sticky-top">
                            <tr>
                                <th class="text-center" style="width: 80px;">Bulan</th>
                                <th>Angsuran</th>
                                <th>Porsi Pokok</th>
                                <th>Porsi Bunga</th>
                                <th style="text-align:right">Sisa Pokok Hutang</th>
                            </tr>
                        </thead>
                        <tbody id="amortTableBody">
                            <!-- Populated dynamically by JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>



<!-- Toast Feedback -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1100">
    <div id="feedbackToast" class="toast align-items-center text-bg-dark border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body d-flex align-items-center gap-2">
                <i class="fas fa-check-circle text-success fs-5"></i>
                <span id="toastMessage">Rincian berhasil disalin ke clipboard!</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>

<!-- Simulation Logic Scripts -->
<script>
// State Management
const simState = {
    mode: 'stok', // 'stok' | 'manual'
    vehicleId: 0,
    vehicleTitle: 'Kendaraan Showroom',
    carPrice: 200000000,
    dpPercent: 20,
    dpAmount: 40000000,
    tenorMonths: 36,
    paymentScheme: 'ADDM', // 'ADDM' | 'ADDB'
    interestRate: 7.5, // % flat per year
    insuranceType: 'tlo', // 'all_risk' | 'tlo' | 'combination' | 'none'
    insuranceRate: 0.9, // % per year
    adminFee: 2500000,
    provisionRate: 0.5, // % of loan
    policyFee: 50000,
    leasingId: '',
    leasingName: 'Umum / Standar Leasing'
};

// Formatter Helpers
function formatRupiah(amount) {
    return 'Rp ' + Math.round(amount).toLocaleString('id-ID');
}

function parseRupiah(str) {
    if (typeof str !== 'string') return Number(str) || 0;
    const clean = str.replace(/[^0-9]/g, '');
    return Number(clean) || 0;
}

// Mode Switcher: Stok vs Manual
function switchMode(mode) {
    simState.mode = mode;
    const btnStok = document.getElementById('btnModeStok');
    const btnManual = document.getElementById('btnModeManual');
    const secStok = document.getElementById('sectionVehicleSelect');
    const secManual = document.getElementById('sectionManualCar');
    const previewCard = document.getElementById('vehiclePreviewCard');

    if (mode === 'stok') {
        btnStok.classList.add('active');
        btnManual.classList.remove('active');
        secStok.classList.remove('d-none');
        secManual.classList.add('d-none');
        
        const vehSelect = document.getElementById('vehicleSelect');
        if (vehSelect.value) {
            onVehicleSelected(vehSelect);
        }
    } else {
        btnManual.classList.add('active');
        btnStok.classList.remove('active');
        secManual.classList.remove('d-none');
        secStok.classList.add('d-none');
        previewCard.classList.add('d-none');
        
        simState.vehicleId = 0;
        const customName = document.getElementById('customCarName').value.trim();
        simState.vehicleTitle = customName || 'Mobil Kustom';
        updateCreateTransactionLink();
    }
}

// Vehicle Selection Handler
function onVehicleSelected(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    const previewCard = document.getElementById('vehiclePreviewCard');
    const prevImg = document.getElementById('prevImg');
    const prevPlaceholder = document.getElementById('prevImgPlaceholder');

    if (!selectEl.value || !opt) {
        previewCard.classList.add('d-none');
        simState.vehicleId = 0;
        simState.vehicleTitle = 'Kendaraan Showroom';
        updateCreateTransactionLink();
        return;
    }

    simState.vehicleId = Number(selectEl.value);
    const price = Number(opt.getAttribute('data-price')) || 0;
    const title = opt.getAttribute('data-title') || 'Mobil Showroom';
    const year = opt.getAttribute('data-year') || '-';
    const plate = opt.getAttribute('data-plate') || '-';
    const trans = opt.getAttribute('data-trans') || '-';
    const image = opt.getAttribute('data-image') || '';

    simState.vehicleTitle = title + (year !== '-' ? ' (' + year + ')' : '');

    // Update Preview Card
    document.getElementById('prevTitle').textContent = title;
    document.getElementById('prevYear').innerHTML = '<i class="fas fa-calendar-alt me-1"></i>' + year;
    document.getElementById('prevPlate').innerHTML = '<i class="fas fa-id-card me-1"></i>' + plate;
    document.getElementById('prevTrans').innerHTML = '<i class="fas fa-cog me-1"></i>' + trans;

    if (image) {
        prevImg.src = '<?php echo BASE_URL; ?>uploads/vehicles/' + encodeURIComponent(image);
        prevImg.classList.remove('d-none');
        prevPlaceholder.classList.add('d-none');
    } else {
        prevImg.classList.add('d-none');
        prevPlaceholder.classList.remove('d-none');
    }

    previewCard.classList.remove('d-none');

    // Update Price Input
    if (price > 0) {
        document.getElementById('carPriceInput').value = price.toLocaleString('id-ID');
        document.getElementById('formattedPriceBadge').textContent = formatRupiah(price);
        simState.carPrice = price;
        syncDpFromPercent();
    }

    updateCreateTransactionLink();
    calculateCredit();
}

// Leasing Change Handler
function onLeasingChange(selectEl) {
    const opt = selectEl.options[selectEl.selectedIndex];
    if (opt) {
        const rate = parseFloat(opt.getAttribute('data-rate')) || 7.5;
        const admin = parseFloat(opt.getAttribute('data-admin')) || 2500000;
        const name = opt.getAttribute('data-name') || selectEl.value;

        simState.leasingId = selectEl.value;
        simState.leasingName = name;
        simState.interestRate = rate;
        simState.adminFee = admin;

        document.getElementById('interestRateInput').value = rate;
        document.getElementById('adminFeeInput').value = admin.toLocaleString('id-ID');
        
        updateCreateTransactionLink();
        calculateCredit();
    }
}

// Price Input Handler
function onPriceInput(input) {
    const raw = parseRupiah(input.value);
    input.value = raw ? raw.toLocaleString('id-ID') : '';
    document.getElementById('formattedPriceBadge').textContent = formatRupiah(raw);
    simState.carPrice = raw;

    syncDpFromPercent();
    calculateCredit();
}

// DP Percentage Setters & Handlers
function setDpPercent(percent) {
    simState.dpPercent = percent;
    document.getElementById('dpSlider').value = percent;
    document.getElementById('dpPercentBadge').textContent = percent + '%';

    // Update Active Chip
    document.querySelectorAll('.dp-chip').forEach(btn => {
        if (parseInt(btn.textContent) === percent) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    syncDpFromPercent();
    calculateCredit();
}

function onDpSliderInput(val) {
    const p = parseInt(val) || 20;
    simState.dpPercent = p;
    document.getElementById('dpPercentBadge').textContent = p + '%';

    // Sync Chips
    document.querySelectorAll('.dp-chip').forEach(btn => {
        if (parseInt(btn.textContent) === p) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });

    syncDpFromPercent();
    calculateCredit();
}

function syncDpFromPercent() {
    const dp = Math.round(simState.carPrice * (simState.dpPercent / 100));
    simState.dpAmount = dp;
    document.getElementById('dpAmountInput').value = dp.toLocaleString('id-ID');
}

function onDpAmountInput(input) {
    const raw = parseRupiah(input.value);
    input.value = raw ? raw.toLocaleString('id-ID') : '';
    simState.dpAmount = raw;

    if (simState.carPrice > 0) {
        let p = (raw / simState.carPrice) * 100;
        p = Math.min(Math.max(p, 5), 90);
        simState.dpPercent = Math.round(p * 10) / 10;
        document.getElementById('dpSlider').value = Math.round(p);
        document.getElementById('dpPercentBadge').textContent = simState.dpPercent + '%';

        // Clear active preset chips if non-standard
        document.querySelectorAll('.dp-chip').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.textContent) === Math.round(p));
        });
    }

    calculateCredit();
}

// Tenor Selection Handler
function selectTenor(months) {
    simState.tenorMonths = months;
    const years = months / 12;

    // Update UI Buttons
    document.querySelectorAll('.tenor-btn').forEach(el => {
        el.classList.toggle('active', parseInt(el.getAttribute('data-months')) === months);
    });

    document.getElementById('tenorLabelInfo').textContent = `${months} Bulan (${years} Tahun)`;
    calculateCredit();
}

// Formatted input helper
function onFormattedInput(input, callback) {
    const raw = parseRupiah(input.value);
    input.value = raw ? raw.toLocaleString('id-ID') : '';
    if (typeof callback === 'function') callback();
}

// Core Financial Calculator
function calculateCredit() {
    const carPrice = simState.carPrice || 0;
    const dpAmount = simState.dpAmount || 0;
    const tenorMonths = simState.tenorMonths || 36;
    const tenorYears = tenorMonths / 12;

    // Suku Bunga
    const interestRateInput = parseFloat(document.getElementById('interestRateInput').value) || 7.5;
    simState.interestRate = interestRateInput;

    // Skema
    const schemeRadio = document.querySelector('input[name="paymentScheme"]:checked');
    const paymentScheme = schemeRadio ? schemeRadio.value : 'ADDM';
    simState.paymentScheme = paymentScheme;

    // Asuransi
    const insSelect = document.getElementById('insuranceTypeSelect');
    const insRate = insSelect ? parseFloat(insSelect.options[insSelect.selectedIndex].getAttribute('data-rate')) || 0 : 0;
    simState.insuranceType = insSelect ? insSelect.value : 'tlo';
    simState.insuranceRate = insRate;

    // Biaya Admin & Provisi
    const adminFee = parseRupiah(document.getElementById('adminFeeInput').value) || 0;
    const provisionRate = parseFloat(document.getElementById('provisionFeeInput').value) || 0;
    simState.adminFee = adminFee;
    simState.provisionRate = provisionRate;

    // 1. Pokok Hutang (Plafon Kredit)
    const loanPrincipal = Math.max(0, carPrice - dpAmount);

    // 2. Total Bunga Flat
    const totalInterest = loanPrincipal * (interestRateInput / 100) * tenorYears;

    // 3. Total Hutang
    const totalDebt = loanPrincipal + totalInterest;

    // 4. Angsuran Bulanan
    const monthlyInstallment = tenorMonths > 0 ? Math.round(totalDebt / tenorMonths) : 0;

    // 5. Biaya Asuransi
    const insuranceCost = Math.round(carPrice * (insRate / 100) * tenorYears) + simState.policyFee;

    // 6. Biaya Provisi
    const provisionCost = Math.round(loanPrincipal * (provisionRate / 100));

    // 7. Total Pembayaran Pertama (TDP)
    let totalTdp = dpAmount + insuranceCost + adminFee + provisionCost;
    if (paymentScheme === 'ADDM') {
        totalTdp += monthlyInstallment;
    }

    // 8. Total Pengembalian Keseluruhan
    const remainingTenor = paymentScheme === 'ADDM' ? (tenorMonths - 1) : tenorMonths;
    const grandTotalPayment = totalTdp + (remainingTenor * monthlyInstallment);

    // Update Result UI
    document.getElementById('resSchemeBadge').textContent = 'Skema ' + paymentScheme;
    document.getElementById('resMonthlyInstallment').textContent = formatRupiah(monthlyInstallment);
    document.getElementById('resInstallmentNotes').textContent = `Flat ${formatRupiah(monthlyInstallment)} / bulan selama ${tenorMonths} bulan`;
    document.getElementById('resTdpAmount').textContent = formatRupiah(totalTdp);
    document.getElementById('resLoanPrincipal').textContent = formatRupiah(loanPrincipal);
    document.getElementById('resTotalInterest').textContent = formatRupiah(totalInterest);
    document.getElementById('resInterestRate').textContent = interestRateInput + '% Flat/Th';

    // Update Breakdown List UI
    document.getElementById('detDpNote').textContent = simState.dpPercent + '% dari OTR (' + formatRupiah(carPrice) + ')';
    document.getElementById('detDpAmount').textContent = formatRupiah(dpAmount);

    const rowFirstIns = document.getElementById('rowFirstInstallment');
    if (paymentScheme === 'ADDM') {
        rowFirstIns.classList.remove('d-none');
        document.getElementById('detFirstInstallment').textContent = formatRupiah(monthlyInstallment);
    } else {
        rowFirstIns.classList.add('d-none');
    }

    const insNames = {
        all_risk: 'All Risk Komprehensif',
        tlo: 'Total Loss Only (TLO)',
        combination: 'Kombinasi',
        none: 'Tanpa Asuransi'
    };
    document.getElementById('detInsuranceNote').textContent = `${insNames[simState.insuranceType] || 'Asuransi'} (${tenorYears} Tahun)`;
    document.getElementById('detInsuranceCost').textContent = formatRupiah(insuranceCost);
    document.getElementById('detAdminCost').textContent = formatRupiah(adminFee);

    const rowProv = document.getElementById('rowProvisionCost');
    if (provisionRate > 0) {
        rowProv.classList.remove('d-none');
        document.getElementById('detProvisionNote').textContent = `${provisionRate}% dari Plafon (${formatRupiah(loanPrincipal)})`;
        document.getElementById('detProvisionCost').textContent = formatRupiah(provisionCost);
    } else {
        rowProv.classList.add('d-none');
    }

    document.getElementById('detTotalTdp').textContent = formatRupiah(totalTdp);

    // Render Matrix & Amortization
    renderTenorMatrix(carPrice, dpAmount, interestRateInput, paymentScheme, insRate, adminFee, provisionRate);
    renderAmortization(loanPrincipal, monthlyInstallment, totalInterest, tenorMonths);
}

// Render Multi-Tenor Comparison Matrix
function renderTenorMatrix(carPrice, dpAmount, baseInterest, paymentScheme, insRate, adminFee, provisionRate) {
    const tbody = document.getElementById('matrixTableBody');
    if (!tbody) return;

    const tenors = [
        { months: 12, years: 1, rate: Math.max(5.5, baseInterest - 1.0) },
        { months: 24, years: 2, rate: Math.max(6.0, baseInterest - 0.5) },
        { months: 36, years: 3, rate: baseInterest },
        { months: 48, years: 4, rate: baseInterest + 0.5 },
        { months: 60, years: 5, rate: baseInterest + 1.0 },
        { months: 72, years: 6, rate: baseInterest + 1.5 }
    ];

    const loanPrincipal = Math.max(0, carPrice - dpAmount);
    let rowsHtml = '';

    tenors.forEach(t => {
        const tInterest = loanPrincipal * (t.rate / 100) * t.years;
        const tDebt = loanPrincipal + tInterest;
        const tInstallment = Math.round(tDebt / t.months);
        const tInsurance = Math.round(carPrice * (insRate / 100) * t.years) + simState.policyFee;
        const tProvision = Math.round(loanPrincipal * (provisionRate / 100));

        let tTdp = dpAmount + tInsurance + adminFee + tProvision;
        if (paymentScheme === 'ADDM') tTdp += tInstallment;

        const remTenor = paymentScheme === 'ADDM' ? (t.months - 1) : t.months;
        const tGrandTotal = tTdp + (remTenor * tInstallment);
        const isCurrent = t.months === simState.tenorMonths;

        rowsHtml += `
            <tr class="${isCurrent ? 'row-selected' : ''}">
                <td class="ps-3">
                    <span class="status-badge ${isCurrent ? 'status-active' : 'bg-light text-muted border'} me-1">${t.years} Tahun</span>
                    <span class="text-muted" style="font-size:0.75rem">(${t.months} Bln)</span>
                </td>
                <td>${t.rate.toFixed(1)}%</td>
                <td class="text-primary fw-bold">${formatRupiah(tInstallment)}</td>
                <td class="fw-semibold text-dark">${formatRupiah(tTdp)}</td>
                <td class="text-muted" style="font-size:0.78rem">${formatRupiah(tGrandTotal)}</td>
                <td style="text-align:right">
                    ${isCurrent ? 
                        '<span class="status-badge status-active"><i class="fas fa-check me-1"></i>Aktif</span>' : 
                        `<button type="button" class="btn-header-action py-1 px-2" style="font-size:0.75rem" onclick="selectTenor(${t.months})">Pilih Tenor</button>`
                    }
                </td>
            </tr>
        `;
    });

    tbody.innerHTML = rowsHtml;
}

// Render Monthly Amortization Table
function renderAmortization(loanPrincipal, monthlyInstallment, totalInterest, tenorMonths) {
    const tbody = document.getElementById('amortTableBody');
    const totalMonthsEl = document.getElementById('amortTotalMonths');
    if (!tbody) return;

    if (totalMonthsEl) totalMonthsEl.textContent = tenorMonths;

    const monthlyPrincipal = tenorMonths > 0 ? (loanPrincipal / tenorMonths) : 0;
    const monthlyInterest = tenorMonths > 0 ? (totalInterest / tenorMonths) : 0;

    let remainingPrincipal = loanPrincipal;
    let rowsHtml = '';

    for (let m = 1; m <= tenorMonths; m++) {
        remainingPrincipal = Math.max(0, remainingPrincipal - monthlyPrincipal);

        rowsHtml += `
            <tr>
                <td class="text-center text-muted fw-semibold">${m}</td>
                <td class="fw-semibold text-dark">${formatRupiah(monthlyInstallment)}</td>
                <td class="text-success">${formatRupiah(monthlyPrincipal)}</td>
                <td class="text-secondary">${formatRupiah(monthlyInterest)}</td>
                <td class="text-end text-muted font-monospace">${formatRupiah(remainingPrincipal)}</td>
            </tr>
        `;
    }

    tbody.innerHTML = rowsHtml;
}

// Update Link to Create Transaction
function updateCreateTransactionLink() {
    const btn = document.getElementById('btnCreateTransaction');
    if (!btn) return;

    let targetUrl = '<?php echo BASE_URL; ?>transactions/create.php?method=installment';
    if (simState.vehicleId > 0) {
        targetUrl += '&vehicle_id=' + simState.vehicleId;
    }
    if (simState.leasingId && simState.leasingId !== 'general') {
        targetUrl += '&leasing_id=' + encodeURIComponent(simState.leasingId);
    }
    btn.href = targetUrl;
}

function copySimulationText() {
    const vehicle = simState.vehicleTitle || 'Unit Showroom';
    const otr = formatRupiah(simState.carPrice);
    const dp = formatRupiah(simState.dpAmount) + ` (${simState.dpPercent}%)`;
    const tenor = `${simState.tenorMonths} Bulan (${simState.tenorMonths / 12} Tahun)`;
    const installment = document.getElementById('resMonthlyInstallment').textContent;
    const tdp = document.getElementById('resTdpAmount').textContent;
    const scheme = simState.paymentScheme;
    const leasing = simState.leasingName || 'Mitra Leasing';

    const plainText = 
`--- SIMULASI KREDIT MOBIL ---
Showroom: <?php echo htmlspecialchars($siteName ?? 'Showroom Mobil'); ?>

Unit Mobil      : ${vehicle}
Harga OTR       : ${otr}
Mitra Leasing   : ${leasing}
Skema           : ${scheme}

Uang Muka (DP)  : ${dp}
Tenor           : ${tenor}
Angsuran/Bln    : ${installment}
Estimasi TDP    : ${tdp}
(Termasuk DP, Asuransi, Admin & Legalitas)

Hubungi tim marketing kami untuk konsultasi dan pengajuan.`;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(plainText).then(() => {
            showToast('Rincian simulasi kredit berhasil disalin!');
        }).catch(() => {
            fallbackCopy(plainText);
        });
    } else {
        fallbackCopy(plainText);
    }
}

function fallbackCopy(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-9999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        document.execCommand('copy');
        showToast('Rincian simulasi kredit berhasil disalin!');
    } catch (err) {
        alert('Gagal menyalin otomatis. Silakan salin manual.');
    }
    document.body.removeChild(textArea);
}

function showToast(message) {
    const toastEl = document.getElementById('feedbackToast');
    const msgEl = document.getElementById('toastMessage');
    if (toastEl && msgEl) {
        msgEl.textContent = message;
        const toast = new bootstrap.Toast(toastEl, { delay: 3000 });
        toast.show();
    }
}

function resetCalculator() {
    document.getElementById('creditSimForm').reset();
    document.getElementById('vehicleSelect').value = '';
    switchMode('stok');
    document.getElementById('carPriceInput').value = '200.000.000';
    document.getElementById('formattedPriceBadge').textContent = 'Rp 200.000.000';
    simState.carPrice = 200000000;
    setDpPercent(20);
    selectTenor(36);
    document.getElementById('schemeADDM').checked = true;
    document.getElementById('interestRateInput').value = '7.5';
    document.getElementById('adminFeeInput').value = '2.500.000';
    document.getElementById('provisionFeeInput').value = '0.5';
    document.getElementById('insuranceTypeSelect').value = 'tlo';
    calculateCredit();
}

// Initial Launch
document.addEventListener('DOMContentLoaded', function() {
    const vehSelect = document.getElementById('vehicleSelect');
    if (vehSelect && vehSelect.value) {
        onVehicleSelected(vehSelect);
    } else {
        calculateCredit();
    }
});
</script>

<?php include '../includes/footer.php'; ?>
