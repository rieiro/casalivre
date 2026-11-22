<?php

declare(strict_types=1);

if (!class_exists('JsonCache', false)) {
    require_once __DIR__ . '/JsonCache.php';
}
require_once __DIR__ . '/FileStamp.php';

final class I18n
{
    public const LOCALES = ['pt-BR' => 'Português', 'en' => 'English', 'es' => 'Español'];

    public static function seed(PDO $pdo): void
    {
        $packs = require dirname(__DIR__) . '/database/messages.php';
        $check = $pdo->prepare('SELECT 1 FROM translations WHERE lang = ? AND tkey = ?');
        $insert = $pdo->prepare('INSERT INTO translations (lang, tkey, text) VALUES (?, ?, ?)');
        $inserted = false;
        foreach ($packs as $lang => $map) {
            foreach ($map as $key => $text) {
                $check->execute([$lang, $key]);
                if (!$check->fetchColumn()) {
                    $insert->execute([$lang, $key, $text]);
                    $inserted = true;
                }
            }
        }
        $refresh = $pdo->prepare('UPDATE translations SET text = ? WHERE lang = ? AND tkey = ? AND text = ?');
        $previous = [
            'pt-BR' => [
                'account.delete_confirm' => ['Inativar esta conta? O cadastro permanece no sistema e só o suporte pode reativar.'],
                'cal.link' => ['Link .ics', 'Link externo (.ics do Booking ou Airbnb)'],
                'footer' => ['Aluguel por temporada. Calendários exportáveis em .ics, compatíveis com Booking e Airbnb.'],
                'cal.export' => ['Exportar este calendário'],
                'cal.legend_open' => ['Noites liberadas', 'Livre'],
                'cal.legend_system' => ['Noites bloqueadas pelo próprio sistema', 'Bloqueado'],
                'cal.legend_sync' => ['Noites bloqueadas por outro calendário', 'Sinc. Ext.'],
                'cal.legend_booking' => ['Noites com reserva', 'Reservado'],
                'cal.legend_prereserva' => ['Noites em pré-reserva (30 min)', 'Pré-reserva'],
                'err.house_numbers' => ['Informe quartos, camas, hóspedes e uma diária mínima de 1,00.', 'Informe quartos, camas e hóspedes entre 1 e 999, e uma diária mínima de 1,00.', 'Informe quartos, camas e hóspedes entre 1 e 999, e uma diária entre 1,00 e 999.999,00.'],
                'err.password_short' => ['A senha precisa ter pelo menos 8 caracteres.'],
                'err.password_weak' => ['A senha é simples demais. Evite sequências como 123456 ou abc123.', 'A senha não foi aceita. Use pelo menos 8 caracteres e misture dois tipos entre letras, números e símbolos. Exemplo: Casa-livre1. Não use sequência nem só letras seguidas de números, como 12345678, abcdefgh, abc12345 ou senha123.', 'A senha escolhida é fraca. A senha precisa ter pelo menos 8 caracteres, com letras, números e símbolos.'],
                'auth.password_rules' => ['Mínimo de 8 caracteres, com pelo menos dois tipos: letras, números ou símbolos. Evite 12345678, abc12345 e senha123.', 'A senha precisa ter pelo menos 8 caracteres, com letras, números e símbolos.'],
                'cal.view_nights' => ['Mínimo'],
                'cal.price_max' => ['A diária deve ficar entre 1,00 e 999.999,00.'],
                'cal.price' => ['Diária nesta noite (opcional)', 'Diária nesta data'],
                'cal.price_hint' => ['vazio para usar a base', 'vazio usa a diária base'],
                'cal.min_from' => ['Mínimo de noites a partir de %s'],
                'cal.min_range' => ['Mínimo de noites de %s a %s'],
                'cal.block' => ['Bloquear esta data'],
                'cal.open_action' => ['Abrir para reserva'],
                'cal.save_changes' => ['Salvar mudanças'],
                'dash.remove_confirm' => ['Remover este imóvel e as fotos?'],
                'form.delete_house' => ['Remover este imóvel e as fotos?'],
                'form.calendar_months_hint' => ['No máximo %s meses, o limite do sistema.'],
                'amenity.pets' => ['Aceita animais'],
                'cal.sync_requested' => ['Sincronização agendada com sucesso.'],
                'amenity.noise_hint' => ['Horário em que é permitido fazer barulho. Exemplo: 08:00 às 22:00.'],
            ],
            'en' => [
                'account.delete_confirm' => ['Deactivate this account? The record stays in the system and only support can restore it.'],
                'cal.link' => ['.ics link', 'External link (.ics from Booking or Airbnb)'],
                'footer' => ['Short-term rentals. Calendars export as .ics for Booking and Airbnb.'],
                'cal.export' => ['Export this calendar'],
                'cal.legend_open' => ['Open nights', 'Open'],
                'cal.legend_system' => ['Nights blocked in this system', 'Blocked'],
                'cal.legend_sync' => ['Nights blocked by another calendar', 'Sync. Ext.'],
                'cal.legend_booking' => ['Nights with a reservation', 'Reserved'],
                'cal.legend_prereserva' => ['Pre-reservation nights (30 min)', 'Pre-Reserved'],
                'err.house_numbers' => ['Enter bedrooms, beds, guests and a nightly price of at least 1.00.', 'Enter bedrooms, beds and guests between 1 and 999, and a nightly price of at least 1.00.', 'Enter bedrooms, beds and guests between 1 and 999, and a nightly price from 1.00 to 999,999.00.'],
                'err.password_short' => ['Password must be at least 8 characters.'],
                'err.password_weak' => ['The password is too simple. Avoid sequences such as 123456 or abc123.', 'The password was not accepted. Use at least 8 characters and mix two kinds among letters, numbers and symbols. Example: Casa-livre1. Do not use a sequence or only letters followed by numbers, such as 12345678, abcdefgh, abc12345 or senha123.', 'The chosen password is weak. It must have at least 8 characters, with letters, numbers and symbols.'],
                'auth.password_rules' => ['At least 8 characters, with two kinds: letters, numbers or symbols. Avoid 12345678, abc12345 and senha123.', 'The password must have at least 8 characters, with letters, numbers and symbols.'],
                'cal.view_nights' => ['Minimum'],
                'cal.price_max' => ['The nightly price must be between 1.00 and 999,999.00.'],
                'cal.price' => ['Nightly price for this date (optional)', 'Nightly price on this date'],
                'cal.price_hint' => ['empty to use the base price', 'empty uses the base price'],
                'cal.min_from' => ['Minimum nights from %s'],
                'cal.min_range' => ['Minimum nights from %s to %s'],
                'cal.block' => ['Block this date'],
                'cal.open_action' => ['Open for booking'],
                'cal.save_changes' => ['Save changes'],
                'dash.remove_confirm' => ['Remove this place and its photos?'],
                'form.delete_house' => ['Remove this place and its photos?'],
                'form.calendar_months_hint' => ['At most %s months, the system limit.'],
                'cal.sync_requested' => ['Synchronization requested.'],
                'amenity.noise_hint' => ['Hours when noise is allowed. Example: 08:00 to 22:00.'],
            ],
            'es' => [
                'account.delete_confirm' => ['¿Inactivar esta cuenta? El registro permanece y solo soporte puede reactivarla.'],
                'cal.link' => ['Enlace .ics', 'Enlace externo (.ics de Booking o Airbnb)'],
                'footer' => ['Alquiler temporario. Calendarios exportables en .ics, compatibles con Booking y Airbnb.'],
                'cal.export' => ['Exportar este calendario'],
                'cal.legend_open' => ['Noches libres', 'Libre'],
                'cal.legend_system' => ['Noches bloqueadas por el propio sistema', 'Bloqueado'],
                'cal.legend_sync' => ['Noches bloqueadas por otro calendario', 'Sinc. Ext.'],
                'cal.legend_booking' => ['Noches con reserva', 'Reservado'],
                'cal.legend_prereserva' => ['Noches en pre-reserva (30 min)', 'Pre-reserva'],
                'err.house_numbers' => ['Indique habitaciones, camas, huéspedes y una tarifa mínima de 1,00.', 'Indique habitaciones, camas y huéspedes entre 1 y 999, y una tarifa mínima de 1,00.', 'Indique habitaciones, camas y huéspedes entre 1 y 999, y una tarifa entre 1,00 y 999.999,00.'],
                'err.password_short' => ['La contraseña debe tener al menos 8 caracteres.'],
                'err.password_weak' => ['La contraseña es demasiado simple. Evite secuencias como 123456 o abc123.', 'La contraseña no fue aceptada. Use al menos 8 caracteres y mezcle dos tipos entre letras, números y símbolos. Ejemplo: Casa-livre1. No use una secuencia ni solo letras seguidas de números, como 12345678, abcdefgh, abc12345 o senha123.', 'La contraseña elegida es débil. Debe tener al menos 8 caracteres, con letras, números y símbolos.'],
                'auth.password_rules' => ['Mínimo de 8 caracteres, con dos tipos: letras, números o símbolos. Evite 12345678, abc12345 y senha123.', 'La contraseña debe tener al menos 8 caracteres, con letras, números y símbolos.'],
                'cal.view_nights' => ['Mínimo'],
                'cal.price_max' => ['La tarifa debe estar entre 1,00 y 999.999,00.'],
                'cal.price' => ['Tarifa de esta noche (opcional)', 'Precio de esta fecha'],
                'cal.price_hint' => ['vacío para usar la base', 'vacío usa el precio base'],
                'cal.min_from' => ['Mínimo de noches a partir de %s'],
                'cal.min_range' => ['Mínimo de noches de %s a %s'],
                'cal.block' => ['Bloquear esta fecha'],
                'cal.open_action' => ['Abrir para reserva'],
                'cal.save_changes' => ['Guardar cambios'],
                'dash.remove_confirm' => ['¿Quitar este alojamiento y las fotos?'],
                'form.delete_house' => ['¿Quitar este alojamiento y las fotos?'],
                'form.calendar_months_hint' => ['Como máximo %s meses, el límite del sistema.'],
                'cal.sync_requested' => ['Sincronización solicitada.'],
                'amenity.noise_hint' => ['Horario en el que se puede hacer ruido. Ejemplo: 08:00 a 22:00.'],
            ],
        ];
        foreach ($previous as $lang => $map) {
            foreach ($map as $key => $olds) {
                foreach ($olds as $old) {
                    $refresh->execute([$packs[$lang][$key], $lang, $key, $old]);
                    if ($refresh->rowCount() > 0) {
                        $inserted = true;
                    }
                }
            }
        }
        $langCheck = $pdo->prepare('SELECT 1 FROM languages WHERE code = ?');
        $langInsert = $pdo->prepare('INSERT INTO languages (code, label) VALUES (?, ?)');
        foreach (self::LOCALES as $code => $label) {
            $langCheck->execute([$code]);
            if (!$langCheck->fetchColumn()) {
                $langInsert->execute([$code, $label]);
            }
        }
        if ($inserted) {
            $out = [];
            foreach ($pdo->query('SELECT lang, tkey, text FROM translations ORDER BY lang, tkey') as $row) {
                $out[$row['lang']][$row['tkey']] = $row['text'];
            }
            JsonCache::write('translations.json', $out);
        }
    }

