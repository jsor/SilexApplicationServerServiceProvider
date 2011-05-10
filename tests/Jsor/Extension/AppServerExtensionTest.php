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
    
    public function testCreateRequestUsesDefaultHostAndPortIfNoHeader()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('127.0.0.1:8080', $request->getHttpHost());
        $this->assertEquals('127.0.0.1', $request->getHost());
    }
    
    public function testCreateRequestUsesHostAndPortFromHeader()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1
Host: localhost:9000';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('localhost:9000', $request->getHttpHost());
        $this->assertEquals('localhost', $request->getHost());
    }
    
    public function testCreateRequestSetsScriptNameAndFilename()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('/app/index.php', $request->getScriptName());
        $this->assertEquals(str_replace('\\', '/', getcwd() . '/app/index.php'), $request->server->get('SCRIPT_FILENAME'));
    }
    
    public function testCreateRequestSetsMethodAndRequestUriAndVersion()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/app/index.php', $request->getRequestUri());
        $this->assertEquals('HTTP/1.1', $request->server->get('HTTP_VERSION'));
    }
    
    public function testCreateRequestSetsHeaders()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1
Host: localhost:8080
User-Agent: Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8
Accept-Language: de,en;q=0.5
Accept-Encoding: gzip, deflate
Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('Mozilla/5.0 (Windows NT 5.1; rv:2.0.1) Gecko/20100101 Firefox/4.0.1', $request->server->get('HTTP_USER_AGENT'));
        $this->assertEquals('text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', $request->server->get('HTTP_ACCEPT'));
        $this->assertEquals(array('text/html', 'application/xhtml+xml', 'application/xml', '*/*'), $request->getAcceptableContentTypes());
        $this->assertEquals('de,en;q=0.5', $request->server->get('HTTP_ACCEPT_LANGUAGE'));
        $this->assertEquals(array('de', 'en'), $request->getLanguages());
        $this->assertEquals('gzip, deflate', $request->server->get('HTTP_ACCEPT_ENCODING'));
        $this->assertEquals('ISO-8859-1,utf-8;q=0.7,*;q=0.7', $request->server->get('HTTP_ACCEPT_CHARSET'));
        $this->assertEquals(array('ISO-8859-1', '*', 'utf-8'), $request->getCharsets());
    }
    
    public function testCreateRequestSetsContentType()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'GET /app/index.php HTTP/1.1
Host: localhost:8080
Content-Type: text/html';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('text/html', $request->server->get('CONTENT_TYPE'));
    }
    
    public function testCreateRequestParsesUrlencodedContent()
    {
        $extension = new ApplicationServerExtension();
        
        $str = 'POST /app/index.php HTTP/1.1
Host: localhost:8080
Content-Type: application/x-www-form-urlencoded

key1=val1&key2=val2';

        $request = $extension->createRequest($str, '127.0.0.1', '8080', '/app/index.php', '123.456.789');
        
        $this->assertInstanceOf('Symfony\Component\HttpFoundation\Request', $request);
        $this->assertEquals('application/x-www-form-urlencoded', $request->server->get('CONTENT_TYPE'));
        $this->assertEquals('val1', $request->request->get('key1'));
        $this->assertEquals('val2', $request->request->get('key2'));
    }
}
