<?php
session_start();
session_regenerate_id(true);
require_once 'config.php';

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_MINUTES',    15);

$client_ip = get_client_ip();
$error     = '';
$success   = '';

function count_recent_attempts(mysqli $conn, string $ip): int {
    $mins = LOCKOUT_MINUTES;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
    );
    $stmt->bind_param("si", $ip, $mins);
    $stmt->execute();
    $stmt->bind_result($cnt);
    $stmt->fetch();
    $stmt->close();
    return (int)$cnt;
}

function log_attempt(mysqli $conn, string $ip): void {
    $stmt = $conn->prepare("INSERT INTO login_attempts (ip) VALUES (?)");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
}

function clear_attempts(mysqli $conn, string $ip): void {
    $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip = ?");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $stmt->close();
}

$is_locked = count_recent_attempts($conn, $client_ip) >= MAX_LOGIN_ATTEMPTS;

if (isset($_GET['logout']) && isset($_SESSION['admin_logged'])) {
    session_destroy();
    header("Location: admin.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if ($is_locked) {
        $error = "Too many failed attempts. Please try again in " . LOCKOUT_MINUTES . " minutes.";
    } elseif (!csrf_verify()) {
        $error = "Invalid request. Please refresh the page and try again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $stmt = $conn->prepare("SELECT password FROM admin_settings WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($hash);
        if ($stmt->fetch() && password_verify($password, $hash)) {
            clear_attempts($conn, $client_ip);
            $_SESSION['admin_logged'] = true;
            $_SESSION['admin_user']   = $username;
            $_SESSION['csrf_token']   = bin2hex(random_bytes(32));
            header("Location: admin.php");
            exit();
        } else {
            log_attempt($conn, $client_ip);
            $remaining = MAX_LOGIN_ATTEMPTS - count_recent_attempts($conn, $client_ip);
            $error = "Incorrect username or password. Attempts remaining: " . max(0, $remaining);
        }
        $stmt->close();
    }
}

$logged_in = isset($_SESSION['admin_logged']) && $_SESSION['admin_logged'] === true;

if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_pass'])) {
    if (!csrf_verify()) {
        $error = "CSRF verification failed.";
    } else {
        $new_pass  = $_POST['new_password']  ?? '';
        $conf_pass = $_POST['conf_password'] ?? '';
        if (strlen($new_pass) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif ($new_pass !== $conf_pass) {
            $error = "Passwords do not match.";
        } else {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT, ['cost' => 12]);
            $user = $_SESSION['admin_user'];
            $stmt = $conn->prepare("UPDATE admin_settings SET password = ? WHERE username = ?");
            $stmt->bind_param("ss", $hash, $user);
            $stmt->execute();
            $stmt->close();
            $success = "Password updated successfully.";
        }
    }
}

if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['sync_url']) || isset($_FILES['m3u_file']))) {
    if (!csrf_verify()) {
        $error = "CSRF verification failed.";
    } else {
        $m3u_content = '';
        if (!empty($_POST['m3u_url'])) {
            $url = filter_var(trim($_POST['m3u_url']), FILTER_VALIDATE_URL);
            if (!$url) {
                $error = "Please enter a valid URL.";
            } else {
                $ctx = stream_context_create(['http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 Spotzfy/1.0']]);
                $m3u_content = @file_get_contents($url, false, $ctx);
                if ($m3u_content === false) $error = "Failed to fetch data from the provided URL.";
            }
        } elseif (isset($_FILES['m3u_file']) && $_FILES['m3u_file']['error'] === UPLOAD_ERR_OK) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES['m3u_file']['tmp_name']);
            finfo_close($finfo);
            $allowed = ['text/plain', 'application/octet-stream', 'audio/x-mpegurl', 'application/vnd.apple.mpegurl'];
            if (!in_array($mime, $allowed)) {
                $error = "Invalid file type. Only .m3u and .m3u8 files are allowed.";
            } elseif ($_FILES['m3u_file']['size'] > 10 * 1024 * 1024) {
                $error = "File size exceeds the 10 MB limit.";
            } else {
                $m3u_content = file_get_contents($_FILES['m3u_file']['tmp_name']);
            }
        }

        if (empty($error) && $m3u_content) {
            preg_match_all(
                '/#EXTINF:-1[^\n]*tvg-logo="([^"]*)"[^\n]*group-title="([^"]*)"[^,]*,([^\n]+)\n([^\n]+)/u',
                $m3u_content, $matches, PREG_SET_ORDER
            );
            if (empty($matches)) {
                $error = "No valid channels found. Please check the M3U format.";
            } else {
                $conn->begin_transaction();
                try {
                    $conn->query("TRUNCATE TABLE channels");
                    $stmt = $conn->prepare("INSERT IGNORE INTO channels (name, logo, category, url) VALUES (?, ?, ?, ?)");
                    $inserted = 0;
                    foreach ($matches as $m) {
                        $logo = !empty(trim($m[1])) ? trim($m[1]) : '';
                        $cat  = !empty(trim($m[2])) ? trim($m[2]) : 'General';
                        $name = trim($m[3]);
                        $url  = trim($m[4]);
                        if (empty($name) || !filter_var($url, FILTER_VALIDATE_URL)) continue;
                        if (strlen($name) > 255) $name = substr($name, 0, 255);
                        $stmt->bind_param("ssss", $name, $logo, $cat, $url);
                        $stmt->execute();
                        $inserted++;
                    }
                    $stmt->close();
                    $conn->commit();
                    $success = "Sync complete. <strong>$inserted</strong> channels added to the database.";
                } catch (Exception $ex) {
                    $conn->rollback();
                    error_log("M3U sync error: " . $ex->getMessage());
                    $error = "Sync failed. Please try again.";
                }
            }
        }
    }
}

