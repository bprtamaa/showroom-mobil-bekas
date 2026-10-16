<?php
// catalog/index.php - Landing Page & Katalog Showroom Mobil Bekas
require_once __DIR__ . '/../config/database.php';

// 1. Ambil Pengaturan Showroom
$settings = [
    'company_name'    => 'Mulya Motor',
    'company_phone'   => '0813-2329-9404',
    'company_address' => 'Jl. Mohamad Hatta No.66, Sukamanah, Kec. Cipedes, Kota Tasikmalaya, Jawa Barat 46131',
    'company_email'   => 'info@mulyamotor.com',
    'company_logo'    => ''
];

try {
    $stmtSettings = $db->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
    $fetched = $stmtSettings->fetch(PDO::FETCH_ASSOC);
    if ($fetched) {
        $settings = array_merge($settings, array_filter($fetched));
    }
} catch (Exception $e) {}

$logoUrl = '';
if (!empty($settings['company_logo'])) {
    if (file_exists(__DIR__ . '/../uploads/settings/' . $settings['company_logo'])) {
        $logoUrl = '../uploads/settings/' . $settings['company_logo'];
    } elseif (file_exists(__DIR__ . '/../uploads/' . $settings['company_logo'])) {
        $logoUrl = '../uploads/' . $settings['company_logo'];
    }
}

$rawPhone = preg_replace('/[^0-9]/', '', $settings['company_phone']);
if (substr($rawPhone, 0, 1) === '0') {
    $waPhone = '62' . substr($rawPhone, 1);
} elseif (substr($rawPhone, 0, 2) === '62') {
    $waPhone = $rawPhone;
} else {
    $waPhone = '6281323299404';
}

