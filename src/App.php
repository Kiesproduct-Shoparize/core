<?php

namespace Benzine;

use Benzine\ORM\Connection\Databases;
use Benzine\ORM\Laminator;
use Benzine\Redis\Redis;
use Benzine\Router\Router;
use Benzine\Services\ConfigurationService;
use Benzine\Services\EnvironmentService;
use Benzine\Services\SessionService;
use Benzine\Twig\Extensions;
use Cache\Adapter\Apc\ApcCachePool;
use Cache\Adapter\Apcu\ApcuCachePool;
use Cache\Adapter\Chain\CachePoolChain;
use Cache\Adapter\PHPArray\ArrayCachePool;
use Cache\Adapter\Redis\RedisCachePool;
use DebugBar\Bridge\MonologCollector;
use DebugBar\DataCollector\ExceptionsCollector;
use DebugBar\DataCollector\MemoryCollector;
use DebugBar\DataCollector\MessagesCollector;
use DebugBar\DataCollector\PhpInfoCollector;
use DebugBar\DataCollector\RequestDataCollector;
use DebugBar\DataCollector\TimeDataCollector;
use DebugBar\DebugBar;
use DI\Container;
use DI\ContainerBuilder;
use Faker\Factory as FakerFactory;
use Faker\Provider;
use Middlewares\TrailingSlash;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Symfony\Bridge\Twig\Extension as SymfonyTwigExtensions;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation;
use Tuupola\Middleware\ServerTimingMiddleware;
use Twig;
use Twig\Loader\FilesystemLoader;

class App
{
    public const DEFAULT_TIMEZONE = 'Europe/London';
    public static App $instance;

    protected EnvironmentService $environmentService;
    protected ConfigurationService $configurationService;
    protected \Slim\App $app;
    protected Logger $logger;
    protected DebugBar $debugBar;
    protected Router $router;
    protected bool $isSessionsEnabled = true;
    protected bool $interrogateControllersComplete = false;
    protected ?CachePoolChain $cachePoolChain = null;
    private array $viewPaths = [];
    private string $cachePath = APP_ROOT.'/cache';
    private string $logPath = APP_ROOT.'/logs';
    private array $supportedLanguages = ['en_US'];
    private bool $debugMode = false;

    private static bool $isInitialised = false;

    public function __construct()
    {
        if (!ini_get('auto_detect_line_endings')) {
            ini_set('auto_detect_line_endings', '1');
        }

        // Configure Dependency Injector
        $container = $this->setupContainer();
        $this->logger = $container->get(Logger::class);
        $this->debugBar = $container->get(DebugBar::class);
        AppFactory::setContainer($container);

        // If we're not on the CLI and Sessions ARE enabled...
        if ('cli' !== php_sapi_name() && $this->isSessionsEnabled) {
            // Call SessionService out of the container to force initialise it
            $container->get(SessionService::class);
        }

        // Configure default expected views paths
        $this->viewPaths[] = APP_ROOT.'/views/';
        $this->viewPaths[] = APP_ROOT.'/src/Views/';

        // Configure Slim
        $this->app = AppFactory::create();
        $this->app->add(Slim\Views\TwigMiddleware::createFromContainer($this->app));
        $this->app->addRoutingMiddleware();

        $this->setupMiddlewares($container);

        // Determine if we're going to enable debug mode
        $this->debugMode = $this->environmentService->get('DEBUG_MODE', 'off') == 'on';

        // Enable the slim error middleware if appropriate.
        if ($this->debugMode) {
            $this->app->addErrorMiddleware(true, true, true, $this->logger);
        }

        $this->debugBar['time']->startMeasure('interrogateTranslations', 'Time to interrogate translation files');
        $this->interrogateTranslations();
        $this->debugBar['time']->stopMeasure('interrogateTranslations');

        $this->app->add(new ServerTimingMiddleware());
    }

    public function getCachePath(): string
    {
        return $this->cachePath;
    }

    public function setCachePath(string $cachePath): App
    {
        $this->cachePath = $cachePath;

        return $this;
    }

    /**
     * @return array
     */
    public function getViewPaths(): array
    {
        return $this->viewPaths;
    }

    /**
     * @param array $viewPaths
     *
     * @return App
     */
    public function setViewPaths(array $viewPaths): App
    {
        $this->viewPaths = $viewPaths;

        return $this;
    }

    /**
     * @return string
     */
    public function getLogPath(): string
    {
        return $this->logPath;
    }

    /**
     * @param string $logPath
     *
     * @return App
     */
    public function setLogPath(string $logPath): App
    {
        $this->logPath = $logPath;

        return $this;
    }

