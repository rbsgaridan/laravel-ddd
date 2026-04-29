<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Incoder\DDD\Support\TypeScript\TypeScriptProxyGenerator;

class GenerateTypeScriptProxies extends Command
{
    protected $signature = 'proxy:generate
        {--output=resources/js/proxiees : Relative output path for the generated proxies}';

    protected $description = 'Generate TypeScript AppService proxies and DTO models from the auto-registered AppServices';

    public function handle(TypeScriptProxyGenerator $generator): int
    {
        $output = (string) $this->option('output');

        $this->info('Generating TypeScript proxies from AppServices...');

        $result = $generator->generate($output);

        $this->info("Generated <comment>{$result['services']}</comment> service proxies.");
        $this->info("Generated <comment>{$result['dtoSchemas']}</comment> DTO/schema models.");
        $this->line("Output: <comment>{$result['outputPath']}</comment>");

        return self::SUCCESS;
    }
}
