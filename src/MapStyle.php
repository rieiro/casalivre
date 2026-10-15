<?php

declare(strict_types=1);

/**
 * Estilo do mapa por tema (Sprint 3, Etapa 3). O estilo base é public/assets/map/style-light.json; um tema pode
 * declarar em brand.php 'mapa' => [papel => cor], e o build gera public/assets/themes/<nome>/map-style.json
 * trocando só as cores daquele papel. Tema sem 'mapa' (o padrão) usa o estilo base, sem cópia.
 *
 * Segurança: papéis só da lista ROLES; cor só #rgb ou #rrggbb (nada de texto livre chega ao JSON); a troca só
 * acontece onde o valor original é uma string de cor (expressões do estilo ficam intactas); json_encode gera o
 * arquivo (sem montar JSON à mão).
 */
final class MapStyle
{
    public const BASE = '/assets/map/style-light.json';
    public const FILE = 'map-style.json';
    public const COLOR_RE = '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i';

    /** papel => lista de [regex do id da camada, propriedade de paint]. */
    public const ROLES = [
        'fundo' => [['/^background$/', 'background-color']],
        'terra' => [['/^earth$/', 'fill-color']],
        'agua' => [['/^water$/', 'fill-color'], ['/^water_(stream|river)$/', 'line-color']],
        'agua_rotulo' => [['/^water_.*label/', 'text-color']],
        'parque' => [['/^landuse_urban_green$/', 'fill-color']],
        'edificio' => [['/^buildings$/', 'fill-color']],
        'via' => [['/^roads_(?!.*casing)(?!.*tunnels)(?!rail$)(?!runway$)(?!taxiway$)(?!oneway$)(?!shields$)(?!labels)/', 'line-color']],
        'via_contorno' => [['/^roads_.*casing/', 'line-color']],
        'limite' => [['/^boundaries/', 'line-color']],
        'rotulo' => [['/^(places_|roads_labels_|address_label$|earth_label)/', 'text-color']],
        'rotulo_halo' => [['/^(places_|roads_labels_|address_label$|earth_label)/', 'text-halo-color']],
    ];

    /** Só papéis conhecidos com cor válida (minúscula). @return array<string,string> */
    public static function clean(mixed $tokens): array
    {
        $out = [];
        if (!is_array($tokens)) {
            return $out;
        }
        foreach ($tokens as $role => $color) {
            if (is_string($role) && isset(self::ROLES[$role]) && is_string($color) && preg_match(self::COLOR_RE, $color) === 1) {
                $out[$role] = strtolower($color);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * Decodifica o estilo como objetos: com arrays associativos o "{}" vazio das expressões "format" viraria "[]"
     * no json_encode e o MapLibre recusaria o estilo inteiro.
     */
    public static function decode(string $json): stdClass
    {
        $style = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$style instanceof stdClass) {
            throw new RuntimeException('estilo do mapa: JSON não é um objeto');
        }
        return $style;
    }

    /** Estilo base (não alterado) com as cores do tema; só valores string de paint mudam. */
    public static function generate(stdClass $base, array $tokens): stdClass
    {
        $style = self::decode(self::encode($base));
        $tokens = self::clean($tokens);
        foreach (is_array($style->layers ?? null) ? $style->layers : [] as $layer) {
            if (!$layer instanceof stdClass || !($layer->paint ?? null) instanceof stdClass) {
                continue;
            }
            $id = is_string($layer->id ?? null) ? $layer->id : '';
            foreach ($tokens as $role => $color) {
                foreach (self::ROLES[$role] as [$re, $prop]) {
                    if (preg_match($re, $id) === 1 && is_string($layer->paint->{$prop} ?? null)) {
                        $layer->paint->{$prop} = $color;
                    }
                }
            }
        }
        return $style;
    }

    public static function encode(stdClass $style): string
    {
        return json_encode($style, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /** Caminho público do estilo do tema (dentro de /assets/themes/<nome>/). */
    public static function themePath(string $name): string
    {
        return '/assets/themes/' . $name . '/' . self::FILE;
    }
}
