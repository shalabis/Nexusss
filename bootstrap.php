<?php
require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME_SECONDS);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'httponly' => true,
        'secure' => SESSION_SECURE_COOKIE,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function ensure_schema(): void {
    $pdo = get_pdo();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id VARCHAR(32) NOT NULL UNIQUE,
            full_name VARCHAR(120) NOT NULL,
            department VARCHAR(120) NOT NULL,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('admin','it','user') NOT NULL DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_code VARCHAR(40) NULL UNIQUE,
            user_id INT NOT NULL,
            category VARCHAR(80) NULL,
            category_detail VARCHAR(80) NULL,
            category_sub_detail VARCHAR(80) NULL,
            category_detail_note VARCHAR(160) NULL,
            problem_location VARCHAR(160) NULL,
            details TEXT NOT NULL,
            file_path VARCHAR(255) NULL,
            file_name VARCHAR(255) NULL,
            status ENUM('pending','in_progress','done','rejected') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            CONSTRAINT fk_complaints_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            complaint_id INT NULL,
            title VARCHAR(160) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id),
            INDEX (complaint_id),
            CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_notifications_complaint FOREIGN KEY (complaint_id) REFERENCES complaints(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
    );

    // Ensure role enum supports new roles in existing databases.
    $pdo->exec(
        "ALTER TABLE users 
         MODIFY role ENUM('admin','it','user') NOT NULL DEFAULT 'user';"
    );

    // Add new columns for existing databases (compatible with older MySQL).
    $columnCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS 
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND COLUMN_NAME = :col"
    );

    $addColumn = function (string $column, string $definition) use ($pdo, $columnCheck) {
        $columnCheck->execute(['db' => DB_NAME, 'col' => $column]);
        $exists = (int) $columnCheck->fetchColumn() > 0;
        if (!$exists) {
            $pdo->exec('ALTER TABLE users ADD COLUMN ' . $column . ' ' . $definition);
        }
    };

    $addColumn('staff_id', 'VARCHAR(32) NULL');
    $addColumn('full_name', 'VARCHAR(120) NULL');
    $addColumn('department', 'VARCHAR(120) NULL');
    $addColumn('email', 'VARCHAR(190) NULL');
    $addColumn('email_verified_at', 'DATETIME NULL');
    $addColumn('email_verification_code', 'VARCHAR(16) NULL');
    $addColumn('email_verification_expires_at', 'DATETIME NULL');
    $addColumn('phone_number', 'VARCHAR(20) NULL');
    $addColumn('password_reset_code', 'VARCHAR(16) NULL');
    $addColumn('password_reset_expires_at', 'DATETIME NULL');

    // Add missing complaint columns for existing databases.
    $complaintColumnCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS 
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'complaints' AND COLUMN_NAME = :col"
    );
    $addComplaintColumn = function (string $column, string $definition) use ($pdo, $complaintColumnCheck) {
        $complaintColumnCheck->execute(['db' => DB_NAME, 'col' => $column]);
        $exists = (int) $complaintColumnCheck->fetchColumn() > 0;
        if (!$exists) {
            $pdo->exec('ALTER TABLE complaints ADD COLUMN ' . $column . ' ' . $definition);
        }
    };

    $addComplaintColumn("status", "ENUM('pending','in_progress','done','rejected') NOT NULL DEFAULT 'pending'");
    $addComplaintColumn("complaint_code", "VARCHAR(40) NULL");
    $addComplaintColumn("category", "VARCHAR(80) NULL");
    $addComplaintColumn("category_detail", "VARCHAR(80) NULL");
    $addComplaintColumn("category_sub_detail", "VARCHAR(80) NULL");
    $addComplaintColumn("category_detail_note", "VARCHAR(160) NULL");
    $addComplaintColumn("problem_location", "VARCHAR(160) NULL");

    // Backfill any missing data to keep the app working smoothly.
    $pdo->exec("UPDATE users SET staff_id = CONCAT('STAFF-', id) WHERE staff_id IS NULL OR staff_id = '';");
    $pdo->exec("UPDATE users SET full_name = COALESCE(NULLIF(username, ''), staff_id) WHERE full_name IS NULL OR full_name = '';");
    $pdo->exec("UPDATE users SET department = 'Unassigned' WHERE department IS NULL OR department = '';");
    $pdo->exec("UPDATE users SET username = staff_id WHERE username IS NULL OR username = '';");
    $pdo->exec("
        UPDATE complaints
        SET complaint_code = CONCAT('CMP-', DATE_FORMAT(created_at, '%Y%m%d'), '-', LPAD(id, 6, '0'))
        WHERE complaint_code IS NULL OR complaint_code = '';
    ");

    // Enforce non-null constraints after backfill.
    $pdo->exec("ALTER TABLE users MODIFY staff_id VARCHAR(32) NOT NULL;");
    $pdo->exec("ALTER TABLE users MODIFY full_name VARCHAR(120) NOT NULL;");
    $pdo->exec("ALTER TABLE users MODIFY department VARCHAR(120) NOT NULL;");
    $pdo->exec("ALTER TABLE complaints MODIFY complaint_code VARCHAR(40) NULL;");

    $emailIndexCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND INDEX_NAME = :index_name"
    );
    $emailIndexCheck->execute([
        'db' => DB_NAME,
        'index_name' => 'uniq_users_email',
    ]);
    if ((int) $emailIndexCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE INDEX uniq_users_email (email)");
    }

    $phoneIndexCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'users' AND INDEX_NAME = :index_name"
    );
    $phoneIndexCheck->execute([
        'db' => DB_NAME,
        'index_name' => 'uniq_users_phone_number',
    ]);
    if ((int) $phoneIndexCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE INDEX uniq_users_phone_number (phone_number)");
    }

    $complaintIndexCheck = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'complaints' AND INDEX_NAME = :index_name"
    );
    $complaintIndexCheck->execute([
        'db' => DB_NAME,
        'index_name' => 'uniq_complaint_code',
    ]);
    if ((int) $complaintIndexCheck->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE complaints ADD UNIQUE INDEX uniq_complaint_code (complaint_code)");
    }

    $adminCountStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $adminCount = (int) $adminCountStmt->fetchColumn();

    if (
        ENABLE_ADMIN_BOOTSTRAP
        && $adminCount === 0
        && ADMIN_STAFF_ID !== ''
        && ADMIN_PASSWORD_PLAIN !== ''
        && ADMIN_FULL_NAME !== ''
    ) {
        $hash = password_hash(ADMIN_PASSWORD_PLAIN, PASSWORD_DEFAULT);
        $insert = $pdo->prepare('INSERT INTO users (staff_id, full_name, department, username, password_hash, role) VALUES (:staff_id, :full_name, :department, :username, :hash, :role)');
        $insert->execute([
            'staff_id' => ADMIN_STAFF_ID,
            'full_name' => ADMIN_FULL_NAME,
            'department' => ADMIN_DEPARTMENT,
            'username' => ADMIN_STAFF_ID,
            'hash' => $hash,
            'role' => 'admin',
        ]);
    }
}

