<?php

namespace Lucent;

use Lucent\Commandline\DocumentationController;
use Lucent\Commandline\MigrationController;
use Lucent\Commandline\UpdateController;
use Lucent\Facades\CommandLine;
use Lucent\Facades\FileSystem;
use Lucent\Http\HttpResponse;
use Lucent\Http\RouteInfo;
use Lucent\Logging\Channel;
use Lucent\Commandline\CliRouter;
use Lucent\Http\HttpRouter;
use Lucent\Http\JsonResponse;
use Lucent\Http\Request;
use Lucent\Logging\NullChannel;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * Main Application class responsible for handling HTTP requests, console commands,
 * routing, and managing the application lifecycle.
 *
 * This class implements a singleton pattern and serves as the central
 * coordination point for the Lucent framework.
 */
class Application
{
    /**
     * HTTP router instance for handling web requests
     *
     * @var HttpRouter
     */
    public private(set) HttpRouter $httpRouter;

    /**
     * CLI router instance for handling console commands
     *
     * @var CliRouter
     */
    public private(set) CliRouter $consoleRouter;

    /**
     * Array of registered route files
     *
     * @var array
     */
    private array $routes = [];

    /**
     * Array of registered command files
     *
     * @var array
     */
    private array $commands = [];

    /**
     * Singleton instance of the Application
     *
     * @var Application|null
     */
    private static ?Application $instance = null;

    /**
     * Singleton instance of service classes
     *
     * @var array
     */
    public private(set) array $services = [];

    /**
     * Environment variables loaded from .env file
     *
     * @var array
     */
    private array $env;

    /**
     * Registered logging channels
     *
     * @var array<string, Channel>
     */
    public private(set) array $loggers = [];

    /**
     * Registered error pages
     *
     * @var array<string, HttpResponse>
     */
    public private(set) array $errorPageResponses;

    /**
     * Fallback route when no error page or route is set.
     */
    public private(set) HttpResponse $fallbackResponse;

    /**
     * An array of globally accessible regex rules.
     */
    public private(set) array $regexRules = [
        'password' => [
            "pattern" => '/^(?=.*[a-z])(?=.*[A-Z]).{8,}$/',
            "message" => "Password must contain at least one lowercase letter, one uppercase letter, and be at least 8 characters long.",
        ],
        'email' => [
            "pattern" => '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
            "message" => "Email address must be a valid email address. (test@example.com)",
        ],
        'date' => [
            "pattern" => '/^\d{4}-\d{2}-\d{2}$/',
            "message" => "Date must be in YYYY-MM-DD format.",
        ],
        'url' => [
            "pattern" => '/^(https?:\/\/)?([\da-z\.-]+)\.([a-z\.]{2,6})([\/\w \.-]*)*\/?$/',
            "message" => "URL must be a valid web address.",
        ],
        'phone' => [
            "pattern" => '/^\+?[1-9]\d{1,14}$/',
            "message" => "Phone number must be in a valid international format.",
        ],
        'ip' => [
            "pattern" => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
            "message" => "Must be a valid IPv4 address.",
        ],
        'hex_color' => [
            "pattern" => '/^#?([a-fA-F0-9]{6}|[a-fA-F0-9]{3})$/',
            "message" => "Must be a valid HEX color code (e.g., #FFF or #FFFFFF).",
        ],
        'uuid' => [
            "pattern" => '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            "message" => "Must be a valid UUID.",
        ],
        'alpha' => [
            "pattern" => '/^[a-zA-Z]+$/',
            "message" => "Must contain only letters.",
        ],
        'alphanumeric' => [
            "pattern" => '/^[a-zA-Z0-9]+$/',
            "message" => "Must contain only letters and numbers.",
        ]
    ];

    /**
     * An array of globally accessible failed message errors.
     */
    private array $ruleMessages = [
        "min" => ":attribute must be at least :min characters",
        "max" => ":attribute may not be greater than :max characters",
        "min_num" => ":attribute must be greater than :min",
        "max_num" => ":attribute may not be less than :max",
        "same" => ":attribute and :second must match"
    ];

    /**
     * An array of globally applicable middleware thats ran for all requests.
     */
    private array $globalMiddlewares = [];

    /**
     * Initialize a new Application instance
     *
     * Sets up HTTP and CLI routers, ensures .env file exists,
     * loads environment variables, and initializes a null logger.
     */
    public function __construct(){

        //Create our router instance
        $this->httpRouter = new HttpRouter();
        $this->consoleRouter = new CliRouter();

        //Check if we have a .env file present, if not
        //then we create the file.
        if(!file_exists(FileSystem::rootPath().DIRECTORY_SEPARATOR.".env")){
            $file = fopen(FileSystem::rootPath().DIRECTORY_SEPARATOR.".env","w");
            fclose($file);
        }

        //Load the env file
        $this->LoadEnv();

        $this->loggers["blank"] = new NullChannel();
    }

