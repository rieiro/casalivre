<?php
// Child process of mail_cooldown_race_test.php (not a test: run.php only runs *_test.php). Waits for the start file,
// then pushes the same (type, e-mail) once on the SQL mail queue, with a pause between the cooldown check and the
// insert; prints 1 if the push was accepted, 0 if the cooldown refused it.
declare(strict_types=1);

const CL_ROOT = __DIR__ . '/../..';
[$_, $go, $email] = $argv;
require_once CL_ROOT . '/src/Db.php';
require_once CL_ROOT . '/src/MailQueue.php';
$q = new DbMailQueue(db());
DbMailQueue::$afterCheck = static fn () => usleep(200000);
while (!is_file($go)) {
    usleep(1000);
}
echo $q->push('activation', $email) ? '1' : '0';
