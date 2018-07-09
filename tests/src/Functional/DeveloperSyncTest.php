<?php

/**
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_edge\Functional;

use Drupal\apigee_edge\Entity\Developer;
use Drupal\apigee_edge\Entity\DeveloperInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Developer synchronization test.
 *
 * @group apigee_edge9
 */
class DeveloperSyncTest extends ApigeeEdgeFunctionalTestBase {

  public static $modules = [
    'block',
  ];

  /**
   * Random property prefix.
   *
   * @var string
   */
  protected $prefix;

  /**
   * Email filter.
   *
   * @var string
   */
  protected $filter;

  /**
   * Array of Apigee Edge developers.
   *
   * @var \Drupal\apigee_edge\Entity\DeveloperInterface[]
   */
  protected $edgeDevelopers = [];

  /**
   * Array of Drupal users.
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected $drupalUsers = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->prefix = $this->randomMachineName();
    $escaped_prefix = preg_quote($this->prefix);
    $this->filter = "/^{$escaped_prefix}\.[a-zA-Z0-9]*@example\.com$/";
    $this->container->get('config.factory')->getEditable('apigee_edge.sync')->set('filter', $this->filter)->save();

    // Create developers on Apigee Edge.
    for ($i = 0; $i < 1; $i++) {
      $this->edgeDevelopers[$i] = Developer::create([
        'email' => "{$this->prefix}.{$this->randomMachineName()}@example.com",
        'userName' => $this->randomMachineName(),
        'firstName' => $this->randomMachineName(),
        'lastName' => $this->randomMachineName(),
      ]);
      $this->edgeDevelopers[$i]->save();
    }

    // Create users in Drupal.
    _apigee_edge_set_sync_in_progress(TRUE);
    for ($i = 0; $i < 1; $i++) {
      $this->drupalUsers[$i] = $this->createAccount([], TRUE, $this->prefix, FALSE);
    }
    _apigee_edge_set_sync_in_progress(FALSE);

    $this->drupalLogin($this->rootUser);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    $remote_ids = array_map(function ($record): string {
      return $record['email'];
    }, $this->edgeDevelopers);
    $drupal_emails = array_map(function (UserInterface $user): string {
      return $user->getEmail();
    }, $this->drupalUsers);
    $ids = array_merge($remote_ids, $drupal_emails);
    foreach ($ids as $id) {
      Developer::load($id)->delete();
    }
    parent::tearDown();
  }

  /**
   * Verifies that the Drupal users and the Edge developers are synchronized.
   */
  protected function verify() {
    $all_users = [];
    /** @var \Drupal\user\UserInterface $account */
    foreach (User::loadMultiple() as $account) {
      $email = $account->getEmail();
      if ($email && $email !== 'admin@example.com') {
        $this->assertTrue($this->filter ? (bool) preg_match($this->filter, $email) : TRUE, "Email ({$email}) is filtered properly.");
        $all_users[$email] = $email;
      }
    }

    unset($all_users[$this->rootUser->getEmail()]);

    foreach ($this->edgeDevelopers as $edgeDeveloper) {
      /** @var \Drupal\user\Entity\User $account */
      $user = user_load_by_mail($edgeDeveloper->getEmail());
      $this->assertNotEmpty($user, 'Account found: ' . $edgeDeveloper->getEmail());
      $this->assertEquals($edgeDeveloper->getUserName(), $user->getAccountName());
      $this->assertEquals($edgeDeveloper->getFirstName(), $user->get('first_name')->value);
      $this->assertEquals($edgeDeveloper->getLastName(), $user->get('last_name')->value);

      unset($all_users[$edgeDeveloper->getEmail()]);
    }

    foreach ($this->drupalUsers as $drupalUser) {
      /** @var \Drupal\apigee_edge\Entity\DeveloperInterface $developer */
      $developer = Developer::load($drupalUser->getEmail());
      $this->assertNotEmpty($developer, 'Developer found on edge.');
      $this->assertEquals($drupalUser->getAccountName(), $developer->getUserName());
      $this->assertEquals($drupalUser->get('first_name')->value, $developer->getFirstName());
      $this->assertEquals($drupalUser->get('last_name')->value, $developer->getLastName());

      unset($all_users[$drupalUser->getEmail()]);
    }

    $this->assertEquals([], $all_users, 'Only the necessary users were synced. ' . implode(', ', $all_users));
  }

  /**
   * Tests Drupal user synchronization.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   */
  public function testUserSync() {
    $this->drupalGet('/admin/config/apigee-edge/developer-settings/sync');
    $this->clickLinkProperly(t('Now'));
    $this->assertSession()->pageTextContains(t('Users are in sync with Edge.'));
    $this->verify();
  }

  /**
   * Tests scheduled Drupal user synchronization.
   *
   * @throws \Behat\Mink\Exception\ResponseTextException
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   * @throws \Exception
   */
  public function testUserAsync() {
    $this->drupalGet('/admin/config/apigee-edge/developer-settings/sync');
    $this->clickLinkProperly(t('Background'));
    $this->assertSession()->pageTextContains(t('User synchronization is scheduled.'));
    /** @var \Drupal\Core\Queue\QueueFactory $queue_service */
    $queue_service = \Drupal::service('queue');
    /** @var \Drupal\Core\Queue\QueueInterface $queue */
    $queue = $queue_service->get('apigee_edge_job');
    /** @var \Drupal\Core\Queue\QueueWorkerManagerInterface $queue_worker_manager */
    $queue_worker_manager = \Drupal::service('plugin.manager.queue_worker');
    /** @var \Drupal\Core\Queue\QueueWorkerInterface $worker */
    $worker = $queue_worker_manager->createInstance('apigee_edge_job');
    while (($item = $queue->claimItem())) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
    $this->verify();
  }

  /**
   * Tests the Drupal user synchronization started from the CLI.
   */
  public function testCliUserSync() {
    $cli_service = $this->container->get('apigee_edge.cli');
    $input = new ArgvInput();
    $output = new BufferedOutput();

    $cli_service->sync(new SymfonyStyle($input, $output), 't');

    $printed_output = $output->fetch();

    foreach ($this->edgeDevelopers as $edge_developer) {
      $this->assertContains($edge_developer['email'], $printed_output);
    }

    $this->verify();
  }

}