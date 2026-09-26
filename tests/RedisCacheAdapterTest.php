<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Tests;

use DateInterval;
use PHP_SF\Cache\Adapter\RedisCacheAdapter;
use PHP_SF\Cache\Connection\Redis;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHPUnit\Framework\TestCase;

final class RedisCacheAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Redis::isAvailable()) {
            $this->markTestSkipped('Redis server not available');
        }
    }

    protected function tearDown(): void
    {
        if (RedisCacheAdapter::isAvailable()) {
            rca()->clear();
        }
    }


    public function testGetInstance(): void
    {
        $instance = RedisCacheAdapter::getInstance();
        $this->assertInstanceOf(RedisCacheAdapter::class, $instance);

        $instance1 = RedisCacheAdapter::getInstance();
        $instance2 = RedisCacheAdapter::getInstance();
        $this->assertSame($instance1, $instance2);
    }

    public function testGet(): void
    {
        $key   = 'test_key';
        $value = 'test_value';

        rca()->set($key, $value);

        $this->assertSame($value, rca()->get($key));
        $this->assertNull(rca()->get('non_existing_key'));
    }

    public function testSet(): void
    {
        $key   = 'key';
        $value = 'value';

        $this->assertTrue(rca()->set($key, $value));
        $this->assertEquals($value, rca()->get($key));

        $this->expectException(CacheValueException::class);
        rca()->set($key, []);
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $key   = 'ttl_key';
        $value = 'ttl_value';
        $ttl   = new DateInterval('PT1S');

        $this->assertTrue(rca()->set($key, $value, $ttl));
        $this->assertEquals($value, rca()->get($key));
        sleep(2);
        $this->assertNull(rca()->get($key));
    }

    public function testHas(): void
    {
        $this->assertFalse(rca()->has('non_existing_key'));

        rca()->set('existing_key', 'existing_value');
        $this->assertTrue(rca()->has('existing_key'));
    }

    public function testDelete(): void
    {
        $key = 'test_key';
        rca()->set($key, 'test_value');
        $this->assertTrue(rca()->has($key));

        rca()->delete($key);
        $this->assertFalse(rca()->has($key));
    }

    public function testClear(): void
    {
        $data = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        rca()->setMultiple($data);

        foreach (array_keys($data) as $key) {
            $this->assertTrue(rca()->has($key));
        }

        rca()->clear();

        foreach (array_keys($data) as $key) {
            $this->assertFalse(rca()->has($key));
        }
    }

    public function testGetMultiple(): void
    {
        $values = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        rca()->setMultiple($values);

        $result = rca()->getMultiple(array_keys($values));
        $this->assertSame($values, $result);
    }

    public function testSetMultiple(): void
    {
        $items = array_combine([ 'key1', 'key2', 'key3' ], [ 'value1', 'value2', 'value3' ]);
        rca()->setMultiple($items, new DateInterval('PT10S'));

        $this->assertSame($items, rca()->getMultiple(array_keys($items)));
    }

    public function testDeleteMultiple(): void
    {
        $keys   = [ 'key1', 'key2', 'key3' ];
        $values = [ 1, 2, 3 ];

        foreach ($keys as $i => $key) {
            rca()->set($key, $values[ $i ]);
        }

        $this->assertTrue(rca()->deleteMultiple($keys));

        foreach ($keys as $key) {
            $this->assertNull(rca()->get($key));
        }
    }

    public function testDeleteByKeyPatternSuffix(): void
    {
        rca()->set('key1', 1);
        rca()->set('key2', 2);
        rca()->set('key3', 3);

        $this->assertTrue(rca()->deleteByKeyPattern('key*'));

        $this->assertNull(rca()->get('key1'));
        $this->assertNull(rca()->get('key2'));
        $this->assertNull(rca()->get('key3'));
    }

    public function testDeleteByKeyPatternPrefix(): void
    {
        rca()->set('key1', 1);
        rca()->set('key2', 2);
        rca()->set('key3', 3);

        $this->assertTrue(rca()->deleteByKeyPattern('*y1'));
        $this->assertTrue(rca()->deleteByKeyPattern('*y2'));
        $this->assertTrue(rca()->deleteByKeyPattern('*y3'));

        $this->assertNull(rca()->get('key1'));
        $this->assertNull(rca()->get('key2'));
        $this->assertNull(rca()->get('key3'));
    }

    public function testDeleteByKeyPatternBothSides(): void
    {
        rca()->set('key1', 1);
        rca()->set('key2', 2);
        rca()->set('key3', 3);

        $this->assertTrue(rca()->deleteByKeyPattern('*ey*'));

        $this->assertNull(rca()->get('key1'));
        $this->assertNull(rca()->get('key2'));
        $this->assertNull(rca()->get('key3'));
    }

    public function testDeleteByKeyPatternNoMatch(): void
    {
        rca()->set('key1', 1);

        $this->assertTrue(rca()->deleteByKeyPattern('nomatch*'));
        $this->assertSame('1', rca()->get('key1'));
    }

    public function testDeleteByKeyPatternInvalidMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        rca()->deleteByKeyPattern('key*key');
    }

    public function testDeleteByKeyPatternInvalidLeadingAndMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        rca()->deleteByKeyPattern('*key*other');
    }

}
