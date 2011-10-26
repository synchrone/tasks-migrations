<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Model for managing migrations
 */
class Model_Minion_Migration extends Model
{
	/**
	 * Database connection to use
	 * @var Kohana_Database
	 */
	protected $_db = NULL;

	/**
	 * The table that's used to store the migrations
	 * @var string
	 */
	protected $_table = 'minion_migrations';

	/**
	 * Constructs the model, taking a Database connection as the first and only
	 * parameter
	 *
	 * @param Kohana_Database Database connection to use
	 */
	public function __construct(Kohana_Database $db)
	{
		$this->_db = $db;
	}

	/**
	 * Returns a list of migrations that are available in the filesystem
	 *
	 * @return array
	 */
	public function available_migrations()
	{
		$files = Kohana::list_files('migrations');

		return $this->compile_migrations_from_files($files);
	}

	/**
	 * Parses a set of files generated by Kohana::find_files and compiles it
	 * down into an array of migrations
	 *
	 * @param  array Available files
	 * @return array Available Migrations
	 */
	public function compile_migrations_from_files(array $files)
	{
		$migrations = array();

		foreach ($files as $file => $path)
		{
			// If this is a directory we're dealing with
			if (is_array($path))
			{
				$migrations += $this->compile_migrations_from_files($path);
			}
			else
			{
				// Skip files without an extension of EXT
				if ('.'.pathinfo($file, PATHINFO_EXTENSION) !== EXT)
					continue;

				$migration = $this->get_migration_from_filename($file);

				$migrations[$migration['group'].':'.$migration['timestamp']] = $migration;
			}
		}

		return $migrations;
	}

	/**
	 * Extracts information about a migration from its filename.
	 *
	 * Returns an array like:
	 *
	 *     array(
	 *        'group'    => 'mygroup',
	 *        'timestamp'   => '1293214439',
	 *        'description' => 'initial-setup',
	 *        'id'          => 'mygroup:1293214439'
	 *     );
	 *
	 * @param  string The migration's filename
	 * @return array  Array of components about the migration
	 */
	public function get_migration_from_filename($file)
	{
		$migration = array();

		// Get rid of the file's "migrations/" prefix, the file extension and then
		// the filename itself.  The "group" is essentially a slash delimited
		// path from the migrations folder to the migration file
		$migration['group'] = dirname(substr($file, 11, -strlen(EXT)));

		if(strpos(basename($file), "_"))
		{
			list($migration['timestamp'], $migration['description'])
				= explode('_', basename($file, EXT), 2);
		}
		else
		{
			$migration['timestamp'] = basename($file, EXT);
			$migration['description'] = "";
		}
		$migration['id'] = $migration['group'].':'.$migration['timestamp'];

		return $migration;
	}

	/**
	 * Gets a migration file from its timestamp, description and group
	 *
	 * @param  integer|array The migration's ID or an array of timestamp, description
	 * @param  string        The migration group
	 * @return string        Path to the migration file
	 */
	public function get_filename_from_migration(array $migration)
	{
		$group  = $migration['group'];

		if(!empty($migration['description']))
		{
			$migration = $migration['timestamp'].'_'.$migration['description'];
		}
		else
		{
			$migration = $migration['timestamp'];
		}

		$group = ( ! empty($group)) ? (rtrim($group, '/').'/') : '';

		return $group.$migration.EXT;
	}

	/**
	 * Allows you to work out the class name from either an array of migration
	 * info, or from a migration id
	 *
	 * @param  string|array The migration's ID or array of migration data
	 * @return string       The migration class name
	 */
	public function get_class_from_migration($migration)
	{
		if (is_string($migration))
		{
			$migration = str_replace(array(':', '/'), ' ', $migration);
		}
		else
		{
			$migration = str_replace('/', ' ', $migration['group']).'_'.$migration['timestamp'];
		}

		return 'Migration_'.str_replace(array(' ', '-'), '_', ucwords($migration));
	}

	/**
	 * Checks to see if the minion migrations table exists and attempts to
	 * create it if it doesn't
	 *
	 * @return boolean
	 */
	public function ensure_table_exists()
	{
		$query = $this->_db->query(Database::SELECT, "SHOW TABLES like '".$this->_table."'");

		if ( ! count($query))
		{
			$sql = file_get_contents(Kohana::find_file('', 'minion_schema', 'sql'));

			$this->_db->query(NULL, $sql);
		}
	}

