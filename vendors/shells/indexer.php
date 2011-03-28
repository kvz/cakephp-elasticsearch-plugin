<?php
/**
 * cd vendors/
 * git clone https://github.com/ruflin/Elastica.git
 * # was made with commit @ 5cfcab6
 *
 */
require_once dirname(__FILE__) . '/templates/true_shell.php';
TrueShell::createAutoloader(dirname(dirname(__FILE__)) . '/Elastica/lib', 'Elastica_');
App::import('Lib', 'Elasticsearch.Elasticsearch');
class IndexerShell extends TrueShell {
	public $tasks = array();

    public function fill ($modelName = null) {
        $modelName = @$this->args[0];
        if ($modelName === '_all' || !$modelName) {
            $models = Elasticsearch::allModels();
        } else {
            $models = array($modelName);
        }

        foreach ($models as $modelName) {
            if (!($Model = ClassRegistry::init($modelName))) {
                return $this->err('Can\'t instantiate model: %s', $modelName);
            }

            if (!is_array($ids = $Model->elastic_index())) {
                return $this->err(
                    'Error indexing model: %s. ids: %s. errors: %s',
                    $modelName,
                    $ids,
                    $Model->Behaviors->Searchable->errors
                );
            }

            $txtIds = '#' . join(', #', $ids);
            if (strlen($txtIds) > 33) {
                $txtIds = substr($txtIds, 0, 30) . '...';
            }

            $this->info(
                '%7s %18s have been added to the Elastic index ids: %s',
                count($ids),
                Inflector::pluralize(Inflector::humanize($Model->alias)),
                $txtIds
            );
        }
    }

    public function search ($modelName = null, $query = null) {
        $modelName = @$this->args[0];
        if ($modelName === '_all' || !$modelName) {
            $models = Elasticsearch::allModels();
        } else {
            $models = array($modelName);
        }

        foreach ($models as $modelName) {
            if ($query === null && !($query = @$this->args[1])) {
                return $this->err('Need to specify: $query');
            }
            if (!($Model = ClassRegistry::init($modelName))) {
                return $this->err('Can\'t instantiate model: %s', $modelName);
            }

            $ResultSet = $Model->elastic_search($query);
            while (($Result = $ResultSet->current())) {
                print_r(compact('Result', 'query'));
                $ResultSet->next();
            }
        }
    }
}