<?php

use MrNewport\LaravelNestedTerms\Support\ExactDecimal;

it('normalizes decimal strings exactly at the requested scale', function ($input, $scale, $expected) {
    expect(ExactDecimal::normalize($input, $scale))->toBe($expected);
})->with([
    [0, 0, '0'], [0, 6, '0.000000'], [-12, 2, '-12.00'], ['-0.000000', 0, '0'],
    ['-0.00', 2, '0.00'], ['1.500', 2, '1.50'], ['12.000', 0, '12'], ['0.000001', 6, '0.000001'],
    ['99999999999999999999.123456', 6, '99999999999999999999.123456'],
    ['100000000000000001', 1, '100000000000000001.0'],
]);

it('rejects invalid decimal commands without coercion or rounding', function ($input, $scale) {
    expect(fn () => ExactDecimal::normalize($input, $scale))->toThrow(InvalidArgumentException::class);
})->with([
    [null, 2], [false, 2], [true, 2], [1.25, 2], [1.0, 0], [INF, 2], [NAN, 2], [[], 2], [new stdClass, 2],
    ['', 2], [' 1', 2], ['1 ', 2], ["1\n", 2], ['1e2', 2], ['+1', 2], ['01.5', 2], ['.5', 2], ['1.', 2],
    ['1,25', 2], ['1_000', 2], ['１.２', 2], ['1.001', 2], ['-0.000001', 5], ['1', -1], ['1', 7],
]);

it('bounds both input bytes and the fixed scale coefficient precision', function () {
    expect(ExactDecimal::normalize(str_repeat('9', 100), 0))->toBe(str_repeat('9', 100));
    expect(fn () => ExactDecimal::normalize(str_repeat('9', 101), 0))->toThrow(InvalidArgumentException::class);
    expect(fn () => ExactDecimal::normalize(str_repeat('9', 95), 6))->toThrow(InvalidArgumentException::class);
    expect(ExactDecimal::normalize(str_repeat('9', 94), 6))->toBe(str_repeat('9', 94).'.000000');
    expect(ExactDecimal::normalize('1.'.str_repeat('0', 126), 0))->toBe('1');
    expect(fn () => ExactDecimal::normalize('1.'.str_repeat('0', 127), 0))->toThrow(InvalidArgumentException::class);
});
