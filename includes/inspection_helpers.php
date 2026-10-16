<?php
// includes/inspection_helpers.php

/**
 * Returns the master list of 100+ Inspection Checklist points categorized.
 */
function getInspectionChecklistMaster() {
    return [
        'frame' => [
            'name' => 'Rangka & Struktur Bodi',
            'icon' => 'fa-shield-halved',
            'weight' => 0.30,
            'items' => [
                'frame_apron_front' => 'Apron / Dudukan Radiator Depan',
                'frame_chassis_front_l' => 'Chassis / Sasis Depan Kiri',
                'frame_chassis_front_r' => 'Chassis / Sasis Depan Kanan',
                'frame_chassis_rear_l' => 'Chassis / Sasis Belakang Kiri',
                'frame_chassis_rear_r' => 'Chassis / Sasis Belakang Kanan',
                'frame_pillar_a_l' => 'Pilar A Kiri (Depan)',
                'frame_pillar_a_r' => 'Pilar A Kanan (Depan)',
                'frame_pillar_b_l' => 'Pilar B Kiri (Tengah)',
                'frame_pillar_b_r' => 'Pilar B Kanan (Tengah)',
                'frame_pillar_c_l' => 'Pilar C Kiri (Belakang)',
                'frame_pillar_c_r' => 'Pilar C Kanan (Belakang)',
                'frame_floor_trunk' => 'Lantai Bagasi & Dudukan Ban Cadangan',
                'frame_floor_cabin' => 'Lantai Dasar Kabin Bawah',
                'frame_crossmember' => 'Crossmember & Dudukan Mesin Bawah',
                'frame_bulkhead' => 'Bulkhead / Sekat Ruang Mesin',
                'frame_roof_structure' => 'Struktur Tulang Atap Mobil',
                'frame_radiator_support' => 'Braket & Dudukan Radiator',
                'frame_wheelhouse_fl' => 'Rumah Roda Depan Kiri',
                'frame_wheelhouse_fr' => 'Rumah Roda Depan Kanan',
                'frame_wheelhouse_rear' => 'Rumah Roda Belakang Kiri & Kanan'
            ]
        ],
        'engine' => [
            'name' => 'Mesin & Transmisi',
            'icon' => 'fa-cogs',
            'weight' => 0.30,
            'items' => [
                'eng_sound_idle' => 'Suara Mesin saat Idle / Stasioner',
                'eng_vibration' => 'Getaran Mesin (Engine Mounting)',
                'eng_oil_leak_head' => 'Kerapatan Head Silinder (Bebas Rembes Oli)',
                'eng_oil_leak_crank' => 'Kerapatan Carter / Crankcase Bawah',
                'eng_oil_dipstick' => 'Kondisi & Kualitas Oli Mesin',
                'eng_radiator_core' => 'Kondisi Kisi Radiator & Bebas Bocor',
                'eng_coolant_tank' => 'Kondisi Coolant & Tabung Reservoir',
                'eng_radiator_fan' => 'Kinerja Kipas Radiator (Extra Fan)',
                'eng_fan_belt' => 'Kondisi Drive Belt / Fan Belt',
                'eng_alternator' => 'Kinerja Pengisian Alternator / Dinamo',
                'eng_battery_health' => 'Kondisi Tegangan & Kesehatan Aki',
                'eng_spark_plug_coils' => 'Koil & Busi / Sistem Pengapian',
                'eng_injector_fuel' => 'Sistem Injeksi & Saluran Bahan Bakar',
                'eng_air_filter_box' => 'Filter Udara & Rumah Filter',
                'eng_brake_fluid' => 'Kondisi & Volume Minyak Rem',
                'eng_power_steering_fluid' => 'Minyak Power Steering / Motor EPS',
                'eng_trans_oil' => 'Kondisi & Kebocoran Oli Transmisi',
                'eng_trans_shifting' => 'Perpindahan Tuas Transmisi (P-R-N-D/Manual)',
                'eng_trans_response' => 'Respons Akselerasi Transmisi (No Delay/Slip)',
                'eng_clutch_pedal' => 'Kopling / Clutch (Keringanan & Tidak Slip)',
                'eng_exhaust_smoke' => 'Asap Knalpot (Bebas Asap Putih / Hitam Pekat)',
                'eng_exhaust_muffler' => 'Kondisi Tabung Knalpot & Pipa Bawah',
                'eng_turbo_charger' => 'Kondisi Turbocharger / Supercharger (Jika Ada)',
                'eng_throttle_body' => 'Kebersihan & Respons Throttle Body',
                'eng_starter_motor' => 'Kemudahan Starter Mesin Dingin & Panas'
            ]
        ],
        'exterior' => [
            'name' => 'Eksterior & Bodi Kendaraan',
            'icon' => 'fa-car-side',
            'weight' => 0.15,
            'items' => [
                'ext_hood_panel' => 'Kap Mesin (Kesejajaran Gap & Cat)',
                'ext_front_bumper' => 'Bumper Depan & Grille',
                'ext_fender_fl' => 'Fender Depan Kiri',
                'ext_fender_fr' => 'Fender Depan Kanan',
                'ext_door_fl' => 'Pintu Depan Kiri & Karet Seal',
                'ext_door_fr' => 'Pintu Depan Kanan & Karet Seal',
                'ext_door_rl' => 'Pintu Belakang Kiri & Karet Seal',
                'ext_door_rr' => 'Pintu Belakang Kanan & Karet Seal',
                'ext_fender_rl' => 'Fender / Quarter Panel Belakang Kiri',
                'ext_fender_rr' => 'Fender / Quarter Panel Belakang Kanan',
                'ext_trunk_lid' => 'Pintu Bagasi / Hatchback',
                'ext_rear_bumper' => 'Bumper Belakang & Diffuser',
                'ext_roof_panel' => 'Panel Atap Mobil / Sunroof Glass',
                'ext_windshield_front' => 'Kaca Depan (Bebas Retak & Jamur Parah)',
                'ext_windshield_rear' => 'Kaca Belakang & Kawat Defogger',
                'ext_door_glass_all' => 'Kaca Jendela Seluruh Pintu',
                'ext_headlight_l' => 'Lampu Utama / Headlamp Kiri',
                'ext_headlight_r' => 'Lampu Utama / Headlamp Kanan',
                'ext_foglamp' => 'Lampu Kabut (Foglamp) Kiri & Kanan',
                'ext_taillight_l' => 'Lampu Belakang (Stop/Sein) Kiri',
                'ext_taillight_r' => 'Lampu Belakang (Stop/Sein) Kanan',
                'ext_mirror_fl' => 'Kaca Spion & Cover Kiri',
                'ext_mirror_fr' => 'Kaca Spion & Cover Kanan',
                'ext_wiper_blades' => 'Karet Wiper Depan & Belakang',
                'ext_washer_spray' => 'Semprotan Air Wiper (Washer)',
                'ext_door_handles' => 'Handle Pintu & Fungsi Keyless/Smart Entry',
                'ext_fuel_cap' => 'Tutup Tangki Bensin & Mekanisme Pembuka',
                'ext_emblems_trim' => 'Emblem Merek/Tipe & List Body Trim',
                'ext_paint_uniformity' => 'Keseragaman Warna Cat Seluruh Bodi',
                'ext_dent_scratches' => 'Bebas Penyok / Goresan Dalam Parah'
            ]
        ],
        'interior' => [
            'name' => 'Interior & Kelistrikan Kabin',
            'icon' => 'fa-couch',
            'weight' => 0.15,
            'items' => [
                'int_dashboard' => 'Dashboard & Konsol Tengah (Bebas Retak/Pudar)',
                'int_steering_wheel' => 'Kondisi Kulit/Bahan Setir & Tombol Audio',
                'int_meter_cluster' => 'Panel Instrumen / Speedometer & Indikator',
                'int_seat_driver' => 'Kursi Pengemudi (Busa, Cover & Rel Maju-Mundur)',
                'int_seat_passenger' => 'Kursi Penumpang Depan',
                'int_seat_rear' => 'Kursi Baris Belakang / Baris Ketiga',
                'int_seatbelts' => 'Sabuk Pengaman (Fungsi Tarik & Bebas Noda Air/Lumpur)',
                'int_roof_lining' => 'Plafon Atap (Bersih & Bebas Noda/Rontok)',
                'int_carpets' => 'Karpet Dasar (Bebas Lembab & Bebas Karat Bawah)',
                'int_door_trims' => 'Door Trim Seluruh Pintu',
                'int_ac_cooling' => 'Suhu Hembusan AC (Dingin Optimal)',
                'int_ac_blower' => 'Kekuatan Kipas Blower & Pengatur Arah',
                'int_ac_heater' => 'Penghangat / Heater & Sirkulasi Udara',
                'int_audio_system' => 'Head Unit Audio, Layar Sentuh & Speaker',
                'int_power_windows' => 'Fungsi Power Window Seluruh Pintu',
                'int_central_lock' => 'Central Door Lock & Remote Kunci',
                'int_electric_mirrors' => 'Motor Pengatur Spion & Fitur Retract Lipat',
                'int_horn' => 'Klakson Kendaraan',
                'int_hazard_switch' => 'Tombol Lampu Hazard & Lampu Sein',
                'int_cabin_lights' => 'Lampu Penerangan Kabin & Pintu',
                'int_sun_visors' => 'Sun Visor Kiri & Kanan (Kaca & Lampu)',
                'int_parking_brake' => 'Fungsi Tuas / Tombol Electronic Handbrake',
                'int_airbags_cover' => 'Cover Airbag Pengemudi & Penumpang (Utuh/Belum Pecah)',
                'int_trunk_space' => 'Kebersihan Ruang Bagasi & Cover Penutup',
                'int_odor_smoke' => 'Aroma Kabin (Bebas Bau Rokok/Apek/Banjir)'
            ]
        ],
        'road_test' => [
            'name' => 'Kaki-kaki, Rem & Uji Jalan',
            'icon' => 'fa-road',
            'weight' => 0.10,
            'items' => [
                'test_shock_front' => 'Shock Absorber Depan (Bebas Bocor & Bunyi)',
                'test_shock_rear' => 'Shock Absorber Belakang (Kenyamanan Ayunan)',
                'test_tierod_balljoint' => 'Tie Rod End & Ball Joint (Tidak Oblak)',
                'test_bushings_arm' => 'Bushing Arm & Karet Stabilizer',
                'test_brake_pads_front' => 'Ketebalan Kampas & Piringan Cakram Depan',
                'test_brake_pads_rear' => 'Kampas Rem Belakang (Cakram / Tromol)',
                'test_abs_system' => 'Kinerja Pengereman ABS (Tanpa Indikator Error)',
                'test_steering_play' => 'Kemudi / Setir (Lurus, Tidak Buang Kiri/Kanan)',
                'test_steering_noise' => 'Bebas Bunyi Gemeretak saat Belok Penuh',
                'test_tire_tread_f' => 'Ketebalan & Keausan Rata Ban Depan (> 70%)',
                'test_tire_tread_r' => 'Ketebalan & Keausan Rata Ban Belakang (> 70%)',
                'test_spare_wheel' => 'Kondisi Ban Cadangan, Dongkrak & Kunci Roda',
                'test_wheel_rims' => 'Velg Roda (Bebas Retak, Bengkok atau Bibir Gompal)',
                'test_acceleration' => 'Akselerasi di Jalan Raya (Mulus & Bertenaga)',
                'test_engine_temp' => 'Suhu Mesin Stabil saat Uji Jalan (No Overheat)'
            ]
        ]
    ];
}

