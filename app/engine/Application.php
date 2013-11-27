<?php
/*
  +------------------------------------------------------------------------+
  | PhalconEye CMS                                                         |
  +------------------------------------------------------------------------+
  | Copyright (c) 2013 PhalconEye Team (http://phalconeye.com/)            |
  +------------------------------------------------------------------------+
  | This source file is subject to the New BSD License that is bundled     |
  | with this package in the file LICENSE.txt.                             |
  |                                                                        |
  | If you did not receive a copy of the license and are unable to         |
  | obtain it through the world-wide-web, please send an email             |
  | to license@phalconeye.com so we can send you a copy immediately.       |
  +------------------------------------------------------------------------+
  | Author: Ivan Vorontsov <ivan.vorontsov@phalconeye.com>                 |
  +------------------------------------------------------------------------+
*/

namespace Engine;

use Engine\Config as EngineConfig;
use Engine\Api\Container as ApiContainer;
use Engine\Asset\Manager;
use Engine\Cache\Dummy;
use Engine\Db\Model\Annotations\Initializer as ModelAnnotationsInitializer;
use Engine\Package\Exception;
use Phalcon\Annotations\Adapter\Memory as AnnotationsMemory;
use Phalcon\Cache\Frontend\Data as CacheData;
use Phalcon\Cache\Frontend\Output as CacheOutput;
use Phalcon\Config;
use Phalcon\Db\Adapter\Pdo;
use Phalcon\Db\Profiler as DatabaseProfiler;
use Phalcon\DI;
use Phalcon\Flash\Direct as FlashDirect;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Logger\Adapter\File;
use Phalcon\Logger\Formatter\Line as FormatterLine;
use Phalcon\Logger;
use Phalcon\Mvc\Application as PhalconApplication;
use Phalcon\Mvc\Model\Manager as ModelsManager;
use Phalcon\Mvc\Model\MetaData\Strategy\Annotations as StrategyAnnotations;
use Phalcon\Mvc\Url;
use Phalcon\Session\Adapter\Files as SessionFiles;

