<!DOCTYPE html>
<html lang="<?= e($locale ?? 'pt-BR') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'CasaLivre') ?></title>
    <link rel="stylesheet" href="/assets/app.css?v=18">
</head>
<body>
<header class="top">
    <a class="brand" href="/">CasaLivre</a>
    <form class="lang" method="post" action="/idioma">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label><?= e(t('nav.language')) ?>
            <select name="locale" onchange="this.form.submit()">
                <?php foreach (I18n::LOCALES as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($locale ?? '') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
    <input id="nav-toggle" class="nav-toggle" type="checkbox">
    <label class="nav-toggle-label" for="nav-toggle"><?= e(t('nav.menu')) ?></label>
    <nav>
        <a href="/"><?= e(t('nav.search')) ?></a>
        <?php if (!empty($user)): ?>
            <a href="/painel"><?= e(t('nav.properties')) ?></a>
            <a href="/conta"><?= e(t('nav.account')) ?></a>
            <form method="post" action="/sair">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <button type="submit" class="linkish"><?= e(t('nav.logout')) ?></button>
            </form>
        <?php else: ?>
            <a href="/cadastro"><?= e(t('nav.advertise')) ?></a>
            <a href="/entrar"><?= e(t('nav.login')) ?></a>
        <?php endif; ?>
    </nav>
</header>
<main>
    <?= $content ?>
</main>
<footer><?= e(t('footer')) ?></footer>
<script src="/assets/form-data.js?v=1"></script>
<script src="/assets/app.js?v=17"></script>
</body>
</html>
