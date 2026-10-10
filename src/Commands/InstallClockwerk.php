<?php

declare(strict_types=1);

namespace Crab\Clockwerk\Commands;

use Crab\Contracts\Config\Config;
use Crab\Contracts\Console\Command;
use Crab\Contracts\Console\Input;
use Crab\Contracts\Console\Output;
use Crab\Contracts\Database\Migrator;

final readonly class InstallClockwerk implements Command
{
    public function __construct(private Migrator $migrator, private Config $config)
    {
    }

    public function description(): string
    {
        return "Write the migration for the dashboard's tables into your migrations\n\nRun `migrate` afterwards to create them.";
    }

    public function handle(Input $input, Output $output): int
    {
        $prefix = (string) $this->config->get('clockwerk.table');
        $name = "create_{$prefix}_tables";
        foreach (array_keys($this->migrator->status()) as $existing) {
            if (str_ends_with($existing, $name)) {
                $output->line("Your migrations already have {$existing}; nothing was written.");
                return 0;
            }
        }
        $file = $this->migrator->make($name);
        file_put_contents($file, self::migration($prefix));
        $output->line("Created {$file}");
        $output->line('Run `migrate` to create the tables.');
        return 0;
    }

    private static function migration(string $prefix): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use Crab\Contracts\Database\Migration;
            use Crab\Contracts\Database\Schema;
            use Crab\Contracts\Database\Table;

            return new class implements Migration {
                public function up(Schema \$schema): void
                {
                    // What each worker counted, by minute: requests by route, jobs by class, tasks by name.
                    \$schema->create('{$prefix}_metrics', function (Table \$table) {
                        \$table->id();
                        \$table->string('worker', 60);
                        \$table->string('kind', 16);
                        \$table->string('name', 160);
                        // Tells names apart exactly, where the database would take "/Foo" and "/foo" for one.
                        \$table->string('name_hash', 40);
                        // The Unix timestamp of the start of the minute.
                        \$table->bigInteger('bucket');
                        \$table->integer('runs');
                        \$table->float('total_ms');
                        \$table->float('max_ms');
                        \$table->integer('errors');
                        \$table->unique(['worker', 'kind', 'name_hash', 'bucket']);
                        \$table->index(['kind', 'bucket']);
                    });
                    // What is read one by one: exceptions, with where they came from.
                    \$schema->create('{$prefix}_events', function (Table \$table) {
                        \$table->id();
                        \$table->string('kind', 16);
                        \$table->bigInteger('at');
                        \$table->string('title');
                        \$table->text('detail');
                        \$table->index(['kind', 'at']);
                    });
                    // The workers alive, each rewritten every few seconds by itself.
                    \$schema->create('{$prefix}_workers', function (Table \$table) {
                        \$table->string('id', 60)->unique();
                        \$table->string('group_name', 60);
                        \$table->integer('pid');
                        \$table->string('host', 120);
                        \$table->bigInteger('started_at');
                        \$table->bigInteger('seen_at')->index();
                        \$table->bigInteger('memory');
                        \$table->bigInteger('handled');
                    });
                }

                public function down(Schema \$schema): void
                {
                    \$schema->drop('{$prefix}_workers');
                    \$schema->drop('{$prefix}_events');
                    \$schema->drop('{$prefix}_metrics');
                }
            };

            PHP;
    }
}
