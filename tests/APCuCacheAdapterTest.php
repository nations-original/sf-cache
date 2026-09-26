<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Tests;

use DateInterval;
use PHP_SF\Cache\Adapter\APCuCacheAdapter;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHPUnit\Framework\TestCase;

final class APCuCacheAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        // APCu tests are skipped in most environments because APCu is not enabled in CLI mode by default.
        if (!APCuCacheAdapter::isAvailable()) {
            $this->markTestSkipped('APCu is not enabled');
        }
    }

    protected function tearDown(): void
    {
        if (APCuCacheAdapter::isAvailable()) {
            aca()->clear();
        }
    }


    public function testGetInstance(): void
    {
        $instance = APCuCacheAdapter::getInstance();
        $this->assertInstanceOf(APCuCacheAdapter::class, $instance);

        $instance1 = APCuCacheAdapter::getInstance();
        $instance2 = APCuCacheAdapter::getInstance();
        $this->assertSame($instance1, $instance2);
    }

    public function testGet(): void
    {
        $key   = 'test_key';
        $value = 'test_value';

        aca()->set($key, $value);

        $this->assertSame($value, aca()->get($key));
        $this->assertNull(aca()->get('non_existing_key'));
    }

    public function testSet(): void
    {
        $key   = 'key';
        $value = 'value';

        $this->assertTrue(aca()->set($key, $value));
        $this->assertEquals($value, aca()->get($key));

        $this->expectException(CacheValueException::class);
        aca()->set($key, []);
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $key   = 'ttl_key';
        $value = 'ttl_value';
        $ttl   = new DateInterval('PT1S');

        $this->assertTrue(aca()->set($key, $value, $ttl));
        $this->assertEquals($value, aca()->get($key));
        sleep(2);
        $this->assertNull(aca()->get($key));
    }

    public function testHas(): void
    {
        $this->assertFalse(aca()->has('non_existing_key'));

        aca()->set('existing_key', 'existing_value');
        $this->assertTrue(aca()->has('existing_key'));
    }

    public function testDelete(): void
    {
        $key = 'test_key';
        aca()->set($key, 'test_value');
        $this->assertTrue(aca()->has($key));

        aca()->delete($key);
        $this->assertFalse(aca()->has($key));
    }

    public function testClear(): void
    {
        $data = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        aca()->setMultiple($data);

        foreach (array_keys($data) as $key) {
            $this->assertTrue(aca()->has($key));
        }

        aca()->clear();

        foreach (array_keys($data) as $key) {
            $this->assertFalse(aca()->has($key));
        }
    }

    public function testGetMultiple(): void
    {
        $values = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        aca()->setMultiple($values);

        $result = aca()->getMultiple(array_keys($values));
        $this->assertSame($values, $result);
    }

    public function testSetMultiple(): void
    {
        $items = array_combine([ 'key1', 'key2', 'key3' ], [ 'value1', 'value2', 'value3' ]);
        aca()->setMultiple($items, new DateInterval('PT10S'));

        $this->assertSame($items, aca()->getMultiple(array_keys($items)));
    }

    public function testDeleteMultiple(): void
    {
        $keys   = [ 'key1', 'key2', 'key3' ];
        $values = [ 1, 2, 3 ];

        foreach ($keys as $i => $key) {
            aca()->set($key, $values[ $i ]);
        }

        $this->assertTrue(aca()->deleteMultiple($keys));

        foreach ($keys as $key) {
            $this->assertNull(aca()->get($key));
        }
    }

    public function testDeleteByKeyPatternSuffix(): void
    {
        $keys   = [ 'key1', 'key2', 'key3' ];
        $values = [ 1, 2, 3 ];

        foreach ($keys as $i => $key) {
            aca()->set($key, $values[ $i ]);
        }

        $this->assertTrue(aca()->deleteByKeyPattern('key*'));

        foreach ($keys as $key) {
            $this->assertNull(aca()->get($key));
        }
    }

    public function testDeleteByKeyPatternPrefix(): void
    {
        aca()->set('key1', 1);
        aca()->set('key2', 2);
        aca()->set('key3', 3);

        $this->assertTrue(aca()->deleteByKeyPattern('*y1'));
        $this->assertTrue(aca()->deleteByKeyPattern('*y2'));
        $this->assertTrue(aca()->deleteByKeyPattern('*y3'));

        $this->assertNull(aca()->get('key1'));
        $this->assertNull(aca()->get('key2'));
        $this->assertNull(aca()->get('key3'));
    }

    public function testDeleteByKeyPatternBothSides(): void
    {
        aca()->set('key1', 1);
        aca()->set('key2', 2);
        aca()->set('key3', 3);

        $this->assertTrue(aca()->deleteByKeyPattern('*ey*'));

        $this->assertNull(aca()->get('key1'));
        $this->assertNull(aca()->get('key2'));
        $this->assertNull(aca()->get('key3'));
    }

    public function testDeleteByKeyPatternStar(): void
    {
        aca()->set('key1', 1);
        aca()->set('key2', 2);

        $this->assertTrue(aca()->deleteByKeyPattern('*'));

        $this->assertNull(aca()->get('key1'));
        $this->assertNull(aca()->get('key2'));
    }

    public function testDeleteByKeyPatternNoMatch(): void
    {
        aca()->set('key1', 1);

        $this->assertTrue(aca()->deleteByKeyPattern('nomatch*'));
        $this->assertSame(1, aca()->get('key1'));
    }

    public function testDeleteByKeyPatternInvalidMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        aca()->deleteByKeyPattern('key*key');
    }

    public function testDeleteByKeyPatternInvalidLeadingAndMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        aca()->deleteByKeyPattern('*key*other');
    }

}
