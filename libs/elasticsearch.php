<?php
Class Elasticsearch {
    /**
     * Goes through filesystem and returns all models that have
     * elasticsearch enabled.
     *
     * @return <type>
     */
    public static function allModels ($instantiated = false) {
        $models = array();
        foreach (glob(MODELS . '*.php') as $filePath) {
            $base  = basename($filePath, '.php');
            $class = Inflector::classify($base);

            // Hacky, but still better than instantiating all Models:
            $buf = file_get_contents($filePath);
            if (false !== stripos($buf, 'Elasticsearch.Searchable')) {
                $Model = ClassRegistry::init($class);
                if (!self::isEnabledOnModel($Model)) {
                    continue;
                }
                if ($instantiated) {
                    $models[] = $Model;
                } else {
                    $models[] = $class;
                }
            }
        }
        
        return $models;
    }

    /**
     * Returns appropriate Model or false on not active
     *
     * @return mixed Object or false on failure
     */
    public static function isEnabledOnModel ($Model) {
        if (!$Model->Behaviors->attached('Searchable')) {
            return false;
        }
        if (!$Model->elastic_enabled()) {
            return false;
        }

        return $Model;
    }
}