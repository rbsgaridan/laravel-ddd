<?php

namespace Incoder\DDD\Support\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateFreshSchema extends Command
{
    protected $signature = 'migrate:fresh-schema {schema} 
                            {--seed : Seed the database after migration}
                            {--seeder= : The class name of the root seeder}';
    protected $description = 'Drop all tables from a specific schema and re-run migrations';

    public function handle()
    {
        $schema = $this->argument('schema');
        
        if (!$this->confirm("This will drop all tables in the '{$schema}' schema. Continue?")) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        // Get all tables in the schema
        if ($driver === 'sqlsrv') {
            $tables = DB::select("
                SELECT TABLE_NAME 
                FROM INFORMATION_SCHEMA.TABLES 
                WHERE TABLE_SCHEMA = ? 
                AND TABLE_TYPE = 'BASE TABLE'
            ", [$schema]);
        } elseif ($driver === 'pgsql') {
            $tables = DB::select("
                SELECT tablename as TABLE_NAME 
                FROM pg_tables 
                WHERE schemaname = ?
            ", [$schema]);
        } else {
            $this->error('Unsupported database driver: ' . $driver);
            return 1;
        }

        if (empty($tables)) {
            $this->info("No tables found in schema '{$schema}'");
        } else {
            // Disable foreign key constraints
            $this->info('Disabling foreign key constraints...');
            Schema::disableForeignKeyConstraints();

            // For SQL Server, we need to drop foreign keys first
            if ($driver === 'sqlsrv') {
                $this->info('Dropping foreign key constraints...');
                $foreignKeys = DB::select("
                    SELECT 
                        OBJECT_NAME(f.parent_object_id) AS TableName,
                        f.name AS ForeignKeyName,
                        SCHEMA_NAME(t.schema_id) AS SchemaName
                    FROM sys.foreign_keys AS f
                    INNER JOIN sys.tables AS t ON f.parent_object_id = t.object_id
                    WHERE SCHEMA_NAME(t.schema_id) = ?
                ", [$schema]);

                foreach ($foreignKeys as $fk) {
                    $this->info("Dropping foreign key: {$fk->SchemaName}.{$fk->TableName}.{$fk->ForeignKeyName}");
                    DB::statement("ALTER TABLE [{$fk->SchemaName}].[{$fk->TableName}] DROP CONSTRAINT [{$fk->ForeignKeyName}]");
                }
            }

            // Drop each table
            foreach ($tables as $table) {
                $tableName = "{$schema}.{$table->TABLE_NAME}";
                $this->info("Dropping table: {$tableName}");
                
                try {
                    Schema::drop($tableName);
                } catch (\Exception $e) {
                    $this->error("Failed to drop table {$tableName}: " . $e->getMessage());
                }
            }
            
            // Re-enable foreign key constraints
            Schema::enableForeignKeyConstraints();
        }

        // Run migrations
        $this->info('Running migrations...');
        $this->call('migrate');
        
        $this->info('Schema migration completed!');

        // Run seeders if --seed flag is provided
        if ($this->option('seed')) {
            $this->info('Seeding database...');
            
            $seederClass = $this->option('seeder');
            
            if ($seederClass) {
                $this->call('db:seed', ['--class' => $seederClass]);
            } else {
                $this->call('db:seed');
            }
        }

        return 0;
    }
}