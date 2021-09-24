<?php

namespace Damms005\LaravelCashier\Commands;

use Illuminate\Console\Command;

class LaravelCashierCommand extends Command
{
    public $signature = 'laravel-cashier';

    public $description = 'My command';

    public function handle()
    {
        $this->comment('All done');
    }
}
