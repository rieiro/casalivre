<!DOCTYPE html>
<html lang="<?= e($locale ?? 'pt-BR') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'Painel Administrativo - CasaLivre') ?></title>
    <link rel="stylesheet" href="<?= e(asset_url('/assets/app.css')) ?>">
    <?= page_data_script($csrf) ?>
    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-sidebar-bg: #0f172a;
            --admin-sidebar-hover: #1e293b;
            --admin-sidebar-active: #2563eb;
            --admin-text-main: #1e293b;
            --admin-text-muted: #64748b;
            --admin-card-bg: #ffffff;
            --admin-border: #e2e8f0;
            --admin-success: #10b981;
            --admin-warning: #f59e0b;
            --admin-danger: #ef4444;
        }
        body.admin-body {
            margin: 0;
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--admin-bg);
            color: var(--admin-text-main);
            display: flex;
            min-height: 100vh;
        }
        .admin-sidebar {
            width: 250px;
            background: var(--admin-sidebar-bg);
            color: #f1f5f9;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
        }
        .admin-sidebar-header {
            padding: 1.25rem 1.5rem;
            font-size: 1.15rem;
            font-weight: 700;
            letter-spacing: -0.025em;
            border-bottom: 1px solid #1e293b;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-sidebar-header a {
            color: #ffffff;
            text-decoration: none;
        }
        .admin-badge-env {
            font-size: 0.7rem;
            padding: 0.15rem 0.5rem;
            border-radius: 9999px;
            background: #334155;
            color: #94a3b8;
            font-weight: 600;
            text-transform: uppercase;
        }
        .admin-nav {
            list-style: none;
            margin: 0;
            padding: 1rem 0;
            flex-grow: 1;
        }
        .admin-nav-item {
            margin: 0.15rem 0.75rem;
        }
        .admin-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 0.85rem;
            border-radius: 0.375rem;
            color: #cbd5e1;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: background 0.15s, color 0.15s;
        }
        .admin-nav-link:hover {
            background: var(--admin-sidebar-hover);
            color: #ffffff;
        }
        .admin-nav-link.active {
            background: var(--admin-sidebar-active);
            color: #ffffff;
        }
        .admin-nav-heading {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            padding: 0.75rem 1.5rem 0.25rem;
            font-weight: 700;
        }
        .admin-sidebar-footer {
            padding: 1rem;
            border-top: 1px solid #1e293b;
            font-size: 0.8rem;
            color: #64748b;
        }
        .admin-sidebar-footer a {
            color: #94a3b8;
            text-decoration: none;
        }
        .admin-main-wrap {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            min-width: 0;
        }
        .admin-topbar {
            height: 60px;
            background: #ffffff;
            border-bottom: 1px solid var(--admin-border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2rem;
        }
        .admin-topbar-title {
            font-size: 1.1rem;
            font-weight: 600;
        }
        .admin-user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.875rem;
        }
        .admin-content {
            padding: 2rem;
            flex-grow: 1;
        }
        .admin-card {
            background: var(--admin-card-bg);
            border: 1px solid var(--admin-border);
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .admin-card-title {
            font-size: 1.05rem;
            font-weight: 600;
            margin-top: 0;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .admin-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .admin-kpi-card {
            background: #ffffff;
            border: 1px solid var(--admin-border);
            border-radius: 0.5rem;
            padding: 1.25rem;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
        }
        .admin-kpi-label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--admin-text-muted);
            margin-bottom: 0.35rem;
        }
        .admin-kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--admin-text-main);
            line-height: 1.1;
        }
        .admin-kpi-sub {
            font-size: 0.8rem;
            color: var(--admin-text-muted);
            margin-top: 0.5rem;
        }
        .admin-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.2rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .admin-pill-ok { background: #dcfce7; color: #15803d; }
        .admin-pill-warn { background: #fef3c7; color: #b45309; }
        .admin-pill-danger { background: #fee2e2; color: #b91c1c; }
        .admin-pill-neutral { background: #f1f5f9; color: #475569; }
        .admin-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .admin-dot-ok { background: #10b981; }
        .admin-dot-danger { background: #ef4444; }
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }
        .admin-table th, .admin-table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--admin-border);
        }
        .admin-table th {
            background: #f8fafc;
            font-weight: 600;
            color: var(--admin-text-muted);
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        .admin-table tr:hover td {
            background: #f8fafc;
        }
        .admin-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            background: #f1f5f9;
            padding: 0.15rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.8rem;
        }
        .admin-alert-banner {
            padding: 1rem 1.25rem;
            border-radius: 0.375rem;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
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
        .admin-grid-split {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
        }
        .admin-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            border: 1px solid transparent;
            transition: background 0.15s;
        }
        .admin-btn-primary {
            background: #2563eb;
            color: #ffffff;
        }
        .admin-btn-primary:hover {
            background: #1d4ed8;
        }
        .admin-btn-secondary {
            background: #ffffff;
            border-color: var(--admin-border);
            color: var(--admin-text-main);
        }
        .admin-btn-secondary:hover {
            background: #f1f5f9;
        }
        @media (max-width: 900px) {
            body.admin-body { flex-direction: column; }
            .admin-sidebar { width: 100%; }
            .admin-grid-split { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body class="admin-body">
    <aside class="admin-sidebar">
        <div class="admin-sidebar-header">
            <a href="/admin">CasaLivre <strong>Admin</strong></a>
            <span class="admin-badge-env"><?= e(getenv('APP_ENV') ?: 'prod') ?></span>
        </div>
        <ul class="admin-nav">
            <li class="admin-nav-heading">Visão Geral</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'dashboard' ? 'active' : '' ?>" href="/admin">
                    <span>📊</span> Painel Geral
                </a>
            </li>

            <li class="admin-nav-heading">Técnico & Operações</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'configuracoes' ? 'active' : '' ?>" href="/admin/configuracoes">
                    <span>⚙️</span> Parâmetros & Limites
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'seguranca' ? 'active' : '' ?>" href="/admin/seguranca">
                    <span>🛡️</span> Segurança & IPs
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'auditoria' ? 'active' : '' ?>" href="/admin/auditoria">
                    <span>📜</span> Trilha de Auditoria
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'filas' ? 'active' : '' ?>" href="/admin/filas">
                    <span>⚡</span> Filas & Daemons
                </a>
            </li>

            <li class="admin-nav-heading">Plataforma</li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'grupos' ? 'active' : '' ?>" href="/admin/grupos">
                    <span>🏢</span> Grupos & Redes
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link <?= ($active_tab ?? '') === 'tema' ? 'active' : '' ?>" href="/admin/tema">
                    <span>🎨</span> Tema Visual
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link" href="/painel">
                    <span>🏠</span> Meus Imóveis
                </a>
            </li>
            <li class="admin-nav-item">
                <a class="admin-nav-link" href="/">
                    <span>🌐</span> Ver Site Público
                </a>
            </li>
        </ul>
        <div class="admin-sidebar-footer">
            <div style="margin-bottom: 0.5rem;">Logado: <strong><?= e($user['email'] ?? 'admin') ?></strong></div>
            <form method="post" action="/sair" style="display: inline;">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" style="background: none; border: none; padding: 0; color: #ef4444; cursor: pointer; font-size: 0.8rem;">Encerrar Sessão</button>
            </form>
        </div>
    </aside>

    <div class="admin-main-wrap">
        <header class="admin-topbar">
            <div class="admin-topbar-title"><?= e($page_title ?? 'Painel de Controle') ?></div>
            <div class="admin-user-menu">
                <span class="admin-pill admin-pill-neutral">PostgreSQL 18</span>
                <span class="admin-pill admin-pill-neutral">PHP <?= e(PHP_VERSION) ?></span>
                <a href="/" class="admin-btn admin-btn-secondary" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">Site Público ↗</a>
            </div>
        </header>

        <main class="admin-content">
            <?php if (!empty($ok)): ?>
                <div class="admin-alert-banner admin-alert-success">✓ Ação executada com sucesso.</div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="admin-alert-banner admin-alert-error">⚠️ <?= e($error) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>
</body>
</html>