/**
 * Application class.
 *
 * @category  PhalconEye
 * @package   Engine
 * @author    Ivan Vorontsov <ivan.vorontsov@phalconeye.com>
 * @copyright 2013 PhalconEye Team
 * @license   New BSD License
 * @link      http://phalconeye.com/
 *
 * @TODO      Refactor this.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Application extends PhalconApplication
{
    // System config location.
    const SYSTEM_CONFIG_PATH = '/app/config/engine.php';

    /**
     * Application configuration.
     *
     * @var Config
     */
    protected $_config;

    /**
     * Default module name.
     *
     * @var string
     */
    public static $defaultModule = 'core';

    /**
     * Loaders for different modes.
     *
     * @var array
     */
    private $_loaders = array(
        'normal' => array(
            'logger',
            'loader',
            'environment',
            'url',
            'cache',
            'annotations',
            'router',
            'database',
            'session',
            'flash',
            'engine'
        ),
        'mini' => array(
            'logger',
            'loader',
            'database',
            'session'
        ),
        'console' => array(
            'logger',
            'loader',
            'database',
            'cache',
            'engine'
        )
    );

    /**
     * Constructor.
     */
    public function __construct()
    {
        // Create default DI.
        $di = new DI\FactoryDefault();

        // Get config.
        $this->_config = include_once(ROOT_PATH . self::SYSTEM_CONFIG_PATH);

        if (!$this->_config->installed) {
            define('CHECK_REQUIREMENTS', true);
            require_once(PUBLIC_PATH . '/requirements.php');
        }

        // Store config in the Di container.
        $di->setShared('config', $this->_config);

        parent::__construct($di);
    }

    /**
     * Runs the application, performing all initializations
     *
     * @param string $mode Mode name.
     *
     * @return void
     */
    public function run($mode = 'normal')
    {
        if (empty($this->_loaders[$mode])) {
            $mode = 'normal';
        }

        // add default module and engine modules
        $modules = array(
            self::$defaultModule => true,
            'user' => true,
        );

        $enabledModules = $this->_config->get('modules');
        if (!$enabledModules) {
            $enabledModules = array(self::$defaultModule);
        } else {
            $enabledModules = $enabledModules->toArray();
        }

        $di = $this->_dependencyInjector;
        $config = $this->_config;

        $modules = array_merge($modules, $enabledModules);

        $di->set('modules', function () use ($modules) {
            return $modules;
        });

        // Set application event manager
        $eventsManager = new \Phalcon\Events\Manager();

        // Init services and engine system
        foreach ($this->_loaders[$mode] as $service) {
            $this->{'init' . $service}($di, $config, $eventsManager);
        }

        // register enabled modules
        $enabledModules = array();
        if (!empty($modules)) {
            foreach ($modules as $module => $enabled) {
                if (!$enabled) {
                    continue;
                }
                $moduleName = ucfirst($module);
                $enabledModules[$module] = array(
                    'className' => $moduleName . '\Bootstrap',
                    'path' => ROOT_PATH . '/app/modules/' . $moduleName . '/Bootstrap.php',
                );
            }

            if (!empty($enabledModules)) {
                $this->registerModules($enabledModules);
            }
        }

        // Set default services to the DI
        EventsManager::attachEngineEvents($eventsManager, $config);
        $this->setEventsManager($eventsManager);
        $di->setShared('eventsManager', $eventsManager);
        $di->setShared('app', $this);
    }

    /**
     * Get application output.
     *
     * @return string
     */
    public function getOutput()
    {
        return $this->handle()->getContent();
    }

    /**
     * Init loader.
     *
     * @param DI            $di            Dependency Injection.
     * @param Config        $config        Config object.
     * @param EventsManager $eventsManager Event manager.
     *
     * @return void
     */
    protected function initLoader($di, $config, $eventsManager)
    {
        $modules = $di->get('modules');
        foreach ($modules as $module => $enabled) {
            if (!$enabled) {
                continue;
            }
            $modulesNamespaces[ucfirst($module)] = $this->_config->application->modulesDir . ucfirst($module);
        }

        $modulesNamespaces['Engine'] = $config->application->engineDir;
        $modulesNamespaces['Plugin'] = $config->application->pluginsDir;
        $modulesNamespaces['Widget'] = $config->application->widgetsDir;
        $modulesNamespaces['Library'] = $config->application->librariesDir;

        $loader = new \Phalcon\Loader();
        $loader->registerNamespaces($modulesNamespaces);

        if ($config->application->debug && $config->installed) {
            $eventsManager->attach('loader', function ($event, $loader, $className) use ($di) {
                if ($event->getType() == 'afterCheckClass') {
                    $di->get('logger')->error("Can't load class '" . $className . "'");
                }
            });
            $loader->setEventsManager($eventsManager);
        }

        $loader->register();

        $di->set('loader', $loader);
    }

    /**
     * Init environment.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initEnvironment($di, $config)
    {
        set_error_handler(array('\Engine\Exception', 'normal'));
        register_shutdown_function(array('\Engine\Exception', 'shutdown'));
        set_exception_handler(array('\Engine\Exception', 'exception'));

        if ($config->application->debug && $config->application->profiler && $config->installed) {
            $profiler = new Profiler();
            $di->set('profiler', $profiler);
        }

        /**
         * The URL component is used to generate all kind of urls in the
         * application
         */
        $url = new Url();
        $url->setBaseUri($config->application->baseUri);
        $di->set('url', $url);
    }

    /**
     * Init annotations.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initAnnotations($di, $config)
    {
        $di->set('annotations', function () use ($config) {
            if (!$config->application->debug && isset($config->annotations)) {
                $annotationsAdapter = '\Phalcon\Annotations\Adapter\\' . $config->annotations->adapter;
                $adapter = new $annotationsAdapter($config->annotations->toArray());
            } else {
                $adapter = new AnnotationsMemory();
            }

            return $adapter;
        }, true);
    }

    /**
     * Init router.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initRouter($di, $config)
    {
        // Check installation.
        if (!$di->get('config')->installed) {
            $router = new \Phalcon\Mvc\Router\Annotations(false);
            $router->setDefaultModule(self::$defaultModule);
            $router->setDefaultNamespace('Core\Controller');
            $router->setDefaultController("Install");
            $router->setDefaultAction("index");
            $router->addModuleResource('core', 'Core\Controller\Install');
            $di->set('installationRequired', true);
            $di->set('router', $router);

            return;
        }

        $routerCacheKey = 'router_data.cache';

        $cacheData = $di->get('cacheData');
        $router = $cacheData->get($routerCacheKey);

        if ($config->application->debug || $router === null) {

            $saveToCache = ($router === null);

            // load all controllers of all modules for routing system
            $modules = $di->get('modules');

            //Use the annotations router
            $router = new \Phalcon\Mvc\Router\Annotations(false);
            $router->setDefaultModule(self::$defaultModule);
            $router->setDefaultNamespace(ucfirst(self::$defaultModule) . '\Controller');
            $router->setDefaultController("Index");
            $router->setDefaultAction("index");

            $router->add('/:module/:controller/:action', array(
                'module' => 1,
                'controller' => 2,
                'action' => 3,
            ));

            $router->notFound(array(
                'module' => self::$defaultModule,
                'namespace' => ucfirst(self::$defaultModule) . '\Controller',
                'controller' => 'error',
                'action' => 'show404'
            ));

            //Read the annotations from controllers
            foreach ($modules as $module => $enabled) {
                if (!$enabled) {
                    continue;
                }

                // Get all file names.
                $files = scandir($config->application->modulesDir . ucfirst($module) . '/Controller');

                // Iterate files.
                foreach ($files as $file) {
                    if ($file == "." || $file == "..") {
                        continue;
                    }

                    $controller = ucfirst($module) . '\Controller\\' . str_replace('Controller.php', '', $file);
                    if (strpos($file, 'Controller.php') !== false) {
                        $router->addModuleResource(strtolower($module), $controller);
                    }
                }
            }
            if ($saveToCache) {
                $cacheData->save($routerCacheKey, $router, 2592000); // 30 days cache
            }
        }

        $di->set('router', $router);
    }

    /**
     * Init logger.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initLogger($di, $config)
    {
        if ($config->application->logger->enabled) {
            $di->set('logger', function () use ($config) {
                $logger = new File($config->application->logger->path . "main.log");
                $formatter = new FormatterLine($config->application->logger->format);
                $logger->setFormatter($formatter);

                return $logger;
            });
        }
    }

    /**
     * Init database.
     *
     * @param DI            $di            Dependency Injection.
     * @param Config        $config        Config object.
     * @param EventsManager $eventsManager Event manager.
     *
     * @return void
     */
    protected function initDatabase($di, $config, $eventsManager)
    {
        if (!$config->installed) {
            return;
        }

        $adapter = '\Phalcon\Db\Adapter\Pdo\\' . $config->database->adapter;
        /** @var Pdo $connection */
        $connection = new $adapter(array(
            "host" => $config->database->host,
            "username" => $config->database->username,
            "password" => $config->database->password,
            "dbname" => $config->database->dbname,
        ));

        if ($config->application->debug) {
            // Attach logger & profiler.
            $logger = new File($config->application->logger->path . "db.log");
            $profiler = new DatabaseProfiler();

            $eventsManager->attach('db', function ($event, $connection) use ($logger, $profiler) {
                if ($event->getType() == 'beforeQuery') {
                    $statement = $connection->getSQLStatement();
                    $logger->log($statement, Logger::INFO);
                    $profiler->startProfile($statement);
                }
                if ($event->getType() == 'afterQuery') {
                    //Stop the active profile.
                    $profiler->stopProfile();
                }
            });

            if ($config->application->profiler && $di->has('profiler')) {
                $di->get('profiler')->setDbProfiler($profiler);
            }
            $connection->setEventsManager($eventsManager);
        }

        $di->set('db', $connection);
        $di->set('modelsManager', function () use ($config, $eventsManager) {
            $modelsManager = new ModelsManager();
            $modelsManager->setEventsManager($eventsManager);

            //Attach a listener to models-manager
            $eventsManager->attach('modelsManager', new ModelAnnotationsInitializer());

            return $modelsManager;
        }, true);

        /**
         * If the configuration specify the use of metadata adapter use it or use memory otherwise.
         */
        $di->set('modelsMetadata', function () use ($config) {
            if (!$config->application->debug && isset($config->metadata)) {
                $metaDataConfig = $config->metadata;
                $metadataAdapter = '\Phalcon\Mvc\Model\Metadata\\' . $metaDataConfig->adapter;
                $metaData = new $metadataAdapter($config->metadata->toArray());
            } else {
                $metaData = new \Phalcon\Mvc\Model\MetaData\Memory();
            }

            $metaData->setStrategy(new StrategyAnnotations());

            return $metaData;
        }, true);

    }

    /**
     * Init session.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initSession($di, $config)
    {
        if (!isset($config->application->session)) {
            $session = new SessionFiles();
        } else {
            $adapterClass = $config->application->session->adapter;
            $session = new $adapterClass($config->application->session->toArray());
        }
        $session->start();
        $di->set('session', $session, true);
    }

    /**
     * Init cache.
     *
     * @param DI     $di     Dependency Injection.
     * @param Config $config Config object.
     *
     * @return void
     */
    protected function initCache($di, $config)
    {
        if (!$config->application->debug) {
            // Get the parameters.
            $cacheAdapter = '\Phalcon\Cache\Backend\\' . $config->application->cache->adapter;
            $frontEndOptions = array('lifetime' => $config->application->cache->lifetime);
            $backEndOptions = $config->application->cache->toArray();
            $frontOutputCache = new CacheOutput($frontEndOptions);
            $frontDataCache = new CacheData($frontEndOptions);

            // Cache:View.
            $viewCache = new $cacheAdapter($frontOutputCache, $backEndOptions);
            $di->set('viewCache', $viewCache, false);

            // Cache:Output.
            $cacheOutput = new $cacheAdapter($frontOutputCache, $backEndOptions);
            $di->set('cacheOutput', $cacheOutput, true);

            // Cache:Data.
            $cacheData = new $cacheAdapter($frontDataCache, $backEndOptions);
            $di->set('cacheData', $cacheData, true);

            // Cache:Models.
            $cacheModels = new $cacheAdapter($frontDataCache, $backEndOptions);
            $di->set('modelsCache', $cacheModels, true);

        } else {
            // Create a dummy cache for system.
            // System will work correctly and the data will be always current for all adapters.
            $dummyCache = new Dummy(null);
            $di->set('viewCache', $dummyCache);
            $di->set('cacheOutput', $dummyCache);
            $di->set('cacheData', $dummyCache);
            $di->set('modelsCache', $dummyCache);
        }
    }

    /**
     * Init flash messages.
     *
     * @param DI $di Dependency Injection.
     *
     * @return void
     */
    protected function initFlash($di)
    {
        $di->set('flash', function () {
            $flash = new FlashDirect(array(
                'error' => 'alert alert-error',
                'success' => 'alert alert-success',
                'notice' => 'alert alert-info',
            ));

            return $flash;
        });

        $di->set('flashSession', function () {
            $flash = new FlashSession(array(
                'error' => 'alert alert-error',
                'success' => 'alert alert-success',
                'notice' => 'alert alert-info',
            ));

            return $flash;
        });
    }

    /**
     * Init engine.
     *
     * @param DI $di Dependency Injection.
     *
     * @return void
     */
    protected function initEngine($di)
    {
        foreach ($di->get('modules') as $module => $enabled) {
            if (!$enabled) {
                continue;
            }

            // initialize module api
            $di->setShared(strtolower($module), function () use ($module, $di) {
                return new ApiContainer($module, $di);
            });
        }

        $di->setShared('assets', new Manager($di));
    }

    /**
     * Clear application cache.
     *
     * @return void
     */
    public function clearCache()
    {
        $viewCache = $this->_dependencyInjector->get('viewCache');
        $cacheOutput = $this->_dependencyInjector->get('cacheOutput');
        $cacheData = $this->_dependencyInjector->get('cacheData');
        $modelsCache = $this->_dependencyInjector->get('modelsCache');
        $config = $this->_dependencyInjector->get('config');

        $keys = $viewCache->queryKeys();
        foreach ($keys as $key) {
            $viewCache->delete($key);
        }

        $keys = $cacheOutput->queryKeys();
        foreach ($keys as $key) {
            $cacheOutput->delete($key);
        }

        $keys = $cacheData->queryKeys();
        foreach ($keys as $key) {
            $cacheData->delete($key);
        }

        $keys = $modelsCache->queryKeys();
        foreach ($keys as $key) {
            $modelsCache->delete($key);
        }

        // Files deleter helper.
        $deleteFiles = function ($files) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        };

        // Clear files cache.
        $deleteFiles(glob($config->application->cache->cacheDir . '*'));

        // Clear view cache.
        $deleteFiles(glob($config->application->view->compiledPath . '*'));

        // Clear metadata cache.
        if ($config->metadata && $config->metadata->metaDataDir) {
            $deleteFiles(glob($config->metadata->metaDataDir . '*'));
        }

        // Clear annotations cache.
        if ($config->annotations && $config->annotations->annotationsDir) {
            $deleteFiles(glob($config->annotations->annotationsDir . '*'));
        }

        // Clear assets.
        $this->_dependencyInjector->getShared('assets')->clear();
    }

    /**
     * Save application config to file.
     *
     * @param Config|null $config Config object.
     *
     * @return void
     */
    public function saveConfig($config = null)
    {
        if ($config === null) {
            $config = $this->_config;
        }
        EngineConfig::save($config);
    }
}