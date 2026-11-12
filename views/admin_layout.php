<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Administrative Panel - CasaLivre') ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/app.css')) ?>">
    <?= page_data_script($csrf) ?>
    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-sidebar-bg: #0b1120;
            --admin-sidebar-surface: #131d33;
            --admin-sidebar-hover: #1e293b;
            --admin-sidebar-active: #2563eb;
            --admin-sidebar-border: #1e293b;
            --admin-text-main: #0f172a;
            --admin-text-muted: #64748b;
            --admin-card-bg: #ffffff;
            --admin-border: #e2e8f0;
            --admin-border-subtle: #f1f5f9;
            --admin-success: #10b981;
            --admin-success-bg: #ecfdf5;
            --admin-warning: #f59e0b;
            --admin-warning-bg: #fffbeb;
            --admin-danger: #ef4444;
            --admin-danger-bg: #fef2f2;
            --admin-info: #3b82f6;
            --admin-info-bg: #eff6ff;
            --admin-shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --admin-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.08), 0 1px 2px -1px rgb(0 0 0 / 0.05);
            --admin-shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.05);
            --admin-shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.03);
            --admin-radius: 0.75rem;
            --admin-radius-sm: 0.375rem;
        }

        *, *::before, *::after {
            box-sizing: border-box;
        }

        body.admin-body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--admin-bg);
            color: var(--admin-text-main);
            display: flex;
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* SIDEBAR STYLES (Osclass Omega inspired with modern SaaS touches) */
        .admin-sidebar {
            width: 260px;
            background: var(--admin-sidebar-bg);
            color: #f1f5f9;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--admin-sidebar-border);
            position: sticky;
            top: 0;
            height: 100vh;
            z-index: 50;
        }

        .admin-sidebar-header {
            padding: 1.25rem 1.5rem;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            border-bottom: 1px solid var(--admin-sidebar-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(180deg, #0f172a 0%, #0b1120 100%);
        }

        .admin-brand {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            color: #ffffff;
            text-decoration: none;
            font-size: 1.15rem;
        }

        .admin-brand-icon {
            width: 28px;
            height: 28px;
            border-radius: 0.375rem;
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.4);
        }

        .admin-badge-env {
            font-size: 0.65rem;
            padding: 0.2rem 0.5rem;
            border-radius: 9999px;
            background: #1e293b;
            color: #94a3b8;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            border: 1px solid #334155;
        }

        .admin-nav {
            list-style: none;
            margin: 0;
            padding: 1rem 0;
            flex-grow: 1;
            overflow-y: auto;
        }

        .admin-nav-item {
            margin: 0.2rem 0.85rem;
        }

        .admin-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.85rem;
            border-radius: var(--admin-radius-sm);
            color: #94a3b8;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.15s ease-in-out;
            position: relative;
        }

        .admin-nav-link:hover {
            background: var(--admin-sidebar-hover);
            color: #f8fafc;
            transform: translateX(2px);
        }

        .admin-nav-link.active {
            background: linear-gradient(90deg, #1d4ed8 0%, #2563eb 100%);
            color: #ffffff;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.35);
        }

        .admin-nav-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            width: 20px;
            text-align: center;
        }

        .admin-nav-heading {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #64748b;
            padding: 1rem 1.5rem 0.35rem;
            font-weight: 700;
        }

        .admin-sidebar-footer {
            padding: 1rem 1.25rem;
            border-top: 1px solid var(--admin-sidebar-border);
            font-size: 0.8rem;
            color: #94a3b8;
            background: #090d16;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .admin-user-info {
            display: flex;
            align-items: center;
            gap: 0.65rem;
            overflow: hidden;
        }

        .admin-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #334155;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.8rem;
            flex-shrink: 0;
            border: 1px solid #475569;
        }

        .admin-user-email {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 130px;
            font-weight: 500;
            color: #f1f5f9;
        }

        /* MAIN CONTENT LAYOUT */
        .admin-main-wrap {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        /* TOPBAR (Clean modern navbar with breadcrumbs & system health) */
        .admin-topbar {
            height: 64px;
            background: #ffffff;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
            position: sticky;
            top: 0;
            z-index: 40;
            box-shadow: var(--admin-shadow-sm);
        }

        .admin-topbar-breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            color: var(--admin-text-muted);
        }

        .admin-topbar-breadcrumb a {
            color: var(--admin-text-muted);
            text-decoration: none;
            transition: color 0.15s;
        }

        .admin-topbar-breadcrumb a:hover {
            color: var(--admin-text-main);
        }

        .admin-topbar-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--admin-text-main);
            letter-spacing: -0.015em;
        }

        .admin-topbar-actions {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .admin-system-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.65rem;
            background: #f8fafc;
            border: 1px solid var(--admin-border);
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            color: #475569;
        }

        /* CONTENT CONTAINER */
        .admin-content {
            padding: 2rem;
            flex-grow: 1;
            max-width: 1440px;
            width: 100%;
            margin: 0 auto;
        }

        /* CARDS & PANELS */
        .admin-card {
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: var(--admin-shadow);
            transition: box-shadow 0.2s ease;
        }

        .admin-card:hover {
            box-shadow: var(--admin-shadow-md);
        }

        .admin-card-title {
            font-size: 1.05rem;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            letter-spacing: -0.01em;
            color: var(--admin-text-main);
            border-bottom: 1px solid var(--admin-border-subtle);
            padding-bottom: 0.75rem;
        }

        /* KPI STATS CARDS */
        .admin-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .admin-kpi-card {
            background: #ffffff;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius);
            padding: 1.25rem 1.5rem;
            box-shadow: var(--admin-shadow);
            display: flex;
            flex-direction: column;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
            position: relative;
            overflow: hidden;
        }

        .admin-kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
        }

        .admin-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--admin-shadow-md);
        }

        .admin-kpi-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--admin-text-muted);
            margin-bottom: 0.4rem;
        }

        .admin-kpi-value {
            font-size: 2.15rem;
            font-weight: 800;
            color: var(--admin-text-main);
            line-height: 1.1;
            letter-spacing: -0.03em;
            font-variant-numeric: tabular-nums;
        }

        .admin-kpi-sub {
            font-size: 0.8rem;
            color: var(--admin-text-muted);
            margin-top: 0.65rem;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            flex-wrap: wrap;
        }

        /* PILLS & BADGES */
        .admin-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.25rem 0.65rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .admin-pill-ok {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .admin-pill-warn {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        .admin-pill-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .admin-pill-neutral {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        /* PULSING STATUS DOTS */
        .admin-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
            position: relative;
        }

        .admin-dot-ok {
            background: #10b981;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }

        .admin-dot-danger {
            background: #ef4444;
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
        }

        @keyframes admin-pulse {
            0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.6); }
            70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); }
            100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .admin-dot-ok {
            animation: admin-pulse 2s infinite;
        }

        /* TABLES */
        .admin-table-container {
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-sm);
            overflow: hidden;
            background: #ffffff;
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }

        .admin-table th, .admin-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--admin-border);
        }

        .admin-table th {
            background: #f8fafc;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 0.06em;
            border-top: none;
        }

        .admin-table tbody tr {
            transition: background-color 0.1s ease;
        }

        .admin-table tbody tr:hover {
            background-color: #f8fafc;
        }

        .admin-table tr:last-child td {
            border-bottom: none;
        }

        /* CODE CHIPS */
        .admin-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: #f1f5f9;
            padding: 0.2rem 0.45rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
            color: #0f172a;
            border: 1px solid #e2e8f0;
        }

        /* ALERT BANNERS */
        .admin-alert-banner {
            padding: 1rem 1.25rem;
            border-radius: var(--admin-radius-sm);
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            box-shadow: var(--admin-shadow-sm);
        }

        .admin-alert-warn {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }

        .admin-alert-success {
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .admin-alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        /* GRID SPLIT */
        .admin-grid-split {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }

        /* BUTTONS */
        .admin-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 1.15rem;
            border-radius: var(--admin-radius-sm);
            font-size: 0.875rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: all 0.15s ease-in-out;
            line-height: 1.25;
        }

        .admin-btn-primary {
            background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
            color: #ffffff;
            box-shadow: 0 1px 2px rgba(37, 99, 235, 0.25);
        }

        .admin-btn-primary:hover {
            background: linear-gradient(180deg, #1d4ed8 0%, #1e40af 100%);
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.35);
        }

        .admin-btn-secondary {
            background: #ffffff;
            border-color: var(--admin-border);
            color: var(--admin-text-main);
            box-shadow: var(--admin-shadow-sm);
        }

        .admin-btn-secondary:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #0f172a;
        }

        .admin-btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }

        /* FORMS */
        .admin-input, .admin-select, .admin-textarea {
            width: 100%;
            padding: 0.55rem 0.85rem;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius-sm);
            font-size: 0.875rem;
            color: var(--admin-text-main);
            background: #ffffff;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .admin-input:focus, .admin-select:focus, .admin-textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        @media (max-width: 1024px) {
            body.admin-body { flex-direction: column; }
            .admin-sidebar { width: 100%; height: auto; position: relative; }
            .admin-grid-split { grid-template-columns: 1fr; }
            .admin-topbar { padding: 0 1rem; }
            .admin-content { padding: 1.25rem; }
        }
    </style>
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
            <form method="post" action="/sair" style="display: inline; margin: 0;">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" style="background: none; border: none; padding: 0.35rem 0.5rem; color: #ef4444; cursor: pointer; font-size: 0.8rem; font-weight: 600; border-radius: 4px; transition: background 0.15s;" onmouseover="this.style.background='#1e293b'" onmouseout="this.style.background='none'">
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
                    <span style="color: var(--admin-text-main); font-weight: 600;"><?= e($page_title ?? 'Dashboard') ?></span>
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
