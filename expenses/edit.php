<?php
// expenses/edit.php - Redirect to expenses index (editing is managed via modal)
require_once '../config/config.php';
requireLogin();

header("Location: " . BASE_URL . "expenses/");
exit;
