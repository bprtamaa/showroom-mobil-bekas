<?php
// keep_session.php
// Endpoint to keep the PHP session alive via AJAX
require_once 'config/config.php';

// config.php logic automatically updates $_SESSION['last_activity'] 
// if the user is logged in.
if (isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'active', 'time' => time()]);
} else {
    http_response_code(401);
    echo json_encode(['status' => 'expired']);
}