// 2. Ambil Brands & Car Types
$brands = [];
try {
    $brandsStmt = $db->query("
        SELECT b.id, b.name, b.logo, COUNT(v.id) as total_available
        FROM brands b
        LEFT JOIN models m ON b.id = m.brand_id
        LEFT JOIN vehicles v ON m.id = v.model_id AND v.status = 'available'
        WHERE b.status = 'active'
        GROUP BY b.id, b.name, b.logo
        ORDER BY total_available DESC, b.name ASC
    ");
    $brands = $brandsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$carTypes = [];
try {
    $typesStmt = $db->query("
        SELECT ct.id, ct.name, COUNT(v.id) as total_available
        FROM car_types ct
        LEFT JOIN models m ON ct.id = m.car_type_id
        LEFT JOIN vehicles v ON m.id = v.model_id AND v.status = 'available'
        WHERE ct.status = 'active'
        GROUP BY ct.id, ct.name
        ORDER BY total_available DESC, ct.name ASC
    ");
    $carTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// 3. Ambil Promo & Diskon Aktif
$activePromotions = [];
try {
    $pStmt = $db->query("
        SELECT * FROM promotions 
        WHERE status = 'active' 
          AND start_date <= CURDATE() 
          AND end_date >= CURDATE()
        ORDER BY discount_amount DESC
    ");
    $activePromotions = $pStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activePromotions = [];
}

// 4. Ambil Semua Kendaraan Berstatus 'available'
$vehicles = [];
try {
    $vSql = "
        SELECT 
            v.id, v.year, v.license_plate, v.color, v.mileage, v.fuel_type, v.transmission,
            v.selling_price, v.condition_rating, v.notes, v.images, v.status, v.is_consignment,
            v.warranty_type, v.warranty_period, v.warranty_notes,
            m.name as model_name, m.id as model_id,
            b.name as brand_name, b.id as brand_id, b.logo as brand_logo,
            ct.name as car_type_name, ct.id as car_type_id,
            insp.overall_grade, insp.overall_score, insp.flood_free, insp.accident_free,
            insp.inspection_date
        FROM vehicles v
        JOIN models m ON v.model_id = m.id
        JOIN brands b ON m.brand_id = b.id
        LEFT JOIN car_types ct ON m.car_type_id = ct.id
        LEFT JOIN (
            SELECT vi1.* 
            FROM vehicle_inspections vi1
            INNER JOIN (
                SELECT vehicle_id, MAX(id) as max_id 
                FROM vehicle_inspections 
                GROUP BY vehicle_id
            ) vi2 ON vi1.id = vi2.max_id
        ) insp ON v.id = insp.vehicle_id
        WHERE v.status = 'available'
        ORDER BY v.created_at DESC
    ";
    $vStmt = $db->query($vSql);
    $rawVehicles = $vStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rawVehicles as $v) {
        $imgList = [];
        if (!empty($v['images'])) {
            $dec = json_decode($v['images'], true);
            if (is_array($dec)) {
                $imgList = $dec;
            } else {
                $imgList = array_filter(array_map('trim', explode(',', $v['images'])));
            }
        }
        
        $resolvedImages = [];
        foreach ($imgList as $img) {
            if (!empty($img) && file_exists(__DIR__ . '/../uploads/vehicles/' . $img)) {
                $resolvedImages[] = '../uploads/vehicles/' . $img;
            }
        }

        $v['images_list'] = $resolvedImages;
        $v['primary_image'] = !empty($resolvedImages[0]) ? $resolvedImages[0] : 'https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=800&q=80';
        
        // Match active promotion
        $matchedPromo = null;
        foreach ($activePromotions as $promo) {
            if ($promo['target_scope'] === 'all_vehicles') {
                $matchedPromo = $promo;
                break;
            } elseif ($promo['target_scope'] === 'selected_vehicles' && !empty($promo['target_ids'])) {
                $ids = json_decode($promo['target_ids'], true);
                if (is_array($ids) && in_array((int)$v['id'], $ids)) {
                    $matchedPromo = $promo;
                    break;
                }
            } elseif ($promo['target_scope'] === 'selected_brands' && !empty($promo['target_ids'])) {
                $bIds = json_decode($promo['target_ids'], true);
                if (is_array($bIds) && in_array((int)$v['brand_id'], $bIds)) {
                    $matchedPromo = $promo;
                    break;
                }
            }
        }

        if ($matchedPromo) {
            $discNominal = ($matchedPromo['discount_type'] === 'percentage') 
                ? ($v['selling_price'] * ($matchedPromo['discount_amount'] / 100)) 
                : $matchedPromo['discount_amount'];
            $v['promo_active'] = true;
            $v['promo_badge'] = $matchedPromo['promo_badge'];
            $v['promo_title'] = $matchedPromo['title'];
            $v['original_price'] = $v['selling_price'];
            $v['final_price'] = max(0, $v['selling_price'] - $discNominal);
            $v['formatted_price'] = 'Rp ' . number_format($v['final_price'], 0, ',', '.');
            $v['formatted_original_price'] = 'Rp ' . number_format($v['original_price'], 0, ',', '.');
            $effectivePrice = $v['final_price'];
        } else {
            $v['promo_active'] = false;
            $v['promo_badge'] = null;
            $v['original_price'] = $v['selling_price'];
            $v['final_price'] = $v['selling_price'];
            $v['formatted_price'] = 'Rp ' . number_format($v['selling_price'], 0, ',', '.');
            $v['formatted_original_price'] = null;
            $effectivePrice = $v['selling_price'];
        }

        // DP 20%, 48 bulan, flat 8%/tahun
        $dpNominal = $effectivePrice * 0.20;
        $loanPrincipal = $effectivePrice - $dpNominal;
        $estMonthly = ($loanPrincipal + ($loanPrincipal * 0.08 * 4)) / 48;
        $v['est_monthly_installment'] = 'Rp ' . number_format($estMonthly, 0, ',', '.');
        $v['est_dp'] = 'Rp ' . number_format($dpNominal, 0, ',', '.');

        $vehicles[] = $v;
    }
} catch (Exception $e) {
    $vehicles = [];
}

$totalStock = count($vehicles);
$totalBrands = count(array_filter($brands, fn($b) => $b['total_available'] > 0));
$featuredVehicle = !empty($vehicles[0]) ? $vehicles[0] : null;

// 5. Data Testimoni Customer Terverifikasi (Katalog Testimonials)
$testimonials = [
    [
        'name' => 'Haji Mustofa',
        'city' => 'Cihideung, Tasikmalaya',
        'car' => 'Toyota Fortuner 2.4 VRZ',
        'year' => '2021',
        'rating' => 5,
        'date' => '24 Oktober 2025',
        'avatar_bg' => '#1e293b',
        'initials' => 'HM',
        'review' => 'Kondisi mobil benar-benar istimewa seperti baru, interior bersih, mesin halus, dan kaki-kaki senyap. Dokumen BPKB dan STNK langsung dicek keasliannya di tempat. Pelayanan sangat amanah, profesional, dan ramah.'
    ],
    [
        'name' => 'Ian Iswahyudi',
        'city' => 'Ampera Barat, Tasikmalaya',
        'car' => 'Honda HR-V 1.5 E Matic',
        'year' => '2020',
        'rating' => 5,
        'date' => '3 November 2025',
        'avatar_bg' => '#047857',
        'initials' => 'II',
        'review' => 'Proses pembelian sangat cepat dan dibantu dari awal sampai serah terima unit. Tim sales sangat responsif dan transparan. Kondisi unit dicek bersama mekanik dan terbukti bebas banjir maupun benturan.'
    ],
    [
        'name' => 'Sarah Septiani',
        'city' => 'Tawang, Tasikmalaya',
        'car' => 'Honda Brio RS CVT',
        'year' => '2022',
        'rating' => 5,
        'date' => '11 Januari 2026',
        'avatar_bg' => '#6d28d9',
        'initials' => 'SS',
        'review' => 'Bagi saya yang pertama kali membeli mobil di showroom, Mulya Motor memberikan rasa aman dan nyaman. Diberikan garansi showroom dan laporan inspeksi lengkap. Unit yang saya ambil sangat terawat.'
    ],
    [
        'name' => 'Setia Budi',
        'city' => 'Cipedes, Tasikmalaya',
        'car' => 'Toyota Innova Reborn 2.4 G',
        'year' => '2019',
        'rating' => 5,
        'date' => '27 November 2025',
        'avatar_bg' => '#1d4ed8',
        'initials' => 'SB',
        'review' => 'Penawaran harga sangat wajar dan kompetitif. Proses transaksi cepat dan transparan di depan konsumen. Mobil langsung siap pakai tanpa kendala, surat-surat kendaraan lengkap dan resmi.'
    ],
    [
        'name' => 'Rafid Halim',
        'city' => 'Bandung, Jawa Barat',
        'car' => 'Mitsubishi Pajero Sport Dakar',
        'year' => '2021',
        'rating' => 5,
        'date' => '28 November 2025',
        'avatar_bg' => '#b45309',
        'initials' => 'RH',
        'review' => 'Datang dari Bandung ke Tasikmalaya sangat puas dengan unit yang didapat. Unit Pajero Sport Dakar sesuai 100% dengan foto dan penjelasan yang diberikan. Dokumen komplit dan proses administrasi rapi.'
    ],
    [
        'name' => 'Dede Rohayati',
        'city' => 'Sadananya, Ciamis',
        'car' => 'Toyota Yaris TRD Sportivo',
        'year' => '2020',
        'rating' => 5,
        'date' => '11 Desember 2025',
        'avatar_bg' => '#be185d',
        'initials' => 'DR',
        'review' => 'Pelayanannya sangat memuaskan, sopan, dan transparan. Test drive diberikan keleluasaan untuk mencoba unit. Kondisi mesin halus, ban masih tebal, dan AC dingin. Sukses terus untuk Mulya Motor.'
    ]
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings['company_name']); ?> - Showroom Mobil Bekas Berkualitas & Bergaransi Tasikmalaya</title>
    <meta name="description" content="Jual beli mobil bekas berkualitas di <?php echo htmlspecialchars($settings['company_name']); ?> Tasikmalaya. Unit siap pakai, bebas banjir & tabrakan, dokumen resmi 100% terjamin, kredit DP ringan & tukar tambah.">
    <?php if (!empty($logoUrl)): ?>
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($logoUrl); ?>">
    <link rel="shortcut icon" href="<?php echo htmlspecialchars($logoUrl); ?>">
    <?php endif; ?>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        /* ============================================
           DESIGN SYSTEM - Clean Modern Automotive
           ============================================ */
        :root {
            --ff: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            
            /* Slate & Ink Palette */
            --c-ink: #0f172a;
            --c-ink-light: #1e293b;
            --c-body: #334155;
            --c-muted: #64748b;
            --c-subtle: #94a3b8;
            --c-line: #e2e8f0;
            --c-line-soft: #f1f5f9;
            --c-wash: #f8fafc;
            --c-bg: #fafaf9;
            --c-white: #ffffff;
            
            /* Accents */
            --c-primary: #0f172a;
            --c-green: #059669;
            --c-green-dark: #047857;
            --c-green-light: #ecfdf5;
            --c-green-border: #a7f3d0;
            --c-wa: #25d366;
            --c-wa-dark: #1eb956;
            --c-accent: #2563eb;
            --c-red: #ef4444;
            --c-amber: #f59e0b;
            
            /* Radius & Shadows */
            --r-xs: 6px;
            --r-sm: 8px;
            --r-md: 12px;
            --r-lg: 16px;
            --r-xl: 20px;
            --r-pill: 9999px;
            
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 2px 4px -1px rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.03);
            --shadow-md: 0 4px 12px -2px rgba(15, 23, 42, 0.06), 0 2px 6px -2px rgba(15, 23, 42, 0.03);
            --shadow-lg: 0 12px 28px -4px rgba(15, 23, 42, 0.07), 0 4px 12px -3px rgba(15, 23, 42, 0.03);
            --shadow-card: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-card-hover: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        
        html { 
            font-size: 15px; 
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
        }
        
        body {
            font-family: var(--ff);
            color: var(--c-body);
            background: var(--c-bg);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            min-height: 100vh;
        }
        
        a { color: inherit; text-decoration: none; }
        img { display: block; max-width: 100%; height: auto; }
        button, input, select, textarea { font: inherit; border: none; outline: none; background: none; }
        ul, ol { list-style: none; }

        .w { max-width: 1240px; margin: 0 auto; padding: 0 24px; }
        @media (max-width: 640px) { .w { padding: 0 16px; } }

        /* Generic Section Headers */
        .sec-head {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 36px;
        }
        .sec-kicker {
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--c-green);
            margin-bottom: 6px;
        }
        .sec-title {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--c-ink);
            letter-spacing: -0.025em;
            line-height: 1.25;
            margin-bottom: 10px;
        }
        .sec-desc {
            font-size: 0.9rem;
            color: var(--c-muted);
            line-height: 1.65;
        }
        @media (min-width: 768px) {
            .sec-title { font-size: 2.1rem; }
        }

        /* Buttons */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 22px;
            background: var(--c-ink);
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            border-radius: var(--r-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 4px rgba(15,23,42,0.1);
        }
        .btn-primary:hover {
            background: var(--c-ink-light);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(15,23,42,0.15);
        }
        .btn-outline {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 22px;
            border: 1px solid var(--c-line);
            background: var(--c-white);
            color: var(--c-ink);
            font-size: 0.82rem;
            font-weight: 700;
            border-radius: var(--r-sm);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-outline:hover {
            border-color: var(--c-ink);
            background: var(--c-wash);
            transform: translateY(-1px);
        }
        .btn-wa {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--c-wa);
            color: #ffffff;
            font-size: 0.82rem;
            font-weight: 700;
            border-radius: var(--r-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(37,211,102,0.2);
        }
        .btn-wa:hover {
            background: var(--c-wa-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37,211,102,0.3);
        }

        /* ============================================
           TOP PROMO BANNER
           ============================================ */
        .promo-top-banner {
            background: #dc2626;
            color: #ffffff;
            padding: 8px 16px;
            font-size: 0.8rem;
            text-align: center;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        /* ============================================
           NAVIGATION
           ============================================ */
        .nav {
            position: sticky;
            top: 0;
            z-index: 100;
            background: rgba(255, 255, 255, 0.95);
            border-bottom: 1px solid var(--c-line);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            transition: background 0.2s;
        }
        .nav-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .nav-brand-logo {
            height: 36px;
            width: auto;
            object-fit: contain;
        }
        .nav-brand-fallback {
            width: 36px; height: 36px;
            background: var(--c-ink);
            color: var(--c-white);
            border-radius: var(--r-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; font-weight: 800;
        }
        .nav-brand-info {
            display: flex;
            flex-direction: column;
        }
        .nav-brand-name {
            font-size: 1.05rem;
            font-weight: 900;
            color: var(--c-ink);
            letter-spacing: -0.03em;
            line-height: 1.1;
        }
        .nav-brand-tag {
            font-size: 0.62rem;
            font-weight: 600;
            color: var(--c-green);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .nav-brand-tag::before {
            content: '';
            width: 5px; height: 5px;
            border-radius: 50%;
            background: var(--c-green);
            display: inline-block;
        }

        .nav-links {
            display: none;
            align-items: center;
            gap: 26px;
        }
        .nav-links a {
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--c-muted);
            letter-spacing: 0.01em;
            transition: color 0.15s ease;
            position: relative;
            padding: 4px 0;
        }
        .nav-links a:hover { color: var(--c-ink); }
        
        .nav-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .nav-phone {
            display: none;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--c-body);
            padding: 6px 12px;
            border-radius: var(--r-sm);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
        }
        .nav-phone:hover { color: var(--c-ink); border-color: var(--c-subtle); }
        .nav-wa {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--c-wa);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            border-radius: var(--r-sm);
            transition: all 0.15s ease;
        }
        .nav-wa:hover { background: var(--c-wa-dark); transform: translateY(-1px); }

        .nav-hamburger {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px; height: 38px;
            border-radius: var(--r-sm);
            cursor: pointer;
            color: var(--c-ink);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
        }
        .nav-hamburger:hover { background: var(--c-line-soft); }

        @media (min-width: 960px) {
            .nav-links { display: flex; }
            .nav-phone { display: flex; }
            .nav-hamburger { display: none; }
        }

        /* Mobile menu */
        .mob-menu {
            display: none;
            position: fixed;
            top: 64px; left: 0; right: 0; bottom: 0;
            background: var(--c-white);
            z-index: 99;
            padding: 20px 24px 40px;
            overflow-y: auto;
        }
        .mob-menu.open { display: block; }
        .mob-menu a {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 0;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--c-ink);
            border-bottom: 1px solid var(--c-line);
        }
        .mob-menu a:last-child { border-bottom: none; }

        /* ============================================
           HERO SECTION
           ============================================ */
        .hero {
            background: radial-gradient(circle at 10% 20%, rgba(241, 245, 249, 0.8) 0%, rgba(255, 255, 255, 1) 90%);
            border-bottom: 1px solid var(--c-line);
            position: relative;
            overflow: hidden;
        }
        .hero-inner {
            display: grid;
            grid-template-columns: 1fr;
            gap: 36px;
            padding: 44px 0;
            align-items: center;
        }
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--c-green);
            background: var(--c-green-light);
            border: 1px solid var(--c-green-border);
            padding: 4px 12px;
            border-radius: var(--r-pill);
            margin-bottom: 14px;
        }
        .hero h1 {
            font-size: 2.1rem;
            font-weight: 900;
            color: var(--c-ink);
            line-height: 1.15;
            letter-spacing: -0.035em;
            margin-bottom: 14px;
        }
        .hero-text {
            font-size: 0.92rem;
            color: var(--c-muted);
            line-height: 1.7;
            max-width: 520px;
            margin-bottom: 24px;
        }
        .hero-stats {
            display: flex;
            gap: 28px;
            margin-bottom: 28px;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.85);
            border: 1px solid var(--c-line);
            border-radius: var(--r-md);
            width: fit-content;
        }
        .hero-stat-item {
            display: flex;
            flex-direction: column;
        }
        .hero-stat-num {
            font-size: 1.5rem;
            font-weight: 900;
            color: var(--c-ink);
            line-height: 1;
            margin-bottom: 2px;
        }
        .hero-stat-label {
            font-size: 0.72rem;
            color: var(--c-subtle);
            font-weight: 600;
        }
        .hero-cta-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        /* Hero featured car */
        .hero-featured {
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            overflow: hidden;
            background: var(--c-white);
            box-shadow: var(--shadow-lg);
            transition: transform 0.25s ease;
        }
        .hero-featured:hover { transform: translateY(-3px); }
        .hero-featured-img {
            aspect-ratio: 16/10;
            background: var(--c-wash);
            overflow: hidden;
            position: relative;
        }
        .hero-featured-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .hero-featured:hover .hero-featured-img img {
            transform: scale(1.03);
        }
        .hero-featured-tag {
            position: absolute;
            top: 12px; left: 12px;
            background: var(--c-ink);
            color: var(--c-white);
            font-size: 0.68rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: var(--r-pill);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .hero-featured-body {
            padding: 18px 20px;
        }
        .hero-featured-name {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--c-ink);
            margin-bottom: 4px;
        }
        .hero-featured-meta {
            font-size: 0.78rem;
            color: var(--c-muted);
            margin-bottom: 12px;
        }
        .hero-featured-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-top: 12px;
            border-top: 1px solid var(--c-line);
        }
        .hero-featured-price {
            font-size: 1.25rem;
            font-weight: 900;
            color: var(--c-ink);
        }

        @media (min-width: 900px) {
            .hero-inner {
                grid-template-columns: 1.2fr 0.8fr;
                gap: 48px;
                padding: 60px 0;
            }
            .hero h1 { font-size: 2.75rem; }
        }

        /* ============================================
           FILTER BAR & BRAND CHIPS
           ============================================ */
        .filter-bar {
            position: sticky;
            top: 64px;
            z-index: 80;
            background: var(--c-white);
            border-bottom: 1px solid var(--c-line);
            padding: 12px 0;
        }
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-search-wrap {
            position: relative;
            flex: 1;
            min-width: 220px;
        }
        .filter-search-wrap i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--c-subtle);
            font-size: 0.85rem;
        }
        .filter-search {
            width: 100%;
            height: 40px;
            padding: 0 32px 0 36px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.82rem;
            color: var(--c-ink);
            background: var(--c-wash);
            transition: all 0.15s ease;
        }
        .filter-search:focus {
            border-color: var(--c-ink);
            background: var(--c-white);
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.06);
        }
        .filter-search::placeholder { color: var(--c-subtle); }
        .filter-clear-btn {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px; height: 20px;
            border-radius: 50%;
            background: var(--c-line);
            color: var(--c-muted);
            font-size: 0.6rem;
            display: none;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .filter-clear-btn.on { display: flex; }

        .filter-sel {
            height: 40px;
            padding: 0 32px 0 12px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--c-ink);
            background: var(--c-wash) url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E") no-repeat right 12px center;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            transition: all 0.15s;
        }
        .filter-sel:focus {
            border-color: var(--c-ink);
            background-color: var(--c-white);
            box-shadow: 0 0 0 3px rgba(15, 23, 42, 0.06);
        }

        .filter-reset {
            height: 40px;
            padding: 0 16px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--c-muted);
            cursor: pointer;
            border-radius: var(--r-sm);
            transition: all 0.15s;
            border: 1px solid var(--c-line);
            background: var(--c-wash);
        }
        .filter-reset:hover {
            color: var(--c-ink);
            background: var(--c-line-soft);
            border-color: var(--c-subtle);
        }

        /* Brand chips */
        .chips-bar {
            background: var(--c-white);
            border-bottom: 1px solid var(--c-line);
            padding: 10px 0;
        }
        .chips-scroll {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
            padding-bottom: 2px;
        }
        .chips-scroll::-webkit-scrollbar { display: none; }
        .chip {
            flex-shrink: 0;
            padding: 6px 14px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-pill);
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--c-muted);
            background: var(--c-wash);
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .chip:hover {
            border-color: var(--c-subtle);
            color: var(--c-ink);
            background: var(--c-white);
        }
        .chip.on {
            background: var(--c-ink);
            color: var(--c-white);
            border-color: var(--c-ink);
        }

        /* ============================================
           CATALOG GRID
           ============================================ */
        .catalog {
            padding: 40px 0 70px;
        }
        .catalog-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .catalog-title {
            font-size: 1.15rem;
            font-weight: 800;
            color: var(--c-ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .catalog-count {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--c-muted);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            padding: 2px 10px;
            border-radius: var(--r-pill);
        }
        .catalog-sort {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .catalog-sort label {
            font-size: 0.78rem;
            color: var(--c-muted);
            font-weight: 600;
        }
        .sort-sel {
            height: 36px;
            padding: 0 28px 0 10px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--c-ink);
            background: var(--c-white) url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%2364748b'/%3E%3C/svg%3E") no-repeat right 10px center;
            -webkit-appearance: none;
            appearance: none;
            cursor: pointer;
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 600px) { .grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 980px) { .grid { grid-template-columns: repeat(3, 1fr); gap: 24px; } }

        /* Vehicle card */
        .card {
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-lg);
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-card);
        }
        .card:hover {
            border-color: #cbd5e1;
            transform: translateY(-4px);
            box-shadow: var(--shadow-card-hover);
        }
        .card-img {
            aspect-ratio: 16/10;
            overflow: hidden;
            background: var(--c-wash);
            position: relative;
        }
        .card-img img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        .card:hover .card-img img {
            transform: scale(1.04);
        }
        .card-badge {
            position: absolute;
            top: 10px; left: 10px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(255, 255, 255, 0.95);
            border: 1px solid var(--c-line);
            font-size: 0.65rem;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: var(--r-xs);
            color: var(--c-green);
            backdrop-filter: blur(4px);
        }
        .card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }
        .card-top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .card-type {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--c-muted);
        }
        .card-year {
            font-size: 0.72rem;
            font-weight: 800;
            color: var(--c-ink);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            padding: 1px 8px;
            border-radius: var(--r-pill);
        }
        .card-name {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--c-ink);
            line-height: 1.3;
            margin-bottom: 12px;
        }
        .card-specs {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            background: var(--c-wash);
            overflow: hidden;
            margin-bottom: 16px;
        }
        .card-spec {
            padding: 7px 8px;
            border-right: 1px solid var(--c-line);
            text-align: center;
        }
        .card-spec:last-child { border-right: none; }
        .card-spec-label {
            font-size: 0.6rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--c-subtle);
            font-weight: 700;
            margin-bottom: 2px;
        }
        .card-spec-val {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--c-ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .card-pricing {
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--c-line);
            margin-bottom: 14px;
        }
        .card-price {
            font-size: 1.2rem;
            font-weight: 900;
            color: var(--c-ink);
            letter-spacing: -0.02em;
        }
        .card-installment {
            font-size: 0.72rem;
            color: var(--c-green);
            font-weight: 700;
            margin-top: 2px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .card-actions {
            display: grid;
            grid-template-columns: 1fr 1.2fr;
            gap: 8px;
        }
        .card-btn-detail {
            padding: 9px 0;
            text-align: center;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--c-ink);
            cursor: pointer;
            transition: all 0.15s ease;
            background: var(--c-white);
        }
        .card-btn-detail:hover {
            border-color: var(--c-ink);
            background: var(--c-wash);
        }
        .card-btn-wa {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 9px 0;
            border-radius: var(--r-sm);
            background: var(--c-ink);
            color: #ffffff;
            font-size: 0.78rem;
            font-weight: 700;
            transition: all 0.15s ease;
        }
        .card-btn-wa:hover {
            background: var(--c-ink-light);
        }

        /* Empty state */
        .empty-state {
            display: none;
            text-align: center;
            padding: 60px 20px;
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-sm);
        }
        .empty-state.show { display: block; }
        .empty-state-icon {
            width: 54px; height: 54px;
            margin: 0 auto 14px;
            border-radius: 50%;
            background: var(--c-wash);
            color: var(--c-subtle);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem;
            border: 1px solid var(--c-line);
        }

        /* ============================================
           CREDIT SIMULATOR
           ============================================ */
        .sim {
            background: var(--c-white);
            border-top: 1px solid var(--c-line);
            border-bottom: 1px solid var(--c-line);
            padding: 64px 0;
        }
        .sim-inner {
            display: grid;
            grid-template-columns: 1fr;
            gap: 40px;
            align-items: start;
        }
        .sim-box {
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 24px;
            background: var(--c-white);
            box-shadow: var(--shadow-md);
        }
        .sim-field {
            margin-bottom: 20px;
        }
        .sim-field:last-of-type { margin-bottom: 0; }
        .sim-field-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .sim-field-label {
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--c-ink);
        }
        .sim-field-value {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--c-ink);
        }
        .sim-slider {
            width: 100%;
            height: 6px;
            -webkit-appearance: none;
            appearance: none;
            background: var(--c-line);
            border-radius: var(--r-pill);
            cursor: pointer;
        }
        .sim-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: var(--c-ink);
            border: 3px solid var(--c-white);
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            cursor: grab;
        }
        .sim-slider::-moz-range-thumb {
            width: 20px; height: 20px;
            border-radius: 50%;
            background: var(--c-ink);
            border: 3px solid var(--c-white);
            box-shadow: 0 2px 6px rgba(0,0,0,0.2);
            cursor: grab;
        }
        .sim-slider-ticks {
            display: flex;
            justify-content: space-between;
            font-size: 0.65rem;
            color: var(--c-subtle);
            margin-top: 4px;
            font-weight: 600;
        }
        .sim-btns {
            display: grid;
            gap: 6px;
        }
        .sim-btns.c4 { grid-template-columns: repeat(4, 1fr); }
        .sim-btns.c5 { grid-template-columns: repeat(5, 1fr); }
        .sim-opt {
            padding: 8px 0;
            text-align: center;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--c-muted);
            background: var(--c-wash);
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .sim-opt:hover {
            border-color: var(--c-subtle);
            color: var(--c-ink);
            background: var(--c-white);
        }
        .sim-opt.on {
            background: var(--c-ink);
            color: var(--c-white);
            border-color: var(--c-ink);
        }
        .sim-result {
            margin-top: 22px;
            padding: 18px 20px;
            background: var(--c-green-light);
            border: 1px solid var(--c-green-border);
            border-radius: var(--r-lg);
        }
        .sim-result-flex {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .sim-result-label {
            font-size: 0.72rem;
            color: var(--c-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sim-result-num {
            font-size: 1.6rem;
            font-weight: 900;
            color: var(--c-green-dark);
            letter-spacing: -0.02em;
        }
        .sim-result-note {
            font-size: 0.68rem;
            color: var(--c-muted);
            margin-top: 3px;
        }

        .sim-partners {
            margin-top: 24px;
            padding: 16px;
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            border-radius: var(--r-md);
        }
        .sim-partners-title {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--c-ink);
            margin-bottom: 10px;
        }
        .sim-partners-list {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .sim-partner-tag {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--c-body);
            background: var(--c-white);
            border: 1px solid var(--c-line);
            padding: 4px 10px;
            border-radius: var(--r-pill);
        }

        @media (min-width: 900px) {
            .sim-inner { grid-template-columns: 1fr 1.15fr; gap: 52px; }
            .sim-result-flex { flex-direction: row; align-items: center; justify-content: space-between; gap: 16px; }
        }
        .sim-result-flex .btn-wa {
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* ============================================
           STANDAR JAMINAN KUALITAS
           ============================================ */
        .trust {
            padding: 68px 0;
            background: var(--c-bg);
        }
        .trust-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 600px) { .trust-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1020px) { .trust-grid { grid-template-columns: repeat(4, 1fr); gap: 24px; } }

        .trust-card {
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 26px 22px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: var(--shadow-sm);
            transition: all 0.25s ease;
        }
        .trust-card:hover {
            transform: translateY(-3px);
            border-color: #cbd5e1;
            box-shadow: var(--shadow-md);
        }
        .trust-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .trust-card-num {
            font-size: 1.15rem;
            font-weight: 900;
            color: var(--c-ink);
            letter-spacing: -0.03em;
        }
        .trust-card-tag {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--c-muted);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            padding: 2px 8px;
            border-radius: var(--r-xs);
        }
        .trust-card-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--c-ink);
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .trust-card-desc {
            font-size: 0.82rem;
            color: var(--c-muted);
            line-height: 1.65;
            margin-bottom: 18px;
            flex-grow: 1;
        }
        .trust-card-footer {
            padding-top: 14px;
            border-top: 1px solid var(--c-line);
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--c-green-dark);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* ============================================
           TUKAR TAMBAH MANDIRI
           ============================================ */
        .tradein-sec {
            padding: 64px 0;
            background: var(--c-white);
            border-top: 1px solid var(--c-line);
            border-bottom: 1px solid var(--c-line);
        }
        .tradein-card-main {
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 32px;
            box-shadow: var(--shadow-md);
        }
        .tradein-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 28px;
        }
        @media (min-width: 860px) {
            .tradein-grid { grid-template-columns: 1fr 1fr; gap: 36px; }
        }
        .step-title {
            font-size: 1rem;
            font-weight: 800;
            color: var(--c-ink);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .step-num {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: var(--c-ink);
            color: #ffffff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
        }
        .step-num.green { background: var(--c-green); }
        .form-group {
            margin-bottom: 14px;
        }
        .form-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--c-body);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .form-input, .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            font-size: 0.85rem;
            color: var(--c-ink);
            background: var(--c-white);
            transition: all 0.15s ease;
        }
        .form-input:focus, .form-select:focus {
            border-color: var(--c-ink);
            box-shadow: 0 0 0 3px rgba(15,23,42,0.06);
        }
        .tradein-result-box {
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-lg);
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            box-shadow: var(--shadow-sm);
        }
        .tradein-result-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            border-bottom: 1px solid var(--c-line-soft);
            padding-bottom: 10px;
        }
        .tradein-result-row:last-child {
            border-bottom: none;
            padding-bottom: 0;
            padding-top: 4px;
        }

        /* ============================================
           TESTIMONI PELANGGAN (CLEAN & PROFESSIONAL)
           ============================================ */
        .testi-sec {
            padding: 68px 0;
            background: var(--c-bg);
            border-bottom: 1px solid var(--c-line);
        }
        
        /* Stats Summary Box */
        .testi-stats-banner {
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 20px 24px;
            margin-bottom: 32px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            box-shadow: var(--shadow-sm);
        }
        .testi-stats-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .testi-rating-huge {
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--c-ink);
            line-height: 1;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .testi-rating-stars {
            color: var(--c-amber);
            font-size: 0.95rem;
            margin-bottom: 4px;
        }
        .testi-rating-count {
            font-size: 0.78rem;
            color: var(--c-muted);
            font-weight: 600;
        }
        .testi-badges-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .testi-trust-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--r-pill);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--c-ink);
        }
        .testi-trust-pill i { color: var(--c-green); }

        /* Testimonials Grid */
        .testi-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media (min-width: 640px) { .testi-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (min-width: 1020px) { .testi-grid { grid-template-columns: repeat(3, 1fr); gap: 24px; } }

        .testi-card {
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: var(--shadow-card);
            transition: all 0.25s ease;
            position: relative;
        }
        .testi-card:hover {
            transform: translateY(-4px);
            border-color: #cbd5e1;
            box-shadow: var(--shadow-card-hover);
        }
        .testi-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .testi-stars {
            color: var(--c-amber);
            font-size: 0.85rem;
            display: flex;
            gap: 3px;
        }
        .testi-verified-pill {
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--c-green);
            background: var(--c-green-light);
            border: 1px solid var(--c-green-border);
            padding: 3px 8px;
            border-radius: var(--r-pill);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .testi-car-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--c-ink);
            background: var(--c-wash);
            border: 1px solid var(--c-line);
            padding: 5px 10px;
            border-radius: var(--r-xs);
            margin-bottom: 14px;
        }
        .testi-car-pill i { color: var(--c-muted); font-size: 0.75rem; }

        .testi-quote {
            font-size: 0.85rem;
            color: var(--c-body);
            line-height: 1.65;
            margin-bottom: 20px;
            flex-grow: 1;
            position: relative;
        }

        .testi-author {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 14px;
            border-top: 1px solid var(--c-line);
        }
        .testi-avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 800;
            flex-shrink: 0;
        }
        .testi-author-info {
            flex: 1;
            min-width: 0;
        }
        .testi-author-name {
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--c-ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .testi-author-sub {
            font-size: 0.72rem;
            color: var(--c-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Clean Light Testimonial Bottom CTA */
        .testi-bottom-cta {
            margin-top: 36px;
            background: var(--c-white);
            border: 1px solid var(--c-line);
            border-radius: var(--r-xl);
            padding: 28px 32px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        .testi-bottom-cta:hover {
            border-color: #cbd5e1;
            box-shadow: var(--shadow-md);
        }
        .testi-cta-info {
            max-width: 680px;
        }
        .testi-cta-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--c-green);
            background: var(--c-green-light);
            border: 1px solid var(--c-green-border);
            padding: 3px 10px;
            border-radius: var(--r-pill);
            margin-bottom: 8px;
        }
        .testi-cta-title {
            font-size: 1.25rem;
            font-weight: 800;
            color: var(--c-ink);
            margin-bottom: 4px;
            letter-spacing: -0.02em;
        }
        .testi-cta-desc {
            font-size: 0.85rem;
            color: var(--c-muted);
            line-height: 1.6;
        }
        @media (min-width: 768px) {
            .testi-bottom-cta {
                flex-direction: row;
                align-items: center;
            }
        }

        /* ============================================
           FOOTER
           ============================================ */
        .foot {
            background: #0b0f19;
            color: #94a3b8;
            padding: 56px 0 28px;
            border-top: 1px solid #1e293b;
        }
        .foot-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 36px;
            margin-bottom: 36px;
        }
        @media (min-width: 768px) { .foot-grid { grid-template-columns: 1.4fr 1.1fr 0.9fr; } }
        
        .foot-brand-title {
            font-size: 1.1rem;
            font-weight: 900;
            color: #ffffff;
            margin-bottom: 12px;
            letter-spacing: -0.02em;
        }
        .foot-title {
            font-size: 0.78rem;
            font-weight: 800;
            color: #f1f5f9;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 16px;
        }
        .foot-text {
            font-size: 0.85rem;
            line-height: 1.7;
            color: #94a3b8;
        }
        .foot-contact-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 12px;
            font-size: 0.85rem;
            color: #cbd5e1;
        }
        .foot-contact-row i { margin-top: 4px; font-size: 0.85rem; color: #64748b; }
        .foot-hours {
            background: rgba(255,255,255,0.03);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: var(--r-md);
            padding: 14px;
            font-size: 0.8rem;
        }
        .foot-hours-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .foot-hours-row:last-child { margin-bottom: 0; }
        .foot-hours-row span:last-child { color: #f1f5f9; font-weight: 700; }
        .foot-copy {
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.07);
            text-align: center;
            font-size: 0.75rem;
            color: #64748b;
        }

        /* ============================================
           FLOATING WHATSAPP BUTTON
           ============================================ */
        .float-wa {
            position: fixed;
            bottom: 24px; right: 24px;
            z-index: 90;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            background: var(--c-wa);
            color: #ffffff;
            font-size: 0.85rem;
            font-weight: 800;
            border-radius: var(--r-pill);
            box-shadow: 0 6px 24px rgba(37, 211, 102, 0.35);
            transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .float-wa:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 10px 28px rgba(37, 211, 102, 0.45);
            background: var(--c-wa-dark);
        }
        .float-wa-text { display: none; }
        @media (min-width: 640px) { .float-wa-text { display: inline; } }

        /* ============================================
           DETAIL MODAL
           ============================================ */
        .modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 200;
            display: none;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            overflow-y: auto;
            padding: 24px 16px;
        }
        .modal-overlay.open {
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }
        .modal-box {
            background: var(--c-white);
            border-radius: var(--r-xl);
            max-width: 720px;
            width: 100%;
            margin: auto;
            overflow: hidden;
            box-shadow: var(--shadow-xl);
            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalPop {
            from { opacity: 0; transform: translateY(12px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--c-line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--c-wash);
        }
        .modal-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--c-ink);
        }
        .modal-subtitle {
            font-size: 0.78rem;
            color: var(--c-muted);
            margin-top: 1px;
        }
        .modal-close {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--c-white);
            border: 1px solid var(--c-line);
            color: var(--c-muted);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.15s;
        }
        .modal-close:hover {
            background: var(--c-line-soft);
            color: var(--c-ink);
            border-color: var(--c-subtle);
        }

        .modal-scroll {
            padding: 20px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .modal-scroll > * + * { margin-top: 16px; }

        .modal-main-img {
            width: 100%;
            aspect-ratio: 16/10;
            border-radius: var(--r-md);
            overflow: hidden;
            background: var(--c-wash);
            border: 1px solid var(--c-line);
        }
        .modal-main-img img { width: 100%; height: 100%; object-fit: cover; }

        .modal-thumbs {
            display: flex;
            gap: 8px;
            overflow-x: auto;
        }
        .modal-thumb {
            width: 68px; height: 48px;
            border-radius: var(--r-sm);
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            flex-shrink: 0;
            background: var(--c-wash);
        }
        .modal-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .modal-thumb.on { border-color: var(--c-ink); }

        .modal-price-row {
            border: 1px solid var(--c-line);
            border-radius: var(--r-md);
            padding: 14px 16px;
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            justify-content: space-between;
            align-items: center;
            background: var(--c-wash);
        }
        .modal-price-label {
            font-size: 0.68rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--c-muted);
        }
        .modal-price-val {
            font-size: 1.35rem;
            font-weight: 900;
            color: var(--c-ink);
        }
        .modal-installment-val {
            font-size: 0.95rem;
            font-weight: 800;
            color: var(--c-green);
        }

        .modal-section-title {
            font-size: 0.78rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--c-ink);
            margin-bottom: 10px;
        }
        .modal-specs-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px;
        }
        @media (min-width: 480px) { .modal-specs-grid { grid-template-columns: repeat(3, 1fr); } }
        
        .modal-spec-cell {
            padding: 10px 12px;
            border: 1px solid var(--c-line);
            border-radius: var(--r-sm);
            background: var(--c-wash);
        }
        .modal-spec-cell-label {
            font-size: 0.62rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--c-subtle);
            font-weight: 700;
        }
        .modal-spec-cell-val {
            font-size: 0.85rem;
            font-weight: 800;
            color: var(--c-ink);
            margin-top: 2px;
        }

        .modal-block {
            border: 1px solid var(--c-line);
            border-radius: var(--r-md);
            padding: 14px 16px;
            background: var(--c-white);
        }
        .modal-checks {
            display: grid;
            grid-template-columns: 1fr;
            gap: 8px;
        }
        @media (min-width: 480px) { .modal-checks { grid-template-columns: 1fr 1fr; } }
        .modal-check-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--c-body);
        }
        .modal-check-item i { color: var(--c-green); font-size: 0.85rem; }

        .modal-notes-text {
            font-size: 0.82rem;
            color: var(--c-muted);
            line-height: 1.6;
        }

        .modal-footer {
            padding: 14px 20px;
            border-top: 1px solid var(--c-line);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            background: var(--c-wash);
        }
    </style>
