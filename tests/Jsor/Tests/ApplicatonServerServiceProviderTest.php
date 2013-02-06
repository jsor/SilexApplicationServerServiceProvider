<?php

/*
 * This file is part of the Silex ApplicationServerServiceProvider.
 *
 * (c) Jan Sorgalla <jsorgalla@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jsor\Tests;

use Jsor\ApplicationServerServiceProvider;
use Silex\Application;

/**
 * ApplicationServerServiceProvider test cases.
 *
 * @author Jan Sorgalla <jsorgalla@googlemail.com>
 */
class ApplicationServerServiceProviderTest extends \PHPUnit_Framework_TestCase
{
    protected $extension;

    public function setUp()
    {
        $this->extension = new ApplicationServerServiceProvider();
    }

    public function tearDown()
    {
        $this->extension = null;
    }

    public function testRegisterReturnsServiceProviderInstance()
    {
        $app = new Application();

        $app->register($this->extension);

        $this->assertSame($this->extension, $app['application_server']);
    }

    /*public function testProcessHeadersUsesDefaultHostAndPortIfNoHeader()
    {
        $this->extension = new ApplicationServerServiceProvider();

        $str = 'GET /app/index.php HTTP/1.1';

        $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php', $headers);

        $this->assertEquals('127.0.0.1:8080', $headers['HTTP_HOST']);
        $this->assertEquals('127.0.0.1', $headers['SERVER_NAME']);
        $this->assertEquals('8080', $headers['SERVER_PORT']);
    }*/

    public function testProcessHeadersUsesHostAndPortFromHeader()
    {
        $str = "GET /app/index.php HTTP/1.1\r\nHost: localhost:9000";

        $headers = $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('localhost:9000', $headers['HTTP_HOST']);
        $this->assertEquals('localhost', $headers['SERVER_NAME']);
        $this->assertEquals('9000', $headers['SERVER_PORT']);
    }

    public function testProcessHeadersSetsScriptNameAndFilename()
    {
        $str = "GET /app/index.php HTTP/1.1\r\n";

        $headers = $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('/app/index.php', $headers['SCRIPT_NAME']);
        $this->assertEquals(str_replace('\\', '/', getcwd() . '/app/index.php'), $headers['SCRIPT_FILENAME']);
    }

    public function testProcessHeadersSetsMethodAndRequestUriAndVersion()
    {
        $str = "GET /app/index.php HTTP/1.1\r\n";

        $headers = $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('GET', $headers['REQUEST_METHOD']);
        $this->assertEquals('/app/index.php', $headers['REQUEST_URI']);
        $this->assertEquals('HTTP/1.1', $headers['HTTP_VERSION']);
    }

    public function testProcessHeadersPopulatesHeaders()
    {
        $headers = array(
            'GET /app/index.php HTTP/1.1',
            'Host: localhost:8080',
            'User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: de,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7',
            'Content-Type: text/html',
            ''
        );

        $str = implode("\r\n", $headers);

        $headers = $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1', $headers['HTTP_USER_AGENT']);
        $this->assertEquals('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', $headers['HTTP_ACCEPT']);
        $this->assertEquals('de,en;q=0.5', $headers['HTTP_ACCEPT_LANGUAGE']);
        $this->assertEquals('gzip, deflate', $headers['HTTP_ACCEPT_ENCODING']);
        $this->assertEquals('ISO-8859-1,utf-8;q=0.7,*;q=0.7', $headers['HTTP_ACCEPT_CHARSET']);
        $this->assertEquals('text/html', $headers['CONTENT_TYPE']);
    }

    public function testProcessBodyParsesUrlencodedContent()
    {
        $str = 'key1=val1&key2=val2';

        $server = array(
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE'   => 'application/x-www-form-urlencoded'
        );

        $this->extension->processBody($str, $server, $parameters, $files);

        $this->assertEquals('val1', $parameters['key1']);
        $this->assertEquals('val2', $parameters['key2']);
    }

    public function testProcessBodyParsesMultipartContent()
    {
        $str = file_get_contents(__DIR__ . '/_files/multipart_content.txt');

        $server = array(
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE'   => 'multipart/form-data; boundary=---------------------------20945431327756'
        );

        $this->extension->processBody($str, $server, $parameters, $files);

        $this->assertEquals('Submit', $parameters['submit']);
        $this->assertArrayHasKey('file', $files);
        $this->assertArrayHasKey('name', $files['file']);
        $this->assertEquals('jan.jpg', $files['file']['name']);
    }

    /**
     * @expectedException \RuntimeException
     * @ecpectedExceptionMessage invalid HTTP header
     */
    public function testInvalidHeaderFormat()
    {
        $str = "GET /app/index.php HTTP/1.1";

        $headers = $this->extension->processHeaders($str, '127.0.0.1', '8080', '/app/index.php');
    }
}
