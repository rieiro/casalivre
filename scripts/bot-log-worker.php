#!/usr/bin/env php
<?php

declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

$root = dirname(__DIR__);
require_once $root . '/src/Db.php';
require_once $root . '/src/ValkeyJobQueue.php';
require_once $root . '/src/BotTelemetry.php';

Db::loadEnv();

$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $handler = static function () use (&$stop): void {
        $stop = true;
    };
    pcntl_signal(SIGTERM, $handler);
    pcntl_signal(SIGINT, $handler);
}

$lastSweep = 0;
$lastRetention = 0;

echo "casalivre-bot-log worker started.
";

while (!$stop) {
    try {
        $now = time();

        // 1. Sweep expired booking intents every 60 seconds
        if ($now - $lastSweep >= 60) {
            $lastSweep = $now;
            db()->exec("UPDATE booking_intents SET status = 'expired' WHERE status = 'held' AND expires_at <= now()");
        }

        // 2. 14-day retention cleanup once per day
        if ($now - $lastRetention >= 86400) {
            $lastRetention = $now;
            db()->exec("DELETE FROM bot_action_logs WHERE created_at < now() - interval '14 days'");
        }

        // 3. Drain up to 500 items from Valkey list
        $redis = Valkey::connect(Valkey::WORKER_TIMEOUT);
        $items = $redis->lPop(BotTelemetry::VALKEY_KEY, 500);

        if (!empty($items)) {
            if (!is_array($items)) {
                $items = [$items];
            }

            $rows = [];
            foreach ($items as $json) {
                $data = json_decode((string) $json, true);
                if (is_array($data) && isset($data['actor_type'])) {
                    $rows[] = $data;
                }
            }

            if (!empty($rows)) {
                $sql = "INSERT INTO bot_action_logs (actor_type, bot_name, channel, action_name, property_id, search_params, status_code, latency_ms, ip_hash) VALUES ";
                $params = [];
                $clauses = [];

                foreach ($rows as $i => $r) {
                    $clauses[] = "('bot', :bot_{$i}, :chan_{$i}, :act_{$i}, :pid_{$i}, :sp_{$i}, :sc_{$i}, :lat_{$i}, :ip_{$i})";
                    $params[":bot_{$i}"] = $r['bot_name'];
                    $params[":chan_{$i}"] = $r['channel'];
                    $params[":act_{$i}"] = $r['action_name'];
                    $params[":pid_{$i}"] = $r['property_id'];
                    $params[":sp_{$i}"] = $r['search_params'];
                    $params[":sc_{$i}"] = (int) $r['status_code'];
                    $params[":lat_{$i}"] = (int) $r['latency_ms'];
                    $params[":ip_{$i}"] = !empty($r['ip_hash']) ? hex2bin($r['ip_hash']) : null;
                }

                $stmt = db()->prepare($sql . implode(', ', $clauses));
                foreach ($params as $k => $v) {
                    if (str_starts_with($k, ':ip_') && $v !== null) {
                        $stmt->bindValue($k, $v, PDO::PARAM_LOB);
                    } elseif (str_starts_with($k, ':sp_') && $v === null) {
                        $stmt->bindValue($k, null, PDO::PARAM_NULL);
                    } elseif (str_starts_with($k, ':pid_') && $v === null) {
                        $stmt->bindValue($k, null, PDO::PARAM_NULL);
                    } else {
                        $stmt->bindValue($k, $v);
                    }
                }
                $stmt->execute();
            }
        } else {
            // Sleep up to 5 seconds if no items
            sleep(5);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "bot-log-worker error: " . $e->getMessage() . "
");
        sleep(2);
    }
}

echo "casalivre-bot-log worker draining before shutdown...
";

// Drain remaining on shutdown
try {
    $redis = Valkey::connect(Valkey::WORKER_TIMEOUT);
    while (true) {
        $items = $redis->lPop(BotTelemetry::VALKEY_KEY, 500);
        if (empty($items)) {
            break;
        }
        if (!is_array($items)) {
            $items = [$items];
        }

        $rows = [];
        foreach ($items as $json) {
            $data = json_decode((string) $json, true);
            if (is_array($data) && isset($data['actor_type'])) {
                $rows[] = $data;
            }
        }

        if (!empty($rows)) {
            $sql = "INSERT INTO bot_action_logs (actor_type, bot_name, channel, action_name, property_id, search_params, status_code, latency_ms, ip_hash) VALUES ";
            $params = [];
            $clauses = [];

            foreach ($rows as $i => $r) {
                $clauses[] = "('bot', :bot_{$i}, :chan_{$i}, :act_{$i}, :pid_{$i}, :sp_{$i}, :sc_{$i}, :lat_{$i}, :ip_{$i})";
                $params[":bot_{$i}"] = $r['bot_name'];
                $params[":chan_{$i}"] = $r['channel'];
                $params[":act_{$i}"] = $r['action_name'];
                $params[":pid_{$i}"] = $r['property_id'];
                $params[":sp_{$i}"] = $r['search_params'];
                $params[":sc_{$i}"] = (int) $r['status_code'];
                $params[":lat_{$i}"] = (int) $r['latency_ms'];
                $params[":ip_{$i}"] = !empty($r['ip_hash']) ? hex2bin($r['ip_hash']) : null;
            }

            $stmt = db()->prepare($sql . implode(', ', $clauses));
            foreach ($params as $k => $v) {
                if (str_starts_with($k, ':ip_') && $v !== null) {
                    $stmt->bindValue($k, $v, PDO::PARAM_LOB);
                } elseif (str_starts_with($k, ':sp_') && $v === null) {
                    $stmt->bindValue($k, null, PDO::PARAM_NULL);
                } elseif (str_starts_with($k, ':pid_') && $v === null) {
                    $stmt->bindValue($k, null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindValue($k, $v);
                }
            }
            $stmt->execute();
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "bot-log-worker drain error: " . $e->getMessage() . "
");
}

echo "casalivre-bot-log worker exited cleanly.
";
exit(0);
