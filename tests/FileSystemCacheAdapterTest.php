<?php

declare(strict_types=1);

namespace PHP_SF\Cache\Tests;

use DateInterval;
use PHP_SF\Cache\Adapter\FileSystemCacheAdapter;
use PHP_SF\Cache\Exception\InvalidCacheKeyException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FileSystemCacheAdapterTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php_sf_cache_test_' . uniqid();
        FileSystemCacheAdapter::configure(new Filesystem(), $this->cacheDir);
    }

    protected function tearDown(): void
    {
        fca()->clear();
        (new Filesystem())->remove($this->cacheDir);
    }


    public function testGetInstance(): void
    {
        $instance = FileSystemCacheAdapter::getInstance();
        $this->assertInstanceOf(FileSystemCacheAdapter::class, $instance);

        $instance1 = FileSystemCacheAdapter::getInstance();
        $instance2 = FileSystemCacheAdapter::getInstance();
        $this->assertSame($instance1, $instance2);
    }

    public function testDiConstructedInstanceBecomesTheSingleton(): void
    {
        $filesystem = new Filesystem();
        $adapter    = new FileSystemCacheAdapter($filesystem, $this->cacheDir);

        $this->assertSame($adapter, FileSystemCacheAdapter::getInstance());
        $this->assertSame($adapter, fca());
    }

    public function testGet(): void
    {
        fca()->set('key', 'value');

        $this->assertSame('value', fca()->get('key'));
        $this->assertNull(fca()->get('missing'));
        $this->assertSame('default', fca()->get('missing', 'default'));
    }

    public function testSetScalar(): void
    {
        $this->assertTrue(fca()->set('str', 'hello'));
        $this->assertTrue(fca()->set('int', 42));
        $this->assertTrue(fca()->set('float', 3.14));
        $this->assertTrue(fca()->set('bool', true));

        $this->assertSame('hello', fca()->get('str'));
        $this->assertSame(42, fca()->get('int'));
        $this->assertSame(3.14, fca()->get('float'));
        $this->assertSame(true, fca()->get('bool'));
    }

    public function testSetArray(): void
    {
        $value = [ 'a' => 1, 'b' => [ 2, 3 ] ];
        $this->assertTrue(fca()->set('arr', $value));
        $this->assertSame($value, fca()->get('arr'));
    }

    public function testSetObject(): void
    {
        $value = (object)[ 'x' => 42 ];
        $this->assertTrue(fca()->set('obj', $value));
        $this->assertEquals($value, fca()->get('obj'));
    }

    public function testSetWithDateIntervalTtl(): void
    {
        fca()->set('ttl_key', 'ttl_value', new DateInterval('PT1S'));
        $this->assertSame('ttl_value', fca()->get('ttl_key'));
        sleep(2);
        $this->assertNull(fca()->get('ttl_key'));
    }

    public function testSetWithNullTtlNeverExpires(): void
    {
        fca()->set('forever', 'value', null);
        $this->assertTrue(fca()->has('forever'));
    }

    public function testHas(): void
    {
        $this->assertFalse(fca()->has('missing'));
        fca()->set('present', 'yes');
        $this->assertTrue(fca()->has('present'));
    }

    public function testDelete(): void
    {
        fca()->set('key', 'value');
        $this->assertTrue(fca()->has('key'));

        $this->assertTrue(fca()->delete('key'));
        $this->assertFalse(fca()->has('key'));

        $this->assertFalse(fca()->delete('never_existed'));
    }

    public function testClear(): void
    {
        fca()->set('key1', 'a');
        fca()->set('key2', 'b');

        $this->assertTrue(fca()->clear());
        $this->assertFalse(fca()->has('key1'));
        $this->assertFalse(fca()->has('key2'));
    }

    public function testGetMultiple(): void
    {
        $values = [ 'k1' => 'v1', 'k2' => 'v2', 'k3' => 'v3' ];
        fca()->setMultiple($values);

        $result = fca()->getMultiple(array_keys($values));
        $this->assertSame($values, $result);
    }

    public function testSetMultiple(): void
    {
        $items = [ 'k1' => 'v1', 'k2' => [ 1, 2 ], 'k3' => (object)[ 'n' => 3 ] ];
        $this->assertTrue(fca()->setMultiple($items));

        $this->assertSame('v1', fca()->get('k1'));
        $this->assertSame([ 1, 2 ], fca()->get('k2'));
        $this->assertEquals((object)[ 'n' => 3 ], fca()->get('k3'));
    }

    public function testDeleteMultiple(): void
    {
        $keys = [ 'key1', 'key2', 'key3' ];
        foreach ($keys as $k) {
            fca()->set($k, $k);
        }

        $this->assertTrue(fca()->deleteMultiple($keys));

        foreach ($keys as $k) {
            $this->assertNull(fca()->get($k));
        }
    }

    public function testDeleteByKeyPatternSuffix(): void
    {
        fca()->set('user_1', 'a');
        fca()->set('user_2', 'b');
        fca()->set('post_1', 'c');

        fca()->deleteByKeyPattern('user_*');

        $this->assertNull(fca()->get('user_1'));
        $this->assertNull(fca()->get('user_2'));
        $this->assertSame('c', fca()->get('post_1'));
    }

    public function testDeleteByKeyPatternPrefix(): void
    {
        fca()->set('cache_user', 'a');
        fca()->set('cache_post', 'b');
        fca()->set('other', 'c');

        fca()->deleteByKeyPattern('*_user');

        $this->assertNull(fca()->get('cache_user'));
        $this->assertSame('b', fca()->get('cache_post'));
        $this->assertSame('c', fca()->get('other'));
    }

    public function testDeleteByKeyPatternBothSides(): void
    {
        fca()->set('prefix_user_suffix', 'a');
        fca()->set('other', 'b');

        fca()->deleteByKeyPattern('*_user_*');

        $this->assertNull(fca()->get('prefix_user_suffix'));
        $this->assertSame('b', fca()->get('other'));
    }

    public function testDeleteByKeyPatternStar(): void
    {
        fca()->set('a', 1);
        fca()->set('b', 2);

        fca()->deleteByKeyPattern('*');

        $this->assertFalse(fca()->has('a'));
        $this->assertFalse(fca()->has('b'));
    }

    public function testDeleteByKeyPatternNoMatch(): void
    {
        fca()->set('key1', 'a');

        $this->assertTrue(fca()->deleteByKeyPattern('nomatch*'));
        $this->assertSame('a', fca()->get('key1'));
    }

    public function testDeleteByKeyPatternInvalidMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        fca()->deleteByKeyPattern('a*b');
    }

    public function testDeleteByKeyPatternInvalidLeadingAndMiddleWildcard(): void
    {
        $this->expectException(InvalidCacheKeyException::class);
        fca()->deleteByKeyPattern('*a*b');
    }

}
