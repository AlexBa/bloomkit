<?php

namespace Bloomkit\Core\Rest;

use Bloomkit\Core\Application\Application;
use Bloomkit\Core\Application\ModuleInterface;
use Bloomkit\Core\Security\Exceptions\AuthConfigException;
use Bloomkit\Core\Http\HttpEvent;
use Bloomkit\Core\Http\HttpEvents;
use Bloomkit\Core\Http\HttpExceptionEvent;
use Bloomkit\Core\Http\Exceptions\HttpNotFoundException;
use Bloomkit\Core\Routing\Exceptions\RessourceNotFoundException;
use Bloomkit\Core\Routing\RouteCollection;

class RestApplication extends Application
{
    /**
     * @var array
     */
    private $supportedVersions = ['v1'];

    /**
     * Constuctor.
     *
     * {@inheritdoc}
     */
    public function __construct($appName, $appVersion, $basePath = null, array $config = [])
    {
        parent::__construct($appName, $appVersion, $basePath, $config);

        $this->registerFactory('routes', 'Bloomkit\Core\Routing\RouteCollection', true);
        $this->registerFactory('exception_handler', 'Bloomkit\Core\Rest\ExceptionListener', true);
        $this->registerFactory('url_matcher', 'Bloomkit\Core\Routing\UrlMatcher', true);

        $this->setAlias('Bloomkit\Core\Routing\RouteCollection', 'routes');

        $this->bind('Psr\Log\LoggerInterface', 'Bloomkit\Core\Application\DummyLogger');

        $this->getEventManager()->addListener(HttpEvents::EXCEPTION, [$this['exception_handler'], 'onException']);
    }