$channel_count = 0;
if ($logged_in) {
    $r = $conn->query("SELECT COUNT(*) FROM channels");
    $channel_count = (int)$r->fetch_row()[0];
}

$token = csrf_token();
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel &mdash; Spotzfy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { darkMode: 'class' };</script>
</head>
<body class="bg-gray-950 text-white min-h-screen flex items-center justify-center p-4">
<div class="bg-gray-900 border border-gray-800 rounded-2xl shadow-2xl w-full max-w-lg p-6">

<?php if (!$logged_in): ?>

    <div class="flex flex-col items-center mb-7">
        <div class="bg-red-600/10 border border-red-600/30 rounded-full p-4 mb-3">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
        </div>
        <h2 class="text-2xl font-bold text-white">Admin Login</h2>
        <p class="text-xs text-gray-500 mt-1">Spotzfy Secure Panel</p>
    </div>

    <?php if ($is_locked): ?>
        <div class="flex items-center gap-3 bg-red-900/30 border border-red-700/50 text-red-300 text-sm rounded-lg px-4 py-3 mb-5">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Account locked. Please try again in <?= LOCKOUT_MINUTES ?> minutes.
        </div>
    <?php elseif ($error): ?>
        <div class="flex items-center gap-3 bg-red-900/30 border border-red-700/50 text-red-300 text-sm rounded-lg px-4 py-3 mb-5">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $token ?>">
        <input type="hidden" name="login" value="1">

        <label class="block text-xs font-medium text-gray-400 mb-1">Username</label>
        <div class="relative mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
            <input type="text" name="username" required autocomplete="username"
                   class="w-full pl-10 pr-3 p-2.5 rounded-lg bg-gray-800 border border-gray-700 text-white text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                   placeholder="admin" <?= $is_locked ? 'disabled' : '' ?>>
        </div>

        <label class="block text-xs font-medium text-gray-400 mb-1">Password</label>
        <div class="relative mb-6">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <input type="password" name="password" required autocomplete="current-password"
                   class="w-full pl-10 pr-3 p-2.5 rounded-lg bg-gray-800 border border-gray-700 text-white text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                   placeholder="••••••••" <?= $is_locked ? 'disabled' : '' ?>>
        </div>

        <button type="submit" <?= $is_locked ? 'disabled' : '' ?>
                class="w-full flex items-center justify-center gap-2 bg-red-600 hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed p-2.5 rounded-lg font-semibold text-sm transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
            </svg>
            Sign In
        </button>
    </form>

