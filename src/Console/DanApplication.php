<?php

declare(strict_types=1);

namespace Dan\Harness\Console;

use Dan\Harness\Console\Diff\DiffCommand;
use Dan\Harness\Console\Run\RunCommand;
use Symfony\Component\Console\Application;

final class DanApplication extends Application
{
    public function __construct()
    {
        parent::__construct('DAN - DAL ANalyzer', '0.1.0');

        $this->addCommand(new RunCommand());
        $this->addCommand(new DiffCommand());
    }
}