</head>
<body>

    <!-- TOP PROMO BANNER (IF ACTIVE) -->
    <?php if (!empty($activePromotions)): ?>
    <div class="promo-top-banner">
        <div class="w" style="display:flex; align-items:center; justify-content:center; gap:8px;">
            <i class="fa-solid fa-tag" style="font-size:0.75rem;"></i>
            <span><strong><?php echo htmlspecialchars($activePromotions[0]['promo_badge']); ?>:</strong> <?php echo htmlspecialchars(!empty($activePromotions[0]['banner_text']) ? $activePromotions[0]['banner_text'] : $activePromotions[0]['title']); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- NAVIGATION -->
    <nav class="nav">
        <div class="w">
            <div class="nav-inner">
                <a href="index.php" class="nav-brand">
                    <?php if (!empty($logoUrl)): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="<?php echo htmlspecialchars($settings['company_name']); ?>" class="nav-brand-logo">
                    <?php else: ?>
                        <div class="nav-brand-fallback"><?php echo strtoupper(substr($settings['company_name'], 0, 2)); ?></div>
                    <?php endif; ?>
                    <div class="nav-brand-info">
                        <span class="nav-brand-name"><?php echo htmlspecialchars($settings['company_name']); ?></span>
                        <span class="nav-brand-tag">Buka Hari Ini</span>
                    </div>
                </a>

                <div class="nav-links">
                    <a href="#katalog">Stok Mobil</a>
                    <a href="#simulasi">Simulasi Kredit</a>
                    <a href="#keunggulan">Jaminan</a>
                    <a href="#tukar-tambah">Tukar Tambah</a>
                    <a href="#testimoni">Testimoni Customer</a>
                    <a href="#kontak">Kontak</a>
                </div>

                <div class="nav-right">
                    <a href="tel:<?php echo htmlspecialchars($settings['company_phone']); ?>" class="nav-phone">
                        <i class="fa-solid fa-phone" style="font-size:0.75rem; color:var(--c-muted);"></i>
                        <?php echo htmlspecialchars($settings['company_phone']); ?>
                    </a>
                    <a href="https://wa.me/<?php echo $waPhone; ?>" target="_blank" class="nav-wa">
                        <i class="fa-brands fa-whatsapp" style="font-size:0.9rem;"></i> Hubungi Kami
                    </a>
                    <button type="button" id="menuToggle" class="nav-hamburger" aria-label="Menu">
                        <i class="fa-solid fa-bars"></i>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Mobile menu -->
    <div id="mobileMenu" class="mob-menu">
        <a href="#katalog" class="mob-link"><span>Stok Mobil</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="#simulasi" class="mob-link"><span>Simulasi Kredit</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="#keunggulan" class="mob-link"><span>Jaminan Kualitas</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="#tukar-tambah" class="mob-link"><span>Tukar Tambah</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="#testimoni" class="mob-link"><span>Testimoni Customer</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="#kontak" class="mob-link"><span>Kontak Showroom</span> <i class="fa-solid fa-angle-right" style="font-size:0.75rem; color:var(--c-subtle);"></i></a>
        <a href="../dashboard/" style="margin-top:16px; color:var(--c-accent); font-weight:700;"><span>Login Staf Admin</span> <i class="fa-solid fa-arrow-right-to-bracket" style="font-size:0.8rem;"></i></a>
    </div>

    <!-- HERO SECTION -->
    <section class="hero">
        <div class="w">
            <div class="hero-inner">
                <div>
                    <div class="hero-badge">
                        <i class="fa-solid fa-shield-halved"></i> Showroom Mobil Bekas Terpercaya
                    </div>
                    <h1>Mobil Bekas Berkualitas,<br>Siap Pakai & Bergaransi</h1>
                    <p class="hero-text">
                        Temukan unit mobil idaman dengan kondisi istimewa di <?php echo htmlspecialchars($settings['company_name']); ?>. Semua kendaraan telah lulus uji inspeksi menyeluruh, bebas banjir & tabrakan, dengan dokumen legalitas 100% terjamin asli.
                    </p>
                    
                    <div class="hero-stats">
                        <div class="hero-stat-item">
                            <div class="hero-stat-num"><?php echo $totalStock; ?></div>
                            <div class="hero-stat-label">Unit Tersedia</div>
                        </div>
                        <div style="width:1px; background:var(--c-line);"></div>
                        <div class="hero-stat-item">
                            <div class="hero-stat-num"><?php echo $totalBrands; ?></div>
                            <div class="hero-stat-label">Merek Pilihan</div>
                        </div>
                        <div style="width:1px; background:var(--c-line);"></div>
                        <div class="hero-stat-item">
                            <div class="hero-stat-num" style="color:var(--c-green);">4.9 / 5.0</div>
                            <div class="hero-stat-label">Rating Kepuasan</div>
                        </div>
                    </div>

                    <div class="hero-cta-row">
                        <a href="#katalog" class="btn-primary">
                            Lihat Stok Mobil <i class="fa-solid fa-arrow-down" style="font-size:0.75rem;"></i>
                        </a>
                        <a href="#simulasi" class="btn-outline">
                            <i class="fa-solid fa-calculator"></i> Simulasi Kredit
                        </a>
                        <a href="#testimoni" class="btn-outline">
                            <i class="fa-solid fa-comments"></i> Ulasan Pembeli
                        </a>
                    </div>
                </div>

                <?php if ($featuredVehicle): ?>
                <div>
                    <div class="hero-featured">
                        <div class="hero-featured-img">
                            <span class="hero-featured-tag">Unit Pilihan Terbaru</span>
                            <img src="<?php echo htmlspecialchars($featuredVehicle['primary_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($featuredVehicle['brand_name'] . ' ' . $featuredVehicle['model_name']); ?>"
                                 onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=800&q=80';">
                        </div>
                        <div class="hero-featured-body">
                            <div class="hero-featured-name"><?php echo htmlspecialchars($featuredVehicle['brand_name'] . ' ' . $featuredVehicle['model_name']); ?></div>
                            <div class="hero-featured-meta"><?php echo $featuredVehicle['year']; ?> &middot; <?php echo ucfirst($featuredVehicle['transmission'] === 'automatic' ? 'Matic' : $featuredVehicle['transmission']); ?> &middot; <?php echo number_format($featuredVehicle['mileage'] ?? 0, 0, ',', '.'); ?> KM</div>
                            <div class="hero-featured-footer">
                                <div>
                                    <div style="font-size:0.65rem; color:var(--c-subtle); font-weight:700; text-transform:uppercase;">Harga OTR</div>
                                    <div class="hero-featured-price"><?php echo $featuredVehicle['formatted_price']; ?></div>
                                </div>
                                <button type="button" onclick="openModal(<?php echo $featuredVehicle['id']; ?>)" class="btn-primary" style="padding:8px 16px; font-size:0.78rem;">Lihat Detail</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- FILTER BAR -->
    <section class="filter-bar">
        <div class="w">
            <form id="filterForm" onsubmit="event.preventDefault(); applyFilters();">
                <div class="filter-row">
                    <div class="filter-search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Cari merek, model, tahun..." class="filter-search">
                        <span id="clearSearch" class="filter-clear-btn" onclick="clearSearch()"><i class="fa-solid fa-xmark"></i></span>
                    </div>
                    <select id="brandSelect" onchange="applyFilters()" class="filter-sel">
                        <option value="">Semua Merek</option>
                        <?php foreach ($brands as $b): ?>
                            <option value="<?php echo htmlspecialchars($b['name']); ?>"><?php echo htmlspecialchars($b['name']); ?> (<?php echo $b['total_available']; ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <select id="typeSelect" onchange="applyFilters()" class="filter-sel">
                        <option value="">Semua Tipe Bodi</option>
                        <?php foreach ($carTypes as $ct): ?>
                            <option value="<?php echo htmlspecialchars($ct['name']); ?>"><?php echo htmlspecialchars($ct['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="transSelect" onchange="applyFilters()" class="filter-sel">
                        <option value="">Semua Transmisi</option>
                        <option value="automatic">Automatic (Matic)</option>
                        <option value="manual">Manual</option>
                    </select>
                    <button type="button" onclick="resetFilters()" class="filter-reset">Reset</button>
                </div>
            </form>
        </div>
    </section>

    <!-- BRAND CHIPS -->
    <section class="chips-bar">
        <div class="w">
            <div class="chips-scroll" id="chipsList">
                <button type="button" onclick="pickBrand('')" class="chip on">Semua Merek (<?php echo $totalStock; ?>)</button>
                <?php foreach ($brands as $b): if ($b['total_available'] > 0): ?>
                <button type="button" onclick="pickBrand('<?php echo htmlspecialchars($b['name']); ?>')" class="chip"><?php echo htmlspecialchars($b['name']); ?> (<?php echo $b['total_available']; ?>)</button>
                <?php endif; endforeach; ?>
            </div>
        </div>
    </section>

    <!-- CATALOG -->
    <section class="catalog" id="katalog">
        <div class="w">
            <div class="catalog-top">
                <div class="catalog-title">
                    <span>Stok Mobil Tersedia</span>
                    <span class="catalog-count" id="countLabel"><?php echo $totalStock; ?> unit</span>
                </div>
                <div class="catalog-sort">
                    <label for="sortSelect">Urutkan:</label>
                    <select id="sortSelect" onchange="doSort()" class="sort-sel">
                        <option value="newest">Terbaru Ditambahkan</option>
                        <option value="price_low">Harga Terendah</option>
                        <option value="price_high">Harga Tertinggi</option>
                        <option value="year_high">Tahun Termuda</option>
                        <option value="mileage_low">Kilometer Terendah</option>
                    </select>
                </div>
            </div>

            <div id="carGrid" class="grid"></div>

            <div id="emptyBox" class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-car-side"></i></div>
                <h3 style="font-size:1.05rem; font-weight:800; color:var(--c-ink); margin-bottom:6px;">Tidak Ada Unit Ditemukan</h3>
                <p style="font-size:0.85rem; color:var(--c-muted); margin-bottom:18px;">Coba ubah kriteria filter atau hubungi sales kami untuk request unit idaman Anda.</p>
                <button type="button" onclick="resetFilters()" class="btn-primary" style="font-size:0.8rem; padding:8px 18px;">Reset Semua Filter</button>
            </div>
        </div>
    </section>

    <!-- CREDIT SIMULATOR -->
    <section class="sim" id="simulasi">
        <div class="w">
            <div class="sim-inner">
                <div>
                    <div class="sec-kicker">Simulasi Pembiayaan</div>
                    <h2 class="sec-title" style="text-align:left; margin-bottom:12px;">Simulasi Kredit Mobil</h2>
                    <p class="sec-desc" style="text-align:left; margin-bottom:24px;">
                        Hitung estimasi Down Payment (DP) dan angsuran bulanan yang sesuai dengan anggaran Anda. Pengajuan kredit dibantu proses approval cepat melalui rekanan leasing resmi berizin OJK.
                    </p>
                    
                    <div class="sim-partners">
                        <div class="sim-partners-title"><i class="fa-solid fa-handshake" style="color:var(--c-green); margin-right:4px;"></i> Rekanan Lembaga Pembiayaan Resmi:</div>
                        <div class="sim-partners-list">
                            <span class="sim-partner-tag">BCA Finance</span>
                            <span class="sim-partner-tag">Mandiri Utama Finance</span>
                            <span class="sim-partner-tag">Adira Finance</span>
                            <span class="sim-partner-tag">OTO Multiartha</span>
                            <span class="sim-partner-tag">Maybank Finance</span>
                            <span class="sim-partner-tag">BSI OTO (Syariah)</span>
                        </div>
                    </div>
                </div>

                <div>
                    <div class="sim-box">
                        <div class="sim-field">
                            <div class="sim-field-top">
                                <span class="sim-field-label">Harga Kendaraan (OTR)</span>
                                <span class="sim-field-value" id="simPriceLabel">Rp 200.000.000</span>
                            </div>
                            <input type="range" id="simPriceSlider" min="50000000" max="1000000000" step="5000000" value="200000000" class="sim-slider" oninput="calcLoan()">
                            <div class="sim-slider-ticks"><span>Rp 50 Jt</span><span>Rp 500 Jt</span><span>Rp 1 Miliar</span></div>
                        </div>

                        <div class="sim-field">
                            <div class="sim-field-top">
                                <span class="sim-field-label">Pilihan DP (<span id="simDpPct">20</span>%)</span>
                                <span class="sim-field-value" id="simDpNom" style="font-size:0.85rem; color:var(--c-green-dark); font-weight:800;">Rp 40.000.000</span>
                            </div>
                            <div class="sim-btns c4">
                                <button type="button" onclick="setDp(20)" class="sim-opt dp-btn on">20%</button>
                                <button type="button" onclick="setDp(30)" class="sim-opt dp-btn">30%</button>
                                <button type="button" onclick="setDp(40)" class="sim-opt dp-btn">40%</button>
                                <button type="button" onclick="setDp(50)" class="sim-opt dp-btn">50%</button>
                            </div>
                        </div>

                        <div class="sim-field">
                            <div class="sim-field-top">
                                <span class="sim-field-label">Pilihan Tenor / Jangka Waktu</span>
                            </div>
                            <div class="sim-btns c5">
                                <button type="button" onclick="setTenor(12)" class="sim-opt tenor-btn">1 Thn</button>
                                <button type="button" onclick="setTenor(24)" class="sim-opt tenor-btn">2 Thn</button>
                                <button type="button" onclick="setTenor(36)" class="sim-opt tenor-btn">3 Thn</button>
                                <button type="button" onclick="setTenor(48)" class="sim-opt tenor-btn on">4 Thn</button>
                                <button type="button" onclick="setTenor(60)" class="sim-opt tenor-btn">5 Thn</button>
                            </div>
                        </div>

                        <div class="sim-result">
                            <div class="sim-result-flex">
                                <div>
                                    <div class="sim-result-label">Estimasi Angsuran / Bulan</div>
                                    <div class="sim-result-num" id="simMonthly">Rp 4.400.000</div>
                                    <div class="sim-result-note">*Estimasi simulasi bunga flat 8%/tahun. Syarat & ketentuan leasing berlaku.</div>
                                </div>
                                <button type="button" onclick="sendSim()" class="btn-wa" style="white-space: nowrap; flex-shrink: 0;">
                                    <i class="fa-brands fa-whatsapp"></i> Ajukan Kredit
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- STANDAR JAMINAN KUALITAS -->
    <section class="trust" id="keunggulan">
        <div class="w">
            <div class="sec-head">
                <div class="sec-kicker">Standar Kualitas & Inspeksi</div>
                <h2 class="sec-title">Standar Kualitas Kendaraan Showroom</h2>
                <p class="sec-desc">Setiap unit yang dipasarkan di <?php echo htmlspecialchars($settings['company_name']); ?> melewati tahapan verifikasi dokumen dan inspeksi teknis secara menyeluruh.</p>
            </div>
            
            <div class="trust-grid">
                <div class="trust-card">
                    <div class="trust-card-top">
                        <span class="trust-card-num">01</span>
                        <span class="trust-card-tag">Legalitas</span>
                    </div>
                    <h3 class="trust-card-title">Verifikasi Dokumen Resmi</h3>
                    <p class="trust-card-desc">Pemeriksaan keabsahan BPKB, STNK, Faktur resmi, serta pencocokan nomor rangka dan mesin di Samsat.</p>
                    <div class="trust-card-footer">
                        <span>Dokumen Terjamin Asli</span>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-card-top">
                        <span class="trust-card-num">02</span>
                        <span class="trust-card-tag">Struktur</span>
                    </div>
                    <h3 class="trust-card-title">Bebas Tabrakan & Banjir</h3>
                    <p class="trust-card-desc">Inspeksi ketat pada struktur tulang rangka utama, pilar bodi, firewall, kolong lantai, dan sistem kelistrikan.</p>
                    <div class="trust-card-footer">
                        <span>Rangka Utuh & Presisi</span>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-card-top">
                        <span class="trust-card-num">03</span>
                        <span class="trust-card-tag">Perlindungan</span>
                    </div>
                    <h3 class="trust-card-title">Garansi Mesin & Transmisi</h3>
                    <p class="trust-card-desc">Jaminan garansi showroom untuk performa mesin dan sistem transmisi pasca serah terima kendaraan kepada konsumen.</p>
                    <div class="trust-card-footer">
                        <span>Garansi Tertulis Showroom</span>
                    </div>
                </div>

                <div class="trust-card">
                    <div class="trust-card-top">
                        <span class="trust-card-num">04</span>
                        <span class="trust-card-tag">Transparansi</span>
                    </div>
                    <h3 class="trust-card-title">Kondisi & Harga Terbuka</h3>
                    <p class="trust-card-desc">Penjelasan kondisi fisik dan riwayat teknis unit secara transparan, dengan penawaran harga sesuai kondisi riil kendaraan.</p>
                    <div class="trust-card-footer">
                        <span>Tanpa Biaya Tersembunyi</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TUKAR TAMBAH MANDIRI -->
    <section class="tradein-sec" id="tukar-tambah">
        <div class="w">
            <div class="sec-head">
                <div class="sec-kicker">Kalkulator Tukar Tambah</div>
                <h2 class="sec-title">Simulasi Tukar Tambah Mobil Mandiri</h2>
                <p class="sec-desc">Dapatkan estimasi nilai taksasi mobil lama Anda secara instan dan hitung selisih biaya untuk upgrade ke mobil idaman di showroom kami.</p>
            </div>

            <div class="tradein-card-main">
                <div class="tradein-grid">
                    <!-- Sisi Kiri: Data Mobil Lama -->
                    <div>
                        <div class="step-title">
                            <span class="step-num">1</span>
                            <span>Data Mobil Lama Anda</span>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Merek & Tipe Mobil Lama</label>
                            <input type="text" id="ttOldCarName" placeholder="Contoh: Toyota Avanza 1.3 G" class="form-input" oninput="calcTradeIn()">
                        </div>

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                            <div>
                                <label class="form-label">Tahun Pembuatan</label>
                                <select id="ttOldYear" class="form-select" onchange="calcTradeIn()">
                                    <?php for ($y = date('Y'); $y >= 2005; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y == 2018 ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Transmisi</label>
                                <select id="ttOldTrans" class="form-select" onchange="calcTradeIn()">
                                    <option value="automatic">Automatic (Matic)</option>
                                    <option value="manual">Manual</option>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Kondisi Fisik & Mesin</label>
                            <select id="ttOldCondition" class="form-select" onchange="calcTradeIn()">
                                <option value="excellent">Istimewa / Sangat Mulus (Like New)</option>
                                <option value="good" selected>Baik & Terawat (Standar Pemakaian Wajar)</option>
                                <option value="fair">Cukup (Ada Baret / Perlu Servis Minor)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Perkiraan Harga Pasaran Mobil Anda (Rp)</label>
                            <input type="number" id="ttOldEstimatePrice" placeholder="Contoh: 140000000" min="0" step="1000000" value="135000000" class="form-input" oninput="calcTradeIn()">
                            <small style="font-size:0.72rem; color:var(--c-subtle); display:block; margin-top:3px;">Isi estimasi harga pasaran mobil Anda saat ini</small>
                        </div>
                    </div>

                    <!-- Sisi Kanan: Pilihan Mobil Showroom & Hasil Estimasi -->
                    <div>
                        <div class="step-title">
                            <span class="step-num green">2</span>
                            <span>Pilih Mobil Showroom Incaran</span>
                        </div>

                        <div class="form-group" style="margin-bottom:18px;">
                            <label class="form-label">Pilih Unit di Showroom Kami</label>
                            <select id="ttTargetCarSelect" class="form-select" style="font-weight:700;" onchange="calcTradeIn()">
                                <?php foreach ($vehicles as $idx => $v): ?>
                                <option value="<?php echo $v['id']; ?>" data-price="<?php echo $v['final_price']; ?>" data-name="<?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ')'); ?>" <?php echo $idx === 0 ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($v['brand_name'] . ' ' . $v['model_name'] . ' (' . $v['year'] . ') - ' . $v['formatted_price']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Hasil Kalkulasi Box -->
                        <div class="tradein-result-box">
                            <div class="tradein-result-row">
                                <span style="color:var(--c-muted); font-size:0.82rem;">Estimasi Taksasi Mobil Lama:</span>
                                <span style="font-weight:800; color:var(--c-ink);" id="ttResAppraisal">Rp 135.000.000</span>
                            </div>
                            <div class="tradein-result-row">
                                <span style="color:var(--c-muted); font-size:0.82rem;">Harga Unit Showroom:</span>
                                <span style="font-weight:800; color:var(--c-ink);" id="ttResTargetPrice">Rp 0</span>
                            </div>
                            <div class="tradein-result-row">
                                <span style="font-size:0.9rem; font-weight:800; color:var(--c-ink);" id="ttResDifferenceLabel">Sisa Biaya yang Dibayar (Cash):</span>
                                <span style="font-size:1.35rem; font-weight:900; color:var(--c-green);" id="ttResDifference">Rp 0</span>
                            </div>
                        </div>

                        <div style="margin-top:18px;">
                            <button type="button" onclick="sendTradeInWA()" class="btn-wa" style="width:100%; padding:12px; font-size:0.88rem;">
                                <i class="fa-brands fa-whatsapp" style="font-size:1.1rem;"></i> Ajukan Penawaran Trade-In via WhatsApp
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- TESTIMONI CUSTOMER (Clean & Professional) -->
    <section class="testi-sec" id="testimoni">
        <div class="w">
            <div class="sec-head">
                <div class="sec-kicker">Ulasan Pelanggan</div>
                <h2 class="sec-title">Apa Kata Pembeli di <?php echo htmlspecialchars($settings['company_name']); ?></h2>
                <p class="sec-desc">Pengalaman nyata para pelanggan yang telah mempercayakan pembelian mobil impian mereka di showroom kami dengan rasa puas dan aman.</p>
            </div>

            <!-- Stats Banner -->
            <div class="testi-stats-banner">
                <div class="testi-stats-left">
                    <div class="testi-rating-huge">
                        4.9 <i class="fa-solid fa-star" style="font-size:1.4rem; color:var(--c-amber);"></i>
                    </div>
                    <div>
                        <div class="testi-rating-stars">
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                            <i class="fa-solid fa-star"></i>
                        </div>
                        <div class="testi-rating-count">Skor Kepuasan dari 150+ Ulasan Pembeli</div>
                    </div>
                </div>

                <div class="testi-badges-row">
                    <div class="testi-trust-pill">
                        <i class="fa-solid fa-circle-check"></i> 100% Dokumen Terverifikasi
                    </div>
                    <div class="testi-trust-pill">
                        <i class="fa-solid fa-car-side"></i> 500+ Unit Terkirim Aman
                    </div>
                    <div class="testi-trust-pill">
                        <i class="fa-brands fa-google"></i> Google Reviews Terverifikasi
                    </div>
                </div>
            </div>

            <!-- Testimonials Grid (Clean cards) -->
            <div class="testi-grid">
                <?php foreach ($testimonials as $t): ?>
                <div class="testi-card">
                    <div>
                        <div class="testi-card-top">
                            <div class="testi-stars">
                                <?php for ($s = 0; $s < $t['rating']; $s++): ?>
                                <i class="fa-solid fa-star"></i>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <div class="testi-car-pill">
                            <i class="fa-solid fa-car-side"></i> Unit: <strong><?php echo htmlspecialchars($t['car'] . ' ' . $t['year']); ?></strong>
                        </div>

                        <p class="testi-quote"><?php echo htmlspecialchars($t['review']); ?></p>
                    </div>

                    <div class="testi-author">
                        <div class="testi-avatar" style="background-color: <?php echo $t['avatar_bg']; ?>;">
                            <?php echo $t['initials']; ?>
                        </div>
                        <div class="testi-author-info">
                            <div class="testi-author-name">
                                <?php echo htmlspecialchars($t['name']); ?>
                            </div>
                            <div class="testi-author-sub"><?php echo $t['date']; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Clean & Elegant Bottom CTA Card (No dark bar) -->
            <div class="testi-bottom-cta">
                <div class="testi-cta-info">
                    <div class="testi-cta-badge">
                        <i class="fa-solid fa-circle-check"></i> Layanan Konsultasi Gratis
                    </div>
                    <h3 class="testi-cta-title">Ingin Konsultasi Mobil Idaman Anda?</h3>
                    <p class="testi-cta-desc">Tim konsultan profesional kami siap membantu memberikan informasi ketersediaan unit, jadwal test drive, dan penawaran terbaik untuk Anda.</p>
                </div>
                <a href="https://wa.me/<?php echo $waPhone; ?>?text=Halo%20<?php echo urlencode($settings['company_name']); ?>,%20saya%20tertarik%20melihat%20katalog%20dan%20ingin%20konsultasi%20pembelian%20mobil." target="_blank" class="btn-wa" style="padding:12px 24px; font-size:0.85rem; white-space:nowrap;">
                    <i class="fa-brands fa-whatsapp"></i> Hubungi Konsultan Sales
                </a>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="foot" id="kontak">
        <div class="w">
            <div class="foot-grid">
                <div>
                    <div class="foot-brand-title"><?php echo htmlspecialchars($settings['company_name']); ?></div>
                    <p class="foot-text">Pusat jual beli mobil bekas berkualitas, amanah, dan terpercaya di Tasikmalaya. Menyediakan unit siap pakai dengan jaminan dokumen legalitas 100% asli serta layanan pembiayaan dan tukar tambah.</p>
                </div>
                <div>
                    <div class="foot-title">Informasi & Lokasi</div>
                    <div class="foot-contact-row">
                        <i class="fa-solid fa-location-dot"></i>
                        <span><?php echo nl2br(htmlspecialchars($settings['company_address'])); ?></span>
                    </div>
                    <div class="foot-contact-row">
                        <i class="fa-solid fa-phone"></i>
                        <span><?php echo htmlspecialchars($settings['company_phone']); ?></span>
                    </div>
                    <?php if (!empty($settings['company_email'])): ?>
                    <div class="foot-contact-row">
                        <i class="fa-solid fa-envelope"></i>
                        <span><?php echo htmlspecialchars($settings['company_email']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <div class="foot-title">Jam Operasional Showroom</div>
                    <div class="foot-hours">
                        <div class="foot-hours-row"><span>Senin - Sabtu</span><span>08.30 - 17.30 WIB</span></div>
                        <div class="foot-hours-row"><span>Minggu & Hari Libur</span><span>09.00 - 16.00 WIB</span></div>
                    </div>
                </div>
            </div>
            <div class="foot-copy">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($settings['company_name']); ?>. Hak Cipta Dilindungi Undang-Undang.</div>
        </div>
    </footer>

    <!-- FLOATING WHATSAPP -->
    <a href="https://wa.me/<?php echo $waPhone; ?>?text=Halo%20<?php echo urlencode($settings['company_name']); ?>,%20saya%20ingin%20bertanya%20tentang%20stok%20mobil%20yang%20tersedia." 
       target="_blank" class="float-wa" aria-label="WhatsApp Showroom">
        <i class="fa-brands fa-whatsapp" style="font-size:1.2rem;"></i>
        <span class="float-wa-text">Chat Sales Showroom</span>
    </a>

    <!-- DETAIL MODAL -->
    <div id="detailModal" class="modal-overlay" role="dialog" aria-modal="true">
        <div class="modal-box">
            <div class="modal-header">
                <div>
                    <div class="modal-title" id="modalTitle">Detail Kendaraan</div>
                    <div class="modal-subtitle" id="modalSubtitle">-</div>
                </div>
                <div class="modal-close" onclick="closeModal()"><i class="fa-solid fa-xmark"></i></div>
            </div>
            <div class="modal-scroll">
                <div>
                    <div class="modal-main-img"><img id="modalMainImg" src="" alt="Foto Mobil" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=800&q=80';"></div>
                    <div id="modalThumbs" class="modal-thumbs" style="margin-top:8px;"></div>
                </div>

                <div class="modal-price-row">
                    <div>
                        <div class="modal-price-label">Harga Tunai (Cash)</div>
                        <div class="modal-price-val" id="modalPrice">Rp 0</div>
                    </div>
                    <div>
                        <div class="modal-price-label">Estimasi Angsuran</div>
                        <div class="modal-installment-val" id="modalInstallment">Rp 0 / bln</div>
                    </div>
                </div>

                <div>
                    <div class="modal-section-title">Spesifikasi Kendaraan</div>
                    <div class="modal-specs-grid">
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Tahun</div><div class="modal-spec-cell-val" id="modalYear">-</div></div>
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Transmisi</div><div class="modal-spec-cell-val" id="modalTrans">-</div></div>
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Kilometer</div><div class="modal-spec-cell-val" id="modalKm">-</div></div>
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Bahan Bakar</div><div class="modal-spec-cell-val" id="modalFuel">-</div></div>
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Warna</div><div class="modal-spec-cell-val" id="modalColor">-</div></div>
                        <div class="modal-spec-cell"><div class="modal-spec-cell-label">Plat Nomor</div><div class="modal-spec-cell-val" id="modalPlate">-</div></div>
                    </div>
                </div>

                <div class="modal-block">
                    <div class="modal-section-title">Hasil Inspeksi & Jaminan</div>
                    <div class="modal-checks">
                        <div class="modal-check-item"><i class="fa-solid fa-circle-check"></i> Bebas Tabrakan Berat</div>
                        <div class="modal-check-item"><i class="fa-solid fa-circle-check"></i> Bebas Terendam Banjir</div>
                        <div class="modal-check-item"><i class="fa-solid fa-circle-check"></i> Dokumen Asli & Lengkap</div>
                        <div class="modal-check-item"><i class="fa-solid fa-circle-check"></i> <span id="modalWarranty">Garansi Showroom Aktif</span></div>
                    </div>
                </div>

                <div id="modalNotesWrap" class="modal-block" style="display:none;">
                    <div class="modal-section-title">Catatan Kondisi Unit</div>
                    <p class="modal-notes-text" id="modalNotesText">-</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-outline" onclick="closeModal()" style="padding:8px 16px; font-size:0.8rem;">Tutup</button>
                <a id="modalWaBtn" href="#" target="_blank" class="btn-wa" style="padding:8px 18px; font-size:0.8rem;">
                    <i class="fa-brands fa-whatsapp"></i> Tanya Unit via WhatsApp
                </a>
            </div>
        </div>
    </div>

    <!-- JAVASCRIPT -->
    <script>
        const vehicles = <?php echo json_encode($vehicles); ?>;
        const waNum = '<?php echo $waPhone; ?>';
        const shopName = <?php echo json_encode($settings['company_name']); ?>;

        let filters = { search: '', brand: '', type: '', trans: '', sort: 'newest' };

        document.addEventListener('DOMContentLoaded', () => {
            render();
            calcLoan();
            calcTradeIn();

            // Mobile menu
            const menuBtn = document.getElementById('menuToggle');
            const mobMenu = document.getElementById('mobileMenu');
            if (menuBtn && mobMenu) {
                menuBtn.addEventListener('click', () => {
                    mobMenu.classList.toggle('open');
                });
                document.querySelectorAll('.mob-link').forEach(el => {
                    el.addEventListener('click', () => mobMenu.classList.remove('open'));
                });
            }

            // Search debounce
            const si = document.getElementById('searchInput');
            const cb = document.getElementById('clearSearch');
            if (si) {
                si.addEventListener('input', debounce(() => {
                    filters.search = si.value.trim();
                    if (cb) cb.classList.toggle('on', filters.search.length > 0);
                    render();
                }, 200));
            }
        });

        function debounce(fn, ms) {
            let t;
            return function() { clearTimeout(t); t = setTimeout(fn, ms); };
        }

        function clearSearch() {
            const si = document.getElementById('searchInput');
            const cb = document.getElementById('clearSearch');
            if (si) si.value = '';
            if (cb) cb.classList.remove('on');
            filters.search = '';
            render();
        }

        function applyFilters() {
            filters.search = document.getElementById('searchInput').value.trim();
            filters.brand = document.getElementById('brandSelect').value;
            filters.type = document.getElementById('typeSelect').value;
            filters.trans = document.getElementById('transSelect').value;
            syncChips();
            render();
        }

        function pickBrand(b) {
            filters.brand = b;
            document.getElementById('brandSelect').value = b;
            syncChips();
            render();
        }

        function syncChips() {
            document.querySelectorAll('.chip').forEach(c => {
                const isAll = c.textContent.includes('Semua');
                if ((filters.brand === '' && isAll) || (filters.brand !== '' && c.textContent.includes(filters.brand))) {
                    c.classList.add('on');
                } else {
                    c.classList.remove('on');
                }
            });
        }

        function doSort() {
            filters.sort = document.getElementById('sortSelect').value;
            render();
        }

        function resetFilters() {
            filters = { search: '', brand: '', type: '', trans: '', sort: 'newest' };
            document.getElementById('searchInput').value = '';
            const cb = document.getElementById('clearSearch');
            if (cb) cb.classList.remove('on');
            document.getElementById('brandSelect').value = '';
            document.getElementById('typeSelect').value = '';
            document.getElementById('transSelect').value = '';
            document.getElementById('sortSelect').value = 'newest';
            syncChips();
            render();
        }

        function render() {
            const grid = document.getElementById('carGrid');
            const empty = document.getElementById('emptyBox');
            const label = document.getElementById('countLabel');

            let list = vehicles.filter(v => {
                if (filters.search) {
                    const hay = `${v.brand_name} ${v.model_name} ${v.year} ${v.license_plate} ${v.color} ${v.car_type_name}`.toLowerCase();
                    if (!hay.includes(filters.search.toLowerCase())) return false;
                }
                if (filters.brand && v.brand_name.toLowerCase() !== filters.brand.toLowerCase()) return false;
                if (filters.type && (!v.car_type_name || v.car_type_name.toLowerCase() !== filters.type.toLowerCase())) return false;
                if (filters.trans && v.transmission.toLowerCase() !== filters.trans.toLowerCase()) return false;
                return true;
            });

            list.sort((a, b) => {
                if (filters.sort === 'price_low') return a.selling_price - b.selling_price;
                if (filters.sort === 'price_high') return b.selling_price - a.selling_price;
                if (filters.sort === 'year_high') return (parseInt(b.year)||0) - (parseInt(a.year)||0);
                if (filters.sort === 'mileage_low') return (parseInt(a.mileage)||0) - (parseInt(b.mileage)||0);
                return b.id - a.id;
            });

            label.textContent = list.length + ' unit';

            if (list.length === 0) {
                grid.innerHTML = '';
                empty.classList.add('show');
                return;
            }

            empty.classList.remove('show');
            grid.innerHTML = list.map(v => buildCard(v)).join('');
        }

        function buildCard(v) {
            const waMsg = encodeURIComponent(`Halo ${shopName}, saya ingin tanya unit ${v.brand_name} ${v.model_name} (${v.year}) Plat: ${v.license_plate}. Apakah unit masih tersedia?`);
            const waLink = `https://wa.me/${waNum}?text=${waMsg}`;

            let badge = '';
            if (v.is_consignment == 1) {
                badge = `<span class="card-badge" style="background:#f59e0b; color:#fff; font-weight:800; border:none; box-shadow:0 2px 6px rgba(245,158,11,0.35);"><i class="fa-solid fa-handshake"></i> Titip Jual</span>`;
            } else if (v.promo_active && v.promo_badge) {
                badge = `<span class="card-badge" style="background:#ef4444; color:#fff; font-weight:800; border:none; box-shadow:0 2px 6px rgba(239,68,68,0.35);"><i class="fa-solid fa-tag"></i> ${v.promo_badge}</span>`;
            } else if (v.overall_grade) {
                badge = `<span class="card-badge"><i class="fa-solid fa-shield-halved"></i> Grade ${v.overall_grade}</span>`;
            } else if (v.warranty_type && v.warranty_type !== 'none') {
                badge = `<span class="card-badge"><i class="fa-solid fa-shield-halved"></i> Bergaransi</span>`;
            }

            let priceHtml = '';
            if (v.promo_active && v.formatted_original_price) {
                priceHtml = `
                    <div style="font-size:0.75rem; text-decoration:line-through; color:var(--c-subtle); font-weight:600; line-height:1; margin-bottom:2px;">${v.formatted_original_price}</div>
                    <div class="card-price" style="color:#ef4444;">${v.formatted_price}</div>
                `;
            } else {
                priceHtml = `<div class="card-price">${v.formatted_price}</div>`;
            }

            return `
            <div class="card">
                <div class="card-img">
                    ${badge}
                    <img src="${v.primary_image}" alt="${v.brand_name} ${v.model_name}" loading="lazy" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=800&q=80';">
                </div>
                <div class="card-body">
                    <div class="card-top-row">
                        <span class="card-type">${v.car_type_name || 'Mobil Bekas'}</span>
                        <span class="card-year">${v.year}</span>
                    </div>
                    <div class="card-name">${v.brand_name} ${v.model_name}</div>
                    <div class="card-specs">
                        <div class="card-spec">
                            <div class="card-spec-label">Transmisi</div>
                            <div class="card-spec-val">${v.transmission === 'automatic' ? 'Matic' : v.transmission}</div>
                        </div>
                        <div class="card-spec">
                            <div class="card-spec-label">Kilometer</div>
                            <div class="card-spec-val">${Number(v.mileage||0).toLocaleString('id-ID')} km</div>
                        </div>
                        <div class="card-spec">
                            <div class="card-spec-label">BBM</div>
                            <div class="card-spec-val">${v.fuel_type}</div>
                        </div>
                    </div>
                    <div class="card-pricing">
                        ${priceHtml}
                        <div class="card-installment"><i class="fa-solid fa-bolt" style="font-size:0.65rem;"></i> Mulai ${v.est_monthly_installment}/bln</div>
                    </div>
                    <div class="card-actions">
                        <button type="button" onclick="openModal(${v.id})" class="card-btn-detail">Lihat Detail</button>
                        <a href="${waLink}" target="_blank" class="card-btn-wa"><i class="fa-brands fa-whatsapp"></i> Chat Sales</a>
                    </div>
                </div>
            </div>`;
        }

        // ---- MODAL DETAIL ----
        function openModal(id) {
            const v = vehicles.find(x => x.id == id);
            if (!v) return;

            document.getElementById('modalTitle').textContent = `${v.brand_name} ${v.model_name}`;
            document.getElementById('modalSubtitle').textContent = `${v.year} \u2022 ${v.car_type_name||'Mobil'} \u2022 Plat ${v.license_plate||'-'}`;
            
            if (v.promo_active && v.formatted_original_price) {
                document.getElementById('modalPrice').innerHTML = `<span style="font-size:0.85rem; text-decoration:line-through; color:var(--c-subtle); display:block;">${v.formatted_original_price}</span> <span style="color:#ef4444;">${v.formatted_price}</span> <span style="font-size:0.68rem; background:#fee2e2; color:#ef4444; padding:2px 8px; border-radius:4px; font-weight:800; vertical-align:middle;"><i class="fa-solid fa-tag"></i> ${v.promo_badge}</span>`;
            } else {
                document.getElementById('modalPrice').textContent = v.formatted_price;
            }

            document.getElementById('modalInstallment').textContent = `${v.est_monthly_installment} / bln (DP ${v.est_dp})`;

            document.getElementById('modalYear').textContent = v.year;
            document.getElementById('modalTrans').textContent = v.transmission === 'automatic' ? 'Matic' : v.transmission;
            document.getElementById('modalKm').textContent = `${Number(v.mileage||0).toLocaleString('id-ID')} km`;
            document.getElementById('modalFuel').textContent = v.fuel_type;
            document.getElementById('modalColor').textContent = v.color || '-';
            document.getElementById('modalPlate').textContent = v.license_plate || '-';

            document.getElementById('modalMainImg').src = v.primary_image;

            const thumbs = document.getElementById('modalThumbs');
            thumbs.innerHTML = '';
            if (v.images_list && v.images_list.length > 1) {
                v.images_list.forEach((img, i) => {
                    const d = document.createElement('div');
                    d.className = 'modal-thumb' + (i === 0 ? ' on' : '');
                    d.innerHTML = `<img src="${img}" alt="Thumb" onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1552519507-da3b142c6e3d?auto=format&fit=crop&w=800&q=80';">`;
                    d.onclick = () => {
                        document.getElementById('modalMainImg').src = img;
                        document.querySelectorAll('.modal-thumb').forEach(t => t.classList.remove('on'));
                        d.classList.add('on');
                    };
                    thumbs.appendChild(d);
                });
                thumbs.style.display = 'flex';
            } else {
                thumbs.style.display = 'none';
            }

            const wEl = document.getElementById('modalWarranty');
            if (v.warranty_type && v.warranty_type !== 'none') {
                wEl.textContent = `Garansi Showroom (${v.warranty_period ? v.warranty_period + ' Hari' : 'Aktif'})`;
            } else {
                wEl.textContent = 'Garansi Inspeksi Showroom';
            }

            const nw = document.getElementById('modalNotesWrap');
            if (v.notes && v.notes.trim()) {
                document.getElementById('modalNotesText').textContent = v.notes;
                nw.style.display = 'block';
            } else {
                nw.style.display = 'none';
            }

            const waMsg = encodeURIComponent(`Halo ${shopName}, saya berminat dengan unit ${v.brand_name} ${v.model_name} (${v.year}) Plat: ${v.license_plate} seharga ${v.formatted_price}. Mohon info jadwal cek unit dan test drive.`);
            document.getElementById('modalWaBtn').href = `https://wa.me/${waNum}?text=${waMsg}`;

            document.getElementById('detailModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('detailModal').classList.remove('open');
            document.body.style.overflow = '';
        }

        document.getElementById('detailModal').addEventListener('click', e => {
            if (e.target.id === 'detailModal') closeModal();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeModal();
        });

        // ---- SIMULATOR KREDIT ----
        let dpRate = 20, tenor = 48;

        function setDp(r) {
            dpRate = r;
            document.querySelectorAll('.dp-btn').forEach(b => b.classList.toggle('on', b.textContent.includes(r+'%')));
            document.getElementById('simDpPct').textContent = r;
            calcLoan();
        }

        function setTenor(m) {
            tenor = m;
            const yr = (m/12)+' Thn';
            document.querySelectorAll('.tenor-btn').forEach(b => b.classList.toggle('on', b.textContent.includes(yr)));
            calcLoan();
        }

        function calcLoan() {
            const price = parseInt(document.getElementById('simPriceSlider').value) || 200000000;
            document.getElementById('simPriceLabel').textContent = 'Rp ' + price.toLocaleString('id-ID');

            const dp = price * (dpRate/100);
            document.getElementById('simDpNom').textContent = 'Rp ' + Math.round(dp).toLocaleString('id-ID');

            const loan = price - dp;
            const yrs = tenor / 12;
            const interest = loan * 0.08 * yrs;
            const monthly = (loan + interest) / tenor;
            document.getElementById('simMonthly').textContent = 'Rp ' + Math.round(monthly).toLocaleString('id-ID');
        }

        function sendSim() {
            const price = parseInt(document.getElementById('simPriceSlider').value) || 200000000;
            const dp = Math.round(price * (dpRate/100));
            const monthly = document.getElementById('simMonthly').textContent;
            const yr = tenor / 12;
            const txt = encodeURIComponent(`Halo ${shopName}, saya ingin konsultasi kredit mobil:\n\n- Harga OTR: Rp ${price.toLocaleString('id-ID')}\n- DP (${dpRate}%): Rp ${dp.toLocaleString('id-ID')}\n- Tenor: ${tenor} Bulan (${yr} Tahun)\n- Estimasi Angsuran: ${monthly}/bln\n\nMohon info persyaratan dan promo pembiayaan.`);
            window.open(`https://wa.me/${waNum}?text=${txt}`, '_blank');
        }

        // ---- KALKULATOR TUKAR TAMBAH MANDIRI ----
        function calcTradeIn() {
            const oldEstPrice = parseFloat(document.getElementById('ttOldEstimatePrice').value) || 0;
            const cond = document.getElementById('ttOldCondition').value;

            let condFactor = 1.0;
            if (cond === 'excellent') condFactor = 1.05;
            else if (cond === 'fair') condFactor = 0.90;

            const appraisedVal = Math.round(oldEstPrice * condFactor);
            document.getElementById('ttResAppraisal').textContent = 'Rp ' + appraisedVal.toLocaleString('id-ID');

            const targetSelect = document.getElementById('ttTargetCarSelect');
            const targetOption = targetSelect.options[targetSelect.selectedIndex];
            const targetPrice = targetOption ? (parseFloat(targetOption.dataset.price) || 0) : 0;

            document.getElementById('ttResTargetPrice').textContent = 'Rp ' + targetPrice.toLocaleString('id-ID');

            const diff = targetPrice - appraisedVal;
            const diffLabel = document.getElementById('ttResDifferenceLabel');
            const diffEl = document.getElementById('ttResDifference');

            if (diff > 0) {
                diffLabel.textContent = 'Sisa Biaya yang Dibayar (Cash):';
                diffEl.textContent = 'Rp ' + diff.toLocaleString('id-ID');
                diffEl.style.color = 'var(--c-green)';
            } else if (diff < 0) {
                diffLabel.textContent = 'Kelebihan Dana (Showroom Mengembalikan):';
                diffEl.textContent = 'Rp ' + Math.abs(diff).toLocaleString('id-ID');
                diffEl.style.color = '#2563eb';
            } else {
                diffLabel.textContent = 'Selisih Biaya:';
                diffEl.textContent = 'Rp 0 (Tukar Pas)';
                diffEl.style.color = 'var(--c-ink)';
            }
        }

        function sendTradeInWA() {
            const oldCar = document.getElementById('ttOldCarName').value.trim() || 'Mobil Lama';
            const oldYear = document.getElementById('ttOldYear').value;
            const oldTrans = document.getElementById('ttOldTrans').options[document.getElementById('ttOldTrans').selectedIndex].text;
            const oldCond = document.getElementById('ttOldCondition').options[document.getElementById('ttOldCondition').selectedIndex].text;
            const appraisedVal = document.getElementById('ttResAppraisal').textContent;

            const targetSelect = document.getElementById('ttTargetCarSelect');
            const targetOption = targetSelect.options[targetSelect.selectedIndex];
            const targetCarName = targetOption ? targetOption.dataset.name : '-';
            const targetCarPrice = document.getElementById('ttResTargetPrice').textContent;
            const diffText = document.getElementById('ttResDifference').textContent;
            const diffLabel = document.getElementById('ttResDifferenceLabel').textContent;

            const msg = encodeURIComponent(
                `Halo ${shopName}, saya ingin mengajukan simulasi TUKAR TAMBAH mobil:\n\n` +
                `*DATA MOBIL LAMA:*\n` +
                `- Merek / Tipe : ${oldCar}\n` +
                `- Tahun        : ${oldYear}\n` +
                `- Transmisi    : ${oldTrans}\n` +
                `- Kondisi Fisik: ${oldCond}\n` +
                `- Estimasi Nilai Taksasi: ${appraisedVal}\n\n` +
                `*UNIT SHOWROOM INCARAN:*\n` +
                `- Unit Dipilih : ${targetCarName}\n` +
                `- Harga Unit   : ${targetCarPrice}\n\n` +
                `*ESTIMASI SELISIH:* ${diffLabel} ${diffText}\n\n` +
                `Mohon info ketersediaan unit dan jadwal appraisal fisik di showroom.`
            );

            window.open(`https://wa.me/${waNum}?text=${msg}`, '_blank');
        }
    </script>
</body>
</html>