    /**
     * Get item from Dependency Injection.
     */
    public function get(string $id)
    {
        return $this->getApp()->getContainer()->get($id);
    }

    public function setupContainer(): Container
    {
        $app = $this;
        $container =
            (new ContainerBuilder())
                ->useAutowiring(true)
                ->useAnnotations(true)
            ;
        //if ((new Filesystem())->exists($this->getCachePath())) {
        //   $container->enableCompilation($this->getCachePath());
        //   $container->writeProxiesToFile(true, "{$this->getCachePath()}/injection-proxies");
        //}

        $container = $container->build();

        $container->set(Slim\Views\Twig::class, function (
            EnvironmentService $environmentService,
            SessionService $sessionService,
            Translation\Translator $translator
        ) {
            foreach ($this->viewPaths as $i => $viewLocation) {
                if (!(new Filesystem())->exists($viewLocation) || !is_dir($viewLocation)) {
                    unset($this->viewPaths[$i]);
                }
            }

            $twigCachePath = "{$this->getCachePath()}/twig";
            $twigSettings = [];

            if ($environmentService->has('TWIG_CACHE') && 'on' == strtolower($environmentService->get('TWIG_CACHE'))) {
                $twigSettings['cache'] = $twigCachePath;
            }

            if (!(new Filesystem())->exists($twigCachePath)) {
                try {
                    (new Filesystem())->mkdir($twigCachePath, 0777);
                } catch (IOException $IOException) {
                    unset($twigSettings['cache']);
                    if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
                        $this->getLogger()->warning(sprintf('Could not create Twig cache (%s), Twig cache disabled ', $twigCachePath));
                    }
                }
            }

            $loader = new FilesystemLoader();

            foreach ($this->viewPaths as $path) {
                $loader->addPath($path);
            }

            $twig = new Slim\Views\Twig($loader, $twigSettings);

            $twig->addExtension(new Extensions\ArrayUniqueTwigExtension());
            $twig->addExtension(new Extensions\FilterAlphanumericOnlyTwigExtension());

            // Add coding string transform filters (ie: camel_case to StudlyCaps)
            $twig->addExtension(new Extensions\TransformExtension());

            // Add pluralisation/depluralisation support with singularize/pluralize filters
            $twig->addExtension(new Extensions\InflectionExtension());

            // Added Twig_Extension_Debug to enable twig dump() etc.
            $twig->addExtension(new Twig\Extension\DebugExtension());

            // Add Twig extension to integrate Kint
            $twig->addExtension(new \Kint\Twig\TwigExtension());

            // Add Twig extension to check if something is an instance of a known class or entity
            $twig->addExtension(new Extensions\InstanceOfExtension());

            // Add Twig Translate from symfony/twig-bridge
            $selectedLanguage = $sessionService->has('Language') ? $sessionService->get('Language') : 'en_US';
            $twig->addExtension(new SymfonyTwigExtensions\TranslationExtension($translator));
            $twig->offsetSet('language', $translator->trans($selectedLanguage));

            // Add Twig Intl Extension
            $twig->addExtension(new Twig\Extensions\IntlExtension());

            // Set some default parameters
            $twig->offsetSet('app_name', defined('APP_NAME') ? APP_NAME : 'APP_NAME not set');
            $twig->offsetSet('year', date('Y'));
            $twig->offsetSet('session', $sessionService);

            return $twig;
        });

        // This is required as some plugins for Slim expect there to be a twig available as "view"
        $container->set('view', function (Slim\Views\Twig $twig) {
            return $twig;
        });

        $container->set(Translation\Translator::class, function (SessionService $sessionService) {
            $selectedLanguage = $sessionService->has('Language') ? $sessionService->get('Language') : 'en_US';

            $translator = new Translation\Translator($selectedLanguage);

            // set default locale
            $translator->setFallbackLocales(['en_US']);

            // build the yaml loader
            $yamlLoader = new Translation\Loader\YamlFileLoader();

            // add the loader to the translator
            $translator->addLoader('yaml', $yamlLoader);

            // add some resources to the translator
            $translator->addResource('yaml', APP_ROOT."/src/Strings/{$selectedLanguage}.yaml", $selectedLanguage);

            return $translator;
        });

        $container->set(ConfigurationService::class, function (EnvironmentService $environmentService) use ($app) {
            return new ConfigurationService(
                $app,
                $environmentService
            );
        });

