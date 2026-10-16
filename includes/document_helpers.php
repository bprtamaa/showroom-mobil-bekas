<?php
// includes/document_helpers.php

/**
 * Convert numeric money to Indonesian words.
 */
function penyebut($nilai) {
    $nilai = abs($nilai);
    $huruf = array("", "satu", "dua", "tiga", "empat", "lima", "enam", "tujuh", "delapan", "sembilan", "sepuluh", "sebelas");
    $temp = "";
    if ($nilai < 12) {
        $temp = " ". $huruf[$nilai];
    } else if ($nilai < 20) {
        $temp = penyebut($nilai - 10). " belas";
    } else if ($nilai < 100) {
        $temp = penyebut($nilai / 10)." puluh". penyebut($nilai % 10);
    } else if ($nilai < 200) {
        $temp = " seratus" . penyebut($nilai - 100);
    } else if ($nilai < 1000) {
        $temp = penyebut($nilai / 100) . " ratus" . penyebut($nilai % 100);
    } else if ($nilai < 2000) {
        $temp = " seribu" . penyebut($nilai - 1000);
    } else if ($nilai < 1000000) {
        $temp = penyebut($nilai / 1000) . " ribu" . penyebut($nilai % 1000);
    } else if ($nilai < 1000000000) {
        $temp = penyebut($nilai / 1000000) . " juta" . penyebut($nilai % 1000000);
    } else if ($nilai < 1000000000000) {
        $temp = penyebut($nilai / 1000000000) . " milyar" . penyebut(fmod($nilai, 1000000000));
    } else if ($nilai < 1000000000000000) {
        $temp = penyebut($nilai / 1000000000000) . " trilyun" . penyebut(fmod($nilai, 1000000000000));
    }     
    return $temp;
}

function terbilang($nilai) {
    if ($nilai < 0) {
        $hasil = "minus ". trim(penyebut($nilai));
    } else {
        $hasil = trim(penyebut($nilai));
    }     
    return ucwords($hasil) . " Rupiah";
}

/**
 * Returns Showroom Profile Information for official letterheads.
 */
function getShowroomCompanyProfile() {
    return [
        'name' => 'SHOWROOM MOBIL BEKAS BERKUALITAS',
        'subname' => 'Pusat Jual Beli Mobil Bekas Terpercaya & Bergaransi',
        'address' => 'Jl. Raya Otomotif No. 88, Kavling Showroom Mandiri',
        'city' => 'Jakarta Selatan, DKI Jakarta 12340',
        'phone' => '(021) 7890-1234 / 0812-3456-7890',
        'email' => 'sales@showroom-mobilbekas.com',
        'website' => 'www.showroom-mobilbekas.com'
    ];
}

/**
 * Render Official Header / Kop Surat for Legal Documents.
 */
function renderOfficialDocumentHeader($docTitle = 'DOKUMEN RESMI', $docCode = '') {
    $info = getShowroomCompanyProfile();
    $html = '
    <div class="doc-header-official border-bottom pb-3 mb-4">
        <div class="row align-items-center">
            <div class="col-8">
                <h4 class="fw-bold mb-0 text-dark" style="letter-spacing: -0.02em;">' . htmlspecialchars($info['name']) . '</h4>
                <div class="text-muted small fw-semibold">' . htmlspecialchars($info['subname']) . '</div>
                <div class="text-muted small mt-1" style="font-size: 0.775rem;">' . htmlspecialchars($info['address'] . ', ' . $info['city']) . '</div>
                <div class="text-muted small" style="font-size: 0.775rem;">Telp: ' . htmlspecialchars($info['phone']) . ' | Email: ' . htmlspecialchars($info['email']) . '</div>
            </div>
            <div class="col-4 text-end">
                <div class="doc-badge-type text-uppercase fw-bold fs-6 text-primary">' . htmlspecialchars($docTitle) . '</div>
                ' . ($docCode ? '<div class="text-muted small font-monospace mt-1">No: ' . htmlspecialchars($docCode) . '</div>' : '') . '
            </div>
        </div>
    </div>
    ';
    return $html;
}

/**
 * Document & BPKB Status Configs
 */
function getBpkbStatusConfig($status) {
    $map = [
        'in_showroom' => [
            'label' => 'Di Showroom (Tersedia)',
            'badge_class' => 'bg-success',
            'icon' => 'fa-vault'
        ],
        'in_leasing' => [
            'label' => 'Di Leasing (Agunan)',
            'badge_class' => 'bg-info text-white',
            'icon' => 'fa-building-columns'
        ],
        'mutasi_process' => [
            'label' => 'Proses Mutasi / BBN',
            'badge_class' => 'bg-warning text-dark',
            'icon' => 'fa-arrows-rotate'
        ],
        'cabut_berkas' => [
            'label' => 'Proses Cabut Berkas',
            'badge_class' => 'bg-secondary',
            'icon' => 'fa-file-export'
        ],
        'released_to_buyer' => [
            'label' => 'Diserahkan ke Pembeli',
            'badge_class' => 'bg-primary',
            'icon' => 'fa-handshake-simple'
        ]
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'badge_class' => 'bg-light text-dark', 'icon' => 'fa-file'];
}

function getLeasingAppStatusConfig($status) {
    $map = [
        'submitted' => [
            'label' => 'Pengajuan Baru',
            'badge_class' => 'bg-secondary',
            'icon' => 'fa-file-lines'
        ],
        'survey_in_progress' => [
            'label' => 'Survey Dokumen / Lokasi',
            'badge_class' => 'bg-warning text-dark',
            'icon' => 'fa-magnifying-glass-location'
        ],
        'approved' => [
            'label' => 'Disetujui (Approved)',
            'badge_class' => 'bg-info text-white',
            'icon' => 'fa-circle-check'
        ],
        'po_disbursed' => [
            'label' => 'PO Cair / Selesai',
            'badge_class' => 'bg-success',
            'icon' => 'fa-money-bill-transfer'
        ],
        'rejected' => [
            'label' => 'Ditolak (Rejected)',
            'badge_class' => 'bg-danger',
            'icon' => 'fa-circle-xmark'
        ],
        'cancelled' => [
            'label' => 'Dibatalkan',
            'badge_class' => 'bg-dark',
            'icon' => 'fa-ban'
        ]
    ];
    return $map[$status] ?? ['label' => ucfirst($status), 'badge_class' => 'bg-light text-dark', 'icon' => 'fa-file'];
}
