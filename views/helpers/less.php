<?php
/**
 * Helper for using less.php with cakephp
 *
 * @author Òscar Casajuana <elboletaire@underave.net>
 * @author Aleix Fabra <aleixfabra@gmail.com>
 * @license Apache-2.0
 * @copyright Òscar Casajuana 2013-2015
 */
class LessHelper extends AppHelper
{
/**
 * {@inheritdoc}
 */
    public $helpers = array(
        'Html'
    );

/**
 * Default lessjs options. Some are defined on setOptions due to the need of using methods.
 *
 * @var array
 */
    public $lessjs_defaults = array(
        'env' => 'production'
    );

/**
 * Default lessc options. Some are defined on setOptions due to the need of using methods.
 *
 * @var array
 */
    private $parser_defaults = array(
        'compress' => true
    );

/**
 * The css path name, where the output files will be stored
 * (including all the cache generated by less.php, if enabled)
 *
 * @var string
 */
    private $css_path  = 'css';

/**
 * Initializes Lessc and cleans less and css paths
 *
 * {@inheritdoc}
 */
    public function __construct()
    {
        // Initialize oyejorge/less.php parser
        require_once ROOT . DS . 'app' . DS . 'vendors' . DS . 'less.php' . DS . 'lib' . DS . 'Less' . DS . 'Autoloader.php';
        Less_Autoloader::register();

        $this->css_path  = WWW_ROOT . trim($this->css_path, '/');
    }

/**
 * Compiles any less files passed and returns the compiled css.
 * In case of error, it will load less with the javascritp parser so you'll be
 * able to see any errors on screen. If not, check out the error.log file in your
 * CakePHP's logs folder.
 *
 * @param  mixed $less         The input .less file to be compiled or an array
 *                             of .less files
 * @param  array  $options     Options in 'js' key will be pased to the less.js
 *                             parser and options in 'parser' will be passed to the less.php parser
 * @param  array  $modify_vars Array of modify vars
 * @return string
 */
    public function less($less = 'styles.less', $options = array(), $modify_vars = array())
    {
        $options = $this->setOptions($options);
        $less    = (array)$less;

        if ($options['js']['env'] == 'development') {
            return $this->jsBlock($less, $options);
        }

        try {
            $css = $this->compile($less, $options['parser'], $modify_vars, $options['cache']);
            if (isset($options['tag']) && !$options['tag']) {
                return $css;
            }
            if (!$options['cache']) {
                return $this->Html->formatTemplate('style', array('content' => $css));
            }
            return $this->Html->css($css);
        }
        catch (Exception $e) {
            // env must be development in order to see errors on-screen
            if (Configure::read('debug')) {
                $options['js']['env'] = 'development';
            }

            $this->error = $e->getMessage();
            $this->log("Error compiling less file: " . $this->error, 'less');

            return $this->jsBlock($less, $options);
        }
    }

/**
 * Returns the required script and link tags to get less.js working
 *
 * @param  string $less The input .less file to be loaded
 * @param  array  $options An array of options to be passed to the `less` configuration var
 * @return string The link + script tags need to launch lesscss
 */
    public function jsBlock($less, array $options = array())
    {
        $return = '';
        $less   = (array)$less;

        // Append the user less files
        foreach ($less as $les) {
            $return .= $this->Html->meta('link', null, array(
                'link' => '/' . $les,
                'rel'  => 'stylesheet/less'
            ));
        }
        // Less.js configuration
        $return .= $this->Html->scriptBlock(sprintf('less = %s;', json_encode($options['js'], JSON_UNESCAPED_SLASHES)));
        // <script> tag for less.js file
        $return .= $this->Html->script($options['less']);

        return $return;
    }

/**
 * Compiles an input less file to an output css file using the PHP compiler
 * @param  array   $input       The input .less files to be compiled
 * @param  array   $options     Options to be passed to the php parser
 * @param  array   $modify_vars Less modify_vars
 * @param  boolean $cache       Whether to cache or not
 * @return string               If cache is not enabled will return the full CSS compiled.
 *                              Otherwise it will return the resulting filename from the compilation.
 */
    public function compile($input, $options = array(), $modify_vars = array(), $cache = true)
    {
        $to_parse = array();
        foreach ($input as $in) {
            $less = realpath(WWW_ROOT . $in);
            // If we have plugin notation (Plugin.less/file.less)
            // ensure to properly load the files
            list($plugin, $basefile) = pluginSplit($in, false);

            if (!empty($plugin) && $this->notFile($basefile)) {
                $less = realpath(App::pluginPath($plugin) . 'webroot' . DS . $basefile);

                if ($less !== false) {
                    $to_parse[$less] = $this->assetBaseUrl($plugin, $basefile);
                    continue;
                }
            }
            if ($less !== false) {
                $to_parse[$less] = '';
            } else {
                // Plugins without plugin notation (/plugin/less/file.less)
                list($plugin, $basefile) = $this->assetSplit($in);
                if ($file = $this->pluginAssetFile(array($plugin, $basefile))) {
                    $to_parse[$file] = $this->assetBaseUrl($plugin, $basefile);
                } else {
                    // Will probably throw a not found error
                    $to_parse[$in] = '';
                }
            }
        }

        if ($cache) {
            $options += array('cache_dir' => $this->css_path);
            return Less_Cache::Get($to_parse, $options, $modify_vars);
        }

        $lessc = new Less_Parser($options);

        foreach ($to_parse as $file => $path) {
            $lessc->parseFile($file, $path);
        }
        // ModifyVars must be called at the bottom of the parsing,
        // this way we're ensuring they override their default values.
        // http://lesscss.org/usage/#command-line-usage-modify-variable
        $lessc->ModifyVars($modify_vars);

        return $lessc->getCss();
    }

/**
 * Sets the less configuration var options based on the ones given by the user
 * and our default ones.
 *
 * Here's also where we define the import_callback used by less.php parser,
 * so it can find files successfully even if they're on plugin folders.
 *
 * @param array  $options An array of options containing our options combined with the ones for the parsers
 * @return array $options The resulting $options array
 */
    private function setOptions(array $options)
    {
        $this->parser_defaults = array_merge($this->parser_defaults, array(
            // The import callback ensures that if a file is not found in the
            // app's webroot, it will search for that file in its plugin's
            // webroot path
            'import_callback' => function($lessTree) {
                if ($path_and_uri = $lessTree->PathAndUri()) {
                    return $path_and_uri;
                }

                $file                    = $lessTree->getPath();
                list($plugin, $basefile) = $this->assetSplit($file);
                $file                    = $this->pluginAssetFile(array($plugin, $basefile));

                if ($file) {
                    return array(
                        $file,
                        $this->assetBaseUrl($plugin, $basefile)
                    );
                }

                return null;
            }
        ));

        if (empty($options['parser'])) {
            $options['parser'] = array();
        }
        $options['parser'] = array_merge($this->parser_defaults, $options['parser']);

        if (empty($options['js'])) {
            $options['js'] = array();
        }
        $options['js'] = array_merge($this->lessjs_defaults, $options['js']);

        if (empty($options['less'])) {
            $options['less'] = 'less.min';
        }

        if (!isset($options['cache'])) {
            $options['cache'] = true;
        }

        return $options;
    }

/**
 * Splits an asset URL
 *
 * @param  string $url Asset URL
 * @return array       The plugin as first key and the rest basefile as second key
 */
    private function assetSplit($url)
    {
        $basefile = ltrim(ltrim($url, '.'), '/');
        $exploded = explode('/', $basefile);
        $plugin   = Inflector::camelize(array_shift($exploded));
        $basefile = implode(DS, $exploded);

        return array(
            $plugin, $basefile
        );
    }

/**
 * Builds asset file path for a plugin based on url.
 *
 * @param string  $url Asset URL
 * @return string Absolute path for asset file
 */
    private function pluginAssetFile(array $url)
    {
        list($plugin, $basefile) = $url;

        if ($plugin) {
            return realpath(App::pluginPath($plugin) . 'webroot' . DS . $basefile);
        }

        return false;
    }

/**
 * Check if class name is not a file
 *
 * @param  string $basefile Class name
 * @return bool             Return true if not match with any file extension
 */
    private function notFile($basefile) {
        $extensions = array('less', 'css');

        if (in_array($basefile, $extensions)) {
            return false;
        }
        return true;
    }
}