<?php else: ?>

    <div class="flex justify-between items-center mb-6 border-b border-gray-800 pb-4">
        <div class="flex items-center gap-3">
            <div class="bg-green-600/10 border border-green-600/30 rounded-full p-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                </svg>
            </div>
            <div>
                <h2 class="text-base font-bold text-white">Control Dashboard</h2>
                <p class="text-xs text-gray-500">Logged in as <span class="text-gray-300 font-medium"><?= htmlspecialchars($_SESSION['admin_user'], ENT_QUOTES) ?></span></p>
            </div>
        </div>
        <a href="?logout=1" class="flex items-center gap-1.5 text-xs bg-gray-800 hover:bg-red-700 border border-gray-700 hover:border-red-600 px-3 py-1.5 rounded-lg transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Logout
        </a>
    </div>

    <div class="grid grid-cols-2 gap-3 mb-5">
        <div class="bg-gray-800/60 border border-gray-700/50 rounded-xl p-4 flex items-center gap-3">
            <div class="bg-red-600/10 border border-red-600/20 rounded-lg p-2 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-white leading-none"><?= $channel_count ?></p>
                <p class="text-xs text-gray-400 mt-0.5">Total Channels</p>
            </div>
        </div>
        <a href="index.php" class="bg-gray-800/60 border border-gray-700/50 hover:border-gray-600 rounded-xl p-4 flex items-center gap-3 transition">
            <div class="bg-blue-600/10 border border-blue-600/20 rounded-lg p-2 shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
            </div>
            <div>
                <p class="text-sm font-semibold text-white">View Site</p>
                <p class="text-xs text-gray-400">Open frontend</p>
            </div>
        </a>
    </div>

    <?php if ($success): ?>
        <div class="flex items-center gap-3 bg-green-900/30 border border-green-700/50 text-green-300 text-sm rounded-lg px-4 py-3 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <polyline points="20 6 9 17 4 12"/>
            </svg>
            <?= $success ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="flex items-center gap-3 bg-red-900/30 border border-red-700/50 text-red-300 text-sm rounded-lg px-4 py-3 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="mb-4 bg-gray-800/40 border border-gray-700/50 p-4 rounded-xl">
        <input type="hidden" name="csrf_token" value="<?= $token ?>">
        <div class="flex items-center gap-2 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/>
                <path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/>
            </svg>
            <h3 class="text-sm font-semibold text-gray-200">M3U Playlist Sync</h3>
        </div>

        <label class="block text-xs text-gray-400 mb-1">Playlist URL</label>
        <input type="url" name="m3u_url" placeholder="https://example.com/playlist.m3u8"
               class="w-full p-2 mb-3 rounded-lg bg-gray-900 border border-gray-600 text-sm text-white focus:outline-none focus:ring-2 focus:ring-red-500 placeholder-gray-600">

        <div class="flex items-center gap-3 my-3">
            <div class="flex-1 h-px bg-gray-700"></div>
            <span class="text-xs text-gray-600 font-medium">OR</span>
            <div class="flex-1 h-px bg-gray-700"></div>
        </div>

        <label class="block text-xs text-gray-400 mb-1">Upload File <span class="text-gray-600">(.m3u / .m3u8)</span></label>
        <input type="file" name="m3u_file" accept=".m3u,.m3u8"
               class="w-full p-2 mb-4 rounded-lg bg-gray-900 border border-gray-600 text-sm text-gray-300 file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:bg-red-700 file:text-white file:text-xs file:cursor-pointer focus:outline-none">

        <button type="submit" name="sync_url"
                class="w-full flex items-center justify-center gap-2 bg-emerald-700 hover:bg-emerald-600 p-2.5 rounded-lg text-sm font-semibold transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Start Database Sync
        </button>
        <p class="flex items-center gap-1.5 text-[10px] text-gray-600 mt-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            Syncing will replace all existing channels with the new playlist.
        </p>
    </form>

    <form method="POST" class="bg-gray-800/40 border border-gray-700/50 p-4 rounded-xl">
        <input type="hidden" name="csrf_token" value="<?= $token ?>">
        <div class="flex items-center gap-2 mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            <h3 class="text-sm font-semibold text-gray-200">Change Password</h3>
        </div>

        <label class="block text-xs text-gray-400 mb-1">New Password <span class="text-gray-600">(min. 8 characters)</span></label>
        <input type="password" name="new_password" minlength="8" required
               class="w-full p-2 mb-3 rounded-lg bg-gray-900 border border-gray-600 text-sm text-white focus:outline-none focus:ring-2 focus:ring-purple-500 placeholder-gray-600"
               placeholder="Enter new password">

        <label class="block text-xs text-gray-400 mb-1">Confirm New Password</label>
        <input type="password" name="conf_password" minlength="8" required
               class="w-full p-2 mb-4 rounded-lg bg-gray-900 border border-gray-600 text-sm text-white focus:outline-none focus:ring-2 focus:ring-purple-500 placeholder-gray-600"
               placeholder="Re-enter new password">

        <button type="submit" name="change_pass"
                class="w-full flex items-center justify-center gap-2 bg-purple-700 hover:bg-purple-600 p-2.5 rounded-lg text-sm font-semibold transition">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v14a2 2 0 0 1-2 2z"/>
                <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
            </svg>
            Update Password
        </button>
    </form>

<?php endif; ?>
</div>
</body>
</html>