function refresh_session_user(): ?array {
    if (empty($_SESSION['user']['id'])) {
        return null;
    }

    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, staff_id, full_name, department, role, email, email_verified_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $_SESSION['user']['id']]);
    $user = $stmt->fetch();

    if (!$user) {
        $_SESSION = [];
        session_destroy();
        return null;
    }

    $_SESSION['user'] = [
        'id' => $user['id'],
        'staff_id' => $user['staff_id'],
        'full_name' => $user['full_name'],
        'department' => $user['department'],
        'role' => $user['role'],
        'email' => $user['email'],
        'email_verified_at' => $user['email_verified_at'],
    ];

    return $_SESSION['user'];
}

function current_user_home(string $role): string {
    if ($role === 'admin') {
        return '/admin/index.php';
    }

    if ($role === 'it') {
        return '/it/index.php';
    }

    return '/user/index.php';
}

function require_login(bool $allowUnverified = false): array {
    $user = refresh_session_user();
    if (!$user) {
        header('Location: /index.php');
        exit;
    }

    if (!$allowUnverified && user_requires_email_verification($user)) {
        header('Location: /email_verification.php');
        exit;
    }

    return $user;
}

function require_admin(): void {
    $user = require_login();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo 'Access denied.';
        exit;
    }
}

function generate_otp_code(): string {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function user_requires_email_verification(array $user): bool {
    return empty($user['email']) || empty($user['email_verified_at']);
}

function set_email_verification_code(int $userId, string $email): string {
    $pdo = get_pdo();
    $code = generate_otp_code();
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));

    $stmt = $pdo->prepare(
        'UPDATE users
         SET email = :email,
             email_verified_at = NULL,
             email_verification_code = :code,
             email_verification_expires_at = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        'email' => $email,
        'code' => $code,
        'expires_at' => $expiresAt,
        'id' => $userId,
    ]);

    refresh_session_user();

    return $code;
}

