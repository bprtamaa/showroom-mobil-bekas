<?php
// includes/header.php
// Start output buffering to avoid 'headers already sent' warnings when helpers call header()/redirect
if (!ob_get_level()) {
    ob_start();
}

// Prevent browser caching for authenticated pages
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past
}

require_once '../config/config.php';
if (file_exists(__DIR__ . '/aging_helpers.php')) {
    require_once __DIR__ . '/aging_helpers.php';
}

// Pastikan session sudah dimulai
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Normalize and initialize common session keys to avoid undefined index warnings
if (!isset($_SESSION['user_id'])) {
    $_SESSION['user_id'] = null;
}
// Support older key 'role' if present, normalize to 'user_role'
if (!isset($_SESSION['user_role']) && isset($_SESSION['role'])) {
    $_SESSION['user_role'] = $_SESSION['role'];
}
if (!isset($_SESSION['user_role'])) {
    $_SESSION['user_role'] = null;
}
// Normalize name keys
if (!isset($_SESSION['user_name']) && isset($_SESSION['full_name'])) {
    $_SESSION['user_name'] = $_SESSION['full_name'];
}
if (!isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = null;
}

// Fungsi requireLogin
if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id'])) {
            // Use redirect helper which handles headers-sent fallback
            redirect('auth/login.php');
        }
    }
}

// Fungsi getAlert
if (!function_exists('getAlert')) {
    function getAlert() {
        if (!empty($_SESSION['alert'])) {
            $alert = $_SESSION['alert'];
            unset($_SESSION['alert']);
            return $alert;
        }
        return false;
    }
}

// Fungsi formatDate global — gunakan jika belum ada
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'd M Y H:i') {
        if (empty($date) || $date === '0000-00-00' || $date === null) {
            return '-';
        }
        $ts = strtotime($date);
        if ($ts === false) return $date; // fallback to raw value if unparsable
        
        $formatted = date($format, $ts);
        
        // Translate months to Indonesian
        $en_months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $id_months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        
        $en_short_months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $id_short_months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        
        $formatted = str_replace($en_months, $id_months, $formatted);
        $formatted = str_replace($en_short_months, $id_short_months, $formatted);
        
        return $formatted;
    }
}

// Generate CSRF token helper
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Sanitize input helper
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($str) {
        return htmlspecialchars(trim((string)$str), ENT_QUOTES, 'UTF-8');
    }
}

// showAlert helper: store an alert in session for display in header
if (!function_exists('showAlert')) {
    function showAlert($msg, $type = 'success') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['alert'] = [
            'message' => $msg,
            'type' => $type
        ];
    }
}

// Format currency helper (IDR)
if (!function_exists('formatCurrency')) {
    function formatCurrency($value) {
        $n = is_numeric($value) ? (float)$value : 0.0;
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}

// Parse currency helper (IDR string to integer/float)
if (!function_exists('parseCurrency')) {
    function parseCurrency($value) {
        return (int)preg_replace('/[^0-9]/', '', (string)$value);
    }
}

// Verify CSRF token helper
if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
    }
}

// Redirect helper
if (!function_exists('redirect')) {
    function redirect($url) {
        // Build target URL
        $target = (!empty($url) && (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0))
            ? $url
            : (defined('BASE_URL') ? BASE_URL : '/') . ltrim($url, '/');

        // If headers not sent, use PHP header; otherwise use JS fallback
        if (!headers_sent()) {
            header('Location: ' . $target);
            exit;
        }

        // Fallback: output JS redirect
        echo '<script>window.location.href=' . json_encode($target) . ';</script>';
        // Try meta refresh as extra fallback
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target) . '"></noscript>';
        // Flush buffer and exit
        if (ob_get_level()) {
            @ob_end_flush();
        }
        exit;
    }
}

// Role helpers
if (!function_exists('isAdmin')) {
    function isAdmin() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        // Check both user_role and role (for backward compatibility)
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
        return !empty($role) && strtolower(trim((string)$role)) === 'admin';
    }
}

if (!function_exists('isStaff')) {
    function isStaff() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        // Check both user_role and role (for backward compatibility)
        $role = $_SESSION['user_role'] ?? $_SESSION['role'] ?? null;
        return !empty($role) && strtolower(trim((string)$role)) === 'staff';
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isAdmin()) {
            showAlert('Anda tidak memiliki akses ke halaman ini!', 'danger');
            redirect('dashboard/');
        }
    }
}

if (!function_exists('requireStaffOrAdmin')) {
    function requireStaffOrAdmin() {
        if (session_status() === PHP_SESSION_NONE) session_start();
        if (!isAdmin() && !isStaff()) {
            showAlert('Anda tidak memiliki akses ke halaman ini!', 'danger');
            redirect('dashboard/');
        }
    }
}


