<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * La periode d'une impression : ?from= et ?to= (le formulaire de la barre de la page d'impression), sinon ?period=, la
 * periode de la liste d'ou l'on vient. to = null : jusqu'a aujourd'hui ; from = null : depuis le debut de l'historique.
 */
final class PrintPeriod
{
    /**
     * @param  array<string|int, ?int>  $presets  ?period= => nombre de jours (null = tout l'historique)
     * @return array{from: ?string, to: ?string}
     */
    public static function read(Request $request, array $presets, string $default): array
    {
        $from = self::date($request->query('from'));
        $to = self::date($request->query('to'));
        if ($from !== null || $to !== null) {
            return $from !== null && $to !== null && $from > $to ? ['from' => $to, 'to' => $from] : ['from' => $from, 'to' => $to];
        }
        $key = (string) $request->query('period', $default);
        $days = $presets[array_key_exists($key, $presets) ? $key : $default];

        return ['from' => $days === null ? null : now()->subDays($days - 1)->toDateString(), 'to' => null];
    }

    /** @param array{from: ?string, to: ?string} $window */
    public static function label(array $window): string
    {
        $show = fn (string $day) => Carbon::parse($day)->format('d/m/Y');
        $today = now()->toDateString();
        $to = $window['to'] === null || $window['to'] > $today ? $today : $window['to'];

        return match (true) {
            $window['from'] === null && $window['to'] === null => __('print.period_all'),
            $window['from'] === null => __('print.period_to', ['to' => $show($to)]),
            $window['from'] === $to => __('print.period_day', ['date' => $show($to)]),
            default => __('print.period_range', ['from' => $show($window['from']), 'to' => $show($to)]),
        };
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }
}
