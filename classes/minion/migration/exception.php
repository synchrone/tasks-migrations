<?php defined('SYSPATH') or die('No direct script access.');

/**
 * Minion exception, thrown during a migration error
 */
class Minion_Migration_Exception extends Minion_Exception {

	protected $_migration = array();

	/**
	 * Constructor
	 *
	 */
	public function __construct($message, array $migration, array $variables = array(), $code = 0)
	{
		$variables[':migration-id']       = $migration['id'];
		$variables[':migration-group'] = $migration['group'];

		$this->_migration = $migration;

		parent::__construct($message, $variables, $code);
	}

	/**
	 * Get the migration that caused this exception to be thrown
	 *
	 * @return array
	 */
	public function get_migration()
	{
		return $this->_migration;
	}
    public function format_for_cli()
   	{
   		return View::factory('minion/task/migrations/run/exception')
   						->set('migration', $this->get_migration())
   						->set('error',     $this->getMessage())
               .PHP_EOL;
   	}
}
