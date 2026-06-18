<?php
// ============================================================
//  config.php — Database connection & one-time setup
//  All tables and default admin are created here on first run
// ============================================================

// ─── Database Credentials ────────────────────────────────────
$db_host = "mysql-14e1053d-fblua8553-2a4c.i.aivencloud.com";   // Your DB Host
$db_user = "avnadmin";               // Your DB User
$db_pass = "AVNS_CcISlT7yc3Y5mITXstz";         // Your DB Password
$db_name = "defaultdb";       // Your DB Name
// ─────────────────────────────────────────────────────────────

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Hide detailed errors in production — log instead
    error_log("DB Connection Error: " . $e->getMessage());
    die(json_encode(['error' => 'Database connection failed.']));
}

// ─── Create Tables (first run) ───────────────────────────────
$conn->query("CREATE TABLE IF NOT EXISTS channels (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(255) NOT NULL,
    logo      TEXT,
    category  VARCHAR(100) DEFAULT 'General',
    url       TEXT NOT NULL,
    UNIQUE KEY uq_name (name(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$conn->query("CREATE TABLE IF NOT EXISTS admin_settings (
    id       INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50)  NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Login attempt tracking for brute-force protection
$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ─── Default Admin (first run only) ─────────────────────────
$stmt = $conn->prepare("SELECT id FROM admin_settings WHERE username = ?");
$stmt->bind_param("s", $admin_user);
$admin_user = 'admin';
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $default_hash = password_hash("admin123", PASSWORD_BCRYPT);
    $ins = $conn->prepare("INSERT INTO admin_settings (username, password) VALUES (?, ?)");
    $ins->bind_param("ss", $admin_user, $default_hash);
    $ins->execute();
    $ins->close();
}
$stmt->close();

// ─── Helper: Get Client IP ───────────────────────────────────
function get_client_ip(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

// ─── Helper: CSRF Token ──────────────────────────────────────
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(): bool {
    return isset($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}