    /**
     * Register a new logging channel
     *
     * @param string $key Channel identifier
     * @param Channel $log Logger instance
     * @return void
     */
    public function addLoggingChannel(string $key, Channel $log): void
    {
        $this->loggers[$key] = $log;
    }

    /**
     * Get a logging channel by key
     *
     * Returns the null logger if the requested channel doesn't exist
     *
     * @param string $key Channel identifier
     * @return Channel Logger instance
     */
    public function getLoggingChannel(string $key) : Channel
    {
        if(!array_key_exists($key, $this->loggers)){
            return $this->loggers["blank"];
        }
        return $this->loggers[$key];
    }

    /**
     * Boot the application
     *
     * Loads all registered routes and commands
     *
     * @return void
     */
    public function boot(): void
    {
        foreach ($this->routes as $route){
            $this->httpRouter->loadRoutes($route["file"]);
        }

        foreach ($this->commands as $command){
            require_once FileSystem::rootPath().DIRECTORY_SEPARATOR.$command;
        }
    }

    /**
     * Get environment variables
     *
     * @return array Environment variables
     */
    public function getEnv(): array
    {
        return $this->env;
    }

    /**
     * Get or create the singleton Application instance
     *
     * @return Application The singleton instance
     */
    public static function getInstance(): Application
    {
        if(Application::$instance == null){
            Application::$instance = new Application();
        }

        return Application::$instance;
    }

    /**
     * Register a route file
     *
     * @param string $route Path to route file
     * @return void
     */
    public function loadRoutes(string $route): void
    {
        $this->routes[] = ["file" => $route];
    }

    /**
     * Execute an HTTP request
     *
     * Process incoming HTTP request by:
     * 1. Booting the application
     * 2. Analyzing and looking up the requested route
     * 3. Validating controller and method
     * 4. Running middleware
     * 5. Performing route model binding
     * 6. Executing the controller method
     *
     * @return string JSON response
     * @throws ReflectionException
     */
    public function executeHttpRequest(): string
    {
        $this->boot();

        $response = $this->httpRouter->AnalyseRouteAndLookup($this->httpRouter->GetUriAsArray($_SERVER["REQUEST_URI"]));
        $request = new Request();

        // Check if route was found - if not, use fallback or 404
        if (!$response["outcome"]) {
            // If we have a fallback response and no route was found, use it
            if (isset($this->fallbackResponse)) {
                http_response_code(200);
                return $this->fallbackResponse->render();
            }
            // Otherwise, return 404 error
            return $this->responseWithError(404);
        }

        // Verify controller exists before trying to instantiate it
        if (!class_exists($response["controller"])) {
            return $this->responseWithError(500);
        }

        $controllerReflection =  new ReflectionClass($response["controller"]);
        $controllerConstructor = $controllerReflection->getConstructor();
        $parameters = [];

        if($controllerConstructor !== null && $controllerConstructor->getNumberOfRequiredParameters() !== 0){

            foreach ($controllerReflection->getConstructor()->getParameters() as $parameter){
                if(array_key_exists($parameter->getType()->getName(), $this->services)){
                    $parameters[$parameter->getName()] = $this->services[$parameter->getType()->getName()];
                }
            }

        }

        //Check if we have a valid method, if not throw a 500 error.
        if (!$controllerReflection->hasMethod($response["method"])) {
            return $this->responseWithError(500);
        }

        $request->setRouteInfo(new RouteInfo(
            $response["controller"],
            $response["method"],
            $response["route"],
            $_SERVER["REQUEST_METHOD"],
            $response["variables"]
        ));

        //Next we check if we have any variables to pass, if not we run the method.
        //Next this as we have not returned we have variables to pass
        $method = $controllerReflection->getMethod($response["method"]);

        //Pass our URL variables to the request object.
        $request->setUrlVars($response["variables"]);

        //Run all our middleware
        foreach (array_merge($this->globalMiddlewares,$response["middleware"]) as $middleware) {
            if($middleware instanceof Middleware){
                $request = $middleware->handle($request);
            }else {
                $object = new $middleware();
                $request = $object->handle($request);
            }
        }

        if($parameters !== []){
            $controller = $controllerReflection->newInstanceArgs($parameters);
        }else{
            $controller = $controllerReflection->newInstance();
        }

        //Check if we require our request object.
        $requestInjection = $this->requiresHttpRequest($method);

        if ($requestInjection !== null) {
            $response["variables"][$requestInjection] = $request;
        }

        // Apply model binding for route parameters
        foreach ($method->getParameters() as $parameter) {

            if($parameter->getType() === null){
                continue;
            }
            if(is_subclass_of($parameter->getType()->getName(), Model::class)){
                $class = $parameter->getType()->getName();
                $reflection = new ReflectionClass($class);

                $pkValue = $response["variables"][$parameter->getName()];
                $pkKey = $class::getDatabasePrimaryKey($reflection)["NAME"];

                if(array_key_exists($parameter->getName(),$request->context)
                    && $request->context[$parameter->getName()] instanceof $class
                    && property_exists($request->context[$parameter->getName()],$pkKey)
                    && $request->context[$parameter->getName()]->$pkKey == $pkValue
                    ){

                    $instance = $request->context[$parameter->getName()];
                }else{

                    $instance = $class::where($pkKey,$pkValue)->getFirst();
                }

                if($instance === null){
                    return $this->responseWithError(404);
                }

                $response["variables"][$parameter->getName()] = $instance;

            }else{
                //Else perform dependency injection
                if(array_key_exists($parameter->getType()->getName(), $this->services)){
                    $response["variables"][$parameter->getName()] = $this->services[$parameter->getType()->getName()];
                }
            }
        }

        $result = $method->invokeArgs($controller, $response["variables"]);

        http_response_code($result->status());
        $this->setHeaders($result->headers);

        return $result->render();
    }

