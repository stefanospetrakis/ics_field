<?php

namespace Drupal\Tests\px_calendar_download\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\NodeType;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Tests that the add/edit Node Forms behaves properly.
 *
 * @group px_calendar_download
 */
class CalendarDownloadNodeFormTest extends BrowserTestBase {

  /**
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * Exempt from strict schema checking.
   *
   * @see \Drupal\Core\Config\Testing\ConfigSchemaChecker
   *
   * @var bool
   */
  protected $strictConfigSchema = FALSE;

  /**
   * @var \Drupal\node\Entity\Node
   */
  protected $testNode;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = ['field_ui', 'node', 'datetime', 'px_calendar_download', 'file'];

  /**
   * {@inheritdoc}
   *
   * @expectedException Drupal\Core\Config\Schema\SchemaIncompleteException
   */
  public function setUp() {
    parent::setUp();

    $this->adminUser = $this->drupalCreateUser([], NULL, 1);
    $this->adminUser->set('timezone', 'Europe/Zurich');
    $this->adminUser->save();

    $this->drupalLogin($this->adminUser);

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'description' => "Use <em>articles</em> for time-sensitive content like news, press releases or blog posts.",
    ]);
    $node_type->save();

    entity_create('field_storage_config', array(
      'field_name' => 'field_dates',
      'entity_type' => 'node',
      'type' => 'datetime',
      'datetime_type' => 'datetime',
    ))->save();
    entity_create('field_config', array(
      'field_name' => 'field_dates',
      'label' => 'Dates',
      'entity_type' => 'node',
      'bundle' => 'article',
    ))->save();
    // S.O.S.: Need to set the widget type, otherwise the form will not contain it.
    entity_get_form_display('node', 'article', 'default')
      ->setComponent('field_dates', [
        'type' => 'datetime_default',
      ])
      ->save();

    $field_ics_download = entity_create('field_storage_config', [
      'field_name' => 'field_ics_download',
      'entity_type' => 'node',
      'type' => 'calendar_download_type',
    ]);
    $field_ics_download->setSettings([
      'vdate_field' => 'field_dates',
      'is_ascii' => FALSE,
      'uri_scheme' => 'public',
      'file_directory' => 'icsfiles',
    ]);
    $field_ics_download->save();
    entity_create('field_config', array(
      'field_name' => 'field_ics_download',
      'label' => 'ICS Download',
      'entity_type' => 'node',
      'bundle' => 'article',
    ))->save();
    // S.O.S.: Need to set the widget type, otherwise the form will not contain it.
    entity_get_form_display('node', 'article', 'default')
      ->setComponent('field_ics_download', [
        'type' => 'calendar_download_default_widget',
      ])
      ->save();
    entity_get_display('node', 'article', 'default')
      ->setComponent('field_ics_download', array(
        'type' => 'calendar_download_default_formatter',
        'settings' => [],
      ))
      ->save();

    entity_create('field_storage_config', array(
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
    ))->save();
    entity_create('field_config', array(
      'field_name' => 'field_body',
      'label' => 'Body',
      'entity_type' => 'node',
      'bundle' => 'article',
    ))->save();
    // S.O.S.: Need to set the widget type, otherwise the form will not contain it.
    entity_get_form_display('node', 'article', 'default')
      ->setComponent('field_body', [
        'type' => 'text_textarea_with_summary',
        'settings' => [
          'rows' => '9',
          'summary_rows' => '3',
        ],
        'weight' => 5,
      ])
      ->save();
  }

  /**
   * Test that we can add a node.
   */
  public function testCreateAndViewNode() {
    $this->drupalLogin($this->adminUser);

    $this->drupalGet('user/' . $this->adminUser->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);

    // Create a random date in the coming week.
    $timestamp = REQUEST_TIME + mt_rand(0, 86400 * 7);
    $date_value_0_date = gmdate(DATETIME_DATE_STORAGE_FORMAT, $timestamp);
    $date_value_0_time = gmdate('H:i:s', $timestamp);

    $add = [
      'title[0][value]' => 'A calendar event',
      'field_dates[0][value][date]' => $date_value_0_date,
      'field_dates[0][value][time]' => $date_value_0_time,
      'field_body[0][value]' => "Lorem ipsum.",
      'field_ics_download[0][vsummary]' => '[node:title]',
      'field_ics_download[0][vdescription]' => '[node:field_body]',
    ];
    $this->drupalPostForm('node/add/article', $add, t('Save and publish'));

    // Check that the node exists in the database.
    $node = $this->drupalGetNodeByTitle($add['title[0][value]']);
    $this->assertTrue($node, 'Node found in database.');

    // Get the node's view.
    $this->drupalGet('node/' . $node->id());

    // Check if there is a link for downloading the ics file.
    $elements = $this->xpath('//a[@href and string-length(@href)!=0 and text() = :label]', [':label' => t('iCal Download')->render()]);
    $el = reset($elements);
    $download_url = $el->getAttribute('href');
    $ics_string = file_get_contents($download_url);

    // Send a post to the ical_validation_url,
    // at http://severinghaus.org/projects/icv/
    $httpClient = \Drupal::httpClient();
    $ical_validation_url = 'http://severinghaus.org/projects/icv/';
    $post_array = array(
      'form_params' => ['snip' => $ics_string],
    );
    $response = $httpClient->post($ical_validation_url, $post_array);
    $crawler = new Crawler($response->getBody()->getContents());
    $this->assertEquals(1, $crawler->filter('div.message.success')->count());

  }

}
