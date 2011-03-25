<?php
/**
 * cd vendors/
 * git clone https://github.com/ruflin/Elastica.git
 * # was made with commit @ 5cfcab6
 *
 */
require_once dirname(__FILE__) . '/templates/true_shell.php';
TrueShell::createAutoloader(dirname(dirname(__FILE__)) . '/Elastica/lib', 'Elastica_');

class IndexerShell extends TrueShell {
	public $tasks = array();

    public function fill ($modelName = null) {
        if ($modelName === null && !($modelName = @$this->args[0])) {
            return $this->err('Need to specify: $modelName');
        }
        if (!($Model = ClassRegistry::init($modelName))) {
            return $this->err('Can\'t instantiate model: %s', $modelName);
        }

        if (!is_array($ids = $Model->elastic_index())) {
            return $this->err('Error indexing model: %s. %s', $modelName, $ids);
        }

        $this->info(
            '%s %s have been added to the Elastic index (%s)',
            count($ids),
            Inflector::pluralize(Inflector::humanize($Model->alias)),
            '#' . join(', #', $ids)
        );
    }

    public function search ($modelName = null, $query = null) {
        if ($modelName === null && !($modelName = @$this->args[0])) {
            return $this->err('Need to specify: $modelName');
        }
        if ($query === null && !($query = @$this->args[1])) {
            return $this->err('Need to specify: $query');
        }
        if (!($Model = ClassRegistry::init($modelName))) {
            return $this->err('Can\'t instantiate model: %s', $modelName);
        }

        $ResultSet = $Model->elastic_search($query);
        while (($Result = $ResultSet->current())) {
            print_r(compact('Result'));
            $ResultSet->next();
        }
    }
}