        $container->set(\Faker\Generator::class, function () {
            $faker = FakerFactory::create();
            $faker->addProvider(new Provider\Base($faker));
            $faker->addProvider(new Provider\DateTime($faker));
            $faker->addProvider(new Provider\Lorem($faker));
            $faker->addProvider(new Provider\Internet($faker));
            $faker->addProvider(new Provider\Payment($faker));
            $faker->addProvider(new Provider\en_US\Person($faker));
            $faker->addProvider(new Provider\en_US\Address($faker));
            $faker->addProvider(new Provider\en_US\PhoneNumber($faker));
            $faker->addProvider(new Provider\en_US\Company($faker));

            return $faker;
        });

        $container->set(CachePoolChain::class, function (Logger $logger, Redis $redis) {
            if (!$this->cachePoolChain) {
                $caches = [];

                // If apc/apcu present, add it to the pool
                if (function_exists('apcu_add')) {
                    $caches[] = new ApcuCachePool(true);
                } elseif (function_exists('apc_add')) {
                    $caches[] = new ApcCachePool(true);
                }

                // If Redis is configured, add it to the pool.
                if ($redis->isAvailable()) {
                    $caches[] = new RedisCachePool($redis->getUnderlyingRedis());
                }
                $caches[] = new ArrayCachePool();

                $this->cachePoolChain = new CachePoolChain($caches);
            }

            return $this->cachePoolChain;
        });

        $container->set('MonologFormatter', function (EnvironmentService $environmentService) {
            return new LineFormatter(
            // the default output format is "[%datetime%] %channel%.%level_name%: %message% %context% %extra%"
                $environmentService->get('MONOLOG_FORMAT', '[%datetime%] %channel%.%level_name%: %message% %context% %extra%')."\n",
                'Y n j, g:i a'
            );
        });

        $container->set(Logger::class, function (ConfigurationService $configurationService, EnvironmentService $environmentService) {
            $appName = $configurationService->get(ConfigurationService::KEY_APP_NAME);
            $logName = $environmentService->has('REQUEST_URI') ? sprintf('%s(%s)', $appName, $environmentService->get('REQUEST_URI')) : $appName;
            $monolog = new Logger($logName);
            $monolog->pushHandler(new StreamHandler(sprintf('%s/%s.log', $this->getLogPath(), strtolower($appName))));
            $monolog->pushHandler(new ErrorLogHandler(), Logger::DEBUG);
            $monolog->pushProcessor(new PsrLogMessageProcessor());

            return $monolog;
        });

        $container->set(Redis::class, function (Logger $logger, EnvironmentService $environmentService) {
            return new Redis(
                $logger,
                $environmentService->get('REDIS_HOST', 'redis'),
                $environmentService->get('REDIS_PORT', 6379),
                $environmentService->get('REDIS_TIMEOUT', 1.0)
            );
        });

        $container->set(Laminator::class, function (ConfigurationService $configurationService, Databases $databases) {
            return new Laminator(
                APP_ROOT,
                $configurationService,
                $databases
            );
        });

        $container->set(TrailingSlash::class, function () {
            return (new TrailingSlash())->redirect();
        });

        $container->set(DebugBar::class, function (Logger $logger) {
            return (new DebugBar())
                ->addCollector(new PhpInfoCollector())
                ->addCollector(new MessagesCollector())
                //->addCollector(new RequestDataCollector())
                ->addCollector(new TimeDataCollector())
                ->addCollector(new MemoryCollector())
                ->addCollector(new ExceptionsCollector())
                ->addCollector(new MonologCollector($logger, Logger::DEBUG))
            ;
        });

        $container->set(\Middlewares\Debugbar::class, function (DebugBar $debugBar) {
            return new \Middlewares\Debugbar(
                $debugBar
            );
        });

        $this->environmentService = $container->get(Services\EnvironmentService::class);
        if ($this->environmentService->has('TIMEZONE')) {
            date_default_timezone_set($this->environmentService->get('TIMEZONE'));
        } elseif ((new Filesystem())->exists('/etc/timezone')) {
            date_default_timezone_set(trim(file_get_contents('/etc/timezone')));
        } else {
            date_default_timezone_set(self::DEFAULT_TIMEZONE);
        }

        $this->router = $container->get(Router::class);

