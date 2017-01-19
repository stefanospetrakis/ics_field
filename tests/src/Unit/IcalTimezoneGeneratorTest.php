<?php
/**
 * Created by PhpStorm.
 * User: twhiston
 * Date: 19.01.17
 * Time: 00:05
 */

namespace Drupal\Tests\px_calendar_download;

use Drupal\px_calendar_download\IcalTimezoneGenerator;

/**
 * @group px_calendar_download
 */
class IcalTimezoneGeneratorTest extends \PHPUnit_Framework_TestCase {

  /**
   * Test that the initial return value of the timezone is the default value
   */
  public function testGetDefaultTimezone() {
    $ic = new IcalTimezoneGenerator();
    $this->assertAttributeEquals($ic->getTimestampFormat(),
                                 'timestampFormat',
                                 $ic);
  }

  /**
   * Test that setting the timezone works
   */
  public function testGetSetTimezone() {
    $ic = new IcalTimezoneGenerator();
    $timestamp = 'Y-m-D H:i:s';
    $ic->setTimestampFormat($timestamp);
    $this->assertEquals($timestamp, $ic->getTimestampFormat());
  }

}
