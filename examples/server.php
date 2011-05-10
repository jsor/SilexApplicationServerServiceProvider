#!/usr/bin/env php
<?php

require_once __DIR__ . '/../silex.phar';

$app = new Silex\Application();

$app['autoloader']->registerNamespace('Jsor', __DIR__ . '/../src');

$app->register(new Jsor\Extension\ApplicationServerExtension());

$app->get('/', function() {
    return "Hello world";
});

$app['application_server']->listen('8080', '127.0.0.1', '/index.php');
