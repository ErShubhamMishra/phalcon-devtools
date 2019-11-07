<?php
declare(strict_types=1);

/**
 * This file is part of the Phalcon Developer Tools.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Phalcon\DevTools;

use DirectoryIterator;
use Phalcon\Annotations\Adapter\Memory as AnnotationsMemory;
use Phalcon\Assets\Manager as AssetsManager;
use Phalcon\Cache;
use Phalcon\Cache\AdapterFactory;
use Phalcon\Config;
use Phalcon\Db\Adapter\Pdo\AbstractPdo;
use Phalcon\DevTools\Access\Manager as AccessManager;
use Phalcon\DevTools\Access\Policy\Ip as IpPolicy;
use Phalcon\DevTools\Elements\Menu\SidebarMenu;
use Phalcon\DevTools\Mvc\View\Engine\Volt\Extension\Php as PhpExt;
use Phalcon\DevTools\Mvc\View\NotFoundListener;
use Phalcon\DevTools\Resources\AssetsResource;
use Phalcon\DevTools\Scanners\Config as ConfigScanner;
use Phalcon\DevTools\Utils\DbUtils;
use Phalcon\DevTools\Utils\FsUtils;
use Phalcon\DevTools\Utils\SystemInfo;
use Phalcon\Di\DiInterface;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Flash\Direct as FlashDirect;
use Phalcon\Flash\Session as FlashSession;
use Phalcon\Logger;
use Phalcon\Logger\Adapter\Stream as FileLogger;
use Phalcon\Logger\Adapter\Syslog;
use Phalcon\Logger\Formatter\Line as LineFormatter;
use Phalcon\Mvc\Dispatcher as MvcDispatcher;
use Phalcon\Mvc\Dispatcher\Exception as DispatchErrorHandler;
use Phalcon\Mvc\Router\Annotations as AnnotationsRouter;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php;
use Phalcon\Mvc\View\Engine\Volt as VoltEngine;
use Phalcon\Registry;
use Phalcon\Session\Adapter\Stream as SessionStream;
use Phalcon\Session\Manager;
use Phalcon\Storage\SerializerFactory;
use Phalcon\Tag;
use Phalcon\Url as UrlResolver;

/**
 * @property DiInterface $di
 */
trait Initializable
{
    /**
     * Initialize the Application Events Manager.
     */
    protected function initEventsManager()
    {
        $this->di->setShared(
            'eventsManager',
            function () {
                $em = new EventsManager;
                $em->enablePriorities(true);

                return $em;
            }
        );
    }

    /**
     * Initialize the Application Config.
     */
    protected function initConfig()
    {
        $basePath = $this->basePath;
        $this->di->setShared(
            'config',
            function () use ($basePath) {
                $scanner = new ConfigScanner($basePath);
                $config = $scanner->load('config');

                if (ENV_PRODUCTION !== APPLICATION_ENV) {
                    $override = $scanner->scan(APPLICATION_ENV);
                    if ($override instanceof Config) {
                        $config->merge($override);
                    }
                }

                return $config;
            }
        );
    }

    /**
     * Initialize the Logger.
     */
    protected function initLogger()
    {
        $hostName = $this->hostName;
        $basePath = $this->basePath;

        $this->di->setShared(
            'logger',
            function () use ($hostName, $basePath) {
                $ptoolsPath = $basePath . DS . '.phalcon' . DS;
                if (is_dir($ptoolsPath) && is_writable($ptoolsPath)) {
                    $formatter = new LineFormatter("%date% {$hostName} php: [%type%] %message%", 'D j H:i:s');
                    $adapter    = new FileLogger($ptoolsPath . 'devtools.log');
                } else {
                    $formatter = new LineFormatter("[devtools@{$hostName}]: [%type%] %message%", 'D j H:i:s');
                    $adapter    = new Syslog('php://stderr');
                }

                $adapter->setFormatter($formatter);

                return new Logger('messages', ['main' => $adapter]);
            }
        );
    }

    /**
     * Initialize the Cache.
     *
     * The frontend must always be Phalcon\Cache\Frontend\Output and the service 'viewCache'
     * must be registered as always open (not shared) in the services container (DI).
     */
    protected function initCache()
    {
        $serializerFactory = new SerializerFactory();
        $adapterFactory    = new AdapterFactory($serializerFactory);

        $this->di->set(
            'viewCache',
            function () use ($adapterFactory) {
                $adapter = $adapterFactory->newInstance('stream');

                return new Cache($adapter);
            }
        );

        $this->di->setShared(
            'modelsCache',
            function () use ($adapterFactory) {
                $adapter = $adapterFactory->newInstance('stream');

                return new Cache($adapter);
            }
        );

        $this->di->setShared(
            'dataCache',
            function () use ($adapterFactory) {
                $adapter = $adapterFactory->newInstance('stream');

                return new Cache($adapter);
            }
        );
    }

