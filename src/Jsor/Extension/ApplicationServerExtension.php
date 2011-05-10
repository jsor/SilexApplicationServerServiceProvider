<?php

/*
 * This file is part of the Silex ApplicationServerExtension.
 *
 * (c) Jan Sorgalla <jsorgalla@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsor\Extension;

use Silex\Application;
use Silex\ExtensionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Silex ApplicationServerExtension class.
 *
 * @author Jan Sorgalla <jsorgalla@googlemail.com>
 */
class ApplicationServerExtension implements ExtensionInterface
{
    /**
     * @var Application
     */
    private $application;

    /**
     * Register the extension.
     * 
     * @param Application $app
     * @return void
     */
    public function register(Application $app)
    {
        $self = $this;
        $app['application_server'] = $app->share(function() use ($app, $self) {
            return $self->setApplication($app);
        });
    }

    /**
     * @param Application $app
     * @return ApplicationServerExtension 
     */
    public function setApplication(Application $app)
    {
        $this->application = $app;
        return $this;
    }

    /**
     * @return Application 
     */
    public function getApplication()
    {
        return $this->application;
    }

    /**
     * Start the application server.
     * 
     * @param string $port
     * @param string $host
     * @param string $script
     * @return ApplicationServerExtension
     */
    public function listen($port, $host = null, $script = null) 
    {
        if (!$host) {
            $host = '0.0.0.0';
        }

        if (!$script) {
            $script = '/index.php';
        }

        $socket = stream_socket_server('tcp://' . $host . ':' . $port, $errno, $errstr);

        if (!$socket) {
            echo "Could not create socket: $errstr ($errno)\n";
        } else {
            echo "\n";
            echo "Server started\n";
            echo "  Host: " . $host . "\n";
            echo "  Port: " . $port . "\n";
            echo "  Script: " . $script . "\n\n";

            $app = $this->getApplication();

            $app->error(function(\Exception $e) {
                echo "  Exception handled\n";
                echo "    Name: " . get_class($e) . "\n";
                echo "    Message: " . $e->getMessage() . "\n\n";

                return new Response('The server encountered an internal error and was unable to complete your request.', 500);
            });

            while ($conn = stream_socket_accept($socket, -1)) {
                $request = $this->createRequest($conn, $host, $port, $script);

                if (false !== $request) {
                    echo "Incoming request\n";
                    echo "  Request Uri: " . $request->getRequestUri() . "\n";
                    echo "  Remote Addr: " . $request->getClientIp() . "\n\n";

                    $response = $app->handle($request);

                    fwrite($conn, $response->__toString());
                }

                fclose($conn);
            }

            fclose($socket);
        }

        return $this;
    }

