<?php

namespace Aerys\Start;

use Alert\Reactor,
    Alert\ReactorFactory,
    Auryn\Injector,
    Auryn\Provider,
    Aerys\Server;

class Bootstrapper {
    const SERVER_OPTION_PREFIX = '__';

    /**
     * Bootstrap a server and its host definitions from command line arguments
     *
     * @param \Aerys\Framework\BinOptions $binOptions
     * @throws \Aerys\Framework\StartException
     * @return array Returns three-element array of the form [$reactor, $server, $hostCollection]
     */
    function boot(BinOptions $binOptions = NULL) {
        $binOptions = $binOptions ?: (new BinOptions)->loadOptions();

        return ($configFile = $binOptions->getConfig())
            ? $this->buildFromFile($configFile)
            : $this->buildDocRoot($binOptions);
    }

    private function buildFromFile($configFile) {
        if (!include($configFile)) {
            throw new StartException(
                sprintf("Failed including config file: %s", $configFile)
            );
        }

        $apps = [];
        $reactors = [];
        $injectors = [];
        $options = [];

        $vars = get_defined_vars();

        foreach ($vars as $key => $value) {
            if ($value instanceof App) {
                $apps[] = $value;
            } elseif ($value instanceof Injector) {
                $injectors[] = $value;
            } elseif ($value instanceof Reactor) {
                $reactors[] = $value;
            } elseif (substr($key, 0, 2) === self::SERVER_OPTION_PREFIX) {
                $key = substr($key, 2);
                $options[$key] = $value;
            }
        }

        if (!$apps) {
            throw new StartException(
                sprintf('No app configuration instances found in config file: %s', $configFile)
            );
        } elseif (count($injectors) > 1) {
            throw new StartException(
                sprintf('Only one injector instance allowed in config file: %s', $configFile)
            );
        } elseif (count($reactors) > 1) {
            throw new StartException(
                sprintf('Only one event reactor instance allowed in config file: %s', $configFile)
            );
        }

        $reactor = $reactors ? end($reactors) : (new ReactorFactory)->select();
        $injector = $injectors ? end($injectors) : new Provider;

        return $this->generateBootables($reactor, $injector, $options, $apps);
    }

    private function generateBootables(Reactor $reactor, Injector $injector, array $options, array $apps) {
        $injector->alias('Alert\Reactor', get_class($reactor));
        $injector->share($reactor);

        $server = $injector->make('Aerys\Server');
        $injector->share($server);
        $injector->define('Aerys\Start\ResponderBuilder', [
            ':injector' => $injector
        ]);

        // Automatically register any ServerObserver implementations with the server
        $injector->prepare('Aerys\ServerObserver', function($observer) use ($server) {
            $server->addObserver($observer);
        });

        $hostBuilder = $injector->make('Aerys\Start\HostBuilder');
        $hostCollection = $injector->make('Aerys\HostCollection');

        foreach ($apps as $app) {
            $host = $hostBuilder->buildHost($app);
            $hostCollection->addHost($host);
        }

        $allowedOptions = array_map('strtolower', array_keys($server->getAllOptions()));
        foreach ($options as $key => $value) {
            if (in_array(strtolower($key), $allowedOptions)) {
                $server->setOption($key, $value);
            }
        }

        return [$reactor, $server, $hostCollection];
    }

    private function buildDocRoot(BinOptions $binOptions) {
        $docroot = realpath($binOptions->getRoot());

        if (!($docroot && is_dir($docroot) && is_readable($docroot))) {
            throw new StartException(
                sprintf('Invalid docroot path: %s', $options['root'])
            );
        }

        $app = (new App)->setDocumentRoot($docroot);

        if ($port = $binOptions->getPort()) {
            $app->setPort($port);
        }
        if ($ip = $binOptions->getIp()) {
            $app->setAddress($ip);
        }
        if ($name = $binOptions->getName()) {
            $app->setName($name);
        }

        $reactor = (new ReactorFactory)->select();
        $injector = new Provider;
        $options = [];
        $apps = [$app];

        return $this->generateBootables($reactor, $injector, $options, $apps);
    }
}