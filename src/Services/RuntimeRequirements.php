<?php

namespace Pelican\AutoBackup\Services;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class RuntimeRequirements
{
    public static function check(bool $worker = true): void
    {
        $queue = config('queue.default');
        if (config("queue.connections.$queue.driver", $queue) === 'sync') {
            throw new RuntimeException('Auto Backup requires an asynchronous queue.');
        }
        $store = Cache::store()->getStore();
        if ($store instanceof \Illuminate\Cache\ArrayStore || !$store instanceof LockProvider) {
            throw new RuntimeException('Auto Backup requires a persistent cache with atomic locks (database, redis or file).');
        }
        $retryAfter = config("queue.connections.$queue.retry_after");
        if ($retryAfter !== null && (int) $retryAfter <= 60) {
            throw new RuntimeException('queue.retry_after must exceed the 60-second job timeout.');
        }
        if ($worker && !function_exists('pcntl_alarm')) {
            throw new RuntimeException('Auto Backup requires PHP pcntl for queue worker timeouts.');
        }
    }
}
