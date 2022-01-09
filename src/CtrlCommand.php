<?php

namespace Phnxdgtl\CtrlClient;

use Illuminate\Console\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Typesense\Client as TypesenseClient;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

class CtrlCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ctrl {args?*} {--fresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Helper commands for the Control CMS';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Extract required arguments from the arguments passed into the Artisan command
     * Can be a single index (one-based), so getArgs(1) gets the first argument
     * or an array of indexes (also one-based), so getArgs([1,3]) gets the first and third arguments
     * @param integer|array $position 
     * @return mixed 
     * @throws InvalidArgumentException 
     */
    protected function getArgs($position) {
        $args = $this->arguments('args')['args'];   
        if (count($args) == 0) {
            $this->error("No argument specified");
            exit();
        } else {
            if (is_array($position)) {
                if (count($args) < count($position)) {
                    $this->error("Not enough arguments available");                    
                } else {
                    $return = [];
                    foreach ($position as $p) {
                        $return[] = $args[$p-1];
                    }
                    return $return;
                }
            } else {
                return $args[$position-1];
            }            
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {        

        $command = $this->getArgs(1);
        switch ($command) {
            case 'search':
                $this->search();
                break;
            case 'images':
                $this->imagesToS3();
                break;
            default:
                $this->error(sprintf("Unrecognised command %s", $command));
        }
        return Command::SUCCESS;
    }

    /**
     * Some search functions... probably just to sync data to Typesense, TBC
     * @return void 
     */
    protected function search() {
        /**
         * This assumes that we're using Typesense Cloud
         */
        $client      = $this->getTypesenseClient();
        $schema_name = $this->getSchemaName();

        $this->info(sprintf("Processing schema %s", $schema_name));

        if (!$this->schemaExists($client, $schema_name)) {
            if (!$this->option('fresh')) { // Don't state the obvious!
                $this->info(sprintf("Schema %s does not exist", $schema_name));
            }
            $this->createSchema($client, $schema_name);
            $this->info(sprintf("Schema %s created", $schema_name));
        } else if ($this->option('fresh')) {
            $this->info(sprintf("Deleting all documents from schema %s", $schema_name));
            $client->collections[$schema_name]->delete();
        }

        [$table_name, $column, $url_format] = $this->getArgs([2, 3, 4]);
        
        $log = sprintf("Pulling column %s from table %s. URL format is %s", $table_name, $column, $url_format);
        
        Log::debug($log);
        $this->line($log);

        $records = DB::table($table_name)->select('id', $column)->get();

        if (count($records) > 0) {
            if ($table_name == 'matches') {
                Log::debug($records);
            }
            $documents = [];
            foreach ($records as $record) {
                $documents[] = [
                    'id'            => sprintf('%s-%s', $table_name, $record->id),
                    'title'         => $record->$column,
                    'url'           => str_replace('_id_', $record->id, $url_format)
                ];
            }
            $client->collections[$schema_name]->documents->import($documents, ['action' => 'upsert']);
            
        }

        $this->line("Search indexed");

    }

    protected function getTypesenseClient() {
        $host   = env('TYPESENSE_HOST', false);
        $key    = env('TYPESENSE_KEY', false);

        if (!$host || !$key) {
            if (!$host) {
                $this->error("No TYPESENSE_HOST found in .env");
            }
            if (!$key) {
                $this->error("No TYPESENSE_KEY found in .env");
            }            
            exit();
        }

        $client = new TypesenseClient(
            [
              'api_key'         => $key,
              'nodes'           => [
                [
                  'host'     => $host,
                  'port'     => '443',
                  'protocol' => 'https',
                ],
              ],
              'connection_timeout_seconds' => 2,
            ]
        );
        return $client;
    }

    protected function getSchemaName() {
     
        $schema_name = request()->getHttpHost();

        if (!$schema_name) {
            $this->error("Cannot generate schema name from URL");
            exit();
        }
        return $schema_name;
    }

    protected function schemaExists($client, $schema_name) {
        try {
            $client->collections[$schema_name]->retrieve();
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            return false;
        } catch (\Exception $e) {
            $this->error(sprintf("Error connecting to Typesense: %s", $e->getMessage()));
            exit();
        }
        return true;
    }

    protected function createSchema($client, $schema_name) {
        $schema = [
            'name' => $schema_name,
            'fields' => [
              ['name' => 'id',      'type' => 'string'],
              ['name' => 'title',   'type' => 'string'],
              ['name' => 'url',     'type' => 'string'],
            ]
        ];          
        $client->collections->create($schema);
    }
}
