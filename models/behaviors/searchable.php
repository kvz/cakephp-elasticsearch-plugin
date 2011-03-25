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
        'debug_traces' => false,
        'searcher_enabled' => true,
        'searcher_action' => 'searcher',
        'searcher_param' => 'q',
        'searcher_serializer' => 'json_encode',
        'auto_update' => false,
        'index_find_params' => array(),
        'index_name' => 'main',
        'error_handler' => 'php',
        'want_objects' => false,
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

    public function IndexType ($Model, $create = false) {
        $Index = $this->Client()->getIndex($this->opt($Model, 'index_name'));
        if ($create) {
            $Index->create(array(), true);
        }
        $Type     = $Index->getType(Inflector::underscore($Model->alias));

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

        // Bam
        if (!($indexParams = $this->opt($Model, 'index_find_params'))) {
            $indexParams = array();
        }

        // Create index
        list($Index, $Type) = $this->IndexType($Model, true);

        // Get records
        $Model->Behaviors->attach('Containable');
        $results = $Model->find('all', $indexParams);

        // Add documents
        $ids = array();
        foreach ($results as $result) {
            if (empty($result[$Model->alias][$Model->primaryKey])) {
                return $this->err(
                    $Model,
                    'I need at least primary key: %s->%s inside the index data. Please include in the index_find_params',
                    $Model->alias,
                    $Model->primaryKey
                );
            }
            $id    = $result[$Model->alias][$Model->primaryKey];
            $ids[] = $id;
            $Doc   = new Elastica_Document($id, Set::flatten($result, '/'));
            $Type->addDocument($Doc);
        }

        // Index needs a moment to be updated
        $Index->refresh();

        return $ids;
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
            $Model = array_shift($args);
        } else if (is_string(@$args[0])) {
            $Model = ClassRegistry::init(array_shift($args));
        }
        if (empty($Model)) {
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
        $queryParams = @$args[0]  ? array_shift($args) : array();

        // Get index
        list($Index, $Type) = $this->IndexType($Model);


        // Search documents
        try {
            $Query = new Elastica_Query(new Elastica_Query_QueryString($query));

            if (array_key_exists('highlight', $queryParams)) {
                $highlight = $queryParams['highlight'];
            } else if (($opt = $this->opt($Model, 'highlight'))) {
                $highlight = $opt;
            }

            if (@$highlight) {
                $Query->setHighlight($highlight);
            }

            $limit = @$queryParams['limit'];
            if ($limit) {
                $Query->setLimit($limit);
            }

            $filters = @$queryParams['filters'];
            if ($filters) {
                $Query->addFilter($filters);
            }

            $sort = @$queryParams['sort'];
            if ($sort) {
                $Query->setSort($sort);
            }

            $ResultSet = $Type->search($Query);

            return $ResultSet;
        } catch (Exception $Exception) {
            $msg = $Exception->getMessage();
            if ($this->opt($Model, 'debug_traces')) {
                $msg .= ' (' . $Exception->getTraceAsString() . ')';
            }

            return $msg;
        }
    }


    protected function _filter_highlight ($Model, $val) {
        if (($params = @$val['fields']['_all'])) {
            unset($val['fields']['_all']);

            if (!array_key_exists($Model->alias, $this->_fields)) {
                $this->_fields[$Model->alias] = false;
                if (($ResultSet = $this->search($Model, '*', array('limit' => 1))) && ($results = $ResultSet->getResults()) && ($result = @$results[0])) {
                    $this->_fields[$Model->alias] = array_keys($result->getData());
                }
            }

            if (is_array($this->_fields[$Model->alias])) {
                foreach ($this->_fields[$Model->alias] as $field) {
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