#!/usr/bin/env php
<?php

require 'vendor/autoload.php';

if (!ini_get('date.timezone')) {
    date_default_timezone_set('UTC');
}

(new YnabLedger\Application)->run($argv);
