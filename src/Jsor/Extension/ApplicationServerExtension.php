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

            while ($conn = stream_socket_accept($socket, -1)) {
                do {
                    $str = stream_get_line($conn, 8192);

                    if ('' === $str) {
                        // client just disconnected
                        continue 2;
                    }
                } while (false === $str);

                $remoteAddr = stream_socket_get_name($conn, true);

                if (false === $remoteAddr) {
                    $remoteAddr = null;
                }

                $request = $this->createRequest($str, $host, $port, $script, $remoteAddr);

                echo "Incoming request\n";
                echo "  Request Uri: " . $request->getRequestUri() . "\n";
                echo "  Remote Addr: " . $remoteAddr . "\n\n";

                $app = $this->getApplication();
                
                $app->error(function(\Exception $e) {
                    echo "  Exception handled\n";
                    echo "    Name: " . get_class($e) . "\n";
                    echo "    Message: " . $e->getMessage() . "\n\n";
                    
                    return new Response('The server encountered an internal error and was unable to complete your request.', 500);
                });

                $response = $app->handle($request);

                fwrite($conn, $response->__toString());
                fclose($conn);
            }

            fclose($socket);
        }
        
        return $this;
    }

    /**
     * Create Request object
     *
     * @param string $str
     * @param string $host
     * @param string $port
     * @param string $script
     * @param string $remoteAddr
     * @return Request 
     */
    public function createRequest($str, $host, $port, $script, $remoteAddr)
    {
        $parameters = array();
        $cookies    = array();
        $files      = array();
        $server     = array();
        $content    = '';

        $parts = preg_split('|(?:\r?\n){2}|m', $str, 2);

        if (isset($parts[1])) {
            $content = $parts[1];
        }

        $lines = explode("\r\n", $parts[0]); // getting headers

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

                if (isset($server[$hName])) {
                    if (!is_array($server[$hName])) {
                        $server[$hName] = array($server[$hName]);
                    }

                    $server[$hName][] = $hValue;
                } else {
                    $server[$hName] = $hValue;
                }
                $lastHeader = $hName;
            } elseif (preg_match("|^\s+(.+)$|", $line, $m) && $lastHeader !== null) {
                if (is_array($server[$lastHeader])) {
                    end($server[$lastHeader]);
                    $lastHeader_key = key($server[$lastHeader]);
                    $server[$lastHeader][$lastHeader_key] .= $m[1];
                } else {
                    $server[$lastHeader] .= $m[1];
                }
            }
        }

        $server['HTTP_VERSION']    = $version;
        $server['SERVER_SOFTWARE'] = __CLASS__;
        $server['SCRIPT_NAME']     = '/' . ltrim($script, '/');
        $server['SCRIPT_FILENAME'] = str_replace('\\', '/', getcwd() . DIRECTORY_SEPARATOR . ltrim($script, '/'));

        if (null !== $remoteAddr) {
            $pos = strrpos($remoteAddr, ':');
            $server['REMOTE_ADDR'] = substr($remoteAddr, 0, $pos);
            $server['REMOTE_PORT'] = substr($remoteAddr, $pos + 1);
        }

        if (isset($server['HTTP_HOST'])) {
            if (false === ($pos = strpos($server['HTTP_HOST'], ':'))) {
                $host = $server['HTTP_HOST'];
                $port = $port;
            } else {
                $host = substr($server['HTTP_HOST'], 0, $pos);
                $port = substr($server['HTTP_HOST'], $pos + 1);
            }

            $server['SERVER_NAME'] = $host;
            $server['SERVER_PORT'] = (string) $port;
        } else {
            $server['HTTP_HOST']   = $host . ':' . $port;
            $server['SERVER_NAME'] = $host;
            $server['SERVER_PORT'] = $port;
        }

        if (isset($server['HTTP_CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = $server['HTTP_CONTENT_TYPE'];
            unset($server['HTTP_CONTENT_TYPE']);
        }

        if (isset($server['HTTP_CONTENT_LENGTH'])) {
            $server['CONTENT_LENGTH'] = $server['HTTP_CONTENT_LENGTH'];
            unset($server['HTTP_CONTENT_LENGTH']);
        } else {
            $server['CONTENT_LENGTH'] = 0;
        }

        // TODO: handle multipart/form-data etc.
        if ($content != '' && 
            in_array(strtoupper($method), array('POST', 'PUT', 'DELETE')) && 
            isset($server['CONTENT_TYPE']) && 
            $server['CONTENT_TYPE'] == 'application/x-www-form-urlencoded') {

            parse_str($content, $parameters);
        }

        return Request::create($uri, $method, $parameters, $cookies, $files, $server, $content);
    }
}