        //!\Kint::dump($this->environmentService->all());exit;
        return $container;
    }

    public function setupMiddlewares(ContainerInterface $container): void
    {
        // Middlewares
        //$this->app->add($container->get(\Middlewares\Geolocation::class));
        $this->app->add($container->get(\Middlewares\TrailingSlash::class));
        //$this->app->add($container->get(\Middlewares\Whoops::class));
        //$this->app->add($container->get(\Middlewares\Minifier::class));
        //$this->app->add($container->get(\Middlewares\GzipEncoder::class));
        $this->app->add($container->get(\Middlewares\ContentLength::class));
    }

    /**
     * @return self
     */
    public static function Instance()
    {
        if (!self::$isInitialised) {
            $calledClass = get_called_class();
            /** @var App $tempApp */
            $tempApp = new $calledClass();
            /** @var ConfigurationService $config */
            $config = $tempApp->get(ConfigurationService::class);
            $configCoreClass = $config->getCore();
            if ($configCoreClass != get_called_class()) {
                self::$instance = new $configCoreClass();
            } else {
                self::$instance = $tempApp;
            }

            self::$isInitialised = true;
        }

        return self::$instance;
    }

    /**
     * Convenience function to get objects out of the Dependency Injection Container.
     */
    public static function DI(string $key)
    {
        return self::Instance()->get($key);
    }

    public function getApp(): Slim\App
    {
        return $this->app;
    }

    public function addViewPath($path)
    {
        if ((new Filesystem())->exists($path)) {
            $this->viewPaths[] = $path;
        }

        return $this;
    }

    public static function Log($message, int $level = Logger::DEBUG)
    {
        return self::Instance()
            ->getLogger()
            ->log($level, ($message instanceof \Exception) ? $message->__toString() : $message)
        ;
    }

    public function runHttp(): void
    {
        $serverRequestCreator = ServerRequestCreatorFactory::create();
        $request = $serverRequestCreator->createServerRequestFromGlobals();

        $this->loadAllRoutes($request);

        $this->debugBar['time']->startMeasure('runHTTP', 'HTTP runtime');
        $this->app->run($request);

        if ($this->debugBar['time']->hasStartedMeasure('runHTTP')) {
            $this->debugBar['time']->stopMeasure('runHTTP');
        }
    }

    /**
     * @return string[]
     */
    public function getSupportedLanguages(): array
    {
        return $this->supportedLanguages;
    }

    /**
     * @param string[] $supportedLanguages
     */
    public function setSupportedLanguages(array $supportedLanguages): self
    {
        $this->supportedLanguages = $supportedLanguages;

        return $this;
    }

    public function addSupportedLanguage(string $supportedLanguage): self
    {
        $this->supportedLanguages[] = $supportedLanguage;
        $this->supportedLanguages = array_unique($this->supportedLanguages);

        return $this;
    }

    public function isSupportedLanguage(string $supportedLanguage): bool
    {
        return in_array($supportedLanguage, $this->supportedLanguages, true);
    }

    /**
     * @return mixed|Router
     */
    public function getRouter()
    {
        return $this->router;
    }

    /**
     * @param mixed|Router $router
     *
     * @return App
     */
    public function setRouter($router)
    {
        $this->router = $router;

        return $this;
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function loadAllRoutes(ServerRequestInterface $request): self
    {
        $this->debugBar['time']->startMeasure('interrogateControllers', 'Time to interrogate controllers for routes');
        $this->interrogateControllers();
        $this->debugBar['time']->stopMeasure('interrogateControllers');

        $timeToBootstrapMs = (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000;
        $bootstrapTooLongThresholdMs = 300;
        if ($timeToBootstrapMs >= $bootstrapTooLongThresholdMs) {
            $this->logger->warning(sprintf('Bootstrap complete in %sms which is more than the threshold of %sms', number_format($timeToBootstrapMs, 2), $bootstrapTooLongThresholdMs));
        }

        $this->router->populateRoutes($this->getApp(), $request);

        return $this;
    }

    protected function interrogateTranslations(): void
    {
        $stringPath = APP_ROOT.'/src/Strings';
        if (!(new Filesystem())->exists($stringPath)) {
            return;
        }
        foreach (new \DirectoryIterator($stringPath) as $translationFile) {
            if ('yaml' === $translationFile->getExtension()) {
                $languageName = substr($translationFile->getBasename(), 0, -5);
                $this->addSupportedLanguage($languageName);
            }
        }
    }

    protected function interrogateControllers(): void
    {
        if ($this->interrogateControllersComplete) {
            return;
        }
        $this->interrogateControllersComplete = true;

        if ($this->environmentService->has('ROUTE_CACHE')
            && 'on' === strtolower($this->environmentService->get('ROUTE_CACHE'))
            && $this->router->loadCache()
        ) {
            return;
        }

        $appClass = new \ReflectionClass(static::class);
        $this->router->loadRoutesFromAnnotations(
            [
                APP_ROOT.'/src/Controllers',
            ],
            $appClass->getNamespaceName()
        );

        $this->router->cache();

        $this->logger->info('ROUTE_CACHE miss.');
    }
}
