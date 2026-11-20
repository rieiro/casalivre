#!/usr/bin/env php
<?php

declare(strict_types=1);

// Verificações OTA pendentes (iCal challenge-response), fora da requisição. Cron sugerido a cada 5 minutos:
//   */5 * * * *  php /caminho/casalivre/scripts/check_pending_ota_verifications.php
// flock em storage/ota-verify.lock (0600): se outra execução estiver rodando, sai com 0 sem fazer nada.
// Uma falha de rede em um item não aborta o lote; o resumo vai para a saída padrão e o processo sai com 0.

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/helpers.php';
require_once $root . '/src/ActivityLog.php';
require_once $root . '/src/QueueWorker.php';
require_once $root . '/src/OtaVerificationService.php';
require_once $root . '/src/IcalExportService.php';
Db::loadEnv();

@mkdir($root . '/storage', 0700, true);
$lock = QueueWorker::openLock($root . '/storage/ota-verify.lock');
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "outra verificação OTA em execução\n";
    exit(0);
}

$now = time();
$report = ['checked' => 0, 'verified' => 0, 'pending' => 0, 'expired' => 0, 'passive_checked' => 0, 'downgraded' => 0, 'attempts_purged' => 0, 'exports_purged' => 0, 'errors' => 0];

try {
    $expired = db()->prepare(
        "UPDATE property_external_verifications
            SET status = 'failed', updated_at = to_timestamp(?)
          WHERE status = 'pending_challenge' AND challenge_expires_at <= to_timestamp(?)"
    );
    $expired->execute([$now, $now]);
    $report['expired'] = $expired->rowCount();

    $stmt = db()->prepare(
        "SELECT id FROM property_external_verifications
          WHERE status = 'pending_challenge'
            AND challenge_expires_at > to_timestamp(?)
            AND (last_checked_at IS NULL OR last_checked_at <= to_timestamp(?))
          ORDER BY last_checked_at ASC NULLS FIRST, id ASC
          LIMIT 50"
    );
    $stmt->execute([$now, $now - OtaVerificationService::CHECK_COOLDOWN_SECONDS]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $report['checked']++;
        try {
            $result = OtaVerificationService::checkChallenge((int) $id, true);
            $status = (string) ($result['status'] ?? '');
            if ($status === 'verified') {
                $report['verified']++;
            } elseif ($status === 'expired') {
                $report['expired']++;
            } else {
                $report['pending']++;
            }
        } catch (Throwable $e) {
            $report['errors']++;
            fwrite(STDERR, 'check_pending_ota_verifications: verificação ' . (int) $id . ': ' . $e->getMessage() . "\n");
        }
    }
    // Verificação passiva (1x/dia): 3 falhas 401/403/404/410 rebaixam o selo.
    $passive = db()->prepare(
        "SELECT id FROM property_external_verifications
          WHERE status = 'verified'
            AND (last_passive_check_at IS NULL OR last_passive_check_at <= to_timestamp(?))
          ORDER BY last_passive_check_at ASC NULLS FIRST, id ASC
          LIMIT 50"
    );
    $passive->execute([$now - 86400]);
    foreach ($passive->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $report['passive_checked']++;
        try {
            $result = OtaVerificationService::passiveVerifyRow((int) $id);
            if (($result['status'] ?? '') === 'downgraded') {
                $report['downgraded']++;
            }
        } catch (Throwable $e) {
            $report['errors']++;
            fwrite(STDERR, 'check_pending_ota_verifications: passiva ' . (int) $id . ': ' . $e->getMessage() . "\n");
        }
    }

    // Retenção: tentativas antigas do limite diário e links de exportação revogados.
    $report['attempts_purged'] = OtaVerificationService::purgeOldChallengeAttempts();
    $report['exports_purged'] = IcalExportService::purgeRevokedExports();

} catch (Throwable $e) {
    fwrite(STDERR, 'check_pending_ota_verifications: ' . $e->getMessage() . "\n");
    exit(0);
}

foreach ($report as $key => $count) {
    echo str_pad($key, 16) . $count . "\n";
}
exit(0);
