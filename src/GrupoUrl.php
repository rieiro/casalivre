<?php

declare(strict_types=1);

require_once __DIR__ . '/GroupRepository.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Acoes/GrupoValidacao.php';

/**
 * URL base do grupo para links absolutos (páginas e e-mails), Sprint 3 PR 9. Nunca vem do Host da requisição:
 *  - grupo com domínio verificado (lista do banco): https://<domínio>/g/<slug> (primeiro verificado, por id; o domínio
 *    é revalidado e nunca é o da plataforma);
 *  - senão: APP_URL + /g/<slug> (Mailer::linkFromAppUrl: sem APP_URL só em dev, com o host fixo do link).
 */
final class GrupoUrl
{
    public static function base(array $group): string
    {
        $path = '/g/' . (string) $group['slug'];
        foreach (GroupRepository::verifiedDomains((int) $group['id']) as $d) {
            $domain = GrupoValidacao::domain($d);
            if ($domain !== null && $domain === $d && !GrupoValidacao::isPlatform($domain)) {
                return 'https://' . $domain . $path;
            }
        }
        return Mailer::linkFromAppUrl('http://localhost' . $path);
    }

    /** Home da vitrine (com barra final). */
    public static function home(array $group): string
    {
        return self::base($group) . '/';
    }
}
