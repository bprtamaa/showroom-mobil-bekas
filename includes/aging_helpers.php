<?php
// includes/aging_helpers.php - Helper untuk Analisis Umur Stok (Aging Inventory) & Kas Operasional

/**
 * Hitung umur stok kendaraan dalam hitungan hari.
 *
 * @param string|null $purchaseDate Tanggal beli unit (format: YYYY-MM-DD)
 * @param string|null $soldDate Tanggal terjual jika sudah sold
 * @param string|null $createdAt Fallback timestamp
 * @return array Detail aging (days, category, badge_html, holding_cost, discount_pct)
 */
function calculateVehicleAging($purchaseDate, $soldDate = null, $createdAt = null, $purchasePrice = 0) {
    $startDateStr = !empty($purchaseDate) ? $purchaseDate : (!empty($createdAt) ? date('Y-m-d', strtotime($createdAt)) : date('Y-m-d'));
    $startDate = new DateTime($startDateStr);
    
    $endDate = !empty($soldDate) ? new DateTime($soldDate) : new DateTime('today');
    
    $diff = $startDate->diff($endDate);
    $days = max(0, (int)$diff->format('%r%a'));
    
    // Klasifikasi kategori
    // Fresh: < 30 hari
    // Warning: 31 - 60 hari
    // Critical (Stok Macet): > 60 hari
    if ($days <= 30) {
        $category = 'fresh';
        $categoryLabel = 'Fresh';
        $badgeClass = 'badge bg-success-subtle text-success border border-success-subtle';
        $iconClass = 'fas fa-check-circle me-1';
        $suggestedDiscountPct = 0;
        $statusText = 'Stok Baru / Perputaran Baik';
    } elseif ($days <= 60) {
        $category = 'warning';
        $categoryLabel = 'Perhatian';
        $badgeClass = 'badge bg-warning-subtle text-warning-emphasis border border-warning-subtle';
        $iconClass = 'fas fa-exclamation-triangle me-1';
        $suggestedDiscountPct = 2.0; // Diskon 2%
        $statusText = 'Mendekati Batas Ideal (>30 Hari)';
    } else {
        $category = 'critical';
        $categoryLabel = 'Stok Macet';
        $badgeClass = 'badge bg-danger-subtle text-danger border border-danger-subtle';
        $iconClass = 'fas fa-clock me-1';
        $suggestedDiscountPct = 5.0; // Diskon 5% untuk quick cashflow
        $statusText = 'Mengendap Terlalu Lama (>60 Hari)';
    }

    // Estimasi holding cost (Biaya modal mengendap)
    // Asumsi standar industri: 1% per bulan (0.033% per hari) dari harga beli untuk bunga modal & depresiasi
    $holdingCostPerDayRate = 0.00033;
    $estimatedHoldingCost = $purchasePrice > 0 ? round($purchasePrice * $holdingCostPerDayRate * $days) : 0;
    
    return [
        'days' => $days,
        'category' => $category,
        'category_label' => $categoryLabel,
        'badge_class' => $badgeClass,
        'icon_class' => $iconClass,
        'status_text' => $statusText,
        'suggested_discount_pct' => $suggestedDiscountPct,
        'holding_cost' => $estimatedHoldingCost
    ];
}

/**
 * Render badge HTML ringkas untuk tabel daftar kendaraan.
 */
function renderAgingBadge($purchaseDate, $soldDate = null, $createdAt = null) {
    $aging = calculateVehicleAging($purchaseDate, $soldDate, $createdAt);
    return sprintf(
        '<span class="%s" title="%s (%d hari)"><i class="%s"></i>%d Hari</span>',
        htmlspecialchars($aging['badge_class']),
        htmlspecialchars($aging['category_label']),
        $aging['days'],
        htmlspecialchars($aging['icon_class']),
        $aging['days']
    );
}

/**
 * Daftar kategori kas operasional beserta label dan icon yang konsisten.
 */
function getExpenseCategories() {
    return [
        'listrik_air' => [
            'label' => 'Listrik & Air (Utilitas)',
            'icon' => 'fas fa-bolt',
            'color' => 'warning',
            'badge' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle'
        ],
        'bensin_operasional' => [
            'label' => 'BBM & Operasional',
            'icon' => 'fas fa-gas-pump',
            'color' => 'primary',
            'badge' => 'bg-primary-subtle text-primary border border-primary-subtle'
        ],
        'cuci_mobil' => [
            'label' => 'Cuci Mobil & Perawatan Display',
            'icon' => 'fas fa-soap',
            'color' => 'info',
            'badge' => 'bg-info-subtle text-info-emphasis border border-info-subtle'
        ],
        'konsumsi' => [
            'label' => 'Konsumsi & Pantry Showroom',
            'icon' => 'fas fa-coffee',
            'color' => 'secondary',
            'badge' => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
        ],
        'sewa_tempat' => [
            'label' => 'Sewa Lahan & Gedung',
            'icon' => 'fas fa-building',
            'color' => 'danger',
            'badge' => 'bg-danger-subtle text-danger border border-danger-subtle'
        ],
        'gaji_karyawan' => [
            'label' => 'Gaji & Honor Karyawan',
            'icon' => 'fas fa-users',
            'color' => 'success',
            'badge' => 'bg-success-subtle text-success border border-success-subtle'
        ],
        'marketing_iklan' => [
            'label' => 'Pemasaran & Iklan Marketplace',
            'icon' => 'fas fa-bullhorn',
            'color' => 'primary',
            'badge' => 'bg-primary-subtle text-primary border border-primary-subtle'
        ],
        'perawatan_gedung' => [
            'label' => 'Perbaikan & Fasilitas Showroom',
            'icon' => 'fas fa-tools',
            'color' => 'secondary',
            'badge' => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
        ],
        'pajak_legalitas' => [
            'label' => 'Pajak & Perizinan Usaha',
            'icon' => 'fas fa-file-contract',
            'color' => 'dark',
            'badge' => 'bg-dark-subtle text-dark border border-dark-subtle'
        ],
        'lainnya' => [
            'label' => 'Lain-lain',
            'icon' => 'fas fa-ellipsis-h',
            'color' => 'secondary',
            'badge' => 'bg-secondary-subtle text-secondary border border-secondary-subtle'
        ]
    ];
}

/**
 * Render badge kategori kas operasional.
 */
function renderExpenseCategoryBadge($categoryKey) {
    $cats = getExpenseCategories();
    $cat = $cats[$categoryKey] ?? [
        'label' => ucfirst(str_replace('_', ' ', $categoryKey)),
        'icon' => 'fas fa-receipt',
        'badge' => 'bg-light text-dark border'
    ];
    return sprintf(
        '<span class="badge %s"><i class="%s me-1"></i>%s</span>',
        htmlspecialchars($cat['badge']),
        htmlspecialchars($cat['icon']),
        htmlspecialchars($cat['label'])
    );
}
