<?php

declare(strict_types=1);

use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;

test('it keeps the declared order and compares by it', function () {
    $registry = new VersionRegistry(['2023-10', 'v10', 'v2'], 'v10');

    expect($registry->all())->toBe(['2023-10', 'v10', 'v2'])
        ->and($registry->has('v10'))->toBeTrue()
        ->and($registry->has('V10'))->toBeFalse()
        ->and($registry->compare('2023-10', 'v2'))->toBe(-1)
        ->and($registry->compare('v2', 'v10'))->toBe(1)
        ->and($registry->compare($registry->get('v2'), 'v2'))->toBe(0)
        ->and($registry->default()?->name())->toBe('v10')
        ->and($registry->get('v2'))->toBe($registry->get('v2'))
        ->and((new VersionRegistry(['v1']))->default())->toBeNull();
});

test('versions compare with names and objects', function () {
    $registry = new VersionRegistry(['v1', 'v2', 'v3']);
    $v2 = $registry->get('v2');

    expect($v2)->toBeInstanceOf(Version::class)
        ->and($v2->name())->toBe('v2')
        ->and((string) $v2)->toBe('v2')
        ->and($v2->is('v2'))->toBeTrue()
        ->and($v2->is($registry->get('v3')))->toBeFalse()
        ->and($v2->atLeast('v2'))->toBeTrue()
        ->and($v2->atLeast('v1'))->toBeTrue()
        ->and($v2->atLeast('v3'))->toBeFalse()
        ->and($v2->atMost('v2'))->toBeTrue()
        ->and($v2->atMost('v1'))->toBeFalse()
        ->and($v2->after('v1'))->toBeTrue()
        ->and($v2->after('v2'))->toBeFalse()
        ->and($v2->before('v3'))->toBeTrue()
        ->and($v2->before('v2'))->toBeFalse();
});

test('comparing with an unknown version fails', function () {
    (new VersionRegistry(['v1', 'v2']))->get('v1')->atLeast('v9');
})->throws(InvalidArgumentException::class, 'Unknown API version [v9]. Declared versions: [v1, v2].');

test('invalid declarations fail', function (array $arguments) {
    expect(fn () => new VersionRegistry(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [[[]]],
    'duplicate' => [[['v1', 'v1']]],
    'not a string' => [[['v1', 2]]],
    'slash' => [[['v1/beta']]],
    'space' => [[['v 1']]],
    'leading dot' => [[['.v1']]],
    'unknown default' => [[['v1'], 'v2']],
    'unknown lifecycle' => [[['v1'], null, ['v2' => ['status' => 'active']]]],
    'bad status' => [[['v1'], null, ['v1' => ['status' => 'sunset']]]],
    'deprecated without date' => [[['v1'], null, ['v1' => ['status' => 'deprecated']]]],
    'sunset without date' => [[['v1'], null, ['v1' => ['status' => 'sunset-announced']]]],
    'bad date' => [[['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => 'someday']]]],
    'not an entry' => [[['v1'], null, ['v1' => 'deprecated']]],
    'bad link' => [[['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-01-01', 'link' => 'not a url']]]],
    'header injection' => [[['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-01-01', 'link' => "https://example.com/\r\nX: y"]]]],
]);

test('lifecycle headers follow rfc 9745 and rfc 8594', function () {
    $registry = new VersionRegistry(['v1', 'v2', 'v3', 'v4'], null, [
        'v1' => ['status' => 'retired', 'deprecated_at' => '2024-01-01', 'sunset_at' => '2025-01-01'],
        'v2' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01T00:00:00+02:00', 'link' => 'https://example.com/v3'],
        'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2027-01-01'],
    ]);

    expect($registry->lifecycleHeaders('v1'))->toBe(['Sunset' => 'Wed, 01 Jan 2025 00:00:00 GMT'])
        ->and($registry->lifecycleHeaders('v2'))->toBe(['Deprecation' => '@'.(gmmktime(0, 0, 0, 6, 1, 2026) - 7200), 'Link' => '<https://example.com/v3>; rel="deprecation"'])
        ->and($registry->lifecycleHeaders('v3'))->toBe(['Sunset' => 'Fri, 01 Jan 2027 00:00:00 GMT'])
        ->and($registry->lifecycleHeaders('v4'))->toBe([])
        ->and($registry->status('v1'))->toBe(VersionRegistry::RETIRED)
        ->and($registry->status('v4'))->toBe(VersionRegistry::ACTIVE);
});
