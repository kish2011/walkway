<?php

/**
 * Walkway
 * =======
 *
 * A modular router for PHP.
 *
 * @author Rasmus Schultz <http://blog.mindplay.dk>
 * @license GPL3 <http://www.gnu.org/licenses/gpl-3.0.txt>
 */

namespace mindplay\walkway;

use Closure;
use ArrayAccess;
use ReflectionFunction;
use ReflectionParameter;

/**
 * This class represents an individual Route: a part of a path (referred to as a token)
 * and a set of patterns to be matched and mapped against nested Route definition-functions.
 *
 * It implements ArrayAccess as the method for defining patterns/functions.
 *
 * It also implements a collection of HTTP method-handlers (e.g. GET, PUT, POST, DELETE)
 * which can be defined and accessed via e.g. <code>$get</code> and other properties.
 *
 * @property Closure|null $get
 * @property Closure|null $head
 * @property Closure|null $post
 * @property Closure|null $put
 * @property Closure|null $delete
 */
class Route implements ArrayAccess
{
    /**
     * @var mixed[] map of parameter-names to values collected during traversal
     */
    public $vars = array();

    /**
     * @var Module the Module to which this Route belongs
     */
    public $module;

    /**
     * @var Route|null parent Route; or null if this is the root-Route.
     */
    public $parent;

    /**
     * @var string the token that was matched when this Route was constructed.
     */
    public $token;

    /**
     * @var string the (partial) path associated with this Route.
     */
    public $path;

    /**
     * @var Closure[] map of patterns to Route definition-functions
     * @see resolve()
     */
    protected $patterns = array();

    /**
     * @var Closure[] map of method-names to functions
     * @see Request::execute()
     */
    protected $methods = array();

    /**
     * @param $module Module parent Module
     * @param $parent Route parent Route
     * @param $token string the token (partial path) that was matched when this Route was constructed.
     */
    protected function setParent(Module $module, Route $parent, $token)
    {
        $this->module = $module;
        $this->parent = $parent;
        $this->token = $token;

        $this->path = ($parent === null || $parent->path === '')
            ? $token
            : "{$parent->path}/{$token}";
    }

    /**
     * @param string $message
     * @see $onLog
     */
    public function log($message)
    {
        if ($log = $this->module->onLog) {
            $log($message);
        }
    }

    /**
     * @see ArrayAccess::offsetSet()
     */
    public function offsetSet($pattern, $init)
    {
        $this->log("define pattern: {$pattern}");
        $this->patterns[$pattern] = $init;
    }

    /**
     * @see ArrayAccess::offsetExists()
     */
    public function offsetExists($pattern)
    {
        return isset($this->patterns[$pattern]);
    }

    /**
     * @see ArrayAccess::offsetUnset()
     */
    public function offsetUnset($pattern)
    {
        unset($this->patterns[$pattern]);
    }

    /**
     * @see ArrayAccess::offsetGet()
     */
    public function offsetGet($pattern)
    {
        return $this->patterns[$pattern];
    }

    /**
     * @param string $name
     * @return Closure
     */
    public function __get($name)
    {
        $name = strtolower($name);

        return isset($this->methods[$name]) ? $this->methods[$name] : null;
    }

    /**
     * @param string $name
     * @param Closure $value
     */
    public function __set($name, $value)
    {
        $this->log("define method: {$name}");
        $name = strtolower($name);
        $this->methods[$name] = $value;
    }

