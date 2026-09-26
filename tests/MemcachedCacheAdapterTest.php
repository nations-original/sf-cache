<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Tests;

use DateInterval;
use PHP_SF\Cache\Adapter\MemcachedCacheAdapter;
use PHP_SF\Cache\Connection\Memcached;
use PHP_SF\Cache\Exception\CacheValueException;
use PHP_SF\Cache\Exception\UnsupportedPlatformException;
use PHPUnit\Framework\TestCase;

final class MemcachedCacheAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Memcached::isAvailable()) {
            $this->markTestSkipped('Memcached server not available');
        }
    }

    protected function tearDown(): void
    {
        if (MemcachedCacheAdapter::isAvailable()) {
            mca()->clear();
        }
    }


    public function testGetInstance(): void
    {
        $instance = MemcachedCacheAdapter::getInstance();
        $this->assertInstanceOf(MemcachedCacheAdapter::class, $instance);

        $instance1 = MemcachedCacheAdapter::getInstance();
        $instance2 = MemcachedCacheAdapter::getInstance();
        $this->assertSame($instance1, $instance2);
    }

    public function testGet(): void
    {
        $key   = 'test_key';
        $value = 'test_value';

        mca()->set($key, $value);

        $this->assertSame($value, mca()->get($key));
        $this->assertNull(mca()->get('non_existing_key'));
    }

    public function testSet(): void
    {
        $key   = 'key';
        $value = 'value';

        $this->assertTrue(mca()->set($key, $value));
        $this->assertEquals($value, mca()->get($key));

        $this->expectException(CacheValueException::class);
        mca()->set($key, []);
    }

    public function testSetWithDateIntervalTtl(): void
    {
        $key   = 'ttl_key';
        $value = 'ttl_value';
        $ttl   = new DateInterval('PT1S');

        $this->assertTrue(mca()->set($key, $value, $ttl));
        $this->assertEquals($value, mca()->get($key));
        sleep(2);
        $this->assertNull(mca()->get($key));
    }

    public function testHas(): void
    {
        $this->assertFalse(mca()->has('non_existing_key'));

        mca()->set('existing_key', 'existing_value');
        $this->assertTrue(mca()->has('existing_key'));
    }

    public function testDelete(): void
    {
        $key = 'test_key';
        mca()->set($key, 'test_value');
        $this->assertTrue(mca()->has($key));

        mca()->delete($key);
        $this->assertFalse(mca()->has($key));
    }

    public function testClear(): void
    {
        $data = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        mca()->setMultiple($data);

        foreach (array_keys($data) as $key) {
            $this->assertTrue(mca()->has($key));
        }

        mca()->clear();

        foreach (array_keys($data) as $key) {
            $this->assertFalse(mca()->has($key));
        }
    }

    public function testGetMultiple(): void
    {
        $values = [ 'key1' => 'value1', 'key2' => 'value2', 'key3' => 'value3' ];
        mca()->setMultiple($values);

        $result = mca()->getMultiple(array_keys($values));
        $this->assertSame($values, $result);
    }

    public function testSetMultiple(): void
    {
        $items = array_combine([ 'key1', 'key2', 'key3' ], [ 'value1', 'value2', 'value3' ]);
        mca()->setMultiple($items, new DateInterval('PT10S'));

        $this->assertSame($items, mca()->getMultiple(array_keys($items)));
    }

    public function testDeleteMultiple(): void
    {
        $keys   = [ 'key1', 'key2', 'key3' ];
        $values = [ 1, 2, 3 ];

        foreach ($keys as $i => $key) {
            mca()->set($key, $values[ $i ]);
        }

        $this->assertTrue(mca()->deleteMultiple($keys));

        foreach ($keys as $key) {
            $this->assertNull(mca()->get($key));
        }
    }

    public function testDeleteByKeyPatternThrows(): void
    {
        $this->expectException(UnsupportedPlatformException::class);
        mca()->deleteByKeyPattern('key*');
    }

}