?>
<?php
// Try to load company logo, name, and favicon from settings (safe, non-fatal)
$siteLogo = '';
$siteName = defined('APP_NAME') ? APP_NAME : 'Showroom';
$siteFavicon = '';
$siteBrandBg = '';
try {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $dbconn = $database->getConnection();
        $sstmt = $dbconn->query("SELECT company_logo, company_name, login_background FROM settings WHERE id = 1");
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow) {
            if (!empty($srow['company_logo']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['company_logo'])) {
                // Use BASE_URL to build public path
                $siteLogo = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['company_logo'];
            }
            if (!empty($srow['company_name'])) {
                $siteName = $srow['company_name'];
            }

            if (!empty($srow['login_background']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['login_background'])) {
                $siteBrandBg = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['login_background'];
            }
        }
    }
} catch (Exception $e) {

}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aplikasi Manajemen Showroom</title>
    <script>
        (function () {
            try {
                if (window.innerWidth > 768 && localStorage.getItem('sidebarCollapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-collapsed-init');
                }
                // Apply dark mode instantly to prevent flash
                var savedTheme = localStorage.getItem('theme');
                if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                    document.documentElement.setAttribute('data-theme', 'dark');
                }
            } catch (e) {}
        })();
    </script>
    <?php
    if (empty($siteFavicon) && !empty($siteLogo)) {
        $siteFavicon = $siteLogo;
    }
    if (!empty($siteFavicon)):
    ?>
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($siteFavicon); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($siteFavicon); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css" />
    <link href="<?php echo ASSETS_PATH; ?>css/style.css?v=<?php echo @filemtime(__DIR__ . '/../assets/css/style.css') ?: time(); ?>" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        /* Ensure topbar dropdown shows above sidebar and content */
        .topbar { position: relative; z-index: 1040; background: #fff; padding: 0.75rem 1rem; }
        .topbar .dropdown-menu { z-index: 1200; }
        /* Make topbar children spread left and right so profile sits at top-right */
        .topbar.d-flex { justify-content: space-between; }
        /* Remove underline from profile name and dropdown toggle */
        .topbar .dropdown-toggle, .topbar .dropdown-toggle:hover, .topbar .dropdown-toggle:focus { text-decoration: none; }
        .topbar .user-name { text-decoration: none; }
        .hamburger-btn { border: none; background: transparent; padding: 6px 10px; display: inline-flex; align-items: center; justify-content: center; color: #64748b; transition: color 0.2s; }
        .hamburger-btn:hover { color: #2d3436; }
        .hamburger-btn:focus { outline: none; }
        .page-title { font-weight: 600; font-size: 1rem; color: #0f172a; }
        @media (min-width: 769px) { .page-title { margin-left: 8px; } }
        @media (max-width: 768px) {
            .hamburger-btn { display: inline-flex; align-items: center; justify-content: center; }
            .page-title { font-size: 0.95rem; }
        }
        .sidebar.sidebar-modern { 
            box-shadow: 0 10px 25px rgba(0,0,0,0.08); 
            height: 100vh;
            max-height: 100vh;
            overflow-y: auto !important;
            overflow-x: hidden !important;
        }
        .sidebar.sidebar-modern .sidebar-brand { 
            position: sticky; 
            top: 0; 
            z-index: 10; 
            background: #2d3436; 
            flex-shrink: 0;
        }
        .sidebar.sidebar-modern .sidebar-brand h4 { font-size: 1.05rem; letter-spacing: .2px; }
        .sidebar.sidebar-modern .sidebar-nav { padding: .5rem 0 2rem 0; }
        .sidebar.sidebar-modern .nav-link { position: relative; border-radius: 10px; margin: 3px 12px; padding: .55rem .85rem; color: rgba(255,255,255,.88); font-size: 0.85rem; font-weight: 500; display: flex; align-items: center; transition: all 0.2s ease; cursor: pointer; }
        .sidebar.sidebar-modern .nav-link:hover { background: rgba(255,255,255,.12); color: #ffffff; transform: translateX(2px); }
        .sidebar.sidebar-modern .nav-link.active { background: rgba(255,255,255,.18); color: #ffffff; box-shadow: inset 0 0 0 1px rgba(255,255,255,.22); }
        .sidebar.sidebar-modern .nav-link.active::before { content:""; position:absolute; left:-12px; top:50%; transform:translateY(-50%); width:4px; height:60%; background:#fff; border-radius:6px; opacity:.95; }
        
        /* Section Heading */
        .sidebar-heading {
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255, 255, 255, 0.4);
            padding: 14px 18px 4px 18px;
            list-style: none;
            user-select: none;
        }
        .sidebar.collapsed .sidebar-heading { display: none !important; }

        /* Subnav Container */
        .sidebar.sidebar-modern .sidebar-subnav {
            list-style: none;
            margin: 2px 12px 6px 22px;
            padding: 2px 0 2px 8px;
            border-left: 1.5px solid rgba(255,255,255,.16);
            display: none;
        }
        .sidebar.sidebar-modern .sidebar-subnav.show {
            display: block;
        }
        .sidebar.sidebar-modern .sidebar-subnav .nav-link {
            padding: .44rem .75rem;
            margin: 2px 0 2px 4px;
            font-size: 0.81rem;
            font-weight: 400;
            color: rgba(255,255,255,.78);
            border-radius: 8px;
        }
        .sidebar.sidebar-modern .sidebar-subnav .nav-link:hover {
            color: #ffffff;
            background: rgba(255,255,255,.1);
            transform: translateX(2px);
        }
        .sidebar.sidebar-modern .sidebar-subnav .nav-link.active {
            color: #ffffff;
            background: rgba(255,255,255,.2);
            font-weight: 600;
            box-shadow: none;
        }
        .sidebar.sidebar-modern .sidebar-subnav .nav-link.active::before {
            display: none;
        }
        .sidebar.sidebar-modern .sidebar-subnav .nav-link i {
            font-size: 0.8rem;
            width: 18px;
            text-align: center;
            opacity: 0.85;
            margin-right: 8px;
        }
        .sidebar-divider { height:1px; background: rgba(255,255,255,.12); margin: 8px 12px; border:0; }

        /* Submenu chevron animation */
        .sidebar .submenu-arrow {
            margin-left: auto;
            font-size: 0.7rem;
            opacity: 0.7;
            transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            transform: rotate(0deg);
        }
        .sidebar .nav-link[aria-expanded="true"] .submenu-arrow {
            transform: rotate(90deg);
            opacity: 1;
        }
        .sidebar.collapsed .submenu-arrow {
            display: none !important;
        }

        /* Sleek sidebar scrollbar */
        .sidebar::-webkit-scrollbar { width: 5px; }
        .sidebar::-webkit-scrollbar-track { background: transparent; }
        .sidebar::-webkit-scrollbar-thumb { background: rgba(255, 255, 255, 0.15); border-radius: 4px; }
        .sidebar::-webkit-scrollbar-thumb:hover { background: rgba(255, 255, 255, 0.35); }

        /* Global Search - Centered in topbar */
        .global-search-wrapper {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 100%;
            max-width: 420px;
            z-index: 10;
        }
        .global-search-input { width: 100%; padding: 9px 16px 9px 40px; border: 1.5px solid #e2e8f0; border-radius: 12px; font-size: 0.875rem; background: #f8fafc; transition: all 0.25s ease; outline: none; color: #334155; }
        .global-search-input:focus { border-color: #94a3b8; background: #fff; box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
        .global-search-input::placeholder { color: #94a3b8; }
        .global-search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem; pointer-events: none; }
        .search-results-dropdown { position: absolute; top: calc(100% + 6px); left: 0; right: 0; background: #fff; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 4px 6px rgba(0,0,0,0.04); z-index: 9999; max-height: 380px; overflow-y: auto; display: none; border: 1px solid #e2e8f0; }
        .search-results-dropdown.active { display: block; }
        .search-result-item { display: flex; align-items: center; padding: 10px 16px; text-decoration: none; color: #334155; transition: background 0.15s; border-bottom: 1px solid #f8fafc; }
        .search-result-item:hover { background: #f8fafc; color: #1e293b; }
        .search-result-item:last-child { border-bottom: none; }
        .search-result-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-right: 12px; font-size: 0.85rem; }
        .search-result-icon.vehicle { background: rgba(59,130,246,0.1); color: #3b82f6; }
        .search-result-icon.customer { background: rgba(16,185,129,0.1); color: #10b981; }
        .search-result-icon.transaction { background: rgba(245,158,11,0.1); color: #f59e0b; }
        .search-result-title { font-weight: 600; font-size: 0.85rem; line-height: 1.3; }
        .search-result-sub { font-size: 0.75rem; color: #94a3b8; }
        .search-no-result { padding: 24px 16px; text-align: center; color: #94a3b8; font-size: 0.85rem; }
        .search-category-label { padding: 8px 16px 4px; font-size: 0.65rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; }
        @media (max-width: 991px) {
            .global-search-wrapper { position: relative; left: auto; top: auto; transform: none; max-width: none; flex: 1 1 auto; margin: 0 0.75rem; }
        }
        @media (max-width: 768px) {
            .global-search-wrapper { margin: 0 0.5rem; }
            .global-search-input { font-size: 0.8rem; padding: 7px 14px 7px 34px; }
        }

        /* Header Dark Mode Overrides */
        [data-theme="dark"] .topbar { background: #1e293b; }
        [data-theme="dark"] .page-title { color: #e2e8f0; }
        [data-theme="dark"] .hamburger-btn { color: #94a3b8; }
        [data-theme="dark"] .hamburger-btn:hover { color: #e2e8f0; }
        [data-theme="dark"] .global-search-input { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        [data-theme="dark"] .global-search-input:focus { border-color: #60a5fa; background: #162032; box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
        [data-theme="dark"] .global-search-input::placeholder { color: #64748b; }
        [data-theme="dark"] .search-results-dropdown { background: #1e293b; border-color: #334155; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.4); }
        [data-theme="dark"] .search-result-item { color: #e2e8f0; border-bottom-color: #334155; }
        [data-theme="dark"] .search-result-item:hover { background: #334155; color: #f1f5f9; }
        [data-theme="dark"] .search-result-title { color: #f1f5f9; }
        [data-theme="dark"] .search-no-result { color: #94a3b8; }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <nav class="sidebar sidebar-modern" id="sidebar">
        <div class="sidebar-brand d-flex align-items-center"<?php echo !empty($siteBrandBg) ? ' style="background-image:linear-gradient(90deg, rgba(0,0,0,0.7), rgba(0,0,0,0.35)), url(' . htmlspecialchars($siteBrandBg) . '); background-size:cover; background-position:center; background-repeat:no-repeat;"' : ''; ?>>
            <?php if (!empty($siteLogo)): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="Logo" class="sidebar-logo" style="height:38px; object-fit:contain;" onerror="this.style.display='none'; var fb=this.parentNode.querySelector('.sidebar-logo-fallback'); if (fb) fb.style.display='block';">
                <i class="fas fa-car sidebar-logo-fallback me-2" style="display:none;"></i>
                <h4 class="mb-0 ms-2"><span>Manajemen Showroom</span></h4>
            <?php else: ?>
                <i class="fas fa-car sidebar-logo-fallback me-2"></i>
                <h4 class="mb-0"><span><?php echo htmlspecialchars($siteName); ?></span></h4>
            <?php endif; ?>
        </div>
        <ul class="sidebar-nav">
            <li class="nav-item">
                <a href="<?php echo BASE_URL; ?>dashboard/" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['REQUEST_URI'], 'dashboard') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Beranda</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="<?php echo BASE_URL; ?>catalog/" target="_blank" class="nav-link" title="Buka Katalog Publik Showroom">
                    <i class="fas fa-globe text-primary"></i>
                    <span>Website Katalog</span>
                    <i class="fas fa-arrow-up-right-from-square ms-auto text-muted" style="font-size:0.75rem;"></i>
                </a>
            </li>
            
            <?php
            // Active states for grouped submenus
            $isDataMasterActive = (strpos($_SERVER['REQUEST_URI'], '/brands/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/car_types/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/leasing/') !== false && strpos($_SERVER['REQUEST_URI'], 'simulation') === false);

            $isInventoryActive = (strpos($_SERVER['REQUEST_URI'], '/models/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/vehicles/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/inspections/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/maintenance/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/documents/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], 'tracking') !== false);

            $isTransactionActive = (strpos($_SERVER['REQUEST_URI'], '/transactions/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/bookings/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/customer_requests/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/trade_in/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/consignments/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/customers/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], 'simulation') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/promotions/') !== false);

            $isFinanceActive = (strpos($_SERVER['REQUEST_URI'], '/expenses/') !== false)
                || (strpos($_SERVER['REQUEST_URI'], '/reports/') !== false);
            ?>

            <li class="sidebar-heading"><span>Manajemen Showroom</span></li>

            <?php if (isAdmin()): ?>
            <!-- Group 1: Data Master (admin only) -->
            <li class="nav-item">
                <a href="#" role="button" class="nav-link <?php echo $isDataMasterActive ? 'active' : ''; ?>" data-submenu-target="dataMasterSub" aria-expanded="<?php echo $isDataMasterActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-database"></i>
                    <span>Data Master</span>
                    <i class="fas fa-chevron-right ms-auto submenu-arrow"></i>
                </a>
                <ul id="dataMasterSub" class="sidebar-subnav <?php echo $isDataMasterActive ? 'show' : ''; ?>">
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>brands/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'brands') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tag"></i> <span>Merek Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>car_types/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'car_types') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-shapes"></i> <span>Tipe Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>leasing/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'leasing') !== false && strpos($_SERVER['REQUEST_URI'], 'simulation') === false ? 'active' : ''; ?>">
                            <i class="fas fa-building-columns"></i> <span>Mitra Leasing</span>
                        </a>
                    </li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- Group 2: Inventaris Mobil -->
            <li class="nav-item">
                <a href="#" role="button" class="nav-link <?php echo $isInventoryActive ? 'active' : ''; ?>" data-submenu-target="inventorySub" aria-expanded="<?php echo $isInventoryActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-car"></i>
                    <span>Inventaris Mobil</span>
                    <i class="fas fa-chevron-right ms-auto submenu-arrow"></i>
                </a>
                <ul id="inventorySub" class="sidebar-subnav <?php echo $isInventoryActive ? 'show' : ''; ?>">
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>models/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'models') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-list-alt"></i> <span>Model Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>vehicles/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'vehicles') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-car-side"></i> <span>Stok Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>inspections/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'inspections') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-clipboard-check"></i> <span>Inspeksi Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>maintenance/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'maintenance') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tools"></i> <span>Perawatan Mobil</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>documents/tracking.php" class="nav-link <?php echo (strpos($_SERVER['REQUEST_URI'], 'tracking') !== false || strpos($_SERVER['REQUEST_URI'], 'documents') !== false) ? 'active' : ''; ?>">
                            <i class="fas fa-folder-tree"></i> <span>Tracking Berkas</span>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Group 3: Transaksi & Layanan -->
            <li class="nav-item">
                <a href="#" role="button" class="nav-link <?php echo $isTransactionActive ? 'active' : ''; ?>" data-submenu-target="transactionSub" aria-expanded="<?php echo $isTransactionActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-cash-register"></i>
                    <span>Transaksi & Layanan</span>
                    <i class="fas fa-chevron-right ms-auto submenu-arrow"></i>
                </a>
                <ul id="transactionSub" class="sidebar-subnav <?php echo $isTransactionActive ? 'show' : ''; ?>">
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>transactions/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'transactions') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-shopping-cart"></i> <span>Transaksi Penjualan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>bookings/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'bookings') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-file-signature"></i> <span>SPK & Booking Fee</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>customer_requests/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'customer_requests') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-user-tag"></i> <span>Request Unit Pelanggan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>trade_in/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'trade_in') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-right-left"></i> <span>Tukar Tambah</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>consignments/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'consignments') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-handshake"></i> <span>Titip Jual</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>customers/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'customers') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-users"></i> <span>Data Pelanggan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>leasing/simulation.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'simulation') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-calculator"></i> <span>Simulasi Kredit</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>promotions/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'promotions') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-tags"></i> <span>Promo & Diskon</span>
                        </a>
                    </li>
                </ul>
            </li>

            <!-- Group 4: Keuangan & Laporan -->
            <li class="nav-item">
                <a href="#" role="button" class="nav-link <?php echo $isFinanceActive ? 'active' : ''; ?>" data-submenu-target="financeSub" aria-expanded="<?php echo $isFinanceActive ? 'true' : 'false'; ?>">
                    <i class="fas fa-wallet"></i>
                    <span>Keuangan & Laporan</span>
                    <i class="fas fa-chevron-right ms-auto submenu-arrow"></i>
                </a>
                <ul id="financeSub" class="sidebar-subnav <?php echo $isFinanceActive ? 'show' : ''; ?>">
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>expenses/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'expenses') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-money-bill-wave"></i> <span>Kas Operasional</span>
                        </a>
                    </li>
                    <?php if (isAdmin()): ?>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>reports/finance.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'finance.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-file-invoice-dollar"></i> <span>Laba Rugi Showroom</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>reports/monthly_sales.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'sales.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-chart-line"></i> <span>Laporan Penjualan</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>reports/inventory.php" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'inventory.php') !== false || strpos($_SERVER['REQUEST_URI'], 'vehicle_purchases.php') !== false ? 'active' : ''; ?>">
                            <i class="fas fa-boxes-stacked"></i> <span>Stok & Pembelian</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="<?php echo BASE_URL; ?>reports/" class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['REQUEST_URI'], 'reports') !== false && strpos($_SERVER['REQUEST_URI'], 'finance.php') === false && strpos($_SERVER['REQUEST_URI'], 'sales.php') === false && strpos($_SERVER['REQUEST_URI'], 'inventory.php') === false ? 'active' : ''; ?>">
                            <i class="fas fa-chart-pie"></i> <span>Pusat Laporan</span>
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </li>

            <li class="sidebar-heading"><span>Sistem</span></li>

            <li class="nav-item">
                <a href="<?php echo BASE_URL; ?>activity/" class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], 'activity') !== false ? 'active' : ''; ?>">
                    <i class="fas fa-clock"></i>
                    <span>Aktivitas</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Top Bar -->
        <div class="topbar d-flex align-items-center">
            <div class="d-flex align-items-center">
                <button id="sidebarToggle" class="hamburger-btn me-2" type="button" aria-label="Toggle sidebar" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                <span class="page-title d-none d-lg-inline">Aplikasi Manajemen Showroom</span>
            </div>

            <!-- Global Search -->
            <div class="global-search-wrapper">
                <i class="fas fa-search global-search-icon"></i>
                <input type="text" class="global-search-input" id="globalSearchInput" placeholder="Cari mobil, pelanggan, transaksi..." autocomplete="off">
                <div class="search-results-dropdown" id="searchResultsDropdown"></div>
            </div>
            
            <div class="d-flex align-items-center">
                <!-- Dark/Light Mode Toggle -->
                <button id="themeToggleBtn" class="theme-toggle-btn" type="button" aria-label="Toggle dark mode" title="Ganti tema gelap/terang">
                    <i class="fas fa-moon theme-icon" id="themeIcon"></i>
                    <span class="theme-label" id="themeLabel">Dark</span>
                </button>
                <div class="dropdown">
                    <?php
                    // Prepare user photo path (prefer session)
                    $userPhotoPath = '';
                    try {
                        if (!empty($_SESSION['user_photo']) && file_exists(__DIR__ . '/../uploads/users/' . $_SESSION['user_photo'])) {
                            $userPhotoPath = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/users/' . $_SESSION['user_photo'];
                        } elseif (!empty($_SESSION['user_id']) && file_exists(__DIR__ . '/../config/database.php')) {
                            // Try DB lookup
                            require_once __DIR__ . '/../config/database.php';
                            $dbtmp = new Database();
                            $connTmp = $dbtmp->getConnection();
                            $uStmt = $connTmp->prepare("SELECT photo FROM users WHERE id = ?");
                            $uStmt->execute([$_SESSION['user_id']]);
                            $uRow = $uStmt->fetch(PDO::FETCH_ASSOC);
                            if ($uRow && !empty($uRow['photo']) && file_exists(__DIR__ . '/../uploads/users/' . $uRow['photo'])) {
                                $userPhotoPath = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/users/' . $uRow['photo'];
                                // cache in session
                                $_SESSION['user_photo'] = $uRow['photo'];
                            }
                        }
                    } catch (Exception $e) {
                        // ignore
                    }
                    ?>
                    <style>
                    /* Prevent horizontal scrollbar */
                    html, body {
                        overflow-x: hidden;
                        max-width: 100%;
                    }
                    
                    /* ===== Modern User Profile Button ===== */
                    .user-profile-btn {
                        display: inline-flex;
                        align-items: center;
                        gap: 10px;
                        padding: 5px 12px 5px 6px !important;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 999px;
                        text-decoration: none !important;
                        cursor: pointer;
                        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
                    }
                    .user-profile-btn:hover, 
                    .user-profile-btn[aria-expanded="true"] {
                        background: #ffffff;
                        border-color: #cbd5e1;
                        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.07);
                        transform: translateY(-1px);
                    }
                    
                    .user-avatar-frame {
                        position: relative;
                        width: 36px;
                        height: 36px;
                        border-radius: 50%;
                        flex-shrink: 0;
                    }
                    .user-avatar-img {
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                        border-radius: 50%;
                        display: block;
                        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
                    }
                    .user-online-badge {
                        position: absolute;
                        bottom: 0;
                        right: 0;
                        width: 10px;
                        height: 10px;
                        border-radius: 50%;
                        background: #10b981;
                        border: 2px solid #ffffff;
                    }
                    
                    .user-info-text {
                        text-align: left;
                        line-height: 1.25;
                    }
                    .user-name-display {
                        font-weight: 700;
                        font-size: 0.85rem;
                        color: #1e293b;
                        letter-spacing: -0.01em;
                        max-width: 120px;
                        white-space: nowrap;
                        overflow: hidden;
                        text-overflow: ellipsis;
                    }
                    .user-role-display {
                        font-size: 0.7rem;
                        color: #64748b;
                        font-weight: 600;
                        text-transform: capitalize;
                    }
                    
                    .dropdown-chevron {
                        font-size: 0.72rem;
                        color: #94a3b8;
                        transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s;
                    }
                    .user-profile-btn:hover .dropdown-chevron,
                    .user-profile-btn[aria-expanded="true"] .dropdown-chevron {
                        color: #1e293b;
                    }
                    .user-profile-btn[aria-expanded="true"] .dropdown-chevron {
                        transform: rotate(180deg);
                    }
                    
                    /* ===== Modern Custom User Dropdown Menu ===== */
                    .custom-user-dropdown {
                        width: 300px;
                        background: #ffffff;
                        border: 1px solid rgba(226, 232, 240, 0.95);
                        border-radius: 18px;
                        box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.12), 0 0 1px 1px rgba(15, 23, 42, 0.05);
                        margin-top: 10px !important;
                        overflow: hidden;
                        padding: 0;
                        position: absolute !important;
                        right: 0 !important;
                        left: auto !important;
                        opacity: 0;
                        transform: translateY(-8px) scale(0.98);
                        transition: opacity 0.2s cubic-bezier(0.16, 1, 0.3, 1), transform 0.2s cubic-bezier(0.16, 1, 0.3, 1);
                        pointer-events: none;
                        backdrop-filter: blur(8px);
                    }
                    
                    .custom-user-dropdown.show {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                        pointer-events: auto;
                    }
                    
                    /* Header Area */
                    .dropdown-user-header {
                        padding: 18px 20px;
                        background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
                        border-bottom: 1px solid #f1f5f9;
                    }
                    .dropdown-user-header .avatar-box {
                        position: relative;
                        width: 46px;
                        height: 46px;
                        border-radius: 50%;
                        flex-shrink: 0;
                    }
                    .dropdown-user-header .avatar-box img {
                        width: 100%;
                        height: 100%;
                        object-fit: cover;
                        border-radius: 50%;
                        border: 2px solid #ffffff;
                        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
                    }
                    .dropdown-user-header .user-badge-role {
                        display: inline-block;
                        padding: 2px 8px;
                        border-radius: 6px;
                        font-size: 0.68rem;
                        font-weight: 700;
                        background: #dbeafe;
                        color: #1e40af;
                        text-transform: uppercase;
                        letter-spacing: 0.04em;
                        margin-top: 3px;
                    }
                    
                    /* Menu Items */
                    .dropdown-menu-items {
                        padding: 8px 10px;
                        list-style: none;
                        margin: 0;
                    }
                    .dropdown-menu-items li {
                        margin-bottom: 2px;
                    }
                    .dropdown-menu-items li:last-child {
                        margin-bottom: 0;
                    }
                    
                    .custom-dropdown-item {
                        display: flex;
                        align-items: center;
                        justify-content: space-between;
                        padding: 9px 12px;
                        border-radius: 12px;
                        color: #334155;
                        text-decoration: none !important;
                        font-size: 0.86rem;
                        font-weight: 600;
                        transition: all 0.18s ease;
                    }
                    .custom-dropdown-item:hover {
                        background: #f8fafc;
                        color: #0f172a;
                        transform: translateX(3px);
                    }
                    
                    .item-left-content {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                    }
                    .item-icon-box {
                        width: 34px;
                        height: 34px;
                        border-radius: 10px;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 0.95rem;
                        flex-shrink: 0;
                        transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
                    }
                    .custom-dropdown-item:hover .item-icon-box {
                        transform: scale(1.08);
                    }
                    
                    .item-icon-box.blue { background: #eff6ff; color: #2563eb; }
                    .item-icon-box.slate { background: #f1f5f9; color: #475569; }
                    .item-icon-box.purple { background: #faf5ff; color: #9333ea; }
                    .item-icon-box.rose { background: #fff1f2; color: #e11d48; }
                    
                    .item-texts {
                        display: flex;
                        flex-direction: column;
                        line-height: 1.25;
                    }
                    .item-title {
                        font-size: 0.85rem;
                        font-weight: 600;
                        color: #1e293b;
                    }
                    .item-sub {
                        font-size: 0.72rem;
                        font-weight: 400;
                        color: #94a3b8;
                    }
                    
                    .item-arrow {
                        font-size: 0.72rem;
                        color: #cbd5e1;
                        opacity: 0;
                        transform: translateX(-4px);
                        transition: all 0.18s ease;
                    }
                    .custom-dropdown-item:hover .item-arrow {
                        opacity: 1;
                        transform: translateX(0);
                        color: #64748b;
                    }
                    
                    /* Logout Special Styling */
                    .logout-item:hover {
                        background: #fef2f2 !important;
                    }
                    .logout-item:hover .item-title {
                        color: #dc2626 !important;
                    }
                    .logout-item:hover .item-arrow {
                        color: #dc2626 !important;
                    }
                    
                    .dropdown-clean-divider {
                        height: 1px;
                        background: #f1f5f9;
                        margin: 6px 6px;
                        border: none;
                    }
                    
                    .no-caret::after { display: none !important; }

                    /* ===== Dark Mode Overrides ===== */
                    [data-theme="dark"] .user-profile-btn {
                        background: #1e293b;
                        border-color: #334155;
                    }
                    [data-theme="dark"] .user-profile-btn:hover,
                    [data-theme="dark"] .user-profile-btn[aria-expanded="true"] {
                        background: #253349;
                        border-color: #475569;
                        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
                    }
                    [data-theme="dark"] .user-name-display { color: #f1f5f9; }
                    [data-theme="dark"] .user-role-display { color: #94a3b8; }
                    [data-theme="dark"] .user-online-badge { border-color: #1e293b; }
                    [data-theme="dark"] .custom-user-dropdown {
                        background: #1e293b;
                        border-color: #334155;
                        box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.6), 0 0 1px 1px rgba(255, 255, 255, 0.05);
                    }
                    [data-theme="dark"] .dropdown-user-header {
                        background: #162032;
                        border-bottom-color: #334155;
                    }
                    [data-theme="dark"] .dropdown-user-header h6 { color: #f1f5f9 !important; }
                    [data-theme="dark"] .dropdown-user-header .avatar-box img { border-color: #1e293b; }
                    [data-theme="dark"] .custom-dropdown-item { color: #cbd5e1; }
                    [data-theme="dark"] .custom-dropdown-item:hover {
                        background: #27354a;
                    }
                    [data-theme="dark"] .item-title { color: #f1f5f9; }
                    [data-theme="dark"] .item-sub { color: #64748b; }
                    [data-theme="dark"] .item-icon-box.blue { background: rgba(37,99,235,0.15); color: #60a5fa; }
                    [data-theme="dark"] .item-icon-box.slate { background: rgba(148,163,184,0.15); color: #cbd5e1; }
                    [data-theme="dark"] .item-icon-box.purple { background: rgba(147,51,234,0.15); color: #c084fc; }
                    [data-theme="dark"] .item-icon-box.rose { background: rgba(225,29,72,0.15); color: #fb7185; }
                    [data-theme="dark"] .logout-item:hover { background: rgba(225,29,72,0.12) !important; }
                    [data-theme="dark"] .logout-item:hover .item-title { color: #fca5a5 !important; }
                    [data-theme="dark"] .dropdown-clean-divider { background: #334155; }
                    </style>

                    <!-- User Profile Trigger Button -->
                    <button class="btn dropdown-toggle no-caret user-profile-btn" id="userMenuButton" type="button"
                        data-bs-toggle="dropdown" aria-expanded="false">
                        
                        <div class="user-avatar-frame">
                            <?php if (!empty($userPhotoPath)): ?>
                                <img src="<?php echo htmlspecialchars($userPhotoPath); ?>" alt="User" class="user-avatar-img">
                            <?php else: ?>
                                <div class="bg-primary bg-opacity-10 d-flex align-items-center justify-content-center rounded-circle text-primary w-100 h-100">
                                    <i class="fas fa-user text-primary" style="font-size: 0.9rem;"></i>
                                </div>
                            <?php endif; ?>
                            <span class="user-online-badge"></span>
                        </div>
                        
                        <div class="user-info-text d-none d-md-block">
                            <div class="user-name-display"><?php echo htmlspecialchars((string)($_SESSION['user_name'] ?? 'Pengguna')); ?></div>
                            <div class="user-role-display"><?php echo ucfirst($_SESSION['user_role'] ?? 'Guest'); ?></div>
                        </div>
                        
                        <i class="fas fa-chevron-down dropdown-chevron ms-1"></i>
                    </button>
                    
                    <!-- Modern Dropdown Menu -->
                    <ul class="dropdown-menu dropdown-menu-end custom-user-dropdown" aria-labelledby="userMenuButton">
                        <!-- User Header Profile Card -->
                        <div class="dropdown-user-header">
                            <div class="d-flex align-items-center">
                                <div class="avatar-box">
                                    <?php if (!empty($userPhotoPath)): ?>
                                        <img src="<?php echo htmlspecialchars($userPhotoPath); ?>" alt="User">
                                    <?php else: ?>
                                        <div class="bg-primary bg-opacity-10 d-flex align-items-center justify-content-center rounded-circle text-primary w-100 h-100">
                                            <i class="fas fa-user fa-lg"></i>
                                        </div>
                                    <?php endif; ?>
                                    <span class="user-online-badge"></span>
                                </div>
                                <div class="flex-grow-1 ms-3" style="min-width: 0;">
                                    <h6 class="mb-0 fw-bold text-dark text-truncate" style="font-size: 0.95rem;"><?php echo htmlspecialchars((string)($_SESSION['user_name'] ?? 'Pengguna')); ?></h6>
                                    <span class="user-badge-role"><?php echo ucfirst($_SESSION['user_role'] ?? 'User'); ?></span>
                                    <small class="text-muted d-block text-truncate mt-1" style="font-size: 0.75rem;"><?php echo isset($_SESSION['user_email']) ? htmlspecialchars($_SESSION['user_email']) : ''; ?></small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Menu Items -->
                        <div class="dropdown-menu-items">
                            <li>
                                <a class="custom-dropdown-item" href="<?php echo BASE_URL; ?>profile/">
                                    <div class="item-left-content">
                                        <div class="item-icon-box blue">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="item-texts">
                                            <span class="item-title">Profil Saya</span>
                                            <span class="item-sub">Informasi & keamanan akun</span>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right item-arrow"></i>
                                </a>
                            </li>
                            
                            <?php if (isAdmin()): ?>
                            <li>
                                <a class="custom-dropdown-item" href="<?php echo BASE_URL; ?>settings/">
                                    <div class="item-left-content">
                                        <div class="item-icon-box slate">
                                            <i class="fas fa-sliders-h"></i>
                                        </div>
                                        <div class="item-texts">
                                            <span class="item-title">Pengaturan</span>
                                            <span class="item-sub">Preferensi sistem & showroom</span>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right item-arrow"></i>
                                </a>
                            </li>
                            <li>
                                <a class="custom-dropdown-item" href="<?php echo BASE_URL; ?>users/">
                                    <div class="item-left-content">
                                        <div class="item-icon-box purple">
                                            <i class="fas fa-users-cog"></i>
                                        </div>
                                        <div class="item-texts">
                                            <span class="item-title">Kelola Pengguna</span>
                                            <span class="item-sub">Hak akses & manajemen staf</span>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right item-arrow"></i>
                                </a>
                            </li>
                            <?php endif; ?>
                            
                            <hr class="dropdown-clean-divider">
                            
                            <li>
                                <a class="custom-dropdown-item logout-item" href="<?php echo BASE_URL; ?>auth/logout.php">
                                    <div class="item-left-content">
                                        <div class="item-icon-box rose">
                                            <i class="fas fa-sign-out-alt"></i>
                                        </div>
                                        <div class="item-texts">
                                            <span class="item-title text-danger">Keluar</span>
                                            <span class="item-sub">Akhiri sesi login saat ini</span>
                                        </div>
                                    </div>
                                    <i class="fas fa-chevron-right item-arrow"></i>
                                </a>
                            </li>
                        </div>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Content Wrapper -->
        <div class="content-wrapper">
            <?php
            // Display alert messages
            $alert = getAlert();
            if ($alert):
            ?>
            <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo $alert['message']; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>
            <script>
            function toggleSubmenu(id, trigger) {
                var el = document.getElementById(id);
                if (!el) return;
                el.classList.toggle('show');
                if (trigger && trigger.setAttribute) {
                    trigger.setAttribute('aria-expanded', el.classList.contains('show') ? 'true' : 'false');
                }
            }
            </script>
            
            <!-- Auto Logout & Keep Alive Script -->
            <script>
            (function() {
                // Settings
                const timeoutDuration = 300000; // 5 minutes in ms
                const checkInterval = 60000;    // Check every 1 minute
                const keepAliveUrl = '<?php echo defined("BASE_URL") ? BASE_URL : "/showroom/"; ?>keep_session.php';
                
                let lastActivity = Date.now();
                
                // Function to update last activity time
                function updateActivity() {
                    lastActivity = Date.now();
                }
                
                // Track user activity events
                const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'];
                events.forEach(function(name) {
                    document.addEventListener(name, updateActivity, { passive: true });
                });
                
                // Periodically check for inactivity
                setInterval(function() {
                    const now = Date.now();
                    const timeSinceLastActivity = now - lastActivity;
                    
                    if (timeSinceLastActivity >= timeoutDuration) {
                        // User has been idle for > 5 mins -> Redirect to Logout
                        // We use a specific reason so we can show a message
                        window.location.href = '<?php echo defined("BASE_URL") ? BASE_URL : "/showroom/"; ?>auth/logout.php?reason=timeout';
                    } else {
                        // User is currently active (or was active recently)
                        // If user was active in the last minute (timeSinceLastActivity < checkInterval),
                        // ping the server to keep the session alive.
                        // This prevents the PHP session from expiring if the user is 
                        // interacting with the page but not navigating (e.g. reading/typing).
                        if (timeSinceLastActivity < checkInterval) {
                            fetch(keepAliveUrl, { method: 'HEAD' }).catch(() => {});
                        }
                    }
                }, checkInterval);
                
            })();
            </script>

