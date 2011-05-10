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

/**
 * ApplicationServerExtension test cases.
 *
 * @author Jan Sorgalla <jsorgalla@googlemail.com>
 */
class ApplicationServerExtensionTest extends \PHPUnit_Framework_TestCase
{
    public function testRegisterReturnsExtensionInstance()
    {
        $app = new Application();
        
        $extension = new ApplicationServerExtension();

        $app->register($extension);

        $this->assertSame($extension, $app['application_server']);
    }
    
    /*public function testParseHeadersUsesDefaultHostAndPortIfNoHeader()
    {
        $extension = new ApplicationServerExtension();

        $str = 'GET /app/index.php HTTP/1.1';

        $headers = $extension->parseHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('127.0.0.1:8080', $headers['HTTP_HOST']);
        $this->assertEquals('127.0.0.1', $headers['SERVER_NAME']);
        $this->assertEquals('8080', $headers['SERVER_PORT']);
    }*/
    
    public function testParseHeadersUsesHostAndPortFromHeader()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1
Host: localhost:9000';

        $headers = $extension->parseHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('localhost:9000', $headers['HTTP_HOST']);
        $this->assertEquals('localhost', $headers['SERVER_NAME']);
        $this->assertEquals('9000', $headers['SERVER_PORT']);
    }

    public function testParseHeadersSetsScriptNameAndFilename()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1';

        $headers = $extension->parseHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('/app/index.php', $headers['SCRIPT_NAME']);
        $this->assertEquals(str_replace('\\', '/', getcwd() . '/app/index.php'), $headers['SCRIPT_FILENAME']);
    }

    public function testParseHeadersSetsMethodAndRequestUriAndVersion()
    {
        $extension = new ApplicationServerExtension();

        $str = 'GET /app/index.php HTTP/1.1';

        $headers = $extension->parseHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('GET', $headers['REQUEST_METHOD']);
        $this->assertEquals('/app/index.php', $headers['REQUEST_URI']);
        $this->assertEquals('HTTP/1.1', $headers['HTTP_VERSION']);
    }

    public function testParseHeadersSetsHeaders()
    {
        $extension = new ApplicationServerExtension();

        $str = 'GET /app/index.php HTTP/1.1
Host: localhost:8080
User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8
Accept-Language: de,en;q=0.5
Accept-Encoding: gzip, deflate
Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7
Content-Type: text/html';

        $headers = $extension->parseHeaders($str, '127.0.0.1', '8080', '/app/index.php');

        $this->assertEquals('Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1', $headers['HTTP_USER_AGENT']);
        $this->assertEquals('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', $headers['HTTP_ACCEPT']);
        $this->assertEquals('de,en;q=0.5', $headers['HTTP_ACCEPT_LANGUAGE']);
        $this->assertEquals('gzip, deflate', $headers['HTTP_ACCEPT_ENCODING']);
        $this->assertEquals('ISO-8859-1,utf-8;q=0.7,*;q=0.7', $headers['HTTP_ACCEPT_CHARSET']);
        $this->assertEquals('text/html', $headers['CONTENT_TYPE']);
    }

    public function testParseBodyParsesUrlencodedContent()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'key1=val1&key2=val2';
        
        $server = array(
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE'   => 'application/x-www-form-urlencoded'
        );

        $extension->parseBody($str, $server, $parameters);

        $this->assertEquals('val1', $parameters['key1']);
        $this->assertEquals('val2', $parameters['key2']);
    }
}
