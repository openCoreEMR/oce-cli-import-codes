<?php

/**
 * GlobalsAccessorTest.php
 * Unit tests for GlobalsAccessor
 *
 * @package   OpenCoreEMR\CLI\ImportCodes\Tests
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenCoreEMR\CLI\ImportCodes\Tests\Unit\Config;

use OpenCoreEMR\CLI\ImportCodes\Config\ConfigAccessorInterface;
use OpenCoreEMR\CLI\ImportCodes\Config\GlobalsAccessor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class GlobalsAccessorTest extends TestCase
{
    private GlobalsAccessor $accessor;

    protected function setUp(): void
    {
        $this->accessor = new GlobalsAccessor();
        // Clear test globals before each test
        unset($GLOBALS['test_string'], $GLOBALS['test_int'], $GLOBALS['test_bool'], $GLOBALS['test_array']);
    }

    protected function tearDown(): void
    {
        // Clean up globals after each test
        unset($GLOBALS['test_string'], $GLOBALS['test_int'], $GLOBALS['test_bool'], $GLOBALS['test_array']);
    }

    #[Test]
    public function implementsConfigAccessorInterface(): void
    {
        $this->assertInstanceOf(ConfigAccessorInterface::class, $this->accessor);
    }

    #[Test]
    public function getReturnsValueWhenKeyExists(): void
    {
        $GLOBALS['test_string'] = 'hello';

        $this->assertEquals('hello', $this->accessor->get('test_string'));
    }

    #[Test]
    public function getReturnsDefaultWhenKeyNotExists(): void
    {
        $this->assertEquals('default', $this->accessor->get('nonexistent', 'default'));
    }

    #[Test]
    public function getReturnsNullWhenKeyNotExistsAndNoDefault(): void
    {
        $this->assertNull($this->accessor->get('nonexistent'));
    }

    #[Test]
    public function hasReturnsTrueWhenKeyExists(): void
    {
        $GLOBALS['test_string'] = 'hello';

        $this->assertTrue($this->accessor->has('test_string'));
    }

    #[Test]
    public function hasReturnsFalseWhenKeyNotExists(): void
    {
        $this->assertFalse($this->accessor->has('nonexistent'));
    }

    #[Test]
    public function getStringReturnsStringValue(): void
    {
        $GLOBALS['test_string'] = 'hello';

        $this->assertEquals('hello', $this->accessor->getString('test_string'));
    }

    #[Test]
    public function getStringConvertsIntToString(): void
    {
        $GLOBALS['test_int'] = 42;

        $this->assertEquals('42', $this->accessor->getString('test_int'));
    }

    #[Test]
    public function getStringReturnsDefaultForNonScalar(): void
    {
        $GLOBALS['test_array'] = ['foo' => 'bar'];

        $this->assertEquals('default', $this->accessor->getString('test_array', 'default'));
    }

    #[Test]
    public function getStringReturnsDefaultWhenKeyNotExists(): void
    {
        $this->assertEquals('default', $this->accessor->getString('nonexistent', 'default'));
    }

    #[Test]
    public function getBooleanReturnsBoolValue(): void
    {
        $GLOBALS['test_bool'] = true;

        $this->assertTrue($this->accessor->getBoolean('test_bool'));
    }

    #[Test]
    public function getBooleanConvertsStringTrue(): void
    {
        $GLOBALS['test_string'] = 'true';

        $this->assertTrue($this->accessor->getBoolean('test_string'));
    }

    #[Test]
    public function getBooleanConvertsStringFalse(): void
    {
        $GLOBALS['test_string'] = 'false';

        $this->assertFalse($this->accessor->getBoolean('test_string'));
    }

    #[Test]
    public function getBooleanConvertsNumericOne(): void
    {
        $GLOBALS['test_int'] = 1;

        $this->assertTrue($this->accessor->getBoolean('test_int'));
    }

    #[Test]
    public function getBooleanConvertsNumericZero(): void
    {
        $GLOBALS['test_int'] = 0;

        $this->assertFalse($this->accessor->getBoolean('test_int'));
    }

    #[Test]
    public function getBooleanReturnsDefaultWhenKeyNotExists(): void
    {
        $this->assertTrue($this->accessor->getBoolean('nonexistent', true));
        $this->assertFalse($this->accessor->getBoolean('nonexistent', false));
    }

    #[Test]
    public function getIntReturnsIntValue(): void
    {
        $GLOBALS['test_int'] = 42;

        $this->assertEquals(42, $this->accessor->getInt('test_int'));
    }

    #[Test]
    public function getIntConvertsNumericString(): void
    {
        $GLOBALS['test_string'] = '123';

        $this->assertEquals(123, $this->accessor->getInt('test_string'));
    }

    #[Test]
    public function getIntReturnsDefaultForNonNumeric(): void
    {
        $GLOBALS['test_string'] = 'not a number';

        $this->assertEquals(99, $this->accessor->getInt('test_string', 99));
    }

    #[Test]
    public function getIntReturnsDefaultWhenKeyNotExists(): void
    {
        $this->assertEquals(99, $this->accessor->getInt('nonexistent', 99));
    }

    #[Test]
    public function allReturnsGlobalsArray(): void
    {
        $GLOBALS['test_string'] = 'hello';

        $all = $this->accessor->all();

        $this->assertIsArray($all);
        $this->assertArrayHasKey('test_string', $all);
        $this->assertEquals('hello', $all['test_string']);
    }
}
