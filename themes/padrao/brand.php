<?php
// Tema padrão da plataforma. Sem tokens: vale o :root do public/assets/app.css (aparência igual à de antes).
// Campos: nome (marca no cabeçalho), rotulo (lista do admin), logo/favicon (arquivo nesta pasta, svg/png/ico;
// null = texto da marca e /favicon.ico), tokens (sobrescrevem o :root), tokens_escuro (prefers-color-scheme: dark).
return [
    'nome' => 'CasaLivre',
    'rotulo' => 'Padrão',
    'logo' => null,
    'favicon' => null,
    'tokens' => [],
    'tokens_escuro' => [],
];
