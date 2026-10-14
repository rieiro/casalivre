<?php

declare(strict_types=1);

require_once __DIR__ . '/Redact.php';

final class ExceptionShield
{
    public static function register(): void
    {
        set_exception_handler(static function (Throwable $e): void {
            $ref = 'CL-' . strtoupper(bin2hex(random_bytes(4)));
            $when = date('Y-m-d H:i:s');
            $route = Redact::route((string) ($_SERVER['REQUEST_URI'] ?? ''));
            $methodName = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
            $dir = dirname(__DIR__) . '/storage';
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            @file_put_contents(
                $dir . '/error.log',
                date('c') . " $ref $methodName $route " . Redact::secrets($e->getMessage()) . "\n" . Redact::secrets($e->getTraceAsString()) . "\n",
                FILE_APPEND
            );
            if (function_exists('activity')) {
                try {
                    activity(ActivityLog::INTERNAL_ERROR, [
                        'referencia' => $ref,
                        'quando' => $when,
                        'rota' => $route,
                        'metodo' => $methodName,
                        'tipo' => $e::class,
                        'mensagem' => substr(Redact::secrets($e->getMessage()), 0, 500),
                        'arquivo' => $e->getFile(),
                        'linha' => $e->getLine(),
                        'rastreio' => substr(Redact::secrets($e->getTraceAsString()), 0, 4000),
                    ]);
                } catch (Throwable) {
                }
            }
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            $safe = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            // Estilo em /assets/app.css (.err-shield): a CSP (sem 'unsafe-inline') bloqueava o <style> que havia aqui.
            $css = function_exists('asset_url') ? asset_url('/assets/app.css') : '/assets/app.css';
            echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Algo deu errado</title><link rel="stylesheet" href="' . $safe($css) . '"></head><body class="err-shield"><main><h1>Não foi possível concluir agora</h1><p>O problema foi registrado. Passe estes dados ao suporte:</p><ul><li>Código: <strong>' . $safe($ref) . '</strong></li><li>Quando: ' . $safe($when) . '</li><li>Página: ' . $safe($route === '' ? '/' : $route) . '</li><li>Método: ' . $safe($methodName) . '</li></ul><p><a href="/">Voltar ao início</a></p></main></body></html>';
        });
    }
}
