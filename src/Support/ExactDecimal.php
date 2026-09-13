<?php

namespace MrNewport\LaravelNestedTerms\Support;

use InvalidArgumentException;

final class ExactDecimal
{
    public const MAX_INPUT_LENGTH = 128;

    public const MAX_DIGITS = 100;

    /** Return an exact fixed-scale string without using binary floats or rounding. */
    public static function normalize(mixed $value, int $scale): string
    {
        if ($scale < 0 || $scale > 6) {
            throw new InvalidArgumentException('Decimal scale must be between zero and six.');
        }
        if (! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException('Exact decimal values must be strings or integers.');
        }
        $value = (string) $value;
        if (strlen($value) > self::MAX_INPUT_LENGTH) {
            throw new InvalidArgumentException('The decimal input length limit was exceeded.');
        }
        if (! preg_match('/\A(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?\z/', $value, $parts)) {
            throw new InvalidArgumentException('Use a plain decimal without spaces, exponent notation or a plus sign.');
        }
        $fraction = $parts[3] ?? '';
        if (strlen($fraction) > $scale && trim(substr($fraction, $scale), '0') !== '') {
            throw new InvalidArgumentException('The decimal value cannot be represented at this scale without rounding.');
        }
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $coefficient = ltrim($parts[2].$fraction, '0');
        if (strlen($coefficient) > self::MAX_DIGITS) {
            throw new InvalidArgumentException('The decimal precision limit was exceeded.');
        }
        $sign = $coefficient === '' ? '' : $parts[1];

        return $sign.$parts[2].($scale === 0 ? '' : '.'.$fraction);
    }
}
