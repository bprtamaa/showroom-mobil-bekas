<?php
// auth/login.php
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

// Clear logout flag if exists
if (isset($_SESSION['logged_out'])) {
    unset($_SESSION['logged_out']);
}

// Try to load company logo/name for the login header (non-fatal)
$loginLogo = '';
$loginFavicon = '';
$loginBackground = '';
$loginAppName = defined('APP_NAME') ? APP_NAME : 'Showroom';
try {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
        $database = new Database();
        $dbconn = $database->getConnection();
        $sstmt = $dbconn->query("SELECT company_logo, company_name, login_background FROM settings WHERE id = 1");
        $srow = $sstmt->fetch(PDO::FETCH_ASSOC);
        if ($srow) {
            if (!empty($srow['company_logo']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['company_logo'])) {
                $loginLogo = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['company_logo'];
            }
            if (!empty($srow['company_name'])) $loginAppName = $srow['company_name'];

            if (!empty($srow['login_background']) && file_exists(__DIR__ . '/../uploads/settings/' . $srow['login_background'])) {
                $loginBackground = (defined('BASE_URL') ? BASE_URL : '/') . 'uploads/settings/' . $srow['login_background'];
            }
        }
    }
} catch (Exception $e) {
    // ignore - keep defaults
}

if (empty($loginFavicon) && !empty($loginLogo)) {
    $loginFavicon = $loginLogo;
}

// Fungsi sanitizeInput
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($str) {
        return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
    }
}

// Fungsi showAlert
if (!function_exists('showAlert')) {
    function showAlert($msg, $type = 'success') {
        echo "<script>alert('{$msg}');</script>";
    }
}

// Fungsi redirect
if (!function_exists('redirect')) {
    function redirect($url) {
        echo "<script>window.location.href='" . BASE_URL . $url . "';</script>";
        exit;
    }
}

// Fungsi isLoggedIn
if (!function_exists('isLoggedIn')) {
    function isLoggedIn() {
        return !empty($_SESSION['user_id']);
    }
}

