<?php

declare(strict_types=1);

require_once __DIR__ . "/Db.php";
require_once __DIR__ . "/QuoteGenerator.php";
require_once __DIR__ . "/HouseRepository.php";

final class QuoteRepository
{
    public static function ensureTable(): void
    {
        db()->exec("CREATE TABLE IF NOT EXISTS quotes (
            id SERIAL PRIMARY KEY,
            quote_hash VARCHAR(32) UNIQUE NOT NULL,
            property_id INTEGER NOT NULL REFERENCES properties(id) ON DELETE CASCADE,
            checkin DATE NOT NULL,
            checkout DATE NOT NULL,
            nights INTEGER NOT NULL,
            nightly_price INTEGER NOT NULL,
            cleaning_fee INTEGER NOT NULL,
            taxes INTEGER NOT NULL,
            special_offer INTEGER,
            total_price INTEGER NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            pix_key VARCHAR(100) NOT NULL DEFAULT 'rafaelrieiro@gmail.com',
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_quotes_hash ON quotes (quote_hash)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_quotes_property ON quotes (property_id)");
        db()->exec("ALTER TABLE properties ADD COLUMN IF NOT EXISTS pix_key TEXT NOT NULL DEFAULT ''");
        if (class_exists('ActivityLog')) {
            ActivityLog::seedActions(db());
        }
    }

    public static function cleanupExpired(?int $propertyId = null): void
    {
        self::ensureTable();
        $check = $propertyId !== null
            ? db()->prepare("SELECT 1 FROM quotes WHERE property_id = ? AND status = 'pending' AND expires_at < CURRENT_TIMESTAMP LIMIT 1")
            : db()->query("SELECT 1 FROM quotes WHERE status = 'pending' AND expires_at < CURRENT_TIMESTAMP LIMIT 1");
        if ($propertyId !== null) {
            $check->execute([$propertyId]);
        }
        if (!$check->fetchColumn()) {
            return;
        }

        if ($propertyId !== null) {
            $stmt = db()->prepare("UPDATE quotes SET status = 'expired' WHERE property_id = ? AND status = 'pending' AND expires_at < CURRENT_TIMESTAMP");
            $stmt->execute([$propertyId]);
        } else {
            db()->exec("UPDATE quotes SET status = 'expired' WHERE status = 'pending' AND expires_at < CURRENT_TIMESTAMP");
        }

        $sql = "UPDATE calendar_days c
                SET blocked = 0, origin = ''
                WHERE c.origin = 'pre-reserva'
                  AND NOT EXISTS (
                      SELECT 1 FROM quotes q
                      WHERE q.property_id = c.property_id
                        AND q.status = 'pending'
                        AND q.expires_at >= CURRENT_TIMESTAMP
                        AND q.checkin <= c.day
                        AND c.day < q.checkout
                  )";
        if ($propertyId !== null) {
            $sql .= " AND c.property_id = ?";
            $stmt = db()->prepare($sql);
            $stmt->execute([$propertyId]);
        } else {
            db()->exec($sql);
        }
    }

    /**
     * @return array{available: bool, conflict_date: ?string}
     */
    public static function checkAvailability(int $propertyId, string $checkin, string $checkout, ?string $forQuoteHash = null): array
    {
        self::ensureTable();
        self::cleanupExpired($propertyId);
        if ($forQuoteHash !== null) {
            $stmt = db()->prepare("SELECT day FROM calendar_days WHERE property_id = ? AND day >= ? AND day < ? AND blocked = 1 AND origin != 'pre-reserva' LIMIT 1");
            $stmt->execute([$propertyId, $checkin, $checkout]);
        } else {
            $stmt = db()->prepare("SELECT day FROM calendar_days WHERE property_id = ? AND day >= ? AND day < ? AND blocked = 1 LIMIT 1");
            $stmt->execute([$propertyId, $checkin, $checkout]);
        }
        $conflict = $stmt->fetchColumn();
        if ($conflict !== false && $conflict !== null) {
            return ["available" => false, "conflict_date" => (string) $conflict];
        }
        return ["available" => true, "conflict_date" => null];
    }

    /**
     * @return array<string, mixed>
     */
    public static function create(
        int $propertyId,
        string $checkin,
        string $checkout,
        int $taxPercent = 0,
        ?int $specialOffer = null,
        string $pixKey = "rafaelrieiro@gmail.com"
    ): array {
        self::ensureTable();
        $avail = self::checkAvailability($propertyId, $checkin, $checkout);
        if (!$avail["available"]) {
            throw new InvalidArgumentException("Data indisponível: " . $avail["conflict_date"]);
        }

        $property = HouseRepository::find($propertyId);
        if (!$property) {
            throw new InvalidArgumentException("Imóvel não encontrado: {$propertyId}");
        }
        if ($pixKey === "rafaelrieiro@gmail.com" && !empty($property["pix_key"])) {
            $pixKey = (string) $property["pix_key"];
        }

        $d1 = new DateTimeImmutable($checkin);
        $d2 = new DateTimeImmutable($checkout);
        $nights = (int) $d1->diff($d2)->days;
        if ($nights < 1) {
            throw new InvalidArgumentException("O período deve ter pelo menos 1 noite.");
        }

        $calc = QuoteGenerator::calculate(
            nightlyPrice: (int) $property["nightly_price"],
            cleaningFee: (int) ($property["cleaning_fee"] ?? 0),
            nights: $nights,
            taxPercent: $taxPercent,
            specialOffer: $specialOffer
        );

        $hash = bin2hex(random_bytes(8));
        $expiresAt = (new DateTimeImmutable("+30 minutes"))->format("Y-m-d H:i:s");

        return Db::transactional(db(), static function () use (
            $hash, $propertyId, $checkin, $checkout, $nights, $property, $calc, $pixKey, $expiresAt
        ): array {
            $stmt = db()->prepare("INSERT INTO quotes (
                quote_hash, property_id, checkin, checkout, nights, nightly_price,
                cleaning_fee, taxes, special_offer, total_price, status, pix_key, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?) RETURNING id");
            $stmt->execute([
                $hash,
                $propertyId,
                $checkin,
                $checkout,
                $nights,
                (int) $property["nightly_price"],
                $calc["cleaning"],
                $calc["taxes"],
                $calc["special_offer"],
                $calc["total"],
                $pixKey,
                $expiresAt,
            ]);
            $id = (int) $stmt->fetchColumn();

            $cursor = new DateTimeImmutable($checkin);
            $end = new DateTimeImmutable($checkout);
            $ins = db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, price, min_nights, origin)
                                  VALUES (?, ?, 1, ?, 1, 'pre-reserva')
                                  ON CONFLICT (property_id, day) DO UPDATE SET blocked = 1, origin = 'pre-reserva'");
            while ($cursor < $end) {
                $dayIso = $cursor->format("Y-m-d");
                $ins->execute([$propertyId, $dayIso, (int) $property["nightly_price"]]);
                $cursor = $cursor->modify("+1 day");
            }

            return array_merge($calc, [
            "id" => (int) $stmt->fetchColumn(),
            "quote_hash" => $hash,
            "property_id" => $propertyId,
            "property" => $property,
            "checkin" => $checkin,
            "checkout" => $checkout,
            "nights" => $nights,
            "pix_key" => $pixKey,
            "expires_at" => $expiresAt,
            "status" => "pending",
            ]);
        });
    }

