<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Displays the current status of migrations in all groups
 *
 * Available config options are:
 *
 * --db-group
 * Use this database config group
 *
 * @author Matt Button <matthew@sigswitch.com>
 */
class Task_Migrations_Status extends Minion_Task {

    protected $_options = array(
        'db-group' => null,
        'format' => null,
        'group' => null,
    );

	/**
	 * Execute the task
	 *
	 * @param array Config for the task
	 */
	public function _execute(array $params)
	{
		$db        = Database::instance($params['db-group']);
		$model     = new Model_Minion_Migration($db);
        $model->ensure_table_exists();

        $manager = new Minion_Migration_Manager($db, $model);
        $manager->sync_migration_files();
        $statuses = $model->get_group_statuses();

        if($params['format'] == 'sh')
        {
            $group = Arr::get($params,'group',Kohana::$config->load('minion/migration.default_group'));
            echo $statuses[$group]['count_available'];
        }else{
            echo View::factory('minion/task/migrations/status')
                ->set('groups',$statuses);
        }
    }
}
