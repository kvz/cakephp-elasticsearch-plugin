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
            return true;
        }

        if ($Controller->action !== $this->opt($Model, 'searcher_action')) {
            return true;
        }

        if (!($query = @$Controller->passedArgs[$this->opt($Model, 'searcher_param')])) {
            return $this->respond(array(
                'errors' => array('No search query')
            ));
        }

        $ResultSet = $Model->elastic_search($query);

        $data = array(
            'message' => 'OK',
            'count' => $ResultSet->count(),
            'results' => array(),
        );
        while (($Result = $ResultSet->current())) {
            $data['results'][] = $Result;
            $ResultSet->next();
        }

        return $this->respond($Model, $data);
    }

    public function respond ($Model, $data) {
        Configure::write('debug', 0);
        $serializer = $this->opt($Model, 'searcher_serializer');
        if (!is_callable($serializer)) {
            echo json_encode(array('errors' => array('Serializer ' . $serializer . ' was not callable')));
        } else {
            echo call_user_func($serializer, $data);
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
        if ($this->opt($Model, 'searcher_enabled') === false) {
            return false;
        }

        return $Model;
    }

    public function opt ($Model, $key) {
        return @$Model->Behaviors->Searchable->settings[$Model->alias][$key];
    }
}