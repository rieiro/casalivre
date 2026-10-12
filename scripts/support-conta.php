#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Db.php';
require dirname(__DIR__) . '/src/UserRepository.php';

$email = '';
$ativar = false;
$desbloquear = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--email=')) {
        $email = strtolower(substr($arg, 8));
    } elseif ($arg === '--ativar') {
        $ativar = true;
    } elseif ($arg === '--desbloquear') {
        $desbloquear = true;
    }
}
if ($email === '' || (!$ativar && !$desbloquear)) {
    fwrite(STDERR, "Uso: php scripts/support-conta.php --email=pessoa@exemplo.com --ativar --desbloquear\n");
    exit(1);
}
$user = UserRepository::findByEmail($email);
if (!$user) {
    fwrite(STDERR, "Conta não encontrada.\n");
    exit(1);
}
if ($ativar) {
    UserRepository::activate((int) $user['id']);
    echo "Conta ativada.\n";
}
if ($desbloquear) {
    UserRepository::clearFailures((int) $user['id']);
    echo "Tentativas de login zeradas.\n";
}
