#!/usr/bin/env php
<?php

require_once __DIR__ . '/../silex.phar';

$app = new Silex\Application();

$app['autoloader']->registerNamespace('Jsor', __DIR__ . '/../src');

$app->register(new Jsor\Extension\ApplicationServerExtension());

$app->get('/', function() {
    return "Hello world";
});

$app->get('/setcookie', function() {
    $response = new Symfony\Component\HttpFoundation\Response('Cookie "foo" set');
    $response->headers->setCookie(new Symfony\Component\HttpFoundation\Cookie('foo', 'bar', 0, '/'));
    
    return $response;
});

$app->get('/clearcookie', function() {
    $response = new Symfony\Component\HttpFoundation\Response('Cookie "foo" cleared');
    $response->headers->clearCookie('foo', '/');
    
    return $response;
});

$app->get('/dumpcookies', function() use ($app) {
    return print_r($app['request']->cookies->all(), true);
});

$app['application_server']->listen('8080', '127.0.0.1', '/index.php');
