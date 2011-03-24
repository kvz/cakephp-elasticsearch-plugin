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

        if (!($indexParams = $Model->elastic_search_opt('index'))) {
            $indexParams = array();
            #return $this->err('No indexing options for %s. Set via model\'s $actAs', $Model->alias);
        }

        $indexName = Inflector::tableize($Model->alias);
        $typeName  = Inflector::underscore($Model->alias);

        $Index = $this->Client()->getIndex($indexName);
        $Index->create(array(), true);
        $Type = $Index->getType($typeName);

        $Model->Behaviors->attach('Containable');
        $results = $Model->find('all', $indexParams);

        $ids = array();
        foreach ($results as $result) {
            $id    = $result[$Model->alias][$Model->primaryKey];
            $ids[] = $id;
            $Doc   = new Elastica_Document($id, Set::flatten($result, '/'));
            $Type->addDocument($Doc);
        }

        // Index needs a moment to be updated
        $Index->refresh();

        $this->info(
            '%s %s have been added to the Elastic index (%s)',
            count($results),
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