    /**
     * Initialize the Volt Template Engine.
     */
    protected function initVolt()
    {
        $basePath = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;
        $that = $this;

        $this->di->setShared(
            'volt',
            function ($view, $di) use ($basePath, $ptoolsPath, $that) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var Config $voltConfig
                 */

                $volt = new VoltEngine($view, $di);
                $config = $this->getShared('config');

                $appCacheDir = $config->get('application', new Config)->get('cacheDir');
                $defaultCacheDir = sys_get_temp_dir() . DS . 'phalcon' . DS . 'volt';

                $voltConfig = null;
                if ($config->offsetExists('volt')) {
                    $voltConfig = $config->get('volt');
                } elseif ($config->offsetExists('view')) {
                    $voltConfig = $config->get('view');
                }

                if (!$voltConfig instanceof Config) {
                    $voltConfig = new Config([
                        'compiledExt'  => '.php',
                        'separator'    => '_',
                        'cacheDir'     => $appCacheDir ?: $defaultCacheDir,
                        'forceCompile' => ENV_DEVELOPMENT === APPLICATION_ENV,
                    ]);
                }

                $compiledPath = function ($templatePath) use (
                    $voltConfig,
                    $basePath,
                    $ptoolsPath,
                    $that
                ) {
                    /**
                     * @var DiInterface $this
                     * @var Config $voltConfig
                     */
                    if (0 === strpos($templatePath, $basePath)) {
                        $templatePath = substr($templatePath, strlen($basePath));
                    } elseif (0 === strpos($templatePath, $ptoolsPath . DS . 'src')) {
                        $templatePath = substr($templatePath, strlen($ptoolsPath . DS . 'src'));
                    }

                    $templatePath = trim($templatePath, '\\/');
                    $filename = str_replace(['\\', '/'], $voltConfig->get('separator', '_'), $templatePath);
                    $filename = basename($filename, '.volt') . $voltConfig->get('compiledExt', '.php');

                    $cacheDir = $that->getCacheDir($voltConfig);

                    return rtrim($cacheDir, '\\/') . DS . $filename;
                };

                $options = [
                    'path'  => $voltConfig->get('compiledPath', $compiledPath),
                    'always' => ENV_DEVELOPMENT === APPLICATION_ENV || boolval($voltConfig->get('forceCompile')),
                ];

                $volt->setOptions($options);
                $volt->getCompiler()->addExtension(new PhpExt);

                return $volt;
            }
        );
    }

    /**
     * get volt cache directory
     *
     * @param Config $voltConfig
     *
     * @return string
     */
    protected function getCacheDir(Config $voltConfig)
    {
        $appCacheDir = $this->di->getShared('config')->path('application.cacheDir');
        $cacheDir = $voltConfig->get('cacheDir', $appCacheDir);
        $defaultCacheDir = sys_get_temp_dir() . DS . 'phalcon' . DS . 'volt';

        if ($cacheDir && is_dir($cacheDir) && is_writable($cacheDir)) {
            return $cacheDir;
        }

        $this->di->getShared('logger')->warning(
            'Unable to initialize Volt cache dir: {cache}. Used temp path: {default}',
            [
                'cache'   => $cacheDir,
                'default' => $defaultCacheDir
            ]
        );

        if (!is_dir($defaultCacheDir)) {
            mkdir($defaultCacheDir, 0777, true);
        }

        return $defaultCacheDir;
    }

    /**
     * Initialize the View.
     */
    protected function initView()
    {
        $this->di->setShared(
            'view',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Registry $registry
                 */

                $view = new View;
                $registry = $this->getShared('registry');

                $view->registerEngines(
                    [
                        '.volt'  => $this->getShared('volt', [$view, $this]),
                        '.phtml' => Php::class
                    ]
                );

                $view->setViewsDir($registry->offsetGet('directories')->webToolsViews . DS)
                     ->setLayoutsDir('layouts' . DS)
                     ->setRenderLevel(View::LEVEL_AFTER_TEMPLATE);

                $em = $this->getShared('eventsManager');
                $em->attach('view', new NotFoundListener);

                $view->setEventsManager($em);

                return $view;
            }
        );
    }

    /**
     * Initialize the Annotations.
     */
    protected function initAnnotations()
    {
        $this->di->setShared(
            'annotations',
            function () {
                return new AnnotationsMemory;
            }
        );
    }

    /**
     * Initialize the Router.
     */
    protected function initRouter()
    {
        $ptoolsPath = $this->ptoolsPath;

        $this->di->setShared(
            'router',
            function () use ($ptoolsPath) {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                $router = new AnnotationsRouter(false);
                $router->removeExtraSlashes(true);
                $router->setDefaultAction('index');
                $router->setDefaultController('index');
                $router->setDefaultNamespace('Phalcon\DevTools\Web\Tools\Controllers');

                // @todo Use Path::normalize()
                $controllersDir = $ptoolsPath . DS . str_replace('/', DS, 'src/Web/Tools/Controllers');
                $dir = new DirectoryIterator($controllersDir);

                $resources = [];

                foreach ($dir as $fileInfo) {
                    if ($fileInfo->isDot() || false === strpos($fileInfo->getBasename(), 'Controller.php')) {
                        continue;
                    }

                    $controller = $fileInfo->getBasename('Controller.php');
                    $resources[] = $controller;
                }

                foreach ($resources as $controller) {
                    $router->addResource($controller);
                }

                $router->setEventsManager($em);
                //$router->notFound(['controller' => 'error', 'action' => 'route404']);

                return $router;
            }
        );
    }

    /**
     * Initialize the Url service.
     */
    protected function initUrl()
    {
        $this->di->setShared(
            'url',
            function () {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 */
                $config = $this->getShared('config');

                $url = new UrlResolver;

                if ($config->get('application', new Config)->offsetExists('baseUri')) {
                    $baseUri = $config->get('application', new Config)->get('baseUri');
                } elseif ($config->offsetExists('baseUri')) {
                    $baseUri = $config->get('baseUri');
                } else {
                    // @todo Log notice here
                    $baseUri = '/';
                }

                if ($config->get('application', new Config)->offsetExists('staticUri')) {
                    $staticUri = $config->get('application', new Config)->get('staticUri');
                } elseif ($config->offsetExists('staticUri')) {
                    $staticUri = $config->get('staticUri');
                } else {
                    // @todo Log notice here
                    $staticUri = '/';
                }

                $url->setBaseUri($baseUri);
                $url->setStaticBaseUri($staticUri);

                return $url;
            }
        );
    }

    /**
     * Initialize the Tag Service.
     */
    protected function initTag()
    {
        $this->di->setShared(
            'tag',
            function () {
                $tag = new Tag;

                $tag->setDocType(Tag::HTML5);
                $tag->setTitleSeparator(' :: ');
                $tag->setTitle('Phalcon WebTools');

                return $tag;
            }
        );
    }

    /**
     * Initialize the Dispatcher.
     */
    protected function initDispatcher()
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = $this->di->getShared('eventsManager');
        $access = $this->di->getShared('access');

        $this->di->setShared(
            'dispatcher',
            function () use ($eventsManager, $access) {
                $dispatcher = new MvcDispatcher;
                $dispatcher->setDefaultNamespace('Phalcon\DevTools\Web\Tools\Controllers');

                $eventsManager->attach('dispatch', $access, 1000);
                $eventsManager->attach('dispatch:beforeException', new DispatchErrorHandler, 999);

                $dispatcher->setEventsManager($eventsManager);

                return $dispatcher;
            }
        );
    }

    /**
     * Initialize the Assets Manager.
     */
    protected function initAssets()
    {
        $this->di->setShared(
            'assets',
            function () {
                return new AssetsManager;
            }
        );
    }

    /**
     * Initialize the Session Service.
     */
    protected function initSession()
    {
        $this->di->setShared(
            'session',
            function () {
                $session = new Manager();
                $files = new SessionStream([
                    'savePath' => '/tmp',
                ]);
                $session->setAdapter($files);

                return $session;
            }
        );
    }

    /**
     * Initialize the Flash Service.
     */
    protected function initFlash()
    {
        $cssClasses = [
            'error'   => 'alert alert-danger fade in',
            'success' => 'alert alert-success fade in',
            'notice'  => 'alert alert-info fade in',
            'warning' => 'alert alert-warning fade in',
        ];

        $this->di->setShared(
            'flash',
            function () use ($cssClasses) {
                $flash = new FlashDirect();
                $flash->setAutoescape(false);
                $flash->setCssClasses($cssClasses);

                return $flash;
            }
        );

        $this->di->setShared(
            'flashSession',
            function () use ($cssClasses) {
                $flash = new FlashSession();
                $flash->setAutoescape(false);
                $flash->setCssClasses($cssClasses);

                return $flash;
            }
        );
    }

    /**
     * Initialize the Database connection.
     */
    protected function initDatabase()
    {
        $this->di->setShared(
            'db',
            function () {
                /** @var DiInterface $this */
                $em = $this->getShared('eventsManager');

                if ($this->getShared('config')->offsetExists('database')) {
                    $config = $this->getShared('config')->get('database')->toArray();
                } else {
                    $dbname = sys_get_temp_dir() . DS . 'phalcon.sqlite';
                    $this->getShared('logger')->warning(
                        'Unable to initialize "db" service. Used Sqlite adapter at path: {path}',
                        ['path' => $dbname]
                    );

                    $config = [
                        'adapter' => 'Sqlite',
                        'dbname'  => $dbname,
                    ];
                }

                $adapter = 'Phalcon\Db\Adapter\Pdo\\' . $config['adapter'];
                unset($config['adapter']);

                /** @var AbstractPdo $connection */
                $connection = new $adapter($config);
                $connection->setEventsManager($em);

                return $connection;
            }
        );
    }

    /**
     * Initialize the Access Manager.
     */
    protected function initAccessManager()
    {
        $ptoolsIp = $this->ptoolsIp;

        $this->di->setShared(
            'access',
            function () use ($ptoolsIp) {
                $policy = new IpPolicy($ptoolsIp);
                return new AccessManager($policy);
            }
        );
    }

    /**
     * Initialize the global registry.
     */
    protected function initRegistry()
    {
        $basePath   = $this->basePath;
        $ptoolsPath = $this->ptoolsPath;
        $templatesPath = $this->templatesPath;

        $this->di->setShared(
            'registry',
            function () use ($basePath, $ptoolsPath, $templatesPath) {
                /**
                 * @var DiInterface $this
                 * @var Config $config
                 * @var FsUtils $fs
                 */
                $registry = new Registry;

                $config  = $this->getShared('config');
                $fs      = $this->getShared('fs');

                $basePath = $fs->normalize(rtrim($basePath, '\\/'));
                $ptoolsPath = $fs->normalize(rtrim($ptoolsPath, '\\/'));
                $templatesPath = $fs->normalize(rtrim($templatesPath, '\\/'));

                $requiredDirectories = [
                    'modelsDir',
                    'controllersDir',
                    'migrationsDir',
                ];

                $directories = [
                    'modelsDir'      => null,
                    'controllersDir' => null,
                    'migrationsDir'  => null,
                    'basePath'       => $basePath,
                    'ptoolsPath'     => $ptoolsPath,
                    'templatesPath'  => $templatesPath,
                    'webToolsViews'  => $fs->normalize($ptoolsPath . '/src/Web/Tools/Views'),
                    'resourcesDir'   => $fs->normalize($ptoolsPath . '/resources'),
                    'elementsDir'    => $fs->normalize($ptoolsPath . '/resources/elements')
                ];

                if (($application = $config->get('application')) instanceof Config) {
                    foreach ($requiredDirectories as $name) {
                        if ($possiblePath = $application->get($name)) {
                            if (!$fs->isAbsolute($possiblePath)) {
                                $possiblePath = $basePath . DS . $possiblePath;
                            }

                            $possiblePath = $fs->normalize($possiblePath);
                            if (is_readable($possiblePath) && is_dir($possiblePath)) {
                                $directories[$name] = $possiblePath;
                            }
                        }
                    }
                }

                $registry->offsetSet('directories', (object) $directories);

                return $registry;
            }
        );
    }

    /**
     * Initialize utilities.
     */
    protected function initUtils()
    {
        $this->di->setShared(
            'fs',
            function () {
                return new FsUtils;
            }
        );

        $this->di->setShared(
            'info',
            function () {
                return new SystemInfo;
            }
        );

        $this->di->setShared(
            'dbUtils',
            function () {
                return new DbUtils;
            }
        );

        $this->di->setShared(
            'resource',
            function () {
                return new AssetsResource;
            }
        );
    }

    /**
     * Initialize User Interface components (mostly HTML elements).
     */
    protected function initUi()
    {
        $that = $this;

        $this->di->setShared(
            'sidebar',
            function () use ($that) {
                /**
                 * @var Registry $registry
                 */
                $registry = $that->di->getShared('registry');
                $menuItems = $registry->offsetGet('directories')->elementsDir . DS . 'sidebar-menu.php';

                /** @noinspection PhpIncludeInspection */
                $menu = new SidebarMenu(include $menuItems);

                $menu->setDI($that->di);

                return $menu;
            }
        );
    }
}