	/**
	 * Gets the status of all groups, whether they're in the db or not.
	 *
	 * @return array
	 */
	public function get_group_statuses()
	{
		// Start out using all the installed groups
		$groups = $this->fetch_current_versions('group');
		$available = $this->available_migrations();

		foreach ($available as $migration)
		{
			if (array_key_exists($migration['group'], $groups))
			{
				continue;
			}

			$groups[$migration['group']] = NULL;
		}

		return $groups;
	}

	/**
	 * Get or Set the table to use to store migrations
	 *
	 * Should only really be used during testing
	 *
	 * @param string Table name
	 * @return string|Model_Minion_Migration Get table name or return $this on set
	 */
	public function table($table = NULL)
	{
		if ($table === NULL)
			return $this->_table;

		$this->_table = $table;

		return $this;
	}

	/**
	 * Creates a new select query which includes all fields in the migrations
	 * table plus a `id` field which is a combination of the timestamp and the
	 * description
	 *
	 * @return Database_Query_Builder_Select
	 */
	protected function _select()
	{
		return DB::select('*', DB::expr('CONCAT(`group`, ":", CAST(`timestamp` AS CHAR)) AS `id`'))->from($this->_table);
	}

	/**
	 * Inserts a migration into the database
	 *
	 * @param array Migration data
	 * @return Model_Minion_Migration $this
	 */
	public function add_migration(array $migration)
	{
		DB::insert($this->_table, array('timestamp', '`group`', 'description'))
			->values(array($migration['timestamp'], $migration['`group`'], $migration['description']))
			->execute($this->_db);

		return $this;
	}

	/**
	 * Get a migration by its id
	 *
	 * @param  string Migration ID
	 * @return array  Migration info
	 */
	public function get_migration($group, $timestamp = NULL)
	{
		if ($timestamp === NULL)
		{
			if (empty($group) OR strpos(':', $group) === FALSE)
			{
				throw new Kohana_Exception('Invalid migration id :id', array(':id' => $group));
			}

			list($group, $timestamp) = explode(':', $group);
		}

		return $this->_select()
			->where('timestamp', '=', (string) $timestamp)
			->where('group',  '=', (string) $group)
			->execute($this->_db)
			->current();
	}

	/**
	 * Deletes a migration from the database
	 *
	 * @param string|array Migration id / info
	 * @return Model_Minion_Migration $this
	 */
	public function delete_migration($migration)
	{
		if (is_array($migration))
		{
			$timestamp = $migration['timestamp'];
			$group  = $migration['`group`'];
		}
		else
		{
			list($timestamp, $group) = explode(':', $migration);
		}

		DB::delete($this->_table)
			->where('timestamp', '=', $timestamp)
			->where('group',  '=', $group)
			->execute($this->_db);

		return $this;
	}

	/**
	 * Update an existing migration record to reflect a new one
	 *
	 * @param array The current migration
	 * @param array The new migration
	 * @return Model_Minion_Migration $this
	 */
	public function update_migration(array $current, array $new)
	{
		$set = array();

		foreach ($new as $key => $value)
		{
			if ($key !== 'id' AND $current[$key] !== $value)
			{
				$set[$key] = $value;
			}
		}

		if (count($set))
		{
			DB::update($this->_table)
				->set($set)
				->where('timestamp', '=', $current['timestamp'])
				->where('`group`', '=', $current['group'])
				->execute($this->_db);
		}

		return $this;
	}

	/**
	 * Change the applied status for a migration
	 *
	 * @param  array Migration information
	 * @param  bool  Whether this migration has been applied or unapplied
	 * @return Model_Minion_Migration
	 */
	public function mark_migration(array $migration, $applied)
	{
		DB::update($this->_table)
			->set(array('applied' => (int) $applied))
			->where('timestamp', '=', $migration['timestamp'])
			->where('`group`',  '=', $migration['group'])
			->execute($this->_db);

		return $this;
	}

	/**
	 * Selects all migrations from the migratinos table
	 *
	 * @return Kohana_Database_Result
	 */
	public function fetch_all($key = NULL, $value = NULL)
	{
		return $this->_select()
			->execute($this->_db)
			->as_array($key, $value);
	}

	/**
	 * Fetches the latest version for all installed groups
	 *
	 * If a group does not have any applied migrations then no result will be
	 * returned for it
	 *
	 * @return Kohana_Database_Result
	 */
	public function fetch_current_versions($key = 'group', $value = NULL)
	{
		// Little hack needed to do an order by before a group by
		return DB::select()
			->from(array(
				$this->_select()
				->where('applied', '>', 0)
				->order_by('timestamp', 'DESC'),
				'temp_table'
			))
			->group_by('`group`')
			->execute($this->_db)
			->as_array($key, $value);
	}

