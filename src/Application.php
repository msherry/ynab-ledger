<?php

namespace YnabLedger;

use CLIFramework\Application as BaseApplication;

class Application extends BaseApplication {
    public function init () {
        parent::init();
        $this->command('convert');
    }
}
