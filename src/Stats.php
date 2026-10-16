<?php

declare(strict_types=1);

namespace Phplyr;

/**
 * Descriptive statistics over plain lists. Uses the php_stats extension when
 * it is loaded and falls back to pure PHP otherwise. Results match R:
 * sample variance (n - 1) and type 7 quantiles.
 *
 * Missing values (null, NAN) follow R's na.rm: by default any missing value
 * makes the result null; with $naRm they are dropped first.
 */
final class Stats
{
    /** Set to false to force the pure-PHP path, for example in tests. */
    public static bool $useExtension = true;

    public static function extensionLoaded(): bool
    {
        return self::$useExtension && \extension_loaded('php_stats');
    }

    /** @param list<mixed> $values */
    public static function sum(array $values, bool $naRm = false): int|float|null
    {
        $values = self::clean($values, $naRm);

        return $values === null ? null : array_sum($values);
    }

    /** @param list<mixed> $values */
    public static function mean(array $values, bool $naRm = false): ?float
    {
        $values = self::clean($values, $naRm);
        if ($values === null || $values === []) {
            return null;
        }

        return self::extensionLoaded()
            ? \stats_mean($values)
            : array_sum($values) / \count($values);
    }

    /** @param list<mixed> $values */
    public static function var(array $values, bool $naRm = false): ?float
    {
        $values = self::clean($values, $naRm);
        if ($values === null || \count($values) < 2) {
            return null;
        }
        if (self::extensionLoaded()) {
            return \stats_var($values);
        }

        // Two-pass for accuracy.
        $mean = array_sum($values) / \count($values);
        $ss = 0.0;
        foreach ($values as $v) {
            $ss += ($v - $mean) ** 2;
        }

        return $ss / (\count($values) - 1);
    }

    /** @param list<mixed> $values */
    public static function sd(array $values, bool $naRm = false): ?float
    {
        if (self::extensionLoaded()) {
            $clean = self::clean($values, $naRm);

            return $clean === null || \count($clean) < 2 ? null : \stats_sd($clean);
        }
        $var = self::var($values, $naRm);

        return $var === null ? null : sqrt($var);
    }

    /** @param list<mixed> $values */
    public static function median(array $values, bool $naRm = false): ?float
    {
        $values = self::clean($values, $naRm);
        if ($values === null || $values === []) {
            return null;
        }

        return self::extensionLoaded() ? \stats_median($values) : self::type7($values, 0.5);
    }

    /** @param list<mixed> $values */
    public static function quantile(array $values, float $p, bool $naRm = false): ?float
    {
        if ($p < 0.0 || $p > 1.0) {
            throw new \InvalidArgumentException("Quantile probability must be in [0, 1], got {$p}.");
        }
        $values = self::clean($values, $naRm);
        if ($values === null || $values === []) {
            return null;
        }

        return self::extensionLoaded() ? \stats_quantile($values, $p) : self::type7($values, $p);
    }

    /** @param list<mixed> $values */
    public static function min(array $values, bool $naRm = false): mixed
    {
        $values = self::present($values, $naRm);

        return $values === null || $values === [] ? null : \min($values);
    }

    /** @param list<mixed> $values */
    public static function max(array $values, bool $naRm = false): mixed
    {
        $values = self::present($values, $naRm);

        return $values === null || $values === [] ? null : \max($values);
    }

    /**
     * Drop or detect missing values and check the rest are numeric.
     *
     * @param list<mixed> $values
     * @return list<int|float>|null Null when a value is missing and $naRm is false.
     */
    private static function clean(array $values, bool $naRm): ?array
    {
        $values = self::present($values, $naRm);
        if ($values === null) {
            return null;
        }
        foreach ($values as $v) {
            if (! \is_int($v) && ! \is_float($v) && ! \is_bool($v)) {
                throw new \InvalidArgumentException('Expected numeric values, got '.get_debug_type($v).'.');
            }
        }

        return array_map(fn ($v) => \is_bool($v) ? (int) $v : $v, $values);
    }

    /**
     * @param list<mixed> $values
     * @return list<mixed>|null
     */
    private static function present(array $values, bool $naRm): ?array
    {
        $out = [];
        foreach ($values as $v) {
            if ($v === null || (\is_float($v) && is_nan($v))) {
                if (! $naRm) {
                    return null;
                }

                continue;
            }
            $out[] = $v;
        }

        return $out;
    }

    /** @param non-empty-list<int|float> $values */
    private static function type7(array $values, float $p): float
    {
        sort($values);
        $h = (\count($values) - 1) * $p;
        $lo = (int) floor($h);
        $hi = (int) ceil($h);

        return $values[$lo] + ($h - $lo) * ($values[$hi] - $values[$lo]);
    }
}
