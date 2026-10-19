<?php

namespace Pelican\AutoBackup\Services;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** UI choices only; persisted schedules remain compatible with existing cron settings. */
class ScheduleInput
{
    public static function fromCron(string $cron): array
    {
        $input = ['schedule_type' => 'custom', 'schedule_time' => '04:00', 'schedule_day' => '1',
            'schedule_interval' => '6', 'schedule_minute' => '0', 'cron' => $cron];
        $fields = preg_split('/\s+/', trim($cron));
        if (count($fields) !== 5) { return $input; }
        [$minute, $hour, $day, $month, $weekday] = $fields;
        if (!ctype_digit($minute) || (int) $minute > 59 || $day !== '*' || $month !== '*') { return $input; }
        if (ctype_digit($hour) && (int) $hour <= 23 && ($weekday === '*' || preg_match('/^[0-6]$/', $weekday))) {
            return array_replace($input, ['schedule_type' => $weekday === '*' ? 'daily' : 'weekly',
                'schedule_time' => sprintf('%02d:%02d', $hour, $minute), 'schedule_day' => $weekday === '*' ? '1' : $weekday]);
        }
        if ($weekday === '*' && ($hour === '*' || preg_match('~^\*/(1|2|3|4|6|8|12)$~', $hour, $match))) {
            return array_replace($input, ['schedule_type' => 'interval',
                'schedule_interval' => $hour === '*' ? '1' : $match[1], 'schedule_minute' => (string) (int) $minute]);
        }
        return $input;
    }

    public static function toCron(array $input): string
    {
        $type = Validator::make($input, ['schedule_type' => ['required', Rule::in(['daily', 'weekly', 'interval', 'custom'])]])
            ->validate()['schedule_type'];
        $rules = match ($type) {
            'daily' => ['schedule_time' => 'required|date_format:H:i'],
            'weekly' => ['schedule_time' => 'required|date_format:H:i', 'schedule_day' => 'required|integer|min:0|max:6'],
            'interval' => ['schedule_interval' => ['required', Rule::in([1, 2, 3, 4, 6, 8, 12])], 'schedule_minute' => 'required|integer|min:0|max:59'],
            'custom' => ['cron' => 'required|string|max:100'],
        };
        $data = Validator::make($input, $rules)->validate();
        if ($type === 'custom') { return $data['cron']; }
        if ($type === 'interval') {
            $hours = (int) $data['schedule_interval'] === 1 ? '*' : '*/' . (int) $data['schedule_interval'];
            return (int) $data['schedule_minute'] . " $hours * * *";
        }
        [$hour, $minute] = array_map('intval', explode(':', $data['schedule_time']));
        return "$minute $hour * * " . ($type === 'weekly' ? (int) $data['schedule_day'] : '*');
    }
}
