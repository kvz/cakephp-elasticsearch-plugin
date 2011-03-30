<?php
/**
 * Searchable
 *
 * Copyright (c) 2011 Kevin van Zonneveld (http://kevin.vanzonneveld.net || kvz@php.net)
 * 
 * @author Kevin van Zonneveld (kvz)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 */
SearchableBehavior::createAutoloader(dirname(dirname(dirname(__FILE__))) . '/vendors/Elastica/lib', 'Elastica_');
class SearchableBehavior extends ModelBehavior {
    
    public $mapMethods = array(
        '/elastic_search_opt/' => 'opt',
        '/elastic_search/' => 'search',
        '/elastic_index/' => 'index',
        '/elastic_enabled/' => 'enabled',
    );
    
    protected $_default = array(
        'highlight' => array(
            'pre_tags' => array('<em class="highlight">'),
            'post_tags' => array('</em>'),
            'fields' => array(
                '_all' => array(
                    'fragment_size' => 200,
                    'number_of_fragments' => 1,
                ),
            ),
        ),
        'fake_fields' => null,
        'debug_traces' => false,
        'searcher_enabled' => true,
        'searcher_action' => 'searcher',
        'searcher_param' => 'q',
        'searcher_serializer' => 'json_encode',
        'auto_update' => false,
        'limit' => 4,
        'index_find_params' => array(),
        'index_name' => 'main',
        'index_chunksize' => 10000,
        'static_url_generator' => array('{model}', 'url'),
        'error_handler' => 'php',
        'enforce' => array(),
    );

    protected $_Client;
    protected $_fields = array();
    public $settings = array();
    public $errors = array();

    protected static $_autoLoaderPrefix = '';
    public static function createAutoloader ($path, $prefix = '') {
        self::$_autoLoaderPrefix = $prefix;
        set_include_path(get_include_path() . PATH_SEPARATOR . $path);
        spl_autoload_register(array('SearchableBehavior', 'autoloader'));
    }
    public static function autoloader ($className) {
        if (substr($className, 0, strlen(self::$_autoLoaderPrefix)) !== self::$_autoLoaderPrefix) {
            // Only autoload stuff for which we created this loader
            #echo 'Not trying to autoload ' . $className. "\n";
            return;
        }

        $path = str_replace('_', '/', $className) . '.php';
        include($path);
    }

    public function IndexType ($Model, $create = false, $reset = false) {
        $Index = $this->Client()->getIndex($this->opt($Model, 'index_name'));
        if ($reset) {
            $Index->create(array(), $reset);
        }
        $Type = $Index->getType(Inflector::underscore($Model->alias));

        return array($Index, $Type);
    }

    public function Client () {
        if (!$this->_Client) {
            $DB = new DATABASE_CONFIG();
            $this->_Client = new Elastica_Client(
                $DB->elastic['host'],
                $DB->elastic['port']
            );
        }

        return $this->_Client;
    }

    public function beforeSave ($Model) {
        if ($this->opt($Model, 'auto_update')) {
            prd('@todo');
        }
        return true;
    }

    public function afterFind ($Model, $results, $primary) {
        if ($this->opt($Model, 'auto_update')) {
            prd('@todo');
        }
        return $results;
    }


    public function index () {
        $args = func_get_args();

        // Strip model from args if needed
        if (is_object(@$args[0])) {
            $Model = array_shift($args);
        } else {
            return $this->err('First argument needs to be a model');
        }

        // Strip method from args if needed (e.g. when called via $Model->mappedMethod())
        if (is_string(@$args[0])) {
            foreach ($this->mapMethods as $pattern => $meth) {
                if (preg_match($pattern, $args[0])) {
                    $method = array_shift($args);
                    break;
                }
            }
        }

        // Create index
        list($Index, $Type) = $this->IndexType($Model, true);

        // Get records
        $Model->Behaviors->attach('Containable');


        $offset = 0;
        $limit  = $this->opt($Model, 'index_chunksize');
        $ids    = array();
        while (true) {
            $curIds = $this->_indexChunk($Model, $Index, $Type, $offset, $limit);
            $ids    = Set::merge($ids, $curIds);

            if (count($curIds) < $limit) {
                break;
            }
            $offset += $limit;
        }

        // Index needs a moment to be updated
        $Index->refresh();

        return $ids;
    }

