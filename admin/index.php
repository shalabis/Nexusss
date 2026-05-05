<?php
require_once __DIR__ . '/../bootstrap.php';
require_admin();

$pdo = get_pdo();
$stats = $pdo->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) AS admins,
        SUM(CASE WHEN role = 'it' THEN 1 ELSE 0 END) AS it_users,
        SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS users
     FROM users"
)->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Nexus IT | Admin Dashboard</title>
    <link rel="stylesheet" href="/assets/styles.css" />
</head>
<body>
    <main class="container">
        <section class="card wide dashboard admin-dashboard">
            <div class="admin-dashboard-shell">
                <div class="admin-dashboard-main">
                    <div class="admin-hero">
                        <div>
                            <p class="eyebrow">Nexus IT</p>
                            <h1 class="brand">Admin Dashboard</h1>
                            <p class="subtitle">Manage accounts, roles, and access in one place.</p>
                        </div>
                        <div class="admin-hero-note">
                            <p class="mini-label">Admin Access</p>
                            <p class="mini-value">Central control for staff accounts and permissions.</p>
                        </div>
                    </div>

                    <div class="stats-grid admin-stats-grid">
                        <div class="stat-card featured">
                            <p class="stat-label">Total Accounts</p>
                            <p class="stat-value"><?php echo (int) $stats['total']; ?></p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">Admins</p>
                            <p class="stat-value"><?php echo (int) $stats['admins']; ?></p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">IT Support</p>
                            <p class="stat-value"><?php echo (int) $stats['it_users']; ?></p>
                        </div>
                        <div class="stat-card">
                            <p class="stat-label">Employees</p>
                            <p class="stat-value"><?php echo (int) $stats['users']; ?></p>
                        </div>
                    </div>

                    <div class="admin-content-grid">
                        <div class="panel admin-actions-panel">
                            <div class="panel-heading">
                                <div>
                                    <h2 class="section-title">Quick Actions</h2>
                                    <p class="hint">Frequently used tools are grouped here for faster access.</p>
                                </div>
                            </div>
                            <div class="admin-action-stack">
                                <a class="button" href="/admin/create.php">Create New Account</a>
                                <a class="button ghost" href="/admin/created.php">Review Created Accounts</a>
                                <a class="button ghost" href="/admin/faq.php">Edit FAQ</a>
                            </div>
                        </div>

                        <div class="admin-info-stack">
                            <div class="panel accent">
                                <h2 class="section-title">Account Rules</h2>
                                <ul class="rules-list">
                                    <li>Only admins can create accounts.</li>
                                    <li>Roles: Admin, IT Support, Employee.</li>
                                    <li>Only employee accounts choose A1, A2, A3, CME, CMS, PNC, or PPM departments.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <aside class="admin-sidebar">
                    <div class="panel admin-sidebar-panel">
                        <p class="mini-label">Navigation</p>
                        <div class="header-actions admin-sidebar-actions">
                            <a class="button ghost" href="/account.php">Account Details</a>
                            <a class="button ghost" href="/logout.php">Log Out</a>
                        </div>
                    </div>

                    <div class="panel admin-sidebar-panel compact">
                        <p class="mini-label">Workspace</p>
                        <p class="hint">Use the main actions area for account operations and keep this section for personal access settings.</p>
                    </div>
                </aside>
            </div>
        </section>
    </main>
</body>
</html>