    /**
     * Processing the request.
     *
     * @param RestRequest $request The request to process
     *
     * @return RestResponse The response to the request
     */
    private function process(RestRequest $request)
    {
        // Check if version is supported
        $apiVersion = $request->getApiVersion();
        if ('' == $apiVersion) {
            return RestResponse::createFault(400, 'Invalid request: The api version is missing', 31030);
        }
        if (false === array_search($apiVersion, $this->supportedVersions)) {
            return RestResponse::createFault(400, 'The requested api version is not supported by this server.', 31030);
        }

        $event = new HttpEvent($request);
        $this['eventManager']->triggerEvent(HttpEvents::REQUEST, $event);

        if ($event->hasResponse()) {
            $this['eventManager']->triggerEvent(HttpEvents::RESPONSE, $event);
            $this['eventManager']->triggerEvent(HttpEvents::FINISH_REQUEST, $event);

            return $event->getResponse();
        }

        try {
            //$tracer = $this->getTracer();
            //$tracer->start('App::findRoute');
            $matcher = $this->getUrlMatcher();
            $parameters = $matcher->match($request->getPathUrl(), $request->getHttpMethod());
            //$tracer->stop('App::findRoute');

            // Authentication
            if (isset($parameters['_auth'])) {
                $auth = $parameters['_auth'];

                if ((isset($auth['authEntryPoint'])) && (class_exists($auth['authEntryPoint']))) {
                    $this->get('firewall')->setAuthEntryPoint(new $auth['authEntryPoint']());
                }

                if (false == isset($auth['authenticator'])) {
                    throw new AuthConfigException(sprintf('"authenticator" parameter is missing in route-config for "%s"', $request->getPathUrl()));
                }
                if (false == isset($auth['userProvider'])) {
                    throw new AuthConfigException(sprintf('"userProvider" parameter is missing in route-config for "%s"', $request->getPathUrl()));
                }

                if (!class_exists($auth['authenticator'])) {
                    throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $auth['authenticator']));
                }
                if (!class_exists($auth['userProvider'])) {
                    throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $auth['userProvider']));
                }
                if (is_subclass_of($auth['userProvider'], 'Bloomkit\Core\Security\User\EntityUserProvider')) {
                    $userProvider = new $auth['userProvider']($this->entityManager);
                } else {
                    $userProvider = new $auth['userProvider']();
                }

                $authenticator = new $auth['authenticator']($userProvider);
                $token = $authenticator->createToken($request);

                if (false == $authenticator->supportsToken($token)) {
                    throw new \Exception('Token is not supported');
                }

                $token = $authenticator->authenticateToken($token, $userProvider);
                $this->getSecurityContext()->setToken($token);
            }

            $request->attributes->addItems($parameters);
            $controllerName = $parameters['_controller'];

            $this['eventManager']->triggerEvent(HttpEvents::CONTROLLER, $event);

            if (false === strpos($controllerName, '::')) {
                throw new \InvalidArgumentException(sprintf('Unable to find controller "%s".', $controllerName));
            }

            $controllerInfo = list($class, $method) = explode('::', $controllerName, 2);

            if (!class_exists($class)) {
                throw new \InvalidArgumentException(sprintf('Class "%s" does not exist.', $class));
            }

            if (is_array($controllerInfo)) {
                $r = new \ReflectionMethod($controllerInfo[0], $controllerInfo[1]);
            }

            $params = $r->getParameters();

            $attributes = $request->attributes->getItems();
            $arguments = [];

            foreach ($params as $param) {
                if (array_key_exists($param->name, $attributes)) {
                    $arguments[] = $attributes[$param->name];
                } elseif ($param->getClass() && $param->getClass()->isInstance($request)) {
                    $arguments[] = $request;
                } elseif ($param->isDefaultValueAvailable()) {
                    $arguments[] = $param->getDefaultValue();
                } else {
                    if (is_array($controller)) {
                        $repr = sprintf('%s::%s()', $controller[0], $controller[1]);
                    } elseif (is_object($controller)) {
                        $repr = get_class($controller);
                    } else {
                        $repr = $controller;
                    }
                    throw new \RuntimeException(sprintf('Controller "%s" requires that you provide a value for the "$%s" argument (because there is no default value or because there is a non optional argument after this one).', $repr, $param->name));
                }
            }

            $controller = new $class($this);
            $controller->setRequest($request);

            //$tracer->start('App::CallController');
            $response = call_user_func_array([$controller, $method], $arguments);
            //$tracer->stop('App::CallController');

            $this['eventManager']->triggerEvent(HttpEvents::VIEW, $event);
            $event->setResponse($response);
            $this['eventManager']->triggerEvent(HttpEvents::RESPONSE, $event);
            $this['eventManager']->triggerEvent(HttpEvents::FINISH_REQUEST, $event);

            return $response;
        } catch (RessourceNotFoundException $e) {
            $message = sprintf('No route found for "%s %s"', $request->getHttpMethod(), $request->getPathUrl());
            throw new HttpNotFoundException($message);
        } catch (MethodNotAllowedException $e) {
            $message = sprintf('No route found for "%s %s": Method Not Allowed (Allow: %s)', $request->getMethod(), $request->getPathUrl(), implode(', ', $e->getAllowedMethods()));
            throw new MethodNotAllowedHttpException($e->getAllowedMethods(), $message, $e);
        }
    }

    /**
     * Returns the url matcher.
     *
     * @return \Bloomkit\Core\Routing\UrlMatcher
     */
    public function getUrlMatcher()
    {
        return $this['url_matcher'];
    }

    /**
     * Handles an exception by trying to convert it to a Response.
     *
     * @param \Exception  $e
     * @param RestRequest $request
     *
     * @return RestResponse
     *
     * @throws \Exception
     */
    private function handleException(\Exception $e, RestRequest $request)
    {
        $event = new HttpExceptionEvent($request, $e);
        $this['eventManager']->triggerEvent(HttpEvents::EXCEPTION, $event);

        // a listener might have replaced the exception
        $e = $event->getException();

        if (!$event->hasResponse()) {
            throw $e;
        }

        return $event->getResponse();
    }

    /**
     * {@inheritdoc}
     */
    public function registerModule(ModuleInterface $module)
    {
        parent::registerModule($module);
        $routes = $module->getRoutes();

        if (($routes instanceof RouteCollection) && ($routes->getCount() > 0)) {
            $this['routes']->addCollection($routes);
        };
    }

    /**
     * Start the application.
     *
     * @param RestRequest $request The request to process.
     */
    public function run(RestRequest $request = null)
    {
        if (is_null($request)) {
            try {
                $request = RestRequest::processRequest();
            } catch (\Exception $e){
                $this['logger']->warning('Invalid request:'.$e->getMessage());
                $response = RestResponse::createFault(400, $e->getMessage(), $e->getCode());
                $response->send();
                exit;
            }
        }

        try {
            $response = $this->process($request);
        } catch (\Exception $e) {
            $response = $this->handleException($e, $request);
        }

        $event = new HttpEvent($request);
        $event->setResponse($response);
        $this['eventManager']->triggerEvent(HttpEvents::RESPONSE, $event);
        $response = $event->getResponse();
        $response->send();
        $this['eventManager']->triggerEvent(HttpEvents::TERMINATE, $event);
        exit;
    }

    /**
     * Set the supported API versions.
     *
     * The API version must be used in every request: https://example.com/api/v1/
     *
     * @param array $versions The API versions to support
     */
    public function setSupportedApiVersions(array $versions)
    {
        $this->supportedVersions = $versions;
    }
}