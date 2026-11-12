<?php

declare(strict_types=1);

require_once __DIR__ . "/Resultado.php";
require_once dirname(__DIR__) . "/AdminRepository.php";

final class AdminTranslations
{
    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $params
     */
    public static function index(array $actor, array $params = []): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $search = !empty($params["search"]) ? trim((string) $params["search"]) : null;
        $prefix = !empty($params["prefix"]) ? trim((string) $params["prefix"]) : null;

        $translations = AdminRepository::getTranslations($search, $prefix);

        $all = AdminRepository::getTranslations(null, null);
        $prefixes = [];
        foreach (array_keys($all) as $k) {
            $parts = explode(".", $k, 2);
            $p = $parts[0] ?? "";
            if ($p !== "") {
                $prefixes[$p] = ($prefixes[$p] ?? 0) + 1;
            }
        }
        ksort($prefixes);

        return new Resultado("ok", null, [
            "translations" => $translations,
            "prefixes" => $prefixes,
            "current_search" => $search,
            "current_prefix" => $prefix,
        ]);
    }

    /**
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $input
     */
    public static function update(array $actor, array $input): Resultado
    {
        if ((int) ($actor["is_admin"] ?? 0) !== 1 || ($actor["status"] ?? "") !== "active") {
            return new Resultado("forbidden", "Acesso restrito a administradores.");
        }

        $tkey = trim((string) ($input["tkey"] ?? ""));
        if ($tkey === "") {
            return new Resultado("invalido", "Chave de tradução inválida.");
        }

        $values = $input["translations"] ?? [];
        if (!is_array($values)) {
            return new Resultado("invalido", "Formato de traduções inválido.");
        }

        foreach (["pt-BR", "en", "es"] as $lang) {
            if (isset($values[$lang]) && is_scalar($values[$lang])) {
                AdminRepository::updateTranslation($tkey, $lang, trim((string) $values[$lang]));
            }
        }

        return new Resultado("ok", "Tradução atualizada com sucesso.", ["tkey" => $tkey]);
    }
}