    protected function _indexChunk ($Model, $Index, $Type, $offset, $limit) {
        // Set params
        if (!($params = $this->opt($Model, 'index_find_params'))) {
            $params = array();
        }

        $params['offset'] = $offset;
        if (empty($params['limit'])) {
            $params['limit']  = $limit;
        }

        $results = $Model->find('all', $params);

        if (empty($results)) {
            return array();
        }


        // Add documents
        $urlCb = $this->opt($Model, 'static_url_generator');
        if ($urlCb[0] === '{model}') {
            $urlCb[0] = $Model->name;
        }
        if (!method_exists($urlCb[0], $urlCb[1])) {
            $urlCb = false;
        }
        $ids = array();
        $fake_fields = $this->opt($Model, 'fake_fields');
        foreach ($results as $result) {
            if (empty($result[$Model->alias][$Model->primaryKey])) {
                return $this->err(
                    $Model,
                    'I need at least primary key: %s->%s inside the index data. Please include in the index_find_params',
                    $Model->alias,
                    $Model->primaryKey
                );
            }
            $result['_id'] = $result[$Model->alias][$Model->primaryKey];;

            $result['_label'] = '';

            // FakeFields
            if (is_array(reset($fake_fields))) {
                foreach ($fake_fields as $fake_field => $xPaths) {
                    $concats = array();
                    foreach ($xPaths as $xPath) {
                        if (substr($xPath, 0, 1) === '/') {
                            $d = Set::extract($result, $xPath);
                            $concats[] = reset($d);
                        } else {
                            $concats[] = $xPath;
                        }
                    }

                    $result[$fake_field] = join(' ', $concats);
                }
            } else {
                if (array_key_exists($Model->displayField, $result[$Model->alias])) {
                    $result['_label'] = $result[$Model->alias][$Model->displayField];
                }
            }

            $result['_descr'] = '';
            if (array_key_exists(@$Model->descripField, $result[$Model->alias])) {
                $result['_descr'] = $result[$Model->alias][$Model->descripField];
            }

            $result['_model'] = $Model->name;
            if (!@$Model->titlePlu) {
                if (!@$Model->title) {
                    $Model->title = Inflector::humanize(Inflector::underscore($result['_model']));
                }
                $Model->titlePlu = Inflector::pluralize($Model->title);
            }
            $result['_model_title'] = $Model->titlePlu;

            $result['_url']   = '';
            if (is_array($urlCb)) {
                $result['_url'] = call_user_func($urlCb, $result['_id'], $result['_model']);
            }

            $ids[] = $result['_id'];
            $Doc   = new Elastica_Document($result['_id'], Set::flatten($result, '/'));
            if (!$Type->addDocument($Doc)) {
                return $this->err(
                    $Model,
                    'Unable to add document %s',
                    $result['_id']
                );
            }
        }

        return $ids;
    }

