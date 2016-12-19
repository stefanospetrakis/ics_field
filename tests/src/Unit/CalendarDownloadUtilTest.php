<?php

namespace Drupal\Tests\px_calendar_download\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\px_calendar_download\CalendarDownloadUtil;
use Drupal\Tests\px_calendar_download\CalendarDownloadUtilTestProtected;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\px_calendar_download\CalendarDownloadUtil
 * @group px_calendar_download
 */
class CalendarDownloadUtilTest extends UnitTestCase {

  /**
   * Tests URL normalization.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::normalizeUrl
   */
  public function testNormalizeUrlEmpty() {
    $url = '';
    $this->assertNull(CalendarDownloadUtilTestProtected::normalizeUrlExposed($url), "Empty URL given, null returned");
  }

  /**
   * Tests URL normalization.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::normalizeUrl
   */
  public function testNormalizeUrlPrefixMissingProtocolTwoParts() {
    $url = 'drupal.org';
    $this->assertEquals('http://drupal.org', CalendarDownloadUtilTestProtected::normalizeUrlExposed($url),
    "Missing protocol in URL (mydomain.com) given, added protocol to URL returned");
  }

  /**
   * Tests URL normalization.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::normalizeUrl
   */
  public function testNormalizeUrlPrefixMissingProtocolThreeParts() {
    $url = 'www.drupal.org';
    $this->assertEquals('http://www.drupal.org', CalendarDownloadUtilTestProtected::normalizeUrlExposed($url),
    "Missing protocol in URL (www.mydomain.com) given, added protocol to URL returned");
  }

  /**
   * Tests URL normalization.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::normalizeUrl
   */
  public function testNormalizeUrlRelativePath() {
    $url = '/node';

    $request = Request::createFromGlobals();

    $request_stack = $this->getMock('Symfony\Component\HttpFoundation\RequestStack');
    $request_stack->expects($this->any())
      ->method('getCurrentRequest')
      ->will($this->returnValue($request));
    $container = new ContainerBuilder();
    $container->set('request_stack', $request_stack);
    \Drupal::setContainer($container);

    $expected_url = \Drupal::request()->getSchemeAndHttpHost() . '/node';
    $this->assertEquals($expected_url, CalendarDownloadUtilTestProtected::normalizeUrlExposed($url), "Relative path given, absolute returned.");
  }

  /**
   * Tests calendar parameters.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::checkParams
   * @expectedException Drupal\px_calendar_download\CalendarDownloadInvalidParametersException
   */
  public function testCheckParamsMissingAtLeastOne() {
    $cal_params = [];
    CalendarDownloadUtilTestProtected::checkParamsExposed($cal_params);
  }

  /**
   * Tests calendar parameters.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::checkParams
   */
  public function testCheckParamsHavingAll() {
    $cal_params = [
      'timezone' => '*',
      'prodid' => '*',
      'summary' => '*',
      'description' => '*',
      'dates' => ['*'],
      'uuid' => '*',
    ];
    $this->assertTrue(CalendarDownloadUtilTestProtected::checkParamsExposed($cal_params));
  }

  /**
   * Tests generated calendar.
   *
   * @covers Drupal\px_calendar_download\CalendarDownloadUtil::generateCalFileAsString
   */
  public function testCalendarGeneration() {
    $cal_params = [
      'timezone' => 'Europe/Zurich',
      'prodid' => 'my domain',
      'summary' => 'An exciting event',
      'description' => 'with a lot more information',
      'dates' => ["1970-01-01 01:00:00 Europe/Zurich", "1971-02-02 02:00:00 Europe/Zurich"],
      'uuid' => '123456789',
    ];
    $expected_str_md5 = 'c47f19b17f76bbe59e01d47b8f804165';
    // Ignore the DTSTAMP lines,they change constantly.
    $generated_str_md5 = md5(preg_replace('#^DTSTAMP.*\n#m', '', CalendarDownloadUtil::generateCalFileAsString($cal_params)));
    $this->assertEquals($expected_str_md5, $generated_str_md5);

    // Expected vcalendar string.
    // We are using on its md5 to check if it matched.
    /*
    BEGIN:VCALENDAR
    VERSION:2.0
    PRODID:my domain
    X-WR-TIMEZONE:Europe/Zurich
    X-PUBLISHED-TTL:P1W
    BEGIN:VTIMEZONE
    TZID:Europe/Zurich
    X-LIC-LOCATION:Europe/Zurich
    BEGIN:DAYLIGHT
    TZNAME:CEST
    TZOFFSETFROM:+0100
    TZOFFSETTO:+0200
    DTSTART:20160327T010000
    END:DAYLIGHT
    BEGIN:DAYLIGHT
    TZNAME:CEST
    TZOFFSETFROM:+0100
    TZOFFSETTO:+0200
    DTSTART:20170326T010000
    END:DAYLIGHT
    BEGIN:STANDARD
    TZNAME:CET
    TZOFFSETFROM:+0200
    TZOFFSETTO:+0100
    DTSTART:20161030T010000
    END:STANDARD
    END:VTIMEZONE
    BEGIN:VEVENT
    UID:e807f1fcf82d132f9bb018ca6738a19f
    DTSTART;TZID=Europe/Zurich:19700101T010000
    SEQUENCE:0
    TRANSP:OPAQUE
    SUMMARY:An exciting event
    CLASS:PUBLIC
    DESCRIPTION:with a lot more information
    X-ALT-DESC;FMTTYPE=text/html:with a lot more information
    DTSTAMP:20161214T132832Z
    END:VEVENT
    BEGIN:VEVENT
    UID:0f7e44a922df352c05c5f73cb40ba115
    DTSTART;TZID=Europe/Zurich:19710202T020000
    SEQUENCE:0
    TRANSP:OPAQUE
    SUMMARY:An exciting event
    CLASS:PUBLIC
    DESCRIPTION:with a lot more information
    X-ALT-DESC;FMTTYPE=text/html:with a lot more information
    DTSTAMP:20161214T132832Z
    END:VEVENT
    END:VCALENDAR
     */
  }

}