    /**
     * Check if a method requires an HTTP Request parameter
     *
     * @param ReflectionMethod $method Method to check
     * @return string|null Parameter name that should receive the Request, or null if none
     */
    private function requiresHttpRequest(ReflectionMethod $method): ?string
    {
        $name = null;

        foreach ($method->getParameters() as $parameter){

            if($parameter->getType() !== null){
                if($parameter->getType()->getName() === Request::class){
                    $name =  $parameter->getName();
                }
            }
        }

        return $name;
    }

    /**
     * Load environment variables from .env file
     *
     * Parses the .env file and populates the env property with key-value pairs.
     * Handles empty lines, comments, and quoted values.
     *
     * @return void
     */
    public function LoadEnv(): void
    {

        $file = fopen(FileSystem::rootPath().DIRECTORY_SEPARATOR.".env", "r");
        $output = [];

        if($file) {
            while(($line = fgets($file)) !== false) {
                // Skip comments and empty lines
                $line = trim($line);
                if(empty($line) || str_starts_with($line, '#')) {
                    continue;
                }

                // Find position of first equals sign
                $pos = strpos($line, '=');
                if($pos !== false) {
                    $key = trim(substr($line, 0, $pos));
                    $value = trim(substr($line, $pos + 1));

                    // Remove quotes if present
                    $value = trim($value, '"\'');

                    if(!empty($key)) {
                        $output[$key] = $value;
                    }
                }
            }
        }

        fclose($file);

        $this->env = $output;
    }

    /**
     * Execute a console command
     *
     * Registers built-in commands, analyzes the command input,
     * validates the controller and method, and executes the command.
     *
     * @param array $args Command line arguments
     * @return string Command output
     * @throws ReflectionException
     */
    public function executeConsoleCommand(array $args = []): string
    {

        $this->boot();

        CommandLine::register("Migration make {class}","make", MigrationController::class);

        CommandLine::register("update check", "check", UpdateController::class);
        CommandLine::register("update install","install", UpdateController::class);
        CommandLine::register("update rollback", "rollback", UpdateController::class);

        CommandLine::register("generate api-docs", "generateApi", DocumentationController::class);

        if($args === []){
            $args = array_slice($_SERVER["argv"],1);
            $args = str_replace("\n", "", $args);
        }

        $processedArgs = $this->processArguments($args);

        $commandArgs = $processedArgs['args'];
        $options = $processedArgs['options'];

        $response = $this->consoleRouter->AnalyseRouteAndLookup($commandArgs);

        if(!$response["outcome"]){
            return "Unrecognized command. Type 'help' to see available commands.\nDid you mean something similar?";
        }

        if (!class_exists($response["controller"])) {
            return "Command registration error: The controller class '".$response["controller"]."' could not be found.\nPlease check your command registration and ensure the class exists.";
        }

        $controller = new $response["controller"]();

        if(!method_exists($controller,$response["method"])) {
            return "Invalid command: The method '".$response["method"]."' is not defined in the '".$controller::class."' class.\nPlease verify the command registration and the controller's method.";
        }

        //Next this as we have not returned we have variables to pass
        $reflect = new ReflectionClass($response["controller"]);
        $method = $reflect->getMethod($response["method"]);

        $varCount = count($response["variables"]);

        $filteredVariables = [];

        $variables = "";

        foreach ($method->getParameters() as $param) {

            if($param->getName() == "options"){
                $filteredVariables["options"] = $options;
                continue;
            }

            $variables .= " [".$param->getName()."]";

            if (array_key_exists($param->getName(), $response["variables"])) {
                $filteredVariables[$param->getName()] = $response["variables"][$param->getName()];
                continue;
            }

            if(!$param->isDefaultValueAvailable()){
                return "Argument missing: The '".$param->getName()."' argument is required for this command.\nExpected format: [command] [argument_name]\nExample usage: ".$response["route"].$variables;
            }

        }

        if($varCount < $method->getNumberOfRequiredParameters() || count($method->getParameters()) < $varCount){
            return "Insufficient arguments! The command requires at least ".$varCount." parameters.\nUsage: ".$response["route"]." ".$variables;
        }


        // Use the filtered variables instead of all variables
        return $method->invokeArgs($controller, $filteredVariables);
    }

