<?php
// Tema de alto contraste (exemplo de tema; só sobrescreve tokens do app.css).
return [
    'nome' => 'CasaLivre',
    'rotulo' => 'Alto contraste',
    'logo' => null,
    'favicon' => null,
    'tokens' => [
        '--ink' => '#000',
        '--muted' => '#2b2b2b',
        '--brand' => '#000',
        '--brand-dark' => '#000',
        '--link' => '#0000c8',
        '--link-button' => '#0000c8',
        '--line' => '#5c5c5c',
        '--bg' => '#fff',
    ],
    // Cores do mapa por papel (ver MapStyle::ROLES); só #rgb/#rrggbb.
    'mapa' => [
        'fundo' => '#ffffff',
        'terra' => '#ffffff',
        'agua' => '#7fb2ff',
        'agua_rotulo' => '#0033aa',
        'parque' => '#8fd19e',
        'edificio' => '#bdbdbd',
        'via' => '#ffffff',
        'via_contorno' => '#333333',
        'limite' => '#000000',
        'rotulo' => '#000000',
        'rotulo_halo' => '#ffffff',
    ],
    'tokens_escuro' => [
        '--ink' => '#fff',
        '--ink-strong' => '#fff',
        '--muted' => '#e0e0e0',
        '--bg' => '#000',
        '--surface' => '#000',
        '--brand' => '#fff',
        '--brand-dark' => '#ddd',
        '--link' => '#9cf',
        '--line' => '#bbb',
    ],
];