// Redirect jika sudah login
if (isLoggedIn()) {
    redirect('dashboard/');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Username dan password harus diisi';
    } else {
        try {
            // Ensure $db connection exists (config should provide it, but double-check)
            if (!isset($db) || !$db) {
                require_once __DIR__ . '/../config/database.php';
                $database = new Database();
                $db = $database->getConnection();
            }
            $stmt = $db->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ? AND status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            // Validate password: prefer password_verify, but accept plain-text match as fallback
            $validPassword = false;
            if ($user) {
                $stored = $user['password'] ?? '';
                if (!empty($stored) && password_verify($password, $stored)) {
                    $validPassword = true;
                } elseif ($password === $stored) {
                    // Fallback for unhashed/stubbed passwords in DB
                    $validPassword = true;
                }
            }

            if ($user && $validPassword) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_username'] = $user['username'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_role'] = $user['role'];
                if (!empty($user['photo'])) {
                    $_SESSION['user_photo'] = $user['photo'];
                }
                
                showAlert('Login berhasil! Selamat datang ' . $user['full_name'], 'success');
                redirect('dashboard/');
            } else {
                $error = 'Nama pengguna atau kata sandi salah';
            }
        } catch (PDOException $e) {
            $error = 'Terjadi kesalahan sistem';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo APP_NAME; ?></title>
    <meta name="description" content="Masuk ke Aplikasi Manajemen Showroom Mobil Bekas">
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

        /* ── Layout ── */
        .login-page {
            display: flex;
            min-height: 100vh;
        }

        /* ── Left Hero Panel ── */
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

        .hero-panel::before {
            content: '';
            position: absolute;
            inset: 0;
            background: transparent;
            z-index: 1;
            pointer-events: none;
        }

        .hero-panel.has-bg::before {
            background: 
                linear-gradient(180deg, 
                    rgba(15,15,19,0.15) 0%, 
                    rgba(15,15,19,0.4) 50%,
                    rgba(15,15,19,0.92) 100%);
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

        /* SVG Pattern fallback when no custom background */
        .hero-pattern {
            position: absolute;
            inset: 0;
            z-index: 0;
            background: 
                radial-gradient(ellipse at 30% 20%, rgba(59,130,246,0.07) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 70%, rgba(59,130,246,0.04) 0%, transparent 50%),
                #f8fafc;
        }

        .hero-pattern::after {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.06;
            background-image: url("data:image/svg+xml,%3Csvg width='80' height='80' viewBox='0 0 80 80' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 0h80v80H0z' fill='none'/%3E%3Cpath d='M40 0v80M0 40h80' stroke='%2394a3b8' stroke-width='0.5'/%3E%3C/svg%3E");
        }

        .hero-content {
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(59,130,246,0.08);
            border: 1px solid rgba(59,130,246,0.15);
            border-radius: 100px;
            padding: 6px 16px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #3b82f6;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 20px;
            backdrop-filter: blur(8px);
        }

        .hero-panel.has-bg .hero-badge {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.2);
            color: #ffffff;
        }

        .hero-badge i {
            font-size: 0.7rem;
        }

        .hero-title {
            font-size: clamp(1.8rem, 3vw, 2.6rem);
            font-weight: 800;
            line-height: 1.15;
            color: #1e293b;
            margin-bottom: 14px;
            letter-spacing: -0.03em;
        }

        .hero-panel.has-bg .hero-title {
            color: #fafafa;
        }

        .hero-subtitle {
            font-size: 1rem;
            color: #64748b;
            line-height: 1.6;
            max-width: 460px;
        }

        .hero-panel.has-bg .hero-subtitle {
            color: #c0c0cc;
        }

        .hero-stats {
            display: flex;
            gap: 40px;
            margin-top: 36px;
            padding-top: 28px;
            border-top: 1px solid #e2e8f0;
        }

        .hero-panel.has-bg .hero-stats {
            border-top-color: rgba(255,255,255,0.08);
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: #1e293b;
            letter-spacing: -0.02em;
        }

        .hero-panel.has-bg .stat-value {
            color: #fafafa;
        }

        .stat-label {
            font-size: 0.78rem;
            color: #94a3b8;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.06em;
        }

        .hero-panel.has-bg .stat-label {
            color: #6b6b7b;
        }

        /* ── Right Form Panel ── */
        .form-panel {
            width: 520px;
            min-width: 420px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 56px;
            background: #ffffff;
            position: relative;
        }

        .form-panel::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 1px;
            background: #e2e8f0;
        }

        .form-header {
            margin-bottom: 40px;
        }

        .form-logo {
            width: 56px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 28px;
        }

        .form-logo.has-icon {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-radius: 14px;
            color: #fff;
            font-size: 24px;
            box-shadow: 0 8px 24px rgba(59,130,246,0.25);
        }

        .form-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            border-radius: 10px;
        }

        .form-header h1 {
            font-size: 1.55rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 8px;
            letter-spacing: -0.02em;
        }

        .form-header p {
            color: #64748b;
            font-size: 0.92rem;
            line-height: 1.5;
        }

        /* ── Alert ── */
        .login-alert {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 24px;
            animation: alertSlideIn 0.35s ease-out;
        }

        .login-alert.error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
        }

        .login-alert i {
            font-size: 0.9rem;
            flex-shrink: 0;
        }

        .login-alert.fade-out {
            opacity: 0;
            transform: translateY(-8px);
            transition: all 0.4s ease;
        }

        @keyframes alertSlideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ── Form Fields ── */
        .field-group {
            margin-bottom: 20px;
        }

        .field-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 8px;
            letter-spacing: 0.02em;
        }

        .field-input-wrap {
            position: relative;
        }

        .field-input-wrap i.field-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }

        .field-input {
            width: 100%;
            height: 50px;
            padding: 0 16px 0 46px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            color: #1e293b;
            font-family: inherit;
            font-size: 0.92rem;
            transition: all 0.2s ease;
            outline: none;
        }

        .field-input::placeholder {
            color: #94a3b8;
        }

        .field-input:hover {
            border-color: #cbd5e1;
        }

        .field-input:focus {
            border-color: #3b82f6;
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(59,130,246,0.1);
        }

        .field-input:focus ~ i.field-icon {
            color: #3b82f6;
        }

        /* autofill fix */
        .field-input:-webkit-autofill,
        .field-input:-webkit-autofill:hover,
        .field-input:-webkit-autofill:focus {
            -webkit-box-shadow: 0 0 0 30px #f8fafc inset !important;
            -webkit-text-fill-color: #1e293b !important;
            border-color: #e2e8f0;
            transition: background-color 5000s ease-in-out 0s;
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
            line-height: 1;
        }

        .password-toggle-btn:hover {
            color: #475569;
        }

        .forgot-link {
            font-size: 0.78rem;
            font-weight: 600;
            color: #2563eb;
            text-decoration: none;
            transition: color 0.15s ease;
        }
        .forgot-link:hover {
            color: #1d4ed8;
            text-decoration: underline;
        }

        /* ── Submit Button ── */
        .btn-login {
            width: 100%;
            height: 50px;
            border: none;
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.25s ease;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: #ffffff;
            margin-top: 8px;
            letter-spacing: 0.01em;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.15) 0%, transparent 60%);
            opacity: 0;
            transition: opacity 0.25s;
        }

        .btn-login:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 24px rgba(59,130,246,0.35);
        }

        .btn-login:hover::before {
            opacity: 1;
        }

        .btn-login:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(59,130,246,0.2);
        }

        .btn-login:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-login .spinner-border {
            width: 18px;
            height: 18px;
            border-width: 2px;
        }

        /* ── Footer ── */
        .form-footer {
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
            color: #94a3b8;
            font-size: 0.8rem;
        }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
            .hero-panel {
                display: none;
            }

            .form-panel {
                width: 100%;
                min-width: unset;
                min-height: 100vh;
                padding: 32px 24px;
            }

            .form-panel::before {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .form-panel {
                padding: 24px 20px;
            }

            .form-header {
                margin-bottom: 32px;
            }

            .form-header h1 {
                font-size: 1.35rem;
            }

            .field-input,
            .btn-login {
                height: 48px;
            }
        }

        /* ── Entrance animation ── */
        .form-panel {
            animation: formFadeIn 0.5s ease-out;
        }

        @keyframes formFadeIn {
            from { opacity: 0; transform: translateX(12px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .hero-content {
            animation: heroFadeUp 0.6s ease-out 0.15s both;
        }

        @keyframes heroFadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

    <div class="login-page">

        <!-- ── Hero Panel ── -->
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
                    Sistem Manajemen Terpadu
                </div>
                <h2 class="hero-title">
                    Kelola Showroom<br>
                    Anda dengan Mudah
                </h2>
                <p class="hero-subtitle">
                    Platform manajemen showroom mobil bekas yang membantu Anda mengelola inventaris, transaksi, dan pelanggan secara efisien.
                </p>
            </div>
        </div>

        <!-- ── Form Panel ── -->
        <div class="form-panel">
            <div class="form-header">
                <div class="form-logo <?php echo empty($loginLogo) ? 'has-icon' : ''; ?>">
                    <?php if (!empty($loginLogo)): ?>
                        <img src="<?php echo htmlspecialchars($loginLogo); ?>" alt="Logo">
                    <?php else: ?>
                        <i class="fas fa-car-side"></i>
                    <?php endif; ?>
                </div>
                <h1><?php echo htmlspecialchars($loginAppName); ?></h1>
                <p>Masuk ke akun Anda untuk melanjutkan</p>
            </div>

            <?php if (!empty($error)): ?>
            <div class="login-alert error" id="loginAlert" role="alert">
                <i class="fas fa-circle-exclamation"></i>
                <span><?php echo $error; ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="" id="loginForm" autocomplete="on">
                <div class="field-group">
                    <label for="username">Nama Pengguna</label>
                    <div class="field-input-wrap">
                        <input 
                            type="text" 
                            class="field-input" 
                            id="username" 
                            name="username" 
                            placeholder="Masukkan nama pengguna"
                            value="<?php echo htmlspecialchars($username ?? ''); ?>" 
                            required 
                            autocomplete="username"
                        >
                        <i class="fas fa-user field-icon"></i>
                    </div>
                </div>

                <div class="field-group">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label for="password" style="margin-bottom: 0;">Kata Sandi</label>
                        <a href="forgot_password.php" class="forgot-link">Lupa Kata Sandi?</a>
                    </div>
                    <div class="field-input-wrap">
                        <input 
                            type="password" 
                            class="field-input" 
                            id="password" 
                            name="password" 
                            placeholder="Masukkan kata sandi"
                            required 
                            autocomplete="current-password"
                            style="padding-right: 46px;"
                        >
                        <i class="fas fa-lock field-icon"></i>
                        <button type="button" class="password-toggle-btn" id="passwordToggle" title="Tampilkan password">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn-login" id="btnSubmit">
                    <span>Masuk</span>
                    <i class="fas fa-arrow-right"></i>
                </button>
            </form>

            <div class="form-footer">
                &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($loginAppName); ?>. Seluruh hak cipta dilindungi.
            </div>
        </div>
    </div>

    <script>
        // Prevent going back to login page after logout
        window.history.pushState(null, "", window.location.href);
        window.onpopstate = function() {
            window.history.pushState(null, "", window.location.href);
        };

        // Toggle password visibility
        document.getElementById('passwordToggle').addEventListener('click', function() {
            const pwd = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });

        // Submit loading state
        document.getElementById('loginForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnSubmit');
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> <span>Memproses...</span>';
            btn.disabled = true;
        });

        // Auto-hide error alert
        document.addEventListener('DOMContentLoaded', function() {
            const alert = document.getElementById('loginAlert');
            if (alert) {
                setTimeout(function() {
                    alert.classList.add('fade-out');
                    setTimeout(function() { alert.style.display = 'none'; }, 400);
                }, 5000);
            }
        });
    </script>
</body>
</html>