    /**
     * Register a command file
     *
     * @param string $commandFile Path to command file
     * @return void
     */
    public function loadCommands(string $commandFile): void
    {
        $this->commands[] = $commandFile;
    }

    /**
     * Resets all the routes currently registered in the application
     *
     * @return void
     */
    public static function reset(): void
    {
        Application::$instance = new Application();
    }

    public function addRegex(string $key, string $pattern,?string $message = null): void
    {
        $this->regexRules[$key] = ["pattern"=>$pattern,"message"=>$message];
    }

    public function getRegexRules(): array{
        return $this->regexRules;
    }

    public function overrideValidationMessage(string $key, string $message): void
    {
        $this->ruleMessages[$key] = $message;
    }

    public function getValidationMessages(): array
    {
        return $this->ruleMessages;
    }

    /**
     * Processes command line arguments, separating regular arguments from options
     * Options are arguments that start with '--'
     * Options can also have values like --file=/test.php
     *
     * @param array $argv Command line arguments array
     * @return array Associative array with 'args' and 'options' keys
     */
    function processArguments(array $argv): array
    {
        $args = [];
        $options = [];

        // Skip the script name (first argument)
        for ($i = 0; $i < count($argv); $i++) {
            $arg = $argv[$i];

            // Check if it's an option (starts with --)
            if (str_starts_with($arg, '--')) {
                $option = substr($arg, 2); // Remove the '--'

                // Check if it has a value with '='
                if (str_contains($option, '=')) {
                    list($key, $value) = explode('=', $option, 2);
                    $options[$key] = $value;
                } else {
                    // Option without value
                    $options[$option] = true;
                }
            } else {
                // Regular argument
                $args[] = $arg;
            }
        }

        return [
            'args' => $args,
            'options' => $options
        ];
    }

    public function registerFallback(HttpResponse $response): void
    {
        $this->fallbackResponse = $response;
    }

    private function requiresOptions(ReflectionMethod $method): bool
    {
        return array_any($method->getParameters(), fn($parameter) => $parameter->getName() === "options");

    }

    /**
     * Set multiple HTTP headers from an associative array
     *
     * @param array $headers Associative array where keys are header names and values are header content
     * @param bool $replace Whether to replace previous headers with the same name (default: true)
     * @return void
     */
    public function setHeaders(array $headers, bool $replace = true): void
    {
        foreach ($headers as $name => $value) {
            header("$name: $value", $replace);
        }
    }

    public function addService(object|string $service, ?string $alias = null) : mixed
    {
        if(getType($service) === "string"){
            $instance = new $service();
            $this->services[$alias ?? $service] = $instance;
            return $instance;
        }

        $this->services[$alias ?? get_class($service)] = $service;
        return $service;
    }

    private function responseWithError(int $code, ?string $message = null): string
    {
        if ($message === null) {
            $message = match ($code) {
                404 => "The page you're looking for cannot be found. It may have been moved, deleted, or never existed.",
                500 => "We're experiencing technical difficulties. Our team has been notified and is working to resolve the issue.",
                401 => "Authentication required. Please log in to access this resource.",
                403 => "You don't have permission to access this resource.",
                default => "An error occurred while processing your request."
            };
        }

        $response = $this->errorPageResponses[$code] ?? new JsonResponse()
            ->setStatusCode($code)
            ->setOutcome(false)
            ->setMessage($message);

        http_response_code($code);

        return $response->render();
    }

    public function registerErrorTemplate(int $code, HttpResponse $response): void
    {
        $this->errorPageResponses[$code] = $response;
    }

    public function registerGlobalMiddleware(Middleware|string $middleware): void
    {
        $this->globalMiddlewares[] = $middleware;
    }


}