	/**
	 * Fetches a list of groups
	 *
	 * @return array
	 */
	public function fetch_groups($group_as_key = FALSE)
	{
		return DB::select()
			->from($this->_table)
			->group_by('`group`')
			->execute($this->_db)
			->as_array($group_as_key ? 'group' : NULL, 'group');
	}

	/**
	 * Fetch a list of migrations that need to be applied in order to reach the
	 * required version
	 *
	 * @param string  The groups to get migrations for
	 * @param mixed   Target version
	 */
	public function fetch_required_migrations(array $group, $target = TRUE)
	{
		$migrations     = array();
		$current_groups = $this->fetch_groups(TRUE);

		// Make sure the group(s) exist
		foreach ($group as $group_name)
		{
			if ( ! isset($current_groups[$group_name]))
			{
				throw new Kohana_Exception("Migration group :group does not exist", array(':group' => $group_name));
			}
		}

		$query = $this->_select();

		if (is_bool($target))
		{
			$up = $target;

			// If we want to limit this migration to certain groups
			if ( ! empty($group))
			{
				if (count($group) > 1)
				{
					$query->where('`group`', 'IN', $group);
				}
				else
				{
					$query->where('`group`', '=', $group[0]);
				}
			}
		}
		// Relative up/down target
		elseif (in_array($target[0], array('+', '-')))
		{
			list($target, $up) = $this->resolve_target($group, $target);

			$query->where('`group`', '=', $group);

			if( $target !== NULL)
			{
				if ($up)
				{
					$query->where('timestamp', '<=', $target);
				}
				else
				{
					$query->where('timestamp', '>=', $target);
				}
			}

		}
		// Absolute timestamp
		else
		{
			$query->where('`group`', '=', $group);

			$statuses = $this->fetch_current_versions('`group`', 'timestamp');
			$up = (empty($statuses) OR ($statuses[$group[0]] < $target));

			if ($up)
			{
				$query->where('timestamp', '<=', $target);
			}
			else
			{
				$query->where('timestamp', '>', $target);
			}
		}

		// If we're migrating up
		if ($up)
		{
			$query
				->where('applied', '=', 0)
				->order_by('timestamp', 'ASC');
		}
		// If we're migrating down
		else
		{
			$query
				->where('applied', '=', 1)
				->order_by('timestamp', 'DESC');
		}

		return array($query->execute($this->_db)->as_array(), $up);
	}

	/**
	 * Resolve a (potentially relative) target for a group to a definite timestamp
	 *
	 * @param string     Group name
	 * @param string|int Target
	 * @return array First element timestamp, second is boolean (TRUE if up, FALSE if down)
	 */
	public function resolve_target($group, $target)
	{
		if (empty($group))
		{
			throw new Kohana_Exception("No group specified");
		}

		if (is_array($group))
		{
			if (count($group) > 1)
			{
				throw new Kohana_Exception("A target can only be expressed for a single group");
			}

			$group = $group[0];
		}

		if( ! in_array($target[0], array('+', '-')))
		{
			throw new Kohana_Exception("Invalid relative target");
		}

		$query         = $this->_select();
		$statuses      = $this->fetch_current_versions();
		$target        = (string) $target;
		$group_applied = isset($statuses[$group]);
		$timestamp     = $group_applied ? $statuses[$group]['timestamp'] : NULL;
		$amount        = substr($target, 1);
		$up            = $target[0] === '+';

		if ($up)
		{
			if ($group_applied)
			{
				$query->where('timestamp', '>', $timestamp);
			}
		}
		else
		{
			if ( ! $group_applied)
			{
				throw new Kohana_Exception(
					"Cannot migrate group :group down as none of its migrations have been applied",
					array(':group' => $group)
				);
			}

			$query
				->where('applied', '=', 1)
				->where('timestamp', '<=', $timestamp);
		}

		$query->limit($amount);

		$query->where('`group`', '=', $group);

		$query->order_by('timestamp', ($up ? 'ASC' : 'DESC'));

		$results = $query->execute($this->_db);

		if ($amount !== NULL AND count($results) != $amount)
		{
			return array(NULL, $up);
		}

		// Seek to the requested row
		for ($i = 0; $i < $amount - 1; $i++)
		{
			$results->next();
		}

		return array((string) $results->get('timestamp'), $up);
	}
}