    /**
     * Create Request object
     *
     * @param resource $conn
     * @param string $host
     * @param string $port
     * @param string $script
     * @return Request 
     */
    public function createRequest($conn, $host, $port, $script)
    {
        do {
            $str = stream_get_line($conn, 0, "\r\n\r\n");

            if ('' === $str) {
                // client just disconnected
                return false;
            }
        } while (false === $str);

        $server = $this->parseHeaders($str, $host, $port, $script);

        $remoteAddr = stream_socket_get_name($conn, true);

        if ($remoteAddr) {
            if (false === ($pos = strpos($remoteAddr, ':'))) {
                $server['REMOTE_ADDR'] = $remoteAddr;
            } else {
                $server['REMOTE_ADDR'] = substr($remoteAddr, 0, $pos);
                $server['REMOTE_PORT'] = substr($remoteAddr, $pos + 1);
            }
        }

        $cookies = array();

        if (!empty($server['HTTP_COOKIE'])) {
            $pairs = explode('; ', $server['HTTP_COOKIE']);

            foreach ($pairs as $pair) {
                list($name, $value) = explode('=', $pair);
                $cookies[$name] = urldecode($value);
            }
        }

        $parameters = array();
        $files      = array();
        $content    = '';

        if ($server['CONTENT_LENGTH'] > 0) {
            $str = stream_get_contents($conn, $server['CONTENT_LENGTH']);
            
            if (false !== $str) {
                $content = $this->parseBody($str, $server, $parameters, $files);
            }
        }

        return Request::create($server['REQUEST_URI'], $server['REQUEST_METHOD'], $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Parserequest headers
     * 
     * @param string $str
     * @return array 
     */
    public function parseHeaders($str, $host, $port, $script)
    {
        $headers = array();

        $lines = preg_split('|(?:\r?\n)+|m', $str); // getting headers

        list($method, $uri, $version) = sscanf(array_shift($lines), "%s %s %s");

        $lastHeader = null;

        // Taken from ZF2 (https://github.com/zendframework/zf2/blob/master/library/Zend/Http/Response.php)
        foreach ($lines as $line) {
            $line = trim($line, "\r\n");

            if ($line == "") {
                break;
            }

            // Locate headers like 'Location: ...' and 'Location:...' (note the missing space)
            if (preg_match("|^([\w-]+):\s*(.+)|", $line, $m)) {
                unset($lastHeader);
                $hName = strtolower($m[1]);
                $hName = 'HTTP_' . str_replace('-', '_', strtoupper($m[1]));
                $hValue = $m[2];

                if (isset($headers[$hName])) {
                    if (!is_array($headers[$hName])) {
                        $headers[$hName] = array($headers[$hName]);
                    }

                    $headers[$hName][] = $hValue;
                } else {
                    $headers[$hName] = $hValue;
                }
                $lastHeader = $hName;
            } elseif (preg_match("|^\s+(.+)$|", $line, $m) && $lastHeader !== null) {
                if (is_array($headers[$lastHeader])) {
                    end($headers[$lastHeader]);
                    $lastHeader_key = key($headers[$lastHeader]);
                    $headers[$lastHeader][$lastHeader_key] .= $m[1];
                } else {
                    $headers[$lastHeader] .= $m[1];
                }
            }
        }

        $headers['HTTP_VERSION']    = $version;
        $headers['REQUEST_METHOD']  = $method;
        $headers['REQUEST_URI']     = $uri;
        $headers['HTTP_VERSION']    = $version;
        $headers['SERVER_SOFTWARE'] = __CLASS__;
        $headers['SCRIPT_NAME']     = '/' . ltrim($script, '/');
        $headers['SCRIPT_FILENAME'] = str_replace('\\', '/', getcwd() . DIRECTORY_SEPARATOR . ltrim($script, '/'));

        if (isset($headers['HTTP_HOST'])) {
            if (false === ($pos = strpos($headers['HTTP_HOST'], ':'))) {
                $headers['SERVER_NAME'] = $headers['HTTP_HOST'];
                $headers['SERVER_PORT'] = '80';
            } else {
                $headers['SERVER_NAME'] = substr($headers['HTTP_HOST'], 0, $pos);
                $headers['SERVER_PORT'] = substr($headers['HTTP_HOST'], $pos + 1);
            }
        } else {
            $headers['HTTP_HOST']   = $host . ':' . $port;
            $headers['SERVER_NAME'] = $host;
            $headers['SERVER_PORT'] = (string) $port;
        }

        if (isset($headers['HTTP_CONTENT_TYPE'])) {
            $headers['CONTENT_TYPE'] = $headers['HTTP_CONTENT_TYPE'];
            unset($headers['HTTP_CONTENT_TYPE']);
        }

        if (isset($headers['HTTP_CONTENT_LENGTH'])) {
            $headers['CONTENT_LENGTH'] = (integer) $headers['HTTP_CONTENT_LENGTH'];
            unset($headers['HTTP_CONTENT_LENGTH']);
        } else {
            $headers['CONTENT_LENGTH'] = 0;
        }

        return $headers;
    }

    /**
     * Parse request body
     * 
     * @param string $str
     * @param array $server
     * @param array $parameters
     * @param array $files
     * @return string 
     */
    public function parseBody($str, &$server, &$parameters = array(), &$files = array())
    {
        // TODO: handle multipart/form-data etc.
        if (isset($server['REQUEST_METHOD']) &&
            in_array(strtoupper($server['REQUEST_METHOD']), array('POST', 'PUT', 'DELETE')) && 
            isset($server['CONTENT_TYPE']) && 
            $server['CONTENT_TYPE'] == 'application/x-www-form-urlencoded') {

            parse_str($str, $parameters);
        }

        return $str;
    }
}
