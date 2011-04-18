<?php
/**
 * Adds logging goodness, help framework, let's you create autoloaders, draw
 * CLI columns
 */
class TrueShell extends Shell {
	protected static $_autoLoaderPrefix = '';

	public static function createAutoloader ($path, $prefix = '') {
		self::$_autoLoaderPrefix = $prefix;
		set_include_path(get_include_path() . PATH_SEPARATOR . $path);
		spl_autoload_register(array('TrueShell', 'autoloader'));
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

	public function ownMethods ($filename, $search = null) {
		if (!file_exists($filename)) {
			return $this->err('Class source: %s not found', $filename);
		}

		$buf = file_get_contents($filename, FILE_IGNORE_NEW_LINES);
		if (!preg_match_all('/^[\t a-z]*function\s+?(.+)\s*\(/ismU', $buf, $matches)) {
			return array();
		}
		$methods = $matches[1];
		if ($search !== null) {
			return in_array($search, $methods);
		}

		return $methods;
	}


	public function main () {
		$this->help();
	}

	/**
	 * Help
	 */
	public function help () {
		$ownMethods = $this->ownMethods($this->Dispatch->shellPath);

		if (empty($this->hiddenPublics)) {
			$this->hiddenPublics = array();
		}

		$Class   = new ReflectionClass($this->name);
		$Methods = $Class->getMethods();

		foreach ($Methods as $i => $Method) {
			if (!$Method->isPublic() || !in_array($Method->name, $ownMethods) || substr($Method->name, 0, 1) === '_' || in_array($Method->name, $this->hiddenPublics)) {
				continue;
			}
			if (($lines = explode("\n", $Method->getDocComment())) && ($line = trim(@$lines[1], ' /*'))) {
				$methods[$Method->name]['line'] = $line;
			}

			$methods[$Method->name]['params'] = array();
			foreach ($Method->getParameters() as $ReflectorParam) {
				if ($ReflectorParam->isOptional()) {
					$t = $ReflectorParam->name . '=\'' . $ReflectorParam->getDefaultValue() . '\'';
					$t = '[' . $t . ']';
				} else {
					$t = $ReflectorParam->name;
				}
				$methods[$Method->name]['params'][] = $t;
			}
			$methods[$Method->name]['params'] = join(' ', $methods[$Method->name]['params']);
		}
		ksort($methods);

		$this->info('');
		$this->info('Usage: ');
		$this->info(' cakecons %s action [arguments]', basename($this->Dispatch->shellPath, '.php'));
		$this->info('');
		$this->info('Actions: ');
		foreach ($methods as $method => $info) {
			$this->info('  %-10s %-23s %s', $method, @$info['params'], @$info['line']);
		}

		$this->info('');
		return '';
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

	protected function _log ($level, $format, $arg1 = null, $arg2 = null, $arg3 = null) {
		$arguments = func_get_args();
		$level     = array_shift($arguments);
		$format    = array_shift($arguments);
		$str       = $format;

		if (count($arguments)) {
			foreach($arguments as $k => $v) {
				$arguments[$k] = $this->sensible($v, '');
			}
			$str = vsprintf($str, $arguments);
		}

		$this->out($level . ': ' . $str);
		return $str;
	}
	public function crit ($format, $arg1 = null, $arg2 = null, $arg3 = null) {
		$args = func_get_args(); array_unshift($args, __FUNCTION__);
		$str  = call_user_func_array(array($this, '_log'), $args);
		trigger_error($str, E_USER_ERROR);
		exit(1);
	}
	public function err ($format, $arg1 = null, $arg2 = null, $arg3 = null) {
		$args = func_get_args(); array_unshift($args, __FUNCTION__);
		$str  = call_user_func_array(array($this, '_log'), $args);
		trigger_error($str, E_USER_ERROR);
		return false;
	}
	public function warn ($format, $arg1 = null, $arg2 = null, $arg3 = null) {
		$args = func_get_args(); array_unshift($args, __FUNCTION__);
		$str  = call_user_func_array(array($this, '_log'), $args);
		return false;
	}
	public function info ($format, $arg1 = null, $arg2 = null, $arg3 = null) {
		$args = func_get_args(); array_unshift($args, __FUNCTION__);
		$str  = call_user_func_array(array($this, '_log'), $args);
		return true;
	}

	public function humanize ($str) {
		return Inflector::humanize(trim(str_replace('/', ' ', $str)));
	}

	public function columns ($data, $paths = null) {
		$columns  = array();
		$longCell = array();
		$rowCount = 0;

		// Detect paths..
		if ($paths === null) {
			// Get recursively flatened list of keys
			$allPaths = array();
			$flats = Set::flatten($data);
			$hasVal = array();
			foreach ($flats as $dots => $val) {
				$path = str_replace('.', '/', strpbrk($dots, '.'));
				if (!isset($allPaths[$path])) {
					$allPaths[$path] = $path;
				}

				if ($val) {
					$hasVal[$path] = true;
				}
			}

			// Unset empty colums
			foreach ($allPaths as $key => $path) {
				if (!isset($hasVal[$path])) {
					unset($allPaths[$key]);
				}
			}

			// Trim more columns, the deeper we're nested
			$prevbase  = '';
			$basecount = 0;
			$paths     = array();
			$nest      = 1;
			$basemax   = 10;
			$factor    = 3;
			$min       = 2;
			$max       = 10;
			foreach ($allPaths as $i => $path) {
				$base = substr($path, 0, strrpos($path, '/'));
				if (($newbase = $base !== $prevbase)) {
					$basecount = 0;
					$nest = substr_count($path, '/');
					$basemax = ($max - ceil($nest * $factor)) + $factor;
					$basemax = $basemax < $min ? $min : $basemax;
				}
				$basecount++;

				if ($basecount <= $basemax) {
					$paths[] = $path;
				}

				$prevbase = $base;
			}

			// Limit total columns just in case
			$paths = array_slice($paths, 0, 40);
		}

		// Compile headers
		foreach ($paths as $human => $path) {
			if (is_numeric($human)) {
				$human = $this->humanize($path);
			}
			$columns[$human] = Set::extract($path, $data);
			$longCell[$human] = 0;
			foreach($columns[$human] as $i => $val) {
				$val = $this->sensible($val, "'", '; ');
				if ($longCell[$human] < ($l = strlen($val))) {
					$longCell[$human] = $l;
				}
			}

			if ($rowCount < ($c = count($columns[$human]))) {
				$rowCount = $c;
			}
		}

		// Plot head
		$width  = 0;
		$this->out('| ', 0);
		foreach ($columns as $human => $rows) {
			$v = str_pad($human, $longCell[$human], ' ', STR_PAD_RIGHT);
			$v = $v . ' | ';
			$width += strlen($v);
			$this->out($v, 0);
		}
		$width++;
		$this->out('');
		$this->out(str_repeat('-', $width));

		// Plot body
		for ($i = 0; $i < $rowCount; $i++) {
			$this->out('| ', 0);
			foreach ($columns as $human => $rows) {
				$v = $this->sensible(@$rows[$i], "'", '; ');
				$v = str_pad($v, $longCell[$human], ' ', STR_PAD_RIGHT);
				$this->out($v . ' | ', 0);
			}
			$this->out('');
		}
	}
}