/**
 * Calculates weighted scores, category scores, certification checks, and overall grade.
 */
function calculateInspectionScoreAndGrade($checklistAnswers, $majorChecks = []) {
    $master = getInspectionChecklistMaster();
    
    $categoryScores = [];
    $totalWeightedScore = 0.0;
    
    // Default item score map: good = 100%, minor = 70%, poor = 30%
    $scoreMap = [
        'good' => 100.0,
        'minor' => 70.0,
        'poor' => 30.0
    ];

    foreach ($master as $catKey => $catData) {
        $items = $catData['items'];
        $itemCount = count($items);
        $catTotal = 0.0;
        
        foreach ($items as $itemKey => $itemLabel) {
            $ans = $checklistAnswers[$itemKey] ?? 'good';
            $ansScore = $scoreMap[$ans] ?? 100.0;
            $catTotal += $ansScore;
        }
        
        $catScoreAvg = $itemCount > 0 ? ($catTotal / $itemCount) : 100.0;
        $categoryScores[$catKey] = round($catScoreAvg, 2);
        
        $totalWeightedScore += ($catScoreAvg * $catData['weight']);
    }

    $overallScore = round($totalWeightedScore, 2);

    // Major safety checks
    $floodFree = !empty($majorChecks['flood_free']) ? 1 : 0;
    $accidentFree = !empty($majorChecks['accident_free']) ? 1 : 0;
    $odometerTampered = !empty($majorChecks['odometer_tampered']) ? 1 : 0;
    $fireFree = !empty($majorChecks['fire_free']) ? 1 : 0;

    // Grade Determination Logic
    // If flooded or heavy accident occurred, automatically grade is capped at D
    if ($floodFree == 0 || $accidentFree == 0 || $fireFree == 0) {
        $grade = 'D';
    } elseif ($overallScore >= 95.0 && $floodFree == 1 && $accidentFree == 1 && $odometerTampered == 0) {
        $grade = 'A+';
    } elseif ($overallScore >= 88.0 && $floodFree == 1 && $accidentFree == 1) {
        $grade = 'A';
    } elseif ($overallScore >= 75.0 && $floodFree == 1 && $accidentFree == 1) {
        $grade = 'B';
    } elseif ($overallScore >= 60.0) {
        $grade = 'C';
    } else {
        $grade = 'D';
    }

    return [
        'overall_score' => $overallScore,
        'overall_grade' => $grade,
        'frame_score' => $categoryScores['frame'] ?? 100.0,
        'engine_score' => $categoryScores['engine'] ?? 100.0,
        'exterior_score' => $categoryScores['exterior'] ?? 100.0,
        'interior_score' => $categoryScores['interior'] ?? 100.0,
        'road_test_score' => $categoryScores['road_test'] ?? 100.0,
        'flood_free' => $floodFree,
        'accident_free' => $accidentFree,
        'odometer_tampered' => $odometerTampered,
        'fire_free' => $fireFree
    ];
}