    public static function locale(): string
    {
        $chosen = $_SESSION['locale'] ?? '';
        if (is_string($chosen) && isset(self::LOCALES[$chosen])) {
            return $chosen;
        }
        $header = strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if (str_contains($header, 'en')) {
            return 'en';
        }
        if (str_contains($header, 'es')) {
            return 'es';
        }
        return 'pt-BR';
    }

    public static function refresh(): array
    {
        self::seed(db());
        $out = [];
        foreach (db()->query('SELECT lang, tkey, text FROM translations ORDER BY lang, tkey') as $row) {
            $out[$row['lang']][$row['tkey']] = $row['text'];
        }
        JsonCache::write('translations.json', $out);
        return $out;
    }

    /** @param array<string, string|int> $params placeholders like {date}/{platform} */
    public static function t(string $key, array $params = []): string
    {
        $lang = self::locale();
        $pack = self::pack($lang);
        $text = $pack[$key] ?? (self::pack('pt-BR')[$key] ?? $key);
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    private static function pack(string $lang): array
    {
        $all = self::all();
        return $all[$lang] ?? [];
    }

    /** Per-process copy of translations.json (one read per request; in the queue worker, until the file changes). */
    private static ?array $rows = null;

    /** filemtime() of translations.json taken just before $rows was loaded. */
    private static ?int $stamp = null;

    /**
     * Drops the per-process copy: the next read goes back to translations.json (or the database).
     * $ifChanged (WorkerCaches): only when translations.json's mtime differs from the one stored at load.
     */
    public static function clearCache(bool $ifChanged = false): void
    {
        if ($ifChanged && (self::$rows === null || FileStamp::of(JsonCache::path('translations.json')) === self::$stamp)) {
            return;
        }
        self::$rows = null;
    }

    private static function all(): array
    {
        if (is_array(self::$rows)) {
            return self::$rows;
        }
        // Stamp before reading: a write after this point changes the stamp and is picked up on the next check.
        self::$stamp = FileStamp::of(JsonCache::path('translations.json'));
        $decoded = JsonCache::read('translations.json');
        if (is_array($decoded) && isset($decoded['pt-BR']['nav.search'])) {
            self::$rows = $decoded;
            return self::$rows;
        }
        self::$rows = self::refresh();
        return self::$rows;
    }
}

function t(string $key, array $params = []): string
{
    return I18n::t($key, $params);
}

function current_locale(): string
{
    return I18n::locale();
}