    /**
     * Follow a (relative) path, walking from this Route to a destination Route.
     *
     * @param $path string relative path
     *
     * @return Route|null returns the resolved Route, or null if no Route was matched
     *
     * @throws RoutingException if a bad Route is encountered
     */
    public function resolve($path)
    {
        /** @var string $part partial path being resolved in the current iteration */
        $part = trim($path, '/'); // trim leading/trailing slashes

        /** @var bool $matched indicates whether the last partial path matched a pattern */
        $matched = true; // assume success (empty path will successfully resolve as root)

        /** @var Route $route the current route (switches as we walk through each token in the path) */
        $route = $this; // track the current Route, starting from $this

        $iteration = 0;

        while ($part) {
            $iteration += 1;

            $this->log("* resolving partial path '{$part}' (iteration {$iteration} of path '{$path}')");

            if (count($route->patterns) === 0) {
                $this->log("end of routes - no match found");
                return null;
            }

            $matched = false; // assume failure

            foreach ($route->patterns as $pattern => $init) {
                /**
                 * @var string $pattern the pattern, with substitutions applied
                 * @var Closure $init route initialization function
                 */

                // apply pattern-substitutions:

                $pattern = $this->module->preparePattern($pattern);

                $this->log("testing pattern '{$pattern}'");

                /** @var int|bool $match result of preg_match() against $pattern */
                $match = @preg_match('#^' . $pattern . '(?=$|/)#i', $part, $matches);

                if ($match === false) {
                    throw new RoutingException("invalid pattern '{$pattern}' (preg_match returned false)", $init);
                }

                if ($match !== 1) {
                    continue; // this pattern was not a match - continue with the next pattern
                }

                $matched = true;

                /** @var string $token the matched token */
                $token = array_shift($matches);

                $this->log("token '{$token}' matched by pattern '{$pattern}'");

                $route = $this->createRoute($route, $token, $init);

                // identify named variables:

                if (count($matches)) {
                    $total = 0;
                    $last = 0;

                    foreach ($matches as $key => $value) {
                        if (is_int($key)) {
                            $last = $key;
                            continue;
                        }

                        $this->log("captured named variable '{$key}' as '{$value}'");

                        $route->vars[$key] = $value;

                        $total += 1;
                    }

                    if ($total - 1 !== $last) {
                        throw new RoutingException('pattern defines an unnamed substring capture: ' . $pattern);
                    }
                }

                // initialize the nested Route:

                if ($route->invoke($init) === false) {
                    // the function explicitly aborted the route
                    $this->log("aborted");
                    return null;
                }

                break; // skip any remaining patterns
            }

            if (isset($token)) {
                // remove previous token from remaining part of path:
                $part = substr($part, strlen($token) + 1);
            } else {
                break;
            }
        }

        return $matched ? $route : null;
    }

    /**
     * @param $method string name of HTTP method-handler to execute (e.g. 'get', 'put', 'post', 'delete', etc.)
     *
     * @return mixed|bool the value returned by the HTTP method-handler; true if the method-handler returned
     *                    no value - or false if the method-handler was not found (or returned false)
     */
    public function execute($method = 'get')
    {
        $func = $this->__get($method);

        if ($func === null) {
            return false; // method-handler not found
        }

        $result = $this->invoke($func);

        return $result === null
            ? true // method-handler executed but returned no value
            : $result; // method-handler returned a result
    }

    /**
     * @param Route   $parent
     * @param string  $token
     * @param Closure $init initialization function
     *
     * @return Route
     */
    protected function createRoute(Route $parent, $token, Closure $init)
    {
        /** @var ReflectionFunction $ref reflection of the Route initialization-function */
        $ref = new ReflectionFunction($init);

        /** @var ReflectionParameter $mod_param reflection of Module-type to be injected */
        $mod_param = null;

        foreach ($ref->getParameters() as $param) {
            if ($param->getClass() && $param->getClass()->isSubClassOf(__NAMESPACE__ . '\\Module')) {
                $mod_param = $param;
                break;
            }
        }

        if ($mod_param) {
            // switch to the Module found in the arguments:

            /* @var string $class intermediary variable holding the class-name of a Module-type to be injected */
            $class = $mod_param->getClass()->name;

            $this->log("switching to Module: {$class}");

            /** @var Module $module */
            $module = new $class();

            $module->vars[$mod_param->name] = $module;

            $module->setParent($module, $parent, $token);

            return $module;
        } else {
            // switch to a nested Route:

            $route = new Route();

            $route->vars = $parent->vars;

            $route->setParent($parent->module, $parent, $token);

            return $route;
        }
    }

    /**
     * Invoke a function using variables collected during traversal.
     *
     * @param Closure $func the function to be invoked.
     *
     * @return mixed the value returned by the invoked function
     *
     * @throws InvocationException
     *
     * @see $vars
     */
    protected function invoke($func)
    {
        /**
         * @var $params mixed[] the list of parameters to be applied to $func
         */

        $fn = new ReflectionFunction($func);

        $params = array();

        foreach ($fn->getParameters() as $param) {
            switch ($param->name) {
                case 'route':
                    $params[] = $this;
                    continue;

                case 'module':
                    $params[] = $this->module;
                    continue;

                default:
                    if (! array_key_exists($param->name, $this->vars)) {
                        throw new InvocationException("missing parameter: \${$param->name}");
                    }

                    $params[] = $this->vars[$param->name];
            }
        }

        return call_user_func_array($func, $params);
    }

    /*
    public function __destruct()
    {
        unset($this->module);
        unset($this->parent);

        foreach ($this->vars as $key => $value) {
            unset($this->vars[$key]);
        }

        unset($this->vars);

        foreach ($this->patterns as $key => $value) {
            unset($this->patterns[$key]);
        }

        unset($this->patterns);

        foreach ($this->methods as $key => $value) {
            unset($this->methods[$key]);
        }

        unset($this->methods);

        echo "- OUT OF SCOPE -\n";
    }
    */
}
