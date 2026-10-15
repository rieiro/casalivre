<?php

declare(strict_types=1);

// Carrega as ações compartilhadas web/API (src/Acoes). Sem autoload do Composer: só require_once, na mesma
// ordem que estava em public/index.php. Os demais require_once do index.php continuam lá porque se intercalam
// com efeitos de inicialização (GeoSalt::enforce, PasswordPolicy, AccountFlow::$enqueue, reject_oversize_upload).
require_once __DIR__ . '/Acoes/LoginAttempt.php';
require_once __DIR__ . '/Acoes/AccountUpdate.php';
require_once __DIR__ . '/Acoes/EmailChangeConfirm.php';
require_once __DIR__ . '/Acoes/CalendarSave.php';
require_once __DIR__ . '/Acoes/ICalImport.php';
require_once __DIR__ . '/Acoes/ICalFeedRemove.php';
require_once __DIR__ . '/Acoes/CoverSet.php';
require_once __DIR__ . '/Acoes/ReviewAdd.php';
require_once __DIR__ . '/Acoes/ThemeSet.php';
