<?php

declare(strict_types=1);

require_once __DIR__ . '/Middleware.php';

final class Sanitize implements Middleware
{
    /** @param list<string> $puros */
    public function __construct(private array $puros)
    {
    }

    public function process(array $request, callable $next): mixed
    {
        $_GET = $this->clean($_GET);
        $_POST = $this->clean($_POST);
        $request['get'] = $_GET;
        $request['post'] = $_POST;
        return $next($request);
    }

    private function clean(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string) $key, $this->puros, true)) {
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->clean($value);
                continue;
            }
            if (is_string($value)) {
                $data[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
        }
        return $data;
    }
}
