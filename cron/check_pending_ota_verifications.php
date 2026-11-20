#!/usr/bin/env php
<?php

declare(strict_types=1);

// Wrapper fino: mantém o mesmo caminho em cron/ e scripts/ (o script real fica em scripts/).
require dirname(__DIR__) . '/scripts/check_pending_ota_verifications.php';
