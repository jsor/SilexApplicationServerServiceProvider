ApplicationServerExtension for Silex
====================================

A simple application server for your Silex applications.

**Note**: This is WIP, is meant to be used locally for testing purposes and is _not_ production ready.

Usage
-----

Create a file with following content and run it from the command line:

    #!/usr/bin/env php
    <?php

    $app = new Silex\Application();

    $app->register(new Jsor\Extension\ApplicationServerExtension());

    $app->get('/', function() {
        return "Hello world";
    });

    $app['application_server']->listen('8080', '127.0.0.1', '/index.php');

This will start the application server on `127.0.0.1:8080` with the base path `/index.php`.
