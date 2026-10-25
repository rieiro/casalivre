<?php

declare(strict_types=1);

/**
 * Prefixo do PHP para um processo filho. Sem efeito colateral no include: o probe
 * só roda na primeira chamada. php.ini de produção não carrega bz2; o filho não
 * herda -d extension=bz2 do pai.
 *
 * @return list<string>
 */
function t_php_cmd(): array
{
    static $cmd = null;
    if ($cmd !== null) {
        return $cmd;
    }
    exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('exit(extension_loaded("bz2") ? 0 : 1);'), $_, $code);
    $cmd = $code === 0 ? [PHP_BINARY] : [PHP_BINARY, '-d', 'extension=bz2'];
    return $cmd;
}

/** Shell-escaped prefix from t_php_cmd(), ready to concatenate in front of a command. */
function t_php_shell(): string
{
    return implode(' ', array_map('escapeshellarg', t_php_cmd()));
}
