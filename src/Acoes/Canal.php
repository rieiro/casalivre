<?php

declare(strict_types=1);

/** Canal que chamou a ação compartilhada; usado onde web e API divergem de propósito (SNAPSHOT). */
enum Canal: string
{
    case Web = 'web';
    case Api = 'api';
}
