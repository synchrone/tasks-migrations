<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Displays the current status of migrations in all groups
 *
 * This task takes no config options
 *
 * @author Matt Button <matthew@sigswitch.com>
 */
class Minion_Task_Migrations_Status extends Minion_Task {

	/**
	 * Execute the task
	 *
	 * @param array Config for the task
	 */
	public function execute(array $config)
	{
		$db        = Database::instance();
		$model     = new Model_Minion_Migration($db);
		$model->ensure_table_exists();

		$manager = new Minion_Migration_Manager($db, $model);
		$manager->sync_migration_files();

		$view = new View('minion/task/migrations/status');

		$view->groups = $model->get_group_statuses();

		echo $view;
	}
}
