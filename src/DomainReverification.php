<?php

declare(strict_types=1);

require_once __DIR__ . '/DomainVerification.php';
require_once __DIR__ . '/GroupRepository.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Reverificação dos domínios de grupo pelo cron (scripts/verify-domains.php), nunca na requisição (pr64 C1/C2).
 *
 * Em cada execução confere os domínios "vencidos": checagem pedida pelo admin (last_check_at NULL) ou verificado com
 * a última checagem há mais de RECHECK_HOURS. Por domínio:
 * - consulta falhou (timeout, SERVFAIL): não conta e não grava nada; fica vencido e volta na próxima execução. Mas, se
 *   verificado e já passaram GRACE_HOURS desde verified_at, perde a verificação (log 46, motivo falha_consulta, aviso):
 *   quem controla o DNS não segura o domínio respondendo SERVFAIL para sempre (pr65 B1);
 * - TXT certo: verified_at = agora (a última confirmação), fail_count = 0; se não estava verificado, log 45;
 * - TXT ausente num não verificado: só grava last_check_at (o pedido do admin foi atendido, sem sucesso);
 * - TXT ausente num verificado: fail_count + 1; com MAX_FAILS seguidas ou GRACE_HOURS sem sucesso (desde verified_at)
 *   -> verified_at = NULL, fail_count = 0, log 46 e aviso aos admins da plataforma (mail_queue, tipo domain-notice).
 * Grava só se o token não mudou no meio (o admin pode ter gerado outro).
 */
final class DomainReverification
{
    /** Cadência do cron (scripts/verify-domains.php, deploy: a cada hora): prazo máximo de um pedido "Verificar agora". */
    public const CRON_INTERVAL_MINUTES = 60;
    public const RECHECK_HOURS = 23;
    public const MAX_FAILS = 3;
    public const GRACE_HOURS = 72;

    /** @var callable(string): void fn(e-mail do admin) */
    private $notify;

    public function __construct(private readonly DomainVerification $verification, ?callable $notify = null)
    {
        // Aviso pela mail_queue (tipo domain-notice; o mail-worker monta e envia, com retentativa): pr65 C2.
        $this->notify = $notify ?? static function (string $to): void {
            require_once __DIR__ . '/Mailer.php';
            Mailer::enqueueAccountMail('domain-notice', $to);
        };
    }

    /** @return array{conferidos:int,ok:int,ausente:int,falha_consulta:int,desverificados:int} */
    public function run(?int $now = null): array
    {
        $now ??= time();
        $stamp = gmdate('Y-m-d H:i:s', $now);
        $out = ['conferidos' => 0, 'ok' => 0, 'ausente' => 0, 'falha_consulta' => 0, 'desverificados' => 0];
        foreach (GroupRepository::domainsWithToken() as $row) {
            if (!self::due($row, $now)) {
                continue;
            }
            $out['conferidos']++;
            $result = $this->verification->check((string) $row['domain'], (string) $row['verify_token']);
            $out[$result]++;
            $id = (int) $row['id'];
            $token = (string) $row['verify_token'];
            $verified = $row['verified_at'] !== null;
            $params = ['grupo' => $row['slug'], 'dominio' => $row['domain']];
            $lastOk = $verified ? strtotime((string) $row['verified_at'] . ' UTC') : false;
            $graceOver = $lastOk !== false && $now - $lastOk >= self::GRACE_HOURS * 3600;
            if ($result === DomainVerification::LOOKUP_FAILED) {
                if ($graceOver && $this->unverify($row, $stamp, (int) $row['fail_count'], DomainVerification::LOOKUP_FAILED)) {
                    $out['desverificados']++;
                }
                continue;
            }
            if ($result === DomainVerification::OK) {
                if (GroupRepository::saveDomainCheck(id: $id, token: $token, verifiedAt: $stamp, checkedAt: $stamp, failCount: 0) && !$verified) {
                    activity(ActivityLog::GROUP_DOMAIN_VERIFIED, $params, ['verificado' => false], ['verificado' => true]);
                }
                continue;
            }
            if (!$verified) {
                GroupRepository::saveDomainCheck(id: $id, token: $token, verifiedAt: null, checkedAt: $stamp, failCount: (int) $row['fail_count']);
                continue;
            }
            $fails = (int) $row['fail_count'] + 1;
            if ($fails < self::MAX_FAILS && !$graceOver) {
                GroupRepository::saveDomainCheck(id: $id, token: $token, verifiedAt: (string) $row['verified_at'], checkedAt: $stamp, failCount: $fails);
                continue;
            }
            if ($this->unverify($row, $stamp, $fails, DomainVerification::MISSING)) {
                $out['desverificados']++;
            }
        }
        return $out;
    }

    /** verified_at NULL + log 46 (com motivo: ausente ou falha_consulta) + aviso; true se gravou. */
    private function unverify(array $row, string $stamp, int $fails, string $motivo): bool
    {
        if (!GroupRepository::saveDomainCheck(id: (int) $row['id'], token: (string) $row['verify_token'], verifiedAt: null, checkedAt: $stamp, failCount: 0)) {
            return false;
        }
        activity(ActivityLog::GROUP_DOMAIN_UNVERIFIED, ['grupo' => $row['slug'], 'dominio' => $row['domain'], 'falhas' => $fails, 'motivo' => $motivo], ['verificado' => true], ['verificado' => false]);
        $this->warn();
        return true;
    }

    /** Prazo do pedido pendente para o admin, a partir da cadência do cron ("em até 1 hora"). */
    public static function checkDeadline(): string
    {
        $m = self::CRON_INTERVAL_MINUTES;
        return $m % 60 === 0 ? 'em até ' . ($m / 60) . ($m === 60 ? ' hora' : ' horas') : 'em até ' . $m . ' minutos';
    }

    /** Vencido: checagem pedida (last_check_at NULL) ou verificado com a última checagem há mais de RECHECK_HOURS. */
    public static function due(array $row, int $now): bool
    {
        if ($row['last_check_at'] === null) {
            return true;
        }
        $last = strtotime((string) $row['last_check_at'] . ' UTC');
        return $row['verified_at'] !== null && ($last === false || $now - $last >= self::RECHECK_HOURS * 3600);
    }

    /** Enfileira o aviso a cada admin ativo (o corpo é montado no worker: Mailer::domainNotice). */
    private function warn(): void
    {
        foreach (GroupRepository::adminEmails() as $email) {
            try {
                ($this->notify)($email);
            } catch (Throwable $e) {
                error_log('CasaLivre: falha ao avisar admin sobre domínio (' . $e::class . ').');
            }
        }
    }
}