function clear_email_verification_code(int $userId): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE users
         SET email_verification_code = NULL,
             email_verification_expires_at = NULL
         WHERE id = :id'
    );
    $stmt->execute(['id' => $userId]);
    refresh_session_user();
}

function email_verification_otp_valid(int $userId, string $otp): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT email_verification_code, email_verification_expires_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email_verification_code']) || empty($user['email_verification_expires_at'])) {
        return false;
    }

    if (!hash_equals((string) $user['email_verification_code'], $otp)) {
        return false;
    }

    if (strtotime((string) $user['email_verification_expires_at']) < time()) {
        return false;
    }

    return true;
}

function mark_email_verified(int $userId): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE users
         SET email_verified_at = NOW(),
             email_verification_code = NULL,
             email_verification_expires_at = NULL
         WHERE id = :id'
    );
    $stmt->execute(['id' => $userId]);
    refresh_session_user();
}

function set_password_reset_code(int $userId): string {
    $pdo = get_pdo();
    $code = generate_otp_code();
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60));

    $stmt = $pdo->prepare(
        'UPDATE users
         SET password_reset_code = :code,
             password_reset_expires_at = :expires_at
         WHERE id = :id'
    );
    $stmt->execute([
        'code' => $code,
        'expires_at' => $expiresAt,
        'id' => $userId,
    ]);

    return $code;
}

function clear_password_reset_code(int $userId): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE users
         SET password_reset_code = NULL,
             password_reset_expires_at = NULL
         WHERE id = :id'
    );
    $stmt->execute(['id' => $userId]);
}

function password_reset_otp_valid(int $userId, string $otp): bool {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT password_reset_code, password_reset_expires_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['password_reset_code']) || empty($user['password_reset_expires_at'])) {
        return false;
    }

    if (!hash_equals((string) $user['password_reset_code'], $otp)) {
        return false;
    }

    if (strtotime((string) $user['password_reset_expires_at']) < time()) {
        return false;
    }

    return true;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}

function csrf_check(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function client_ip_address(): string {
    $value = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    return $value !== '' ? $value : 'unknown';
}

function rate_limit_key(string $scope, string $identifier = ''): string {
    $ip = client_ip_address();
    return $scope . '|' . $ip . '|' . strtolower(trim($identifier));
}

function rate_limit_check(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): bool {
    if (!isset($_SESSION['rate_limits']) || !is_array($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $key = rate_limit_key($scope, $identifier);
    $now = time();
    $windowStart = $now - $windowSeconds;
    $attempts = $_SESSION['rate_limits'][$key] ?? [];

    if (!is_array($attempts)) {
        $attempts = [];
    }

    $attempts = array_values(array_filter($attempts, static function ($timestamp) use ($windowStart) {
        return is_int($timestamp) && $timestamp >= $windowStart;
    }));

    $_SESSION['rate_limits'][$key] = $attempts;

    return count($attempts) < $maxAttempts;
}

function rate_limit_hit(string $scope, string $identifier): void {
    if (!isset($_SESSION['rate_limits']) || !is_array($_SESSION['rate_limits'])) {
        $_SESSION['rate_limits'] = [];
    }

    $key = rate_limit_key($scope, $identifier);
    $attempts = $_SESSION['rate_limits'][$key] ?? [];
    if (!is_array($attempts)) {
        $attempts = [];
    }

    $attempts[] = time();
    $_SESSION['rate_limits'][$key] = array_slice($attempts, -25);
}

function rate_limit_enforce(string $scope, string $identifier, int $maxAttempts, int $windowSeconds, string $message): ?string {
    if (!rate_limit_check($scope, $identifier, $maxAttempts, $windowSeconds)) {
        return $message;
    }

    rate_limit_hit($scope, $identifier);
    return null;
}

function build_complaint_code(int $id, ?string $createdAt = null): string {
    $datePart = date('Ymd');
    if ($createdAt) {
        $timestamp = strtotime($createdAt);
        if ($timestamp !== false) {
            $datePart = date('Ymd', $timestamp);
        }
    }

    return sprintf('CMP-%s-%06d', $datePart, $id);
}

function create_notification(int $userId, string $title, string $message, ?int $complaintId = null): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO notifications (user_id, complaint_id, title, message, is_read)
         VALUES (:user_id, :complaint_id, :title, :message, 0)'
    );
    $stmt->execute([
        'user_id' => $userId,
        'complaint_id' => $complaintId,
        'title' => $title,
        'message' => $message,
    ]);
}

function create_role_notifications(string $role, string $title, string $message, ?int $complaintId = null): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE role = :role');
    $stmt->execute(['role' => $role]);
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$userIds) {
        return;
    }

    $insert = $pdo->prepare(
        'INSERT INTO notifications (user_id, complaint_id, title, message, is_read)
         VALUES (:user_id, :complaint_id, :title, :message, 0)'
    );

    foreach ($userIds as $userId) {
        $insert->execute([
            'user_id' => (int) $userId,
            'complaint_id' => $complaintId,
            'title' => $title,
            'message' => $message,
        ]);
    }
}

