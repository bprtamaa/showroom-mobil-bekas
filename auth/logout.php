<?php
// auth/logout.php
// Start output buffering first to prevent headers already sent errors
if (!ob_get_level()) {
    ob_start();
}

// Prevent browser caching
if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
}

// Include config first (which handles session start)
require_once __DIR__ . '/../config/config.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store logout flag before destroying session
$_SESSION = array();

// Delete session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Destroy the session
session_destroy();

// Start a new session for the redirect
session_start();

// Set a flag to indicate user has logged out
$_SESSION['logged_out'] = true;

// Define BASE_URL if not defined
if (!defined('BASE_URL')) {
    define('BASE_URL', '/showroom/');
}

// Redirect to login page with JavaScript to prevent back button
?>
<!DOCTYPE html>
<html>
<head>
    <title>Logging out...</title>
    <script>
        // Clear browser history to prevent back button
        window.history.pushState(null, "", window.location.href);
        window.onpopstate = function() {
            window.history.pushState(null, "", window.location.href);
        };
        
        // Redirect to login
        window.location.replace("<?php echo BASE_URL; ?>auth/login.php");
    </script>
</head>
<body>
    <p>Logging out...</p>
</body>
</html>
<?php
exit;