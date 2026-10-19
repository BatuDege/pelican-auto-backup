<?php

namespace Pelican\AutoBackup\Services;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Validator;

class Settings
{
    public static function defaults(): array
    {
        return [
            'cron' => '0 4 * * *', 'timezone' => 'Europe/Berlin', 'live' => false,
            'warnings' => [], 'commands' => [], 'pre_delay' => 5,
            'shutdown_timeout' => 120, 'backup_timeout' => 7200, 'start_timeout' => 180,
            'restart_success' => true, 'restart_failure' => false,
            'retention' => 7, 'name' => 'Auto Backup {date}',
        ];
    }

    public static function validate(array $data): array
    {
        $data = array_replace(self::defaults(), $data);
        return Validator::make($data, [
            'cron' => ['required', 'string', 'max:100', function ($attribute, $value, $fail) {
                if (count(preg_split('/\s+/', trim($value))) !== 5 || !CronExpression::isValidExpression($value)) {
                    $fail('Enter a valid five-field cron expression.');
                }
            }],
            'timezone' => 'required|timezone', 'live' => 'required|boolean',
            'warnings' => 'array|max:20', 'warnings.*.seconds' => 'required|integer|min:1|max:3600',
            'warnings.*.command' => 'required|string|max:1000|not_regex:/[\r\n]/',
            'commands' => 'array|max:20', 'commands.*.command' => 'required|string|max:1000|not_regex:/[\r\n]/',
            'pre_delay' => 'required|integer|min:0|max:300',
            'shutdown_timeout' => 'required|integer|min:10|max:1800',
            'backup_timeout' => 'required|integer|min:60|max:86400',
            'start_timeout' => 'required|integer|min:10|max:1800',
            'restart_success' => 'required|boolean', 'restart_failure' => 'required|boolean',
            'retention' => 'required|integer|min:1|max:1000', 'name' => 'required|string|max:150',
        ])->validate();
    }

    public static function lead(array $settings): int
    {
        return $settings['live'] ? 0 : max([0, ...array_column($settings['warnings'], 'seconds')]);
    }

    public static function next(array $settings, CarbonImmutable $after): CarbonImmutable
    {
        return CarbonImmutable::instance((new CronExpression($settings['cron']))
            ->getNextRunDate($after, 0, false, $settings['timezone']))->utc();
    }

    public static function expand(string $value, string $server, CarbonImmutable $date, string $name = '', int $seconds = 0): string
    {
        return strtr($value, [
            '{server}' => str_replace(["\r", "\n"], ' ', $server),
            '{date}' => $date->format('Y-m-d H:i:s'), '{backup_name}' => $name, '{time}' => (string) $seconds,
        ]);
    }
}
