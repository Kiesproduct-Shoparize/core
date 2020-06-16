<?php

namespace Benzine\Services;

use Benzine\App;
use Benzine\ORM\Connection\Database;
use Symfony\Component\Yaml\Yaml;

class ConfigurationService
{
    public const KEY_APP_NAME = 'application/name';
    public const KEY_APP_ROOT = 'application/root';
    public const KEY_DEBUG_ENABLE = 'application/debug';
    public const KEY_DEFAULT_ACCESS = 'application/default_access';
    public const KEY_TIMEZONE = 'application/timezone';
    public const KEY_LOCALE = 'application/locale';
    public const KEY_SESSION_ENABLED = 'application/session_enabled';
    public const KEY_LOG_FORMAT_DATE = 'logging/format_date';
    public const KEY_LOG_FORMAT_MESSAGE = 'logging/format_message';

    protected App $app;
    protected EnvironmentService $environmentService;
    protected string $appRoot;
    protected array $config;

    public function __construct(App $app, EnvironmentService $environmentService)
    {
        $this->app = $app;
        $this->environmentService = $environmentService;
        $this->findConfig();
        $this->setupDefines();
    }

    protected function setupDefines() : void
    {
        define("APP_ROOT", $this->appRoot);
        define("APP_NAME", $this->get('application/name'));
    }

    /**
     * Locate .benzine.yml
     * @param string|null $path
     */
    protected function findConfig(string $path = null) : bool {
        if(!$path){
            $path = getcwd();
            //$path = dirname($this->environmentService->get('SCRIPT_FILENAME'));
        }
        if(!file_exists($path . "/.benzine.yml")){
            $currentDirElem = explode(DIRECTORY_SEPARATOR, $path);
            array_pop($currentDirElem);
            $parentPath = implode(DIRECTORY_SEPARATOR, $currentDirElem);
            return $this->findConfig($parentPath);
        }

        $this->parseFile($path . "/.benzine.yml");
        $this->appRoot = $path;

        return true;
    }

    protected function parseFile(string $file)
    {
        $yaml = file_get_contents($file);
        foreach($this->environmentService->all() as $key => $value){
            if(is_string($value)) {
                $yaml = str_replace("\$$key", $value, $yaml);
            }
        }
        $this->config = Yaml::parse($yaml);
    }

    public function has(string $key) : bool {
        return $this->get($key) !== null;
    }
    /**
     * @param string $key
     * @param string|null $defaultValue
     * @return string|array|null
     */
    public function get(string $key, string $defaultValue = null){
        $scope = $this->config;
        foreach (explode('/', strtolower($key)) as $keyBit) {
            $scope = &$scope[$keyBit];
        }

        if (is_array($scope)) {
            return $scope;
        }

        if (!$scope) {
            return $defaultValue;
        }

        return trim($scope);
    }

    /**
     * @return Database[]
     */
    public function getDatabases() : array
    {
        $databases = [];
        foreach($this->config['databases'] as $name => $config){
               $database = new Database($name, $config);
               $databases[$database->getName()] = $database;
        }
        return $databases;
    }

    public function getNamespace(): string
    {
        $coreClass = explode("\\", $this->getCore());
        array_pop($coreClass);
        return implode("\\", $coreClass);
    }

    public function getCore(): string
    {
        return $this->get('application/core');
    }

    public function getAppName(): string
    {
        return $this->get('application/name');
    }

    public function getLaminatorTemplates(): array
    {
        return $this->get('laminator/templates')
            ?? ['Models', 'Services', 'Controllers', 'Endpoints', 'Routes'];
    }
}