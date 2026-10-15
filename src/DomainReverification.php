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
 * - consulta falhou (timeout, SERVFAIL): não conta e não grava nada; fica vencido e volta na próxima execução;
 * - TXT certo: verified_at = agora (a última confirmação), fail_count = 0; se não estava verificado, log 45;
 * - TXT ausente num não verificado: só grava last_check_at (o pedido do admin foi atendido, sem sucesso);
 * - TXT ausente num verificado: fail_count + 1; com MAX_FAILS seguidas ou GRACE_HOURS sem sucesso (desde verified_at)
 *   -> verified_at = NULL, fail_count = 0, log 46 e aviso por e-mail aos admins da plataforma.
 * Grava só se o token não mudou no meio (o admin pode ter gerado outro).
 */
final class DomainReverification
{
    public const RECHECK_HOURS = 23;
    public const MAX_FAILS = 3;
    public const GRACE_HOURS = 72;

    /** @var callable(string, string, string): void fn(e-mail, assunto, corpo) */
    private $notify;

    public function __construct(private readonly DomainVerification $verification, ?callable $notify = null)
    {
        $this->notify = $notify ?? static function (string $to, string $subject, string $body): void {
            require_once __DIR__ . '/Mailer.php';
            Mailer::deliver($to, $subject, $body);
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
            if ($result === DomainVerification::LOOKUP_FAILED) {
                continue;
            }
            $id = (int) $row['id'];
            $token = (string) $row['verify_token'];
            $verified = $row['verified_at'] !== null;
            $params = ['grupo' => $row['slug'], 'dominio' => $row['domain']];
            if ($result === DomainVerification::OK) {
                if (GroupRepository::saveDomainCheck($id, $token, $stamp, $stamp, 0) && !$verified) {
                    activity(ActivityLog::GROUP_DOMAIN_VERIFIED, $params, ['verificado' => false], ['verificado' => true]);
                }
                continue;
            }
            if (!$verified) {
                GroupRepository::saveDomainCheck($id, $token, null, $stamp, (int) $row['fail_count']);
                continue;
            }
            $fails = (int) $row['fail_count'] + 1;
            $lastOk = strtotime((string) $row['verified_at'] . ' UTC');
            $expired = $fails >= self::MAX_FAILS || ($lastOk !== false && $now - $lastOk >= self::GRACE_HOURS * 3600);
            if (!$expired) {
                GroupRepository::saveDomainCheck($id, $token, (string) $row['verified_at'], $stamp, $fails);
                continue;
            }
            if (GroupRepository::saveDomainCheck($id, $token, null, $stamp, 0)) {
                $out['desverificados']++;
                activity(ActivityLog::GROUP_DOMAIN_UNVERIFIED, $params + ['falhas' => $fails], ['verificado' => true], ['verificado' => false]);
                $this->warn((string) $row['slug'], (string) $row['domain'], $fails);
            }
        }
        return $out;
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

    private function warn(string $slug, string $domain, int $fails): void
    {
        $subject = 'CasaLivre: o domínio ' . $domain . ' do grupo ' . $slug . ' perdeu a verificação';
        $body = 'A reverificação diária não achou o registro DNS TXT ' . DomainVerification::txtName($domain)
            . ' (' . $fails . ' checagem(ns) seguida(s) sem o token ou ' . self::GRACE_HOURS . " h sem sucesso).\r\n"
            . "O domínio deixou de usar o tema do grupo. Para voltar, publique o TXT de novo e clique em \"Verificar agora\" em /admin/grupos/" . $slug . ".\r\n";
        foreach (GroupRepository::adminEmails() as $email) {
            try {
                ($this->notify)($email, $subject, $body);
            } catch (Throwable $e) {
                error_log('CasaLivre: falha ao avisar admin sobre domínio (' . $e::class . ').');
            }
        }
    }
}
