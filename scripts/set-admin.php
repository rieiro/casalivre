#!/usr/bin/env php
<?php

declare(strict_types=1);

// Liga/desliga o papel de admin (users.is_admin) de uma conta ativa. Só pela linha de comando, no servidor:
// não existe tela nem API para promover admin.
// Uso: php scripts/set-admin.php <email>          torna admin
//      php scripts/set-admin.php <email> --remover tira o papel
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
$email = strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "uso: php scripts/set-admin.php <email> [--remover]\n");
    exit(2);
}
$valor = in_array('--remover', $argv, true) ? 0 : 1;
$stmt = db()->prepare("UPDATE users SET is_admin = ? WHERE lower(email) = ? AND status = 'active'");
$stmt->execute([$valor, $email]);
if ($stmt->rowCount() !== 1) {
    fwrite(STDERR, "nenhuma conta ativa com esse e-mail\n");
    exit(1);
}
echo $valor === 1 ? "admin ligado\n" : "admin removido\n";
