<?php
/**
 * Adds a new action: searcher to existing controllers.
 *
 * @author    Kevin van Zonneveld <kvz@php.net>
 */
class SearcherComponent extends Object {
    public $Controller;
    public $LeadModel;
    public $settings = array();
    public $serializer;
    protected $_default = array(
        
    );

    public function initialize ($Controller, $settings = array()) {
        $this->settings = Set::merge(
            $this->_default,
            $settings
        );
    }
    
    public function startup ($Controller) {
        App::import('Lib', 'Elasticsearch.Elasticsearch');
        if ($this->opt('model') === '_all') {
            $Models = Elasticsearch::allModels(true);
            $this->LeadingModel = reset($Models);
        } else {
            $this->LeadingModel = $this->isEnabled($Controller);
            $Models = array($this->LeadingModel);
        }

        if (!$this->LeadingModel) {
            return null;
        }

        if ($Controller->action !== $this->mOpt($this->LeadingModel, 'searcher_action')) {
            return null;
        }

        if (!($query = @$Controller->passedArgs[$this->mOpt($this->LeadingModel, 'searcher_param')])) {
            if (!($query = @$Controller->data[$this->mOpt($this->LeadingModel, 'searcher_param')])) {
                return $this->err(
                    'No search query. '
                );
            }
        }

        $queryParams = array();
        
        $response = $this->search($query, $queryParams, $Models);

        return $this->respond($response);
    }

    public function search ($query, $queryParams, $Models) {
        $ResultSet = $this->LeadingModel->elastic_search($query, $queryParams, $Models);

        if (is_string($ResultSet)) {
            return $this->err('Error while doing search: %s', $ResultSet);
        }
        if (!$ResultSet) {
            return $this->err('Received an invalid ResultSet: %s', $ResultSet);
        }

        $response = array(
            'message' => 'OK',
            'query' => $query,
            'count' => $ResultSet->count(),
            'results' => array(),
        );
        while (($Result = $ResultSet->current())) {
            $id     = $Result->getId();
            $result = array(
                'data' => $Result->getData(),
                'highlights' => $Result->getHighlights(),
                'score' => $Result->getScore(),
                'type' => $Result->getType(),
                'id' => $id,
            );

            $result['id']    = @$result['data']['_id'];
            $result['label'] = @$result['data']['_label'];
            $result['descr'] = @$result['data']['_descr'];
            $result['url']   = @$result['data']['_url'];
            $result['model'] = @$result['data']['_model'];
            $result['category'] = @$result['data']['_model_title'];

            if (($html = @$result['highlights']['_label'][0])) {
                $result['html'] = $html;
            } else {
                $result['html'] = $result['label'];
            }

            // Add te response
            $response['results'][] = $result;

            $ResultSet->next();
        }

        // Trim down for jQuery autocomplete
        $response = @$response['results'] ? @$response['results'] : array();
        
        return $response;
    }

    public function err ($format, $arg1 = null, $arg2 = null, $arg3 = null) {
        $arguments = func_get_args();
        $format    = array_shift($arguments);

        $str = $format;
        if (count($arguments)) {
            foreach($arguments as $k => $v) {
                $arguments[$k] = is_scalar($v) ? $v : json_decode($v);
            }
            $str = vsprintf($str, $arguments);
        }

        return $this->respond(array(
            'errors' => explode('; ', $str),
        ));
    }
    
    public function respond ($response) {
        Configure::write('debug', 0);

        $serializer = $this->mOpt($this->LeadingModel, 'searcher_serializer');

        if (!is_callable($serializer)) {
            echo json_encode(array(
                'errors' => array('Serializer ' . $serializer . ' was not callable', ),
            ));
        } else {
            echo call_user_func($serializer, $response);
        }

        global $xhprof_on, $TIME_START, $profiler_namespace;
        if ($xhprof_on) {
            $xhprof_data  = xhprof_disable();
            $xhprof_runs  = new XHProfRuns_Default();
            $run_id       = $xhprof_runs->save_run($xhprof_data, $profiler_namespace);
            $parsetime = number_format(getmicrotime() - $TIME_START, 3);
            $xhprof = sprintf(
                'http://%s%s/xhprof/xhprof_html/index.php?run=%s&source=%s',
                $_SERVER['HTTP_HOST'],
                Configure::read('App.urlpath'),
                $run_id,
                $profiler_namespace
            );
            echo "<a href=\"".$xhprof ."\">xhprof</a>@".$parsetime."";
        }


        die();
    }

    /**
     * Returns appropriate Model or false on not active
     *
     * @return mixed Object or false on failure
     */
    public function isEnabled ($Controller) {
        if (!isset($Controller)) {
            return false;
        }
        if (!isset($Controller->modelClass)) {
            return false;
        }

        $modelName = $Controller->modelClass;
        if (!isset($Controller->$modelName)) {
            return false;
        }
        if (!is_object($Controller->$modelName)) {
            return false;
        }

        $Model = $Controller->$modelName;

        return Elasticsearch::isEnabledOnModel($Model);
    }
    
    public function mOpt ($Model, $key) {
        return @$Model->Behaviors->Searchable->settings[$Model->alias][$key];
    }
    
    public function opt ($key) {
        return @$this->settings[$key];
    }
}