/**
 * Returns Grade configuration including color, label, and explanation.
 */
function getGradeConfig($grade) {
    $configs = [
        'A+' => [
            'label' => 'Grade A+',
            'subtext' => 'Kondisi Istimewa (Like New)',
            'badge_bg' => '#10b981',
            'badge_text' => '#ffffff',
            'border_color' => '#059669',
            'soft_bg' => '#ecfdf5',
            'soft_text' => '#047857',
            'icon' => 'fa-award',
            'description' => 'Kendaraan dalam kondisi mendekati baru, seluruh komponen berfungsi sempurna, bodi mulus, mesin sangat sehat, bebas banjir dan tabrakan.'
        ],
        'A' => [
            'label' => 'Grade A',
            'subtext' => 'Kondisi Sangat Baik',
            'badge_bg' => '#0284c7',
            'badge_text' => '#ffffff',
            'border_color' => '#0369a1',
            'soft_bg' => '#f0f9ff',
            'soft_text' => '#0284c7',
            'icon' => 'fa-circle-check',
            'description' => 'Kondisi kendaraan sangat terawat dengan pemakaian minimal, mesin halus, interior bersih, bebas banjir dan tabrakan berat.'
        ],
        'B' => [
            'label' => 'Grade B',
            'subtext' => 'Kondisi Baik (Pemakaian Wajar)',
            'badge_bg' => '#6366f1',
            'badge_text' => '#ffffff',
            'border_color' => '#4f46e5',
            'soft_bg' => '#eef2ff',
            'soft_text' => '#4f46e5',
            'icon' => 'fa-thumbs-up',
            'description' => 'Kondisi kendaraan baik dan terawat, terdapat sedikit tanda pemakaian normal, mesin prima, bebas banjir dan tabrakan berat.'
        ],
        'C' => [
            'label' => 'Grade C',
            'subtext' => 'Cukup (Perlu Minor Perbaikan)',
            'badge_bg' => '#f59e0b',
            'badge_text' => '#ffffff',
            'border_color' => '#d97706',
            'soft_bg' => '#fffbeb',
            'soft_text' => '#b45309',
            'icon' => 'fa-triangle-exclamation',
            'description' => 'Kendaraan berfungsi normal namun memerlukan beberapa perbaikan minor pada kosmetik atau suku cadang aus.'
        ],
        'D' => [
            'label' => 'Grade D',
            'subtext' => 'Perlu Perbaikan Menyeluruh',
            'badge_bg' => '#ef4444',
            'badge_text' => '#ffffff',
            'border_color' => '#dc2626',
            'soft_bg' => '#fef2f2',
            'soft_text' => '#b91c1c',
            'icon' => 'fa-circle-xmark',
            'description' => 'Kendaraan memerlukan perbaikan besar atau terdapat riwayat tabrakan/banjir yang mempengaruhi struktur.'
        ]
    ];

    return $configs[$grade] ?? $configs['B'];
}

/**
 * Renders an interactive or modern HTML badge for vehicle inspection grade.
 */
function renderGradeBadge($grade, $score = null, $size = 'md') {
    $cfg = getGradeConfig($grade);
    $scoreText = ($score !== null) ? ' (' . number_format($score, 1) . '/100)' : '';
    
    $padding = ($size === 'lg') ? 'padding: 6px 14px; font-size: 0.95rem;' : (($size === 'sm') ? 'padding: 2px 8px; font-size: 0.72rem;' : 'padding: 4px 10px; font-size: 0.8rem;');
    
    return '<span class="d-inline-flex align-items-center gap-1 fw-bold rounded-pill shadow-xs" style="background: ' . $cfg['badge_bg'] . '; color: ' . $cfg['badge_text'] . '; ' . $padding . '" title="' . htmlspecialchars($cfg['subtext']) . '">
        <i class="fas ' . $cfg['icon'] . '"></i> ' . htmlspecialchars($cfg['label']) . $scoreText . '
    </span>';
}
