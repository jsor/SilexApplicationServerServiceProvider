<?php

/*
 * This file is part of the Silex ApplicationServerExtension.
 *
 * (c) Jan Sorgalla <jsorgalla@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once 'phar://'.__DIR__.'/../silex.phar/autoload.php';

$loader->registerNamespaces(array(
    'Jsor' => array(__DIR__, __DIR__.'/../src') 
));
