<?php

declare(strict_types=1);

namespace Pharaonic\Laravel\ApiVersioning\Tests\Unit;

use InvalidArgumentException;
use Pharaonic\Laravel\ApiVersioning\Version;
use Pharaonic\Laravel\ApiVersioning\VersionRegistry;
use PHPUnit\Framework\TestCase;

final class VersionRegistryTest extends TestCase
{
    public function test_it_keeps_the_declared_order_and_compares_by_it(): void
    {
        $registry = new VersionRegistry(['2023-10', 'v10', 'v2'], 'v10');

        $this->assertSame(['2023-10', 'v10', 'v2'], $registry->all());
        $this->assertTrue($registry->has('v10'));
        $this->assertFalse($registry->has('V10'));
        $this->assertSame(-1, $registry->compare('2023-10', 'v2'));
        $this->assertSame(1, $registry->compare('v2', 'v10'));
        $this->assertSame(0, $registry->compare($registry->get('v2'), 'v2'));
        $this->assertSame('v10', $registry->default()?->name());
        $this->assertSame($registry->get('v2'), $registry->get('v2'));
        $this->assertNull((new VersionRegistry(['v1']))->default());
    }

    public function test_versions_compare_with_names_and_objects(): void
    {
        $registry = new VersionRegistry(['v1', 'v2', 'v3']);
        $v2 = $registry->get('v2');

        $this->assertInstanceOf(Version::class, $v2);
        $this->assertSame('v2', $v2->name());
        $this->assertSame('v2', (string) $v2);
        $this->assertTrue($v2->is('v2'));
        $this->assertFalse($v2->is($registry->get('v3')));
        $this->assertTrue($v2->atLeast('v2'));
        $this->assertTrue($v2->atLeast('v1'));
        $this->assertFalse($v2->atLeast('v3'));
        $this->assertTrue($v2->atMost('v2'));
        $this->assertFalse($v2->atMost('v1'));
        $this->assertTrue($v2->after('v1'));
        $this->assertFalse($v2->after('v2'));
        $this->assertTrue($v2->before('v3'));
        $this->assertFalse($v2->before('v2'));
    }

    public function test_comparing_with_an_unknown_version_fails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown API version [v9]. Declared versions: [v1, v2].');

        (new VersionRegistry(['v1', 'v2']))->get('v1')->atLeast('v9');
    }

    public function test_invalid_declarations_fail(): void
    {
        $cases = [
            'empty' => [[]],
            'duplicate' => [['v1', 'v1']],
            'not a string' => [['v1', 2]],
            'slash' => [['v1/beta']],
            'space' => [['v 1']],
            'leading dot' => [['.v1']],
            'unknown default' => [['v1'], 'v2'],
            'unknown lifecycle' => [['v1'], null, ['v2' => ['status' => 'active']]],
            'bad status' => [['v1'], null, ['v1' => ['status' => 'sunset']]],
            'deprecated without date' => [['v1'], null, ['v1' => ['status' => 'deprecated']]],
            'sunset without date' => [['v1'], null, ['v1' => ['status' => 'sunset-announced']]],
            'bad date' => [['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => 'someday']]],
            'not an entry' => [['v1'], null, ['v1' => 'deprecated']],
            'bad link' => [['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-01-01', 'link' => 'not a url']]],
            'header injection' => [['v1'], null, ['v1' => ['status' => 'deprecated', 'deprecated_at' => '2026-01-01', 'link' => "https://example.com/\r\nX: y"]]],
        ];

        foreach ($cases as $case => $arguments) {
            try {
                new VersionRegistry(...$arguments);
                $this->fail('Accepted '.$case);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_lifecycle_headers_follow_rfc_9745_and_rfc_8594(): void
    {
        $registry = new VersionRegistry(['v1', 'v2', 'v3', 'v4'], null, [
            'v1' => ['status' => 'retired', 'deprecated_at' => '2024-01-01', 'sunset_at' => '2025-01-01'],
            'v2' => ['status' => 'deprecated', 'deprecated_at' => '2026-06-01T00:00:00+02:00', 'link' => 'https://example.com/v3'],
            'v3' => ['status' => 'sunset-announced', 'sunset_at' => '2027-01-01'],
        ]);

        $this->assertSame(['Sunset' => 'Wed, 01 Jan 2025 00:00:00 GMT'], $registry->lifecycleHeaders('v1'));
        $this->assertSame(['Deprecation' => '@'.(gmmktime(0, 0, 0, 6, 1, 2026) - 7200), 'Link' => '<https://example.com/v3>; rel="deprecation"'], $registry->lifecycleHeaders('v2'));
        $this->assertSame(['Sunset' => 'Fri, 01 Jan 2027 00:00:00 GMT'], $registry->lifecycleHeaders('v3'));
        $this->assertSame([], $registry->lifecycleHeaders('v4'));
        $this->assertSame(VersionRegistry::RETIRED, $registry->status('v1'));
        $this->assertSame(VersionRegistry::ACTIVE, $registry->status('v4'));
    }
}