    public static function findByHash(string $hash): ?array
    {
        self::ensureTable();
        $stmt = db()->prepare("SELECT q.*, p.title as property_title, p.public_hash as property_hash,
                                      p.address, p.nightly_price as base_nightly, p.max_guests,
                                      p.bedrooms, p.beds, p.checkin_time, p.checkout_time
                               FROM quotes q
                               JOIN properties p ON p.id = q.property_id
                               WHERE q.quote_hash = ?");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $nights = (int) ($row["nights"] ?? 0);
        $nightlyPrice = (int) ($row["nightly_price"] ?? 0);
        $cleaningFee = (int) ($row["cleaning_fee"] ?? 0);
        $taxes = (int) ($row["taxes"] ?? 0);
        $totalPrice = (int) ($row["total_price"] ?? 0);
        $subtotal = $nights * $nightlyPrice;

        return array_merge($row, [
            "nights" => $nights,
            "nightly_price" => $nightlyPrice,
            "subtotal" => $subtotal,
            "cleaning" => $cleaningFee,
            "cleaning_fee" => $cleaningFee,
            "taxes" => $taxes,
            "total" => $totalPrice,
            "total_price" => $totalPrice,
            "special_offer" => (isset($row["special_offer"]) && $row["special_offer"] !== null) ? (int) $row["special_offer"] : null,
        ]);
    }

    public static function confirmPayment(string $hash): bool
    {
        self::ensureTable();
        self::cleanupExpired();
        $quote = self::findByHash($hash);
        if (!$quote) {
            return false;
        }
        if ($quote["status"] === "confirmed") {
            return true;
        }
        if ($quote["status"] === "expired") {
            return false;
        }

        return Db::transactional(db(), static function () use ($quote, $hash): bool {
            $avail = self::checkAvailability((int) $quote["property_id"], (string) $quote["checkin"], (string) $quote["checkout"], $hash);
            if (!$avail["available"]) {
                throw new RuntimeException("Data conflitante ao confirmar pagamento.");
            }

            $cursor = new DateTimeImmutable((string) $quote["checkin"]);
            $end = new DateTimeImmutable((string) $quote["checkout"]);
            $ins = db()->prepare("INSERT INTO calendar_days (property_id, day, blocked, price, min_nights, origin)
                                  VALUES (?, ?, 1, ?, 1, 'reserva')
                                  ON CONFLICT (property_id, day) DO UPDATE SET blocked = 1, origin = 'reserva'");

            while ($cursor < $end) {
                $dayIso = $cursor->format("Y-m-d");
                $ins->execute([(int) $quote["property_id"], $dayIso, (int) $quote["nightly_price"]]);
                $cursor = $cursor->modify("+1 day");
            }

            db()->prepare("UPDATE quotes SET status = 'confirmed' WHERE quote_hash = ?")->execute([$hash]);
            return true;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function listForUser(int $userId, ?string $status = null): array
    {
        self::ensureTable();
        $sql = "SELECT q.*, p.title as property_title, p.public_hash as property_hash
                FROM quotes q
                JOIN properties p ON p.id = q.property_id
                WHERE (p.user_id = ? OR p.group_id IN (SELECT group_id FROM group_members WHERE user_id = ? AND role = 'gestor'))";
        $params = [$userId, $userId];
        if ($status !== null && $status !== "") {
            $sql .= " AND q.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY q.created_at DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function listAll(?string $status = null): array
    {
        self::ensureTable();
        $sql = "SELECT q.*, p.title as property_title, p.public_hash as property_hash
                FROM quotes q
                JOIN properties p ON p.id = q.property_id";
        $params = [];
        if ($status !== null && $status !== '') {
            $sql .= " WHERE q.status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY q.created_at DESC";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
