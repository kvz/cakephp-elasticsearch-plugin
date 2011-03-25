<?php
/**
 * 
 *
 * @author    Kevin van Zonneveld <kvz@php.net>
 */
class SearcherComponent extends Object {
    public $Controller;

    public function startup ($Controller, $settings = array()) {
        if (!($Model = $this->isEnabled($Controller))) {
            return null;
        }
        if ($Controller->action !== $this->opt($Model, 'searcher_action')) {
            return null;
        }

        if (!($query = @$Controller->passedArgs[$this->opt($Model, 'searcher_param')])) {
            return $this->err($Model, 'No search query');
        }
        $ResultSet = $Model->elastic_search($query);

        if (is_string($ResultSet)) {
            return $this->err($Model, 'Error while doing search: %s', $ResultSet);
        }
        if (!$ResultSet) {
            return $this->err($Model, 'Received an invalid ResultSet: %s', $ResultSet);
        }

        $response = array(
            'message' => 'OK',
            'count' => $ResultSet->count(),
            'results' => array(),
        );
        while (($Result = $ResultSet->current())) {
            $id = $Result->getId();
            if (array_key_exists($id, $response['results'])) {
                // If id is not suitable for indexing, use
                // incremental index.
                $id = count($response['results']);
            }

            $response['results'][$id] = array(
                'data' => $Result->getData(),
                'highlights' => $Result->getHighlights(),
                'score' => $Result->getScore(),
                'type' => $Result->getType(),
                'id' => $Result->getId(),
            );
            $ResultSet->next();
        }

        return $this->respond($Model, $response);
    }

    public function err ($Model, $format, $arg1 = null, $arg2 = null, $arg3 = null) {
        $arguments = func_get_args();
        $Model     = array_shift($arguments);
        $format    = array_shift($arguments);

        $str = $format;
        if (count($arguments)) {
            foreach($arguments as $k => $v) {
                $arguments[$k] = is_scalar($v) ? $v : json_decode($v);
            }
            $str = vsprintf($str, $arguments);
        }

        return $this->respond($Model, array(
            'errors' => explode('; ', $str),
        ));
    }
    
    public function respond ($Model, $response) {
        Configure::write('debug', 0);
        $serializer = $this->opt($Model, 'searcher_serializer');
        if (!is_callable($serializer)) {
            echo json_encode(array(
                'errors' => array('Serializer ' . $serializer . ' was not callable', ), 
            ));
        } else {
            echo call_user_func($serializer, $response);
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
        if (!$Model->Behaviors->attached('Searchable')) {
            return false;
        }
        if (!$Model->elastic_enabled()) {
            return false;
        }

        return $Model;
    }

    public function opt ($Model, $key) {
        return @$Model->Behaviors->Searchable->settings[$Model->alias][$key];
    }
}