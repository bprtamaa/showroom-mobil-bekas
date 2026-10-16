<?php
// auth/forgot_password.php
// Prevent browser caching
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

require_once '../config/config.php';

// Definisikan BASE_URL jika belum ada
if (!defined('BASE_URL')) {
    define('BASE_URL', '/showroom/');
}

// Definisikan APP_NAME jika belum ada
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Showroom');
}

// Definisikan ASSETS_PATH jika belum ada
if (!defined('ASSETS_PATH')) {
    define('ASSETS_PATH', BASE_URL . 'assets/');
}

// Mulai session jika belum
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect jika sudah login
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'dashboard/');
    exit;
}

// Load company settings
$loginLogo = '';
$loginFavicon = '';
$loginBackground = '';
$loginAppName = defined('APP_NAME') ? APP_NAME : 'Showroom';
$adminPhone = '081323299404';

try {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $dbconn = $database->getConnection();
        $sstmt = $dbconn->query("SELECT company_logo, company_name, login_background, company_phone FROM settings WHERE id = 1");
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow) {
            if (!empty($srow['company_logo']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['company_logo'])) {
                $loginLogo = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['company_logo'];
            }
            if (!empty($srow['company_name'])) $loginAppName = $srow['company_name'];
            if (!empty($srow['company_phone'])) $adminPhone = $srow['company_phone'];
            if (!empty($srow['login_background']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['login_background'])) {
                $loginBackground = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['login_background'];
            }
        }
    }
} catch (Exception $e) {
    // Non-fatal
}

if (empty($loginFavicon) && !empty($loginLogo)) {
    $loginFavicon = $loginLogo;
}

// Sanitization
function sanitizeTxt($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$error = '';
$success = '';
$step = 1; // Step 1: Cari Akun, Step 2: Buat Password Baru, Step 3: Sukses

// Handle reset flow
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['fp_user_id']);
    unset($_SESSION['fp_user_name']);
    unset($_SESSION['fp_user_username']);
    unset($_SESSION['fp_user_phone']);
    unset($_SESSION['fp_user_role']);
    header('Location: forgot_password.php');
    exit;
}

// Connect DB
if (!isset($db) || !$db) {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
}

// Determine active step from session
if (!empty($_SESSION['fp_user_id'])) {
    $step = 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'find_account') {
        $identifier = trim($_POST['identifier'] ?? '');

        if (empty($identifier)) {
            $error = 'Silakan masukkan nama pengguna atau nomor telepon terdaftar.';
        } else {
            try {
                $stmt = $db->prepare("SELECT id, username, full_name, phone, role, status FROM users WHERE (username = ? OR phone = ?) AND status = 'active' LIMIT 1");
                $stmt->execute([$identifier, $identifier]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    $_SESSION['fp_user_id'] = $user['id'];
                    $_SESSION['fp_user_name'] = $user['full_name'];
                    $_SESSION['fp_user_username'] = $user['username'];
                    $_SESSION['fp_user_phone'] = $user['phone'] ?? '';
                    $_SESSION['fp_user_role'] = $user['role'] ?? 'staff';
                    $step = 2;
                } else {
                    $error = 'Akun tidak ditemukan atau status pengguna tidak aktif.';
                }
            } catch (PDOException $e) {
                $error = 'Terjadi kesalahan pada sistem database.';
            }
        }
    } elseif ($action === 'reset_password') {
        if (empty($_SESSION['fp_user_id'])) {
            $step = 1;
            $error = 'Sesi reset kata sandi telah kedaluwarsa. Silakan cari akun kembali.';
        } else {
            $verifyPhone = trim($_POST['verify_phone'] ?? '');
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $userPhone = $_SESSION['fp_user_phone'] ?? '';

            // Verification check if user has phone on file
            $phoneValid = true;
            if (!empty($userPhone)) {
                // Normalize phone
                $cleanUserPhone = preg_replace('/[^0-9]/', '', $userPhone);
                $cleanInputPhone = preg_replace('/[^0-9]/', '', $verifyPhone);
                
                // Compare last 4 digits or exact match
                if (empty($cleanInputPhone)) {
                    $phoneValid = false;
                    $error = 'Nomor telepon verifikasi wajib diisi.';
                } elseif ($cleanUserPhone !== $cleanInputPhone && substr($cleanUserPhone, -4) !== substr($cleanInputPhone, -4)) {
                    $phoneValid = false;
                    $error = 'Nomor telepon verifikasi tidak cocok dengan data akun.';
                }
            }

            if ($phoneValid) {
                if (strlen($newPassword) < 6) {
                    $error = 'Kata sandi baru minimal 6 karakter.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'Konfirmasi kata sandi tidak cocok.';
                } else {
                    try {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $updateStmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                        $updateStmt->execute([$hashedPassword, $_SESSION['fp_user_id']]);

                        // Clean session
                        unset($_SESSION['fp_user_id']);
                        unset($_SESSION['fp_user_name']);
                        unset($_SESSION['fp_user_username']);
                        unset($_SESSION['fp_user_phone']);
                        unset($_SESSION['fp_user_role']);

                        $step = 3;
                        $success = 'Kata sandi berhasil diperbarui! Silakan masuk dengan kata sandi baru Anda.';
                    } catch (PDOException $e) {
                        $error = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
                    }
                }
            }
        }
    }
}