    protected function _queryParams ($Model, $queryParams, $keys) {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $queryParams) && ($opt = $this->opt($Model, $key))) {
                $queryParams[$key] = $opt;
            }
        }
        
        return $queryParams;
    }

    public function Query ($query, $queryParams) {
        $BoolQuery = new Elastica_Query_Bool();
        
        $FreeQuery = new Elastica_Query_QueryString($query);
        $BoolQuery->addMust($FreeQuery);

        $dims = Set::countDim($queryParams['enforce']);
        $enforcings = $queryParams['enforce'];
        if ($dims < 3) {
            $enforcings = array($enforcings);
        }

        $EnforceContainer = new Elastica_Query_Bool();

        // @todo enforcings are now all AND based.
        foreach ($enforcings as $enforcing) {
            foreach ($enforcing as $key => $val) {
                if (substr($key, 0 ,1) === '#' && is_array($val)) {
                    $args   = $val;
                    $Class  = array_shift($args);
                    $method = array_shift($args);

                    $val = call_user_func_array(array($Class, $method), $args);
                    // If null is returned, effictively remove key from enforce
                    // params
                    if ($val !== null) {
                        $enforcing[substr($key, 1)] = $val;
                    }
                    unset($enforcing[$key]);
                }
            }
            if (!empty($enforcing)) {
                $QueryEnforcer = new Elastica_Query_Term($enforcing);
                $BoolQuery->addMust($QueryEnforcer);
            }
        }

        //$BoolQuery->addMust($EnforceContainer);

        $Query = new Elastica_Query($BoolQuery);
        if ($queryParams['highlight']) {
            $Query->setHighlight($queryParams['highlight']);
        }
        if ($queryParams['limit']) {
            $Query->setLimit($queryParams['limit']);
        }
        if (@$queryParams['sort']) {
            $Query->setSort($sort);
        }
        
        return $Query;
    }

    /**
     * Search. Arguments can be different wether the call is made like
     *  - $Model->elastic_search, or
     *  - $this->search
     * that's why I eat&check away arguments with array_shift
     *
     * @return string
     */
    public function search () {
        $args = func_get_args();

        // Strip model from args if needed
        if (is_object(@$args[0])) {
            $LeadingModel = array_shift($args);
        } else if (is_string(@$args[0])) {
            $LeadingModel = ClassRegistry::init(array_shift($args));
        }
        if (empty($LeadingModel)) {
            return $this->err('First argument needs to be a valid model');
        }

        // Strip method from args if needed (e.g. when called via $Model->mappedMethod())
        if (is_string(@$args[0])) {
            foreach ($this->mapMethods as $pattern => $meth) {
                if (preg_match($pattern, $args[0])) {
                    $method = array_shift($args);
                    break;
                }
            }
        }

        // No query!
        if (!($query = array_shift($args))) {
            return;
        }

        // queryParams
        $queryParams = array_key_exists(0, $args) ? array_shift($args) : array();

        // All models
        $ManyModels = array_key_exists(0, $args) ? array_shift($args) : array();
        if (!$ManyModels) {
            $ManyModels = array($LeadingModel);
        }


        $queryParams = $this->_queryParams($LeadingModel, $queryParams, array(
            'enforce',
            'highlight',
            'limit',
        ));
        $enforcings = array();
        foreach ($ManyModels as $Model) {
            $qParams = $this->_queryParams($Model, $queryParams, array(
                'enforce',
            ));
            $enforcings[] = @$qParams['enforce'];
        }

        $queryParams['enforce'] = array_unique($enforcings);

        $Query = $this->Query($query, $queryParams);
        
        // Search documents
        try {
            // Get index
            list($Index, $Type) = $this->IndexType($LeadingModel);

            if (count($ManyModels) > 1) {
                // Index search
                $ResultSet = $Index->search($Query);
            } else {
                // Type search
                $ResultSet = $Type->search($Query);
            }

            return $ResultSet;
        } catch (Exception $Exception) {
            $msg = $Exception->getMessage();
            if ($this->opt($LeadingModel, 'debug_traces')) {
                $msg .= ' (' . $Exception->getTraceAsString() . ')';
            }

            return $msg;
        }
    }

    protected function _allFields ($modelAlias, $params) {
        $flats  = Set::flatten($params, '/');

        $fields = array();
        foreach ($flats as $flat => $field) {
            $flat = '/' . $flat;
            if (false !== ($pos = strpos($flat, '/fields'))) {
                $flat   = substr($flat, 0, $pos);
                $prefix = str_replace(array('/contain', '/fields', '/limit'), '' , $flat);

                if ($prefix === '') {
                    $prefix = '/' . $modelAlias;
                }

                $field  = $prefix . '/' . $field;
                
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Hack so you can now do highlights on '_all'.
     * Elasticsearch does not support that syntax for highlights yet,
     * just for queries.
     *
     * @param object $Model
     * @param array  $val
     * 
     * @return array
     */
    protected function _filter_highlight ($Model, $val) {
        if (($params = @$val['fields']['_all'])) {
            unset($val['fields']['_all']);
            if (false !== ($k = array_search('_no_all', $val['fields'], true))) {
                return $val;
            }

            if (!array_key_exists($Model->alias, $this->_fields)) {
                $this->_fields[$Model->alias] = $this->_allFields($Model->alias, $Model->opt('index_find_params'));
            }

            if (is_array($this->_fields[$Model->alias])) {
                foreach ($this->_fields[$Model->alias] as $field) {
                    if (substr($field, 0, 1) === '_') {
                        continue;
                    }
                    $val['fields'][$field] = $params;
                }
            }
        }

        return $val;
    }

    public function enabled ($Model, $method) {
        if ($this->opt($Model, 'searcher_enabled') === false) {
            return false;
        }
        return true;
    }

    public function setup ($Model, $settings = array()) {
        $this->settings[$Model->alias] = Set::merge(
            $this->_default,
            $settings
        );
    }

    public function err ($Model, $format, $arg1 = null, $arg2 = null, $arg3 = null) {
        $arguments = func_get_args();
        $Model     = array_shift($arguments);
        $format    = array_shift($arguments);

        $str = $format;
        if (count($arguments)) {
            foreach($arguments as $k => $v) {
                $arguments[$k] = $this->sensible($v);
            }
            $str = vsprintf($str, $arguments);
        }

        $this->errors[] = $str;

        if (@$this->settings[$Model->alias]['error_handler'] === 'php') {
            trigger_error($str, E_USER_ERROR);
        }

        return false;
    }

    public function sensible ($arguments) {
        if (is_object($arguments)) {
            return get_class($arguments);
        }
        if (!is_array($arguments)) {
            if (!is_numeric($arguments) && !is_bool($arguments)) {
                $arguments = "'" . $arguments . "'";
            }
            return $arguments;
        }
        $arr = array();
        foreach ($arguments as $key => $val) {
            if (is_array($val)) {
                $val = json_encode($val);
            } elseif (is_object($val)) {
                $val = get_class($val);
            } elseif (!is_numeric($val) && !is_bool($val)) {
                $val = "'" . $val . "'";
            }

            if (strlen($val) > 33) {
                $val = substr($val, 0, 30) . '...';
            }

            $arr[] = $key . ': ' . $val;
        }
        return join(', ', $arr);
    }

    public function opt () {
        $args  = func_get_args();

        // Strip model from args if needed
        if (is_object($args[0])) {
            $Model = array_shift($args);
        } else {
            return $this->err('First argument needs to be a model');
        }

        // Strip method from args if needed (e.g. when called via $Model->mappedMethod())
        if (is_string($args[0])) {
            foreach ($this->mapMethods as $pattern => $meth) {
                if (preg_match($pattern, $args[0])) {
                    $method = array_shift($args);
                    break;
                }
            }
        }

        $count = count($args);
        $key   = @$args[0];
        $val   = @$args[1];
        if ($count > 1) {
            $this->settings[$Model->alias][$key] = $val;
        } else if ($count > 0) {
            if (!array_key_exists($key, $this->settings[$Model->alias])) {
                return $this->err(
                    $Model,
                    'Option %s was not set',
                    $key
                );
            }

            $val = $this->settings[$Model->alias][$key];
            
            // Filter with callback
            $cb = array($this, '_filter_' . $key);
            if (method_exists($cb[0], $cb[1])) {
                $val = call_user_func($cb, $Model, $val);
            }

            return $val;
        } else {
            return $this->err(
                $Model,
                'Found remaining arguments: %s Opt needs more arguments (1 for Model; 1 more for getting, 2 more for setting)',
                $args
            );
        }
    }
}