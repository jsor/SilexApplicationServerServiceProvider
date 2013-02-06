<?php

/*
 * This file is part of the Silex ApplicationServerServiceProvider.
 *
 * (c) Jan Sorgalla <jsorgalla@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsor;

use Silex\Application;
use Silex\ServiceProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The Silex ApplicationServerServiceProvider class.
 *
 * @author Jan Sorgalla <jsorgalla@googlemail.com>
 */
class ApplicationServerServiceProvider implements ServiceProviderInterface
{
    /**
     * @var Application
     */
    private $application;

    /**
     * {@inhertidoc}
     */
    public function boot(Application $app) {}

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
     * @return ApplicationServerServiceProvider
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
     * @return ApplicationServerServiceProvider
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
                echo "  Exception thrown\n";
                echo "    Name: " . get_class($e) . "\n";
                echo "    Message: " . $e->getMessage() . "\n\n";
            });

            while ($conn = stream_socket_accept($socket, -1)) {
                $request = $this->createRequest($conn, $host, $port, $script);

                if (false !== $request) {
                    echo "Incoming request\n";
                    echo "  Request Uri: " . $request->getRequestUri() . "\n";
                    echo "  Remote Addr: " . $request->getClientIp() . "\n\n";

                    if (isset($app['application_server.shutdown_url']) && $app['application_server.shutdown_url'] === $request->getPathInfo()) {
                        fwrite($conn, (string) new Response('Shutting server down.'));
                        break;
                    }

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

        $server = $this->processHeaders($str, $host, $port, $script);

        $remoteAddr = stream_socket_get_name($conn, true);

        if ($remoteAddr) {
            $parts = explode(':', $remoteAddr);
            $server['REMOTE_ADDR'] = $parts[0];
            if (isset($parts[1])) {
                $server['REMOTE_PORT'] = $parts[1];
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

        if (in_array(strtoupper($server['REQUEST_METHOD']), array('POST', 'PUT', 'DELETE')) &&
            $server['CONTENT_LENGTH'] > 0) {

            $str = stream_get_contents($conn, $server['CONTENT_LENGTH']);

            if (false !== $str) {
                $content = $str;
                $this->processBody($content, $server, $parameters, $files);
            }
        }

        return Request::create($server['REQUEST_URI'], $server['REQUEST_METHOD'], $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Process request headers
     *
     * @param string $str
     * @param string $host
     * @param string $port
     * @param string $script
     *
     * @return array
     *
     * @throws \RuntimeException When the HTTP header format is invalid
     */
    public function processHeaders($str, $host, $port, $script)
    {
        $server = $this->parseHeaders($str);

        if (!preg_match("|^(.*?) (.*?) (.*?)\r\n|", $str, $m)) {
            throw new \RuntimeException('Invalid HTTP header');
        }

        $server['REQUEST_METHOD']  = $m[1];
        $server['REQUEST_URI']     = $m[2];
        $server['HTTP_VERSION']    = $m[3];

        $server['SERVER_SOFTWARE'] = __CLASS__;
        $server['SCRIPT_NAME']     = '/' . ltrim($script, '/');
        $server['SCRIPT_FILENAME'] = str_replace('\\', '/', getcwd() . '/' . ltrim($script, '/'));

        if (isset($server['HTTP_HOST'])) {
            $server['SERVER_PORT'] = '80';
            if (count($parts = explode(':', $server['HTTP_HOST'])) == 2) {
                $server['SERVER_PORT'] = $parts[1];
            }

            $server['SERVER_NAME'] = $parts[0];
        } else {
            $server['HTTP_HOST']   = $host . ':' . $port;
            $server['SERVER_NAME'] = $host;
            $server['SERVER_PORT'] = (string) $port;
        }

        if (isset($server['HTTP_CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = $server['HTTP_CONTENT_TYPE'];
            unset($server['HTTP_CONTENT_TYPE']);
        }

        if (isset($server['HTTP_CONTENT_LENGTH'])) {
            $server['CONTENT_LENGTH'] = (integer) $server['HTTP_CONTENT_LENGTH'];
            unset($server['HTTP_CONTENT_LENGTH']);
        } else {
            $server['CONTENT_LENGTH'] = 0;
        }

        return $server;
    }

    /**
     * Taken from ZF2 (https://github.com/zendframework/zf2/blob/master/library/Zend/Http/Response.php)
     *
     * @param string $str
     * @return array
     */
    public function parseHeaders($str)
    {
        $headers = array();

        // First, split body and headers
        $parts = preg_split('|(?:\r?\n){2}|m', $str, 2);
        if (!$parts[0]) {
            return $headers;
        }

        // Split headers part to lines
        $lines = explode("\n", $parts[0]);
        unset($parts);
        $last_header = null;

        foreach ($lines as $line) {
            $line = trim($line, "\r\n");
            if ($line == "") {
                break;
            }

            // Locate headers like 'Location: ...' and 'Location:...' (note the missing space)
            if (preg_match("|^([\w-]+):\s*(.+)|", $line, $m)) {
                unset($last_header);
                $h_name = 'HTTP_' . str_replace('-', '_', strtoupper($m[1]));
                $h_value = $m[2];

                if (isset($headers[$h_name])) {
                    if (!is_array($headers[$h_name])) {
                        $headers[$h_name] = array($headers[$h_name]);
                    }

                    $headers[$h_name][] = $h_value;
                } else {
                    $headers[$h_name] = $h_value;
                }
                $last_header = $h_name;
            } elseif (preg_match("|^\s+(.+)$|", $line, $m) && $last_header !== null) {
                if (is_array($headers[$last_header])) {
                    end($headers[$last_header]);
                    $last_header_key = key($headers[$last_header]);
                    $headers[$last_header][$last_header_key] .= $m[1];
                } else {
                    $headers[$last_header] .= $m[1];
                }
            }
        }

        return $headers;
    }

    /**
     * Process request body
     *
     * @param string $str
     * @param array $server
     * @param array $parameters
     * @param array $files
     * @return string
     */
    public function processBody($str, &$server, &$parameters, &$files)
    {
        if (null === $server) {
            $server = array();
        }

        if (null === $parameters) {
            $parameters = array();
        }

        if (null === $files) {
            $files = array();
        }

        if (!isset($server['CONTENT_TYPE'])) {
            return;
        }

        if (stripos($server['CONTENT_TYPE'], 'application/x-www-form-urlencoded') !== false) {
            parse_str($str, $parameters);
        } elseif (stripos($server['CONTENT_TYPE'], 'multipart/form-data') !== false) {
            try {
                $this->processMultipart($server['CONTENT_TYPE'], $str, $parameters, $files);
            } catch (\Exception $e) {
                throw new HttpException(400);
            }
        }
    }

    /**
     * Taken from https://github.com/indeyets/appserver-in-php/blob/master/AiP/Middleware/HTTPParser.php
     *
     * @param string $contentType
     * @param string $str
     * @param array $parameters
     * @param array $files
     */
    public function processMultipart($contentType, $str, &$parameters, &$files)
    {
        foreach (explode('; ', $contentType) as $contentType_part) {
            $pos = strpos($contentType_part, 'boundary=');

            if ($pos !== 0)
                continue;

            $boundary = '--'.substr($contentType_part, $pos + 9);
            $boundary_len = strlen($boundary);
        }

        if (!isset($boundary))
            throw new \RuntimeException("Didn't find boundary-declaration in multipart");

        $post_strs = array();
        $pos = 0;
        while (substr($str, $pos + $boundary_len, 2) != '--') {
            // getting headers of part
            $h_start = $pos + $boundary_len + 2;
            $h_end = strpos($str, "\r\n\r\n", $h_start);

            if (false === $h_end) {
                throw new \RuntimeException("Didn't find end of headers-zone");
            }

            $headers = array();
            foreach (explode("\r\n", substr($str, $h_start, $h_end - $h_start)) as $h_str) {
                $divider = strpos($h_str, ':');
                $headers[substr($h_str, 0, $divider)] = html_entity_decode(substr($h_str, $divider + 2), ENT_QUOTES, 'UTF-8');
            }

            if (!isset($headers['Content-Disposition']))
                throw new \RuntimeException("Didn't find Content-disposition in one of the parts of multipart: ".var_export(array_keys($headers), true));

            // parsing dispositin-header of part
            $disposition = array();
            foreach (explode("; ", $headers['Content-Disposition']) as $d_part) {
                if ($d_part == 'form-data')
                    continue;

                $divider = strpos($d_part, '=');
                $disposition[substr($d_part, 0, $divider)] = substr($d_part, $divider + 2, -1);
            }

            // getting body of part
            $b_start = $h_end + 4;
            $b_end = strpos($str, "\r\n".$boundary, $b_start);

            if (false === $b_end) {
                throw new \RuntimeException("Didn't find end of body :-/");
            }

            $file_data = substr($str, $b_start, $b_end - $b_start);

            if (isset($disposition['filename'])) {
                $tmp_dir = ini_get('upload_tmp_dir') ? ini_get('upload_tmp_dir') : sys_get_temp_dir();

                // ToDo:
                //  UPLOAD_ERR_FORM_SIZE
                //  UPLOAD_ERR_PARTIAL (?)
                //  UPLOAD_ERR_NO_FILE (?)
                //  UPLOAD_ERR_EXTENSION

                if (empty($tmp_dir)) {
                    $fdata = array(
                        'name' => '',
                        'type' => '',
                        'tmp_name' => '',
                        'error' => UPLOAD_ERR_NO_TMP_DIR,
                        'size' => 0,
                    );
                } elseif ($b_end - $b_start > $this->iniStringToBytes(ini_get('upload_max_filesize'))) {
                    $fdata = array(
                        'name' => '',
                        'type' => '',
                        'tmp_name' => '',
                        'error' => UPLOAD_ERR_INI_SIZE,
                        'size' => 0,
                    );
                } elseif (0 === strlen($disposition['filename'])) {
                    $fdata = array(
                        'name' => '',
                        'type' => '',
                        'tmp_name' => '',
                        'error' => UPLOAD_ERR_NO_FILE,
                        'size' => 0,
                    );
                } elseif (false === $tmp_file = tempnam($tmp_dir, __CLASS__) or false === file_put_contents($tmp_file, $file_data)) {
                    if ($tmp_file !== false)
                        unlink($tmp_file);

                    $fdata = array(
                        'name' => '',
                        'type' => '',
                        'tmp_name' => '',
                        'error' => UPLOAD_ERR_CANT_WRITE,
                        'size' => 0,
                    );
                } else {
                    $filesize = filesize($tmp_file);

                    $handle = finfo_open(FILEINFO_MIME);
                    $type = finfo_file($handle, $tmp_file);
                    finfo_close($handle);

                    if (false !== ($pos = strpos($type, ';'))) {
                        $type = substr($type, 0, $pos);
                    }

                    if (empty($type) || $type == 'application/x-empty') {
                        $type = '';
                    }

                    $fdata = array(
                        'name' => $disposition['filename'],
                        'type' => $type,
                        'tmp_name' => $tmp_file,
                        'error' => (0 === $filesize) ? 5 : UPLOAD_ERR_OK,
                        'size' => $filesize,
                    );
                }

                // Files can be submitted as arrays. If field name is "file[xyz]",
                // name must be stored as "file[name][xyz]". To avoid manual parsing
                // of the tricky syntax, we use eval().

                // First, we quote everything except square brackets.
                $sel = preg_replace('/([^\[\]]+)/', '\'\1\'', $disposition['name']);

                // Second, insert a special key between the name of the field and
                // the rest of the array path.
                $parts = explode('[', $sel, 2);
                foreach (array_keys($fdata) as $key) {
                    if (count($parts) == 1) {
                        $files[$disposition['name']][$key] = $fdata[$key];
                    } else {
                        eval($code = '$files[' . $parts[0] . '][\'' . $key . '\'][' . $parts[1] . ' = $fdata[\'' . $key . '\'];');
                    }
                }
            } else {
                $post_strs[] = urlencode($disposition['name']).'='.urlencode($file_data);
            }
            unset($file_data);

            $pos = $b_end + 2;
        }

        parse_str(implode('&', $post_strs), $parameters);
    }

    protected function iniStringToBytes($val)
    {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }

        return $val;
    }
}
