<?php

declare(strict_types=1);

/** Banco atrás de Db::SCHEMA_VERSION numa requisição pgsql: rode scripts/migrate.php (o ExceptionShield responde 503). */
final class SchemaDesatualizado extends RuntimeException
{
}
