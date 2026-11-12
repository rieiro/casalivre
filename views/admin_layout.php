<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Administrative Panel - CasaLivre') ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/app.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('/assets/admin.css')) ?>">
    <?= page_data_script($csrf) ?>
</head>
<body class="admin-body">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-header">
            <a href="/admin" class="admin-brand">
                <span class="admin-brand-icon">⚡</span>
                <span>CasaLivre <strong>Admin</strong></span>
            </a>
            <span class="admin-badge-env"><?= e(getenv('APP_ENV') ?: 'prod') ?></span>
        </div>
        <ul class="admin-nav">
            <li class="admin-nav-heading">Overview</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'dashboard' ? 'active' : '' ?>" href="/admin">
                    <span class="admin-nav-icon">📊</span>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="admin-nav-heading">Technical & Operations</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'configuracoes' ? 'active' : '' ?>" href="/admin/configuracoes">
                    <span class="admin-nav-icon">⚙️</span>
                    <span>Parameters & Limits</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'seguranca' ? 'active' : '' ?>" href="/admin/seguranca">
                    <span class="admin-nav-icon">🛡️</span>
                    <span>Security & IPs</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'auditoria' ? 'active' : '' ?>" href="/admin/auditoria">
                    <span class="admin-nav-icon">📜</span>
                    <span>Audit Trail</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'filas' ? 'active' : '' ?>" href="/admin/filas">
                    <span class="admin-nav-icon">⚡</span>
                    <span>Queues & Daemons</span>
                </a>
            </li>

            <li class="admin-nav-heading">Platform & Content</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'grupos' ? 'active' : '' ?>" href="/admin/grupos">
                    <span class="admin-nav-icon">🏢</span>
                    <span>Groups & Networks</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'tema' ? 'active' : '' ?>" href="/admin/tema">
                    <span class="admin-nav-icon">🎨</span>
                    <span>Visual Theme</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'traducoes' ? 'active' : '' ?>" href="/admin/traducoes">
                    <span class="admin-nav-icon">🌐</span>
                    <span>Translations & Copy</span>
                </a>
            </li>

            <li class="admin-nav-heading">Quick Shortcuts</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link" href="/painel">
                    <span class="admin-nav-icon">🏠</span>
                    <span>My Properties</span>
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link" href="/" target="_blank" rel="noopener">
                    <span class="admin-nav-icon">↗</span>
                    <span>View Public Site</span>
                </a>
            </li>
        </ul>
        <div class="admin-sidebar-footer">
            <div class="admin-user-info">
                <div class="admin-user-avatar">
                    <?= strtoupper(substr($user['email'] ?? 'A', 0, 1)) ?>
                </div>
                <div class="admin-user-email" title="<?= e($user['email'] ?? 'admin') ?>">
                    <?= e($user['email'] ?? 'admin') ?>
                </div>
            </div>
            <form method="post" action="/sair">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="admin-signout-btn">
                    Sign Out
                </button>
            </form>
        </div>
    </aside>

    <div class="admin-main-wrap">
        <header class="admin-topbar">
            <div>
                <div class="admin-topbar-breadcrumb">
                    <a href="/admin">Admin</a>
                    <span>/</span>
                    <span class="admin-sysinfo-label"><?= e($page_title ?? 'Dashboard') ?></span>
                </div>
                <div class="admin-topbar-title"><?= e($page_title ?? 'Dashboard') ?></div>
            </div>
            <div class="admin-topbar-actions">
                <span class="admin-system-chip">
                    <span class="admin-dot admin-dot-ok"></span>
                    <span>PostgreSQL 18</span>
                </span>
                <span class="admin-system-chip">
                    <span class="admin-dot admin-dot-ok"></span>
                    <span>PHP <?= e(PHP_VERSION) ?></span>
                </span>
                <a href="/" target="_blank" rel="noopener" class="admin-btn admin-btn-secondary admin-btn-sm">
                    <span>Public Site ↗</span>
                </a>
            </div>
        </header>

        <main class="admin-content">
            <?php if (!empty($ok)): ?>
                <div class="admin-alert-banner admin-alert-success">
                    <span>✓</span>
                    <span>Action completed successfully.</span>
                </div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="admin-alert-banner admin-alert-error">
                    <span>⚠️</span>
                    <span><?= e($error) ?></span>
                </div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</body>
</html>
