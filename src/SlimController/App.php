<?php

/*
 * This file is part of SlimController.
 *
 * @author Ulrich Kautz <uk@fortrabbit.de>
 * @copyright 2012 Ulrich Kautz
 * @version 0.1.2
 * @package SlimController
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SlimController;

/**
 * Extended Slim base
 */
class App extends \Slim\App
{
    /**
     * @var array
     */
    protected static $ALLOWED_HTTP_METHODS = array('ANY', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD');

    /**
     * @var array
     */
    protected $routeNames = array();

    /**
     * Add multiple controller based routes
     *
     * Simple Format
     * <code>
     * $app->addRoutes(array(
     *  '/some/path' => 'className:methodName'
     * ));
     * </code>
     *
     * With explicit HTTP method
     * <code>
     * $app->addRoutes(array(
     *  '/some/path' => array('get' => 'className:methodName')
     * ));
     * </code>
     *
     * With local middleware
     * <code>
     * $app->addRoutes(array(
     *  '/some/path'  => array('get' => 'className:methodName', function() {})
     *  '/other/path' => array('className:methodName', function() {})
     * ));
     * </code>
     *
     * With global middleware
     * <code>
     * $app->addRoutes(array(
     *  '/some/path'  => 'className:methodName',
     * ), function() {});
     * </code>
     *
     * @param array $routes The route definitions
     * @param array $globalMiddlewares
     * @throws \InvalidArgumentException
     * @internal param $callable ,... $middlewares Optional callable used for all routes as middleware
     *
     * @return $this
     */
    public function addRoutes(array $routes, $globalMiddlewares = array())
    {
        if (!is_array($globalMiddlewares)) {
            if (func_num_args() > 2) {
                $args = func_get_args();
                $globalMiddlewares = array_slice($args, 1);
            } else {
                $globalMiddlewares = array($globalMiddlewares);
            }
        }

        foreach ($routes as $path => $routeArgs) {
            // create array for simple request
            $routeArgs = (is_array($routeArgs)) ? $routeArgs : array('any' => $routeArgs);

            if (array_keys($routeArgs) === range(0, count($routeArgs) - 1)) {
                // route args is a sequential array not associative
                $routeArgs = array('any' => array($routeArgs[0],
                    isset($routeArgs[1]) && is_array($routeArgs[1]) ? $routeArgs[1] : array_slice($routeArgs, 1))
                );
            }

            foreach ($routeArgs as $httpMethod => $classArgs) {
                // assign vars if middleware callback exists
                if (is_array($classArgs)) {
                    $classRoute       = $classArgs[0];
                    $localMiddlewares = is_array($classArgs[1]) ? $classArgs[1] : array_slice($classArgs, 1);
                } else {
                    $classRoute       = $classArgs;
                    $localMiddlewares = array();
                }

                // specific HTTP method
                $httpMethod = strtoupper($httpMethod);
                if (!in_array($httpMethod, static::$ALLOWED_HTTP_METHODS)) {
                    throw new \InvalidArgumentException("Http method '$httpMethod' is not supported.");
                }
                
                if ('any' === $httpMethod) {
                    $httpMethod = static::$ALLOWED_HTTP_METHODS;
                } else {
                    $httpMethod = $httpMethod; //ahuaehuae
                }

                $routeMiddlewares = array_merge($localMiddlewares, $globalMiddlewares);
                $route = $this->addControllerRoute($path, $classRoute, $routeMiddlewares, $httpMethod);

            }
        }

        return $this;
    }

    /**
     * Add a new controller route
     *
     * <code>
     * $app->addControllerRoute("/the/path", "className:methodName", array(function () { doSome(); }))
     *  ->via('GET')->condition(..);
     *
     * $app->addControllerRoute("/the/path", "className:methodName")
     * ->via('GET')->condition(..);
     * </code>
     *
     * @param string     $path
     * @param string     $route
     * @param callable[] $middleware,...
     *
     * @return \Slim\Route
     */
    public function addControllerRoute($path, $route, array $middleware = array(), $methods = "GET")
    {
        $callback = $this->buildCallbackFromControllerRoute($route);

        array_unshift($middleware, $path);
        array_push($middleware, $callback);

        if (is_string($methods))
            $this->$methods($path, $callback); 
            
        if (is_array($methods))
            foreach ($methods as $method)
                $this->$method($path, $callback); 
        
        return $route;
    }

    /**
     * Builds closure callback from controller route
     *
     * @param $route
     *
     * @return \Closure
     */
    protected function buildCallbackFromControllerRoute($route)
    {
        list($controller, $methodName) = $this->determineClassAndMethod($route);
        $app      = $this;
        $callable = function () use ($app, $controller, $methodName) {
            // Get action arguments
            $args = func_get_args();

            // Try to fetch the instance from Slim's container, otherwise lazy-instantiate it
            $instance = $app->getContainer()->has($controller) 
                    ? $app->getContainer()->get($controller) 
                    : new $controller($app);

            return call_user_func_array(array($instance, $methodName), $args);
        };

        return $callable;
    }

    /**
     * @param string $classMethod
     *
     * @return array
     * @throws \InvalidArgumentException
     */
    protected function determineClassAndMethod($classMethod)
    {
        // determine class prefix (eg "\Vendor\Bundle\Controller") and suffix (eg "Controller")
        $classNamePrefix = $this->config('controller.class_prefix');
        if ($classNamePrefix && substr($classNamePrefix, -strlen($classNamePrefix) !== '\\')) {
            $classNamePrefix .= '\\';
        }
        $classNameSuffix = $this->config('controller.class_suffix') ? : '';

        // determine method suffix or default to "Action"
        $methodNameSuffix = $this->config('controller.method_suffix');
        if (is_null($methodNameSuffix)) {
            $methodNameSuffix = 'Action';
        }
        $realClassMethod  = $classMethod;
        if (strpos($realClassMethod, '\\') !== 0) {
            $realClassMethod = $classNamePrefix . $classMethod;
        }

        // having <className>:<methodName>
        if (preg_match('/^([a-zA-Z0-9\\\\_]+):([a-zA-Z0-9_]+)$/', $realClassMethod, $match)) {
            $className  = $match[1] . $classNameSuffix;
            $methodName = $match[2] . $methodNameSuffix;
        } // malformed
        else {
            throw new \InvalidArgumentException(
                "Malformed class action for '$classMethod'. Use 'className:methodName' format."
            );
        }

        return array($className, $methodName);
    }

    /* Reimplement config method from slim Framework 2 */
    function config($name, $value = null)
    {
        if (! is_null($value))
            return $this->getContainer()->set($name, $value);
        else
            if ($this->getContainer()->has($name))
                return $this->getContainer()->get($name);
            else
                return null;
    }
    
    public function urlFor($a)
    {
        return $this->getContainer()->get("router")->pathFor($a);
    }
    
}
