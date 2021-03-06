<?php defined('SYSPATH') or die('No direct script access.');

/**
 * The migration manager is responsible for locating migration files, syncing
 * them with the migrations table in the database and selecting any migrations
 * that need to be executed in order to reach a target version
 *
 * @author Matt Button <matthew@sigswitch.com>
 */
class Minion_Migration_Manager {

	/**
	 * The database connection that sould be used
	 * @var Kohana_Database
	 */
	protected $_db;

	/**
	 * Model used to interact with the migrations table in the database
	 * @var Model_Minion_Migration
	 */
	protected $_model;

	/**
	 * Whether this is a dry run migration
	 * @var boolean
	 */
	protected $_dry_run = FALSE;

	/**
	 * A set of SQL queries that were generated on the dry run
	 * @var array
	 */
	protected $_dry_run_sql = array();

	/**
	 * Set of migrations that were executed
	 */
	protected $_executed_migrations = array();


	/**
	 * Constructs the object, allows injection of a Database connection
	 *
	 * @param Kohana_Database        The database connection that should be passed to migrations
	 * @param Model_Minion_Migration Inject an instance of the minion model into the manager
	 */
	public function __construct(Kohana_Database $db, Model_Minion_Migration $model = NULL)
	{
		if ($model === NULL)
		{
			$model = new Model_Minion_Migration($db);
		}

		$this->_db    = $db;
		$this->_model = $model;
	}

	/**
	 * Set the database connection to be used
	 *
	 * @param Kohana_Database Database connection
	 * @return Minion_Migration_Manager
	 */
	public function set_db(Kohana_Database $db)
	{
		$this->_db = $db;

		return $this;
	}

	/**
	 * Set the model to be used in the rest of the app
	 *
	 * @param Model_Minion_Migration Model instance
	 * @return Minion_Migration_Manager
	 */
	public function set_model(Model_Minion_Migration $model)
	{
		$this->_model = $model;

		return $this;
	}

	/**
	 * Set whether the manager should execute a dry run instead of a real run
	 *
	 * @param boolean Whether we should do a dry run
	 * @return Minion_Migration_Manager
	 */
	public function set_dry_run($dry_run)
	{
		$this->_dry_run = (bool) $dry_run;

		return $this;
	}

	/**
	 * Returns a set of queries that would've been executed had dry run not been
	 * enabled.  If dry run was not enabled, this returns an empty array
	 *
	 * @return array SQL Queries
	 */
	public function get_dry_run_sql()
	{
		return $this->_dry_run_sql;
	}

	/**
	 * Returns a set of executed migrations
	 * @return array
	 */
	public function get_executed_migrations()
	{
		return $this->_executed_migrations;
	}

    /**
     * Run migrations in the specified groups so as to reach specified targets
     * @param array $group Versions for specified groups
     * @param bool $target
     * @throws Kohana_Exception
     * @throws Minion_Migration_Exception
     * @return array   Array of all migrations that were successfully applied
     */
	public function run_migration($group = array(), $target = TRUE)
	{
		list($migrations, $is_up) = $this->_model->fetch_required_migrations($group, $target);

		$method = $is_up ? 'up' : 'down';

		foreach ($migrations as $migration)
		{
			if ($method == 'down' AND $migration['timestamp'] <= Kohana::$config->load('minion/migration')->lowest_migration)
			{
				Minion_CLI::write(
					'You\'ve reached the lowest migration allowed by your config: '.Kohana::$config->load('minion/migration')->lowest_migration,
					'red'
				);
				return;
			}

			$filename  = $this->_model->get_filename_from_migration($migration);

			if ( ! ($file  = Kohana::find_file('migrations', $filename, FALSE)))
			{
				throw new Kohana_Exception(
					'Cannot load migration :migration (:file)',
					array(
						':migration' => $migration['id'],
						':file'      => $filename
					)
				);
			}

			$class = $this->_model->get_class_from_migration($migration);

			include_once $file;

            /** @var $instance Minion_Migration_Base */
			$instance = new $class($migration);

			$db = $this->_dry_run ? Minion_Migration_Database::faux_instance() : $this->_db;

			try
			{
				$instance->$method($db);
			}
			catch(Database_Exception $e)
			{
				throw new Minion_Migration_Exception($e->getMessage(), $migration);
			}

			if ($this->_dry_run)
			{
				$this->_dry_run_sql[$migration['group']][$migration['timestamp']] = $db->reset_query_stack();
			}
			else
			{
				$this->_model->mark_migration($migration, $is_up);
			}

			$this->_executed_migrations[] = $migration;
		}
	}

	/**
	 * Syncs all available migration files with the database
	 *
	 * @chainable
	 * @return Minion_Migration_Manager Chainable instance
	 */
	public function sync_migration_files()
	{
		// Get array of installed migrations with the id as key
		$installed = $this->_model->fetch_all('id');

		$available = $this->_model->available_migrations();

		$all_migrations = array_merge(array_keys($installed), array_keys($available));

		foreach ($all_migrations as $migration)
		{
			// If this migration has since been deleted
			if (isset($installed[$migration]) AND ! isset($available[$migration]))
			{
				// We should only delete a record of this migration if it does
				// not exist in the "real world"
				if ($installed[$migration]['applied'] === '0')
				{
					$this->_model->delete_migration($installed[$migration]);
				}
			}
			// If the migration has not yet been installed :D
			elseif ( ! isset($installed[$migration]) AND isset($available[$migration]))
			{
				$this->_model->add_migration($available[$migration]);
			}
			// Somebody changed the description of the migration, make sure we
			// update it in the db as we use this to build the filename!
			elseif ($installed[$migration]['description'] !== $available[$migration]['description'])
			{
				$this->_model->update_migration($installed[$migration], $available[$migration]);
			}
		}

		return $this;
	}

	/**
	 * Gets a database connection for running the migrations
	 *
	 * @param  string Database connection group name
	 * @return Kohana_Database Database connection
	 */
	protected function _get_db_instance($db_group)
	{
		// If this isn't a dry run then just use a normal database connection
		if ( ! $this->_dry_run)
			return Database::instance($db_group);

		return Minion_Migration_Database::faux_instance($db_group);
	}
}
