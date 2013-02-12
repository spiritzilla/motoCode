<?php

/**
 * @file
 * Definition of Drupal\views\Tests\Plugin\QueryTest.
 */

namespace Drupal\views\Tests\Plugin;

use Drupal\views\Tests\ViewUnitTestBase;
use Drupal\views_test_data\Plugin\views\query\QueryTest as QueryTestPlugin;

/**
 * Tests query plugins.
 */
class QueryTest extends ViewUnitTestBase {

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = array('test_view');

  public static function getInfo() {
    return array(
      'name' => 'Query',
      'description' => 'Tests query plugins.',
      'group' => 'Views Plugins'
    );
  }

  protected function viewsData() {
    $data = parent::viewsData();
    $data['views_test_data']['table']['base']['query_id'] = 'query_test';

    return $data;
  }

  /**
   * Tests query plugins.
   */
  public function testQuery() {
    $this->_testInitQuery();
    $this->_testQueryExecute();
  }

  /**
   * Tests the ViewExecutable::initQuery method.
   */
  public function _testInitQuery() {
    $view = views_get_view('test_view');
    $view->setDisplay();

    $view->initQuery();
    $this->assertTrue($view->query instanceof QueryTestPlugin, 'Make sure the right query plugin got instantiated.');
  }

  public function _testQueryExecute() {
    $view = views_get_view('test_view');
    $view->setDisplay();

    $view->initQuery();
    $view->query->setAllItems($this->dataSet());

    $this->executeView($view);
    $this->assertTrue($view->result, 'Make sure the view result got filled');
  }

}