// Mask phone helper
function maskPhone($phone) {
    if (empty($phone)) return '-';
    $len = strlen($phone);
    if ($len <= 6) return $phone;
    return substr($phone, 0, 4) . ' **** ' . substr($phone, -4);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi - <?php echo htmlspecialchars($loginAppName); ?></title>
    <meta name="description" content="Reset kata sandi akun Aplikasi Manajemen Showroom">
    <?php if (!empty($loginFavicon)): ?>
    <link rel="icon" type="image/x-icon" href="<?php echo htmlspecialchars($loginFavicon); ?>">
    <link rel="shortcut icon" type="image/x-icon" href="<?php echo htmlspecialchars($loginFavicon); ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            background: #f1f5f9;
            color: #334155;
            overflow-x: hidden;
        }

        .login-page {
            display: flex;
            min-height: 100vh;
        }

        /* Hero Panel */
        .hero-panel {
            flex: 1;
            position: relative;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 48px;
            overflow: hidden;
            background: #f8fafc;
        }

        .hero-panel.has-bg {
            background: #16161d;
        }

        .hero-panel.has-bg::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, 
                rgba(15,15,19,0.2) 0%, 
                rgba(15,15,19,0.5) 50%,
                rgba(15,15,19,0.92) 100%);
            z-index: 1;
            pointer-events: none;
        }

        .hero-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
        }

        .hero-bg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .hero-pattern {
            position: absolute;
            inset: 0;
            z-index: 0;
            background: 
                radial-gradient(ellipse at 30% 20%, rgba(59,130,246,0.07) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 70%, rgba(59,130,246,0.04) 0%, transparent 50%),
                #f8fafc;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            max-width: 480px;
            color: #ffffff;
        }

        .hero-panel:not(.has-bg) .hero-content {
            color: #0f172a;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: rgba(59,130,246,0.15);
            border: 1px solid rgba(59,130,246,0.25);
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #3b82f6;
            margin-bottom: 20px;
            backdrop-filter: blur(8px);
        }

        .hero-panel.has-bg .hero-badge {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.15);
            color: #e2e8f0;
        }

        .hero-title {
            font-size: 2.2rem;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 16px;
            letter-spacing: -0.02em;
        }

        .hero-subtitle {
            font-size: 0.95rem;
            line-height: 1.6;
            opacity: 0.85;
        }

        /* Form Panel */
        .form-panel {
            width: 480px;
            min-width: 440px;
            background: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px;
            position: relative;
            z-index: 2;
            box-shadow: -4px 0 24px rgba(0,0,0,0.03);
            animation: formFadeIn 0.5s ease-out;
        }

        @keyframes formFadeIn {
            from { opacity: 0; transform: translateX(12px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .form-header {
            margin-bottom: 28px;
        }

        .form-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .form-logo.has-icon {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: #ffffff;
            font-size: 1.3rem;
            box-shadow: 0 4px 12px rgba(59,130,246,0.3);
        }

        .form-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 12px;
        }

        .form-header h1 {
            font-size: 1.5rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            margin-bottom: 6px;
        }

        .form-header p {
            font-size: 0.88rem;
            color: #64748b;
            line-height: 1.5;
        }

        /* Step Progress */
        .step-progress {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            padding-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }

        .step-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #94a3b8;
        }

        .step-item.active {
            color: #2563eb;
        }

        .step-item.done {
            color: #16a34a;
        }

        .step-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #f1f5f9;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
        }

        .step-item.active .step-num {
            background: #2563eb;
            color: #ffffff;
        }

        .step-item.done .step-num {
            background: #16a34a;
            color: #ffffff;
        }

        /* User Profile Card */
        .user-preview-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .user-preview-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #2563eb;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            font-weight: 800;
        }

        .user-preview-name {
            font-size: 0.9rem;
            font-weight: 800;
            color: #0f172a;
        }

        .user-preview-sub {
            font-size: 0.75rem;
            color: #64748b;
        }

        .btn-change-user {
            font-size: 0.75rem;
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            background: #eff6ff;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid #dbeafe;
            transition: all 0.15s ease;
        }

        .btn-change-user:hover {
            background: #dbeafe;
            color: #1d4ed8;
        }

        /* Alerts */
        .login-alert {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
            line-height: 1.45;
        }

        .login-alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .login-alert.success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #16a34a;
        }

        /* Form Fields */
        .field-group {
            margin-bottom: 18px;
        }

        .field-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        .field-input-wrap {
            position: relative;
        }

        .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.92rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .field-input {
            width: 100%;
            height: 48px;
            padding: 0 14px 0 42px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.88rem;
            color: #1e293b;
            outline: none;
            transition: all 0.2s ease;
        }

        .field-input:focus {
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .field-input:focus ~ .field-icon {
            color: #3b82f6;
        }

        .password-toggle-btn {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px;
            font-size: 0.92rem;
            transition: color 0.2s;
        }

        .password-toggle-btn:hover {
            color: #475569;
        }

        /* Buttons */
        .btn-submit {
            width: 100%;
            height: 48px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            margin-top: 6px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37,99,235,0.25);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(37,99,235,0.35);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-back-login {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 12px;
            margin-top: 14px;
            font-size: 0.85rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            transition: color 0.15s ease;
        }

        .btn-back-login:hover {
            color: #0f172a;
        }

        /* Help Box */
        .help-box {
            margin-top: 24px;
            padding: 14px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            font-size: 0.78rem;
            color: #64748b;
            text-align: center;
        }

        .help-box a {
            color: #16a34a;
            font-weight: 700;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 4px;
        }

        .help-box a:hover {
            text-decoration: underline;
        }

        /* Footer */
        .form-footer {
            margin-top: 32px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 0.78rem;
        }

        @media (max-width: 1024px) {
            .hero-panel { display: none; }
            .form-panel {
                width: 100%;
                min-width: unset;
                min-height: 100vh;
                padding: 32px 24px;
            }
        }
    </style>
</head>
<body>

    <div class="login-page">

        <!-- Hero Panel -->
        <div class="hero-panel<?php echo !empty($loginBackground) ? ' has-bg' : ''; ?>">
            <?php if (!empty($loginBackground)): ?>
                <div class="hero-bg">
                    <img src="<?php echo htmlspecialchars($loginBackground); ?>" alt="Showroom Background">
                </div>
            <?php else: ?>
                <div class="hero-pattern"></div>
            <?php endif; ?>

            <div class="hero-content">
                <div class="hero-badge">
                    <i class="fas fa-shield-halved"></i>
                    Pemulihan Akun Aman
                </div>
                <h2 class="hero-title">
                    Pusat Bantuan &<br>
                    Reset Kata Sandi
                </h2>
                <p class="hero-subtitle">
                    Layanan pemulihan akses akun resmi manajemen showroom. Pastikan Anda memperbarui kata sandi dengan kombinasi yang aman dan mudah diingat.
                </p>
            </div>
        </div>

        <!-- Form Panel -->
        <div class="form-panel">
            <div class="form-header">
                <div class="form-logo <?php echo empty($loginLogo) ? 'has-icon' : ''; ?>">
                    <?php if (!empty($loginLogo)): ?>
                        <img src="<?php echo htmlspecialchars($loginLogo); ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-key"></i>
                    <?php endif; ?>
                </div>
                <h1>Lupa Kata Sandi</h1>
                <p>Ikuti langkah verifikasi untuk mengatur ulang kata sandi akun Anda</p>
            </div>

            <!-- Step Indicator -->
            <div class="step-progress">
                <div class="step-item <?php echo $step === 1 ? 'active' : ($step > 1 ? 'done' : ''); ?>">
                    <span class="step-num"><?php echo $step > 1 ? '<i class="fas fa-check" style="font-size:0.65rem;"></i>' : '1'; ?></span>
                    <span>Cari Akun</span>
                </div>
                <div style="flex:1; height:2px; background:<?php echo $step > 1 ? '#16a34a' : '#e2e8f0'; ?>; margin:0 8px;"></div>
                <div class="step-item <?php echo $step === 2 ? 'active' : ($step === 3 ? 'done' : ''); ?>">
                    <span class="step-num"><?php echo $step === 3 ? '<i class="fas fa-check" style="font-size:0.65rem;"></i>' : '2'; ?></span>
                    <span>Kata Sandi Baru</span>
                </div>
            </div>

            <?php if (!empty($error)): ?>
            <div class="login-alert error">
                <i class="fas fa-circle-exclamation" style="margin-top:2px;"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
            <div class="login-alert success">
                <i class="fas fa-circle-check" style="margin-top:2px;"></i>
                <span><?php echo $success; ?></span>
            </div>
            <?php endif; ?>

            <!-- STEP 1: CARI AKUN -->
            <?php if ($step === 1): ?>
            <form method="POST" action="">
                <input type="hidden" name="action" value="find_account">

                <div class="field-group">
                    <label for="identifier">Nama Pengguna (Username) atau No. HP</label>
                    <div class="field-input-wrap">
                        <input 
                            type="text" 
                            class="field-input" 
                            id="identifier" 
                            name="identifier" 
                            placeholder="Contoh: Admin atau 08123456789"
                            required 
                            autofocus
                        >
                        <i class="fas fa-user field-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <span>Lanjutkan Verifikasi</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <a href="login.php" class="btn-back-login">
                <i class="fas fa-arrow-left"></i> Kembali ke Halaman Login
            </a>

            <!-- STEP 2: BUAT KATA SANDI BARU -->
            <?php elseif ($step === 2): ?>
            <div class="user-preview-card">
                <div class="user-preview-left">
                    <div class="user-avatar-circle">
                        <?php echo strtoupper(substr($_SESSION['fp_user_name'] ?? 'U', 0, 1)); ?>
                    </div>
                    <div>
                        <div class="user-preview-name"><?php echo htmlspecialchars($_SESSION['fp_user_name'] ?? ''); ?></div>
                        <div class="user-preview-sub">@<?php echo htmlspecialchars($_SESSION['fp_user_username'] ?? ''); ?> &bull; Role: <?php echo ucfirst(htmlspecialchars($_SESSION['fp_user_role'] ?? 'Staff')); ?></div>
                    </div>
                </div>
                <a href="forgot_password.php?reset=1" class="btn-change-user">Ganti</a>
            </div>

            <form method="POST" action="">
                <input type="hidden" name="action" value="reset_password">

                <?php if (!empty($_SESSION['fp_user_phone'])): ?>
                <div class="field-group">
                    <label for="verify_phone">Verifikasi Nomor HP Terdaftar (<?php echo maskPhone($_SESSION['fp_user_phone']); ?>)</label>
                    <div class="field-input-wrap">
                        <input 
                            type="text" 
                            class="field-input" 
                            id="verify_phone" 
                            name="verify_phone" 
                            placeholder="Masukkan nomor telepon akun Anda"
                            required
                        >
                        <i class="fas fa-phone field-icon"></i>
                    </div>
                </div>
                <?php endif; ?>

                <div class="field-group">
                    <label for="new_password">Kata Sandi Baru (Min. 6 Karakter)</label>
                    <div class="field-input-wrap">
                        <input 
                            type="password" 
                            class="field-input" 
                            id="new_password" 
                            name="new_password" 
                            placeholder="Masukkan kata sandi baru"
                            required
                            minlength="6"
                            style="padding-right: 44px;"
                        >
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="password-toggle-btn" onclick="togglePass('new_password', 'toggleNewIcon')">
                            <i class="fas fa-eye" id="toggleNewIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="field-group">
                    <label for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                    <div class="field-input-wrap">
                        <input 
                            type="password" 
                            class="field-input" 
                            id="confirm_password" 
                            name="confirm_password" 
                            placeholder="Ulangi kata sandi baru"
                            required
                            minlength="6"
                            style="padding-right: 44px;"
                        >
                        <i class="fas fa-shield-halved field-icon"></i>
                        <button type="button" class="password-toggle-btn" onclick="togglePass('confirm_password', 'toggleConfirmIcon')">
                            <i class="fas fa-eye" id="toggleConfirmIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-check"></i> Simpan & Perbarui Kata Sandi
                </button>
            </form>

            <a href="forgot_password.php?reset=1" class="btn-back-login">
                <i class="fas fa-arrow-left"></i> Batal & Kembali
            </a>

            <!-- STEP 3: SUKSES -->
            <?php elseif ($step === 3): ?>
            <div style="text-align:center; padding: 20px 0;">
                <div style="width:64px; height:64px; background:#dcfce7; color:#16a34a; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1.8rem; margin-bottom:16px;">
                    <i class="fas fa-circle-check"></i>
                </div>
                <h3 style="font-size:1.15rem; font-weight:800; color:#0f172a; margin-bottom:8px;">Kata Sandi Berhasil Direset!</h3>
                <p style="font-size:0.85rem; color:#64748b; margin-bottom:24px; line-height:1.6;">
                    Kata sandi akun Anda telah diperbarui. Silakan login kembali menggunakan kredensial yang baru.
                </p>
                <a href="login.php" class="btn-submit" style="text-decoration:none;">
                    <span>Masuk ke Akun Anda</span>
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <?php endif; ?>

            <!-- Help Contact -->
            <div class="help-box">
                Kendala saat reset kata sandi? Hubungi Administrator:
                <br>
                <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $adminPhone); ?>?text=Halo%20Admin,%20saya%20membutuhkan%20bantuan%20reset%20kata%20sandi%20akun%20showroom." target="_blank">
                    <i class="fab fa-whatsapp"></i> Chat WhatsApp Admin Showroom
                </a>
            </div>

            <div class="form-footer">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($loginAppName); ?>. Seluruh hak cipta dilindungi.
            </div>
        </div>
    </div>

    <script>
        function togglePass(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