function get_user_notifications(int $userId, int $limit = 5): array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT n.id, n.title, n.message, n.is_read, n.created_at, c.complaint_code
         FROM notifications n
         LEFT JOIN complaints c ON c.id = n.complaint_id
         WHERE n.user_id = :user_id
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ' . max(1, (int) $limit)
    );
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll();
}

function get_unread_notification_count(int $userId): int {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM notifications
         WHERE user_id = :user_id AND is_read = 0'
    );
    $stmt->execute(['user_id' => $userId]);

    return (int) $stmt->fetchColumn();
}

function mark_all_notifications_read(int $userId): void {
    $pdo = get_pdo();
    $stmt = $pdo->prepare(
        'UPDATE notifications
         SET is_read = 1
         WHERE user_id = :user_id AND is_read = 0'
    );
    $stmt->execute(['user_id' => $userId]);
}

function complaint_attachment_url(int $complaintId): string {
    return '/download_attachment.php?id=' . $complaintId;
}

function complaint_status_display(string $status): string {
    return ucwords(str_replace('_', ' ', $status));
}

function role_display_name(string $role): string {
    if ($role === 'it') {
        return 'IT Support';
    }

    if ($role === 'user') {
        return 'Employee';
    }

    return 'Admin';
}

function complaint_categories(): array {
    return [
        'Account & Log in',
        'Hardware',
        'Software Support',
        'Connectivity & Network',
    ];
}

function complaint_category_valid(string $category): bool {
    return in_array($category, complaint_categories(), true);
}

function complaint_category_detail_options(string $category): array {
    if ($category === 'Account & Log in') {
        return [
            'Forgot my account',
            'My account is lock',
            'Need access to new folder / app',
            'MFA / 2FA is not working',
        ];
    }

    if ($category === 'Hardware') {
        return [
            'Laptop',
            'Monitor',
            'Barcode Scanner',
            'Other',
        ];
    }

    if ($category === 'Software Support') {
        return [
            'Fix issue / error message',
            'Request new software',
            'Update Existing Software',
        ];
    }

    if ($category === 'Connectivity & Network') {
        return [
            'Home / Remote (VPN)',
            'Home Wifi',
            'Office Wifi',
        ];
    }

    return [];
}

function complaint_category_detail_valid(string $category, string $detail): bool {
    $options = complaint_category_detail_options($category);
    if ($options === []) {
        return $detail === '';
    }

    return in_array($detail, $options, true);
}

function complaint_category_sub_detail_options(string $category, string $detail): array {
    if ($category === 'Software Support' && complaint_category_detail_valid($category, $detail)) {
        return [
            'ERP',
            'EDMS',
            'BOSS.NET',
            'MeteorCloud',
            'MTM Live',
            'Microsoft Teams',
            'Other',
        ];
    }

    if ($category === 'Connectivity & Network' && complaint_category_detail_valid($category, $detail)) {
        return [
            'No connection at all',
            'Slow',
            'Specific website / tools blocked',
        ];
    }

    return [];
}

function complaint_category_sub_detail_valid(string $category, string $detail, string $subDetail): bool {
    $options = complaint_category_sub_detail_options($category, $detail);
    if ($options === []) {
        return $subDetail === '';
    }

    return in_array($subDetail, $options, true);
}

function complaint_category_requires_location(string $category): bool {
    return in_array($category, ['Hardware', 'Connectivity & Network'], true);
}

function complaint_category_detail_note_required(string $category, string $detail, string $subDetail = ''): bool {
    if ($category === 'Hardware' && $detail === 'Other') {
        return true;
    }

    return $category === 'Software Support' && $subDetail === 'Other';
}

if (AUTO_SCHEMA_MIGRATE) {
    ensure_schema();
}
