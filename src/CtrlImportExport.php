<?php

namespace Phnxdgtl\CtrlClient;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

class CtrlImportExport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ctrl:csv
                                {direction : import or export data}
                                {table_name : the table that we\'re importing to, or exporting from}
                                {file_name : the name of the file that we\'re importing/exporting}
                            ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import or Export data';

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
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $direction  = $this->argument('direction');
        $table_name = $this->argument('table_name');
        $file_name  = $this->argument('file_name');

        if (!in_array($direction, ['import', 'export']) || !$table_name || !$file_name) {
            $this->error("Usage: ctrl:csv import|export {table_name} {file_name}");
            exit();
        } else if (!Schema::hasTable(($table_name))) {
            $this->error(sprintf("Table %s doesn't exist", $table_name));
            exit();
        }

        if ($direction == 'import') {
            $this->import();
        } else if ($direction == 'export') {
            $this->export($table_name, $file_name);
        }

        return 0;
    }

    /**
     * Export data from a given table
     * @return void 
     */
    protected function export($table_name, $file_name) {
        /**
         * Model exports aren't yet supported
         */
        // $model = $this->getModelNameFromTableName($table_name);

        $headers  = $this->getTableColumns($table_name);
        $filtered = array_diff($headers, ['id', 'updated_at']);
        $data     = DB::table($table_name)->select($filtered)->get()->map(function ($object) {
            return (array)$object;
        })->toArray();
        $csv = $this->array2csv($data);
        
        if (!Storage::disk($this->getDisk())->put($file_name, $csv)) {
            $this->error(sprintf("Cannot write to %s", $file_name));
        } else {
            $this->line(sprintf("File exported to %s", $file_name));
        }
        
    }

    /**
     * Get the disk we should use; also lifted from CtrlClientController
     * @return mixed 
     */
    protected function getDisk() {
        if (config()->has('filesystems.disks.ctrl')) {
			return 'ctrl';
		} else {
			return config('filesystems.default');
		}
    }

    /**
	 * Given the name of a table, what's the corresponding Ctrl class called? 
     * Duplicated from the CtrlClientController
	 * @param string $table_name 
	 * @return string 
	 */
	protected function getModelNameFromTableName($table_name) {
		return '\App\Models\Ctrl\\'.Str::studly(Str::singular($table_name));
	}

    /**
     * Based on: https://stackoverflow.com/a/71127234/1463965
     * @param string $table_name 
     * @return array 
     * @throws InvalidArgumentException 
     */
    protected function getTableColumns($table_name)
    {
        $headers = DB::select(
            (new \Illuminate\Database\Schema\Grammars\MySqlGrammar)->compileColumnListing()
                .' order by ordinal_position',
            [env('DB_DATABASE'), $table_name]
        );
        return collect($headers)->pluck('column_name')->toArray();
    }

    /**
     * From: https://stackoverflow.com/a/13474770/1463965
     * @param array $array 
     * @return string|false|null 
     */
    protected function array2csv(array &$array)
    {
        if (count($array) == 0) {
            return null;
        }
        ob_start();
        $df = fopen("php://output", 'w');
        fputcsv($df, array_keys(reset($array)));
        foreach ($array as $row) {
            fputcsv($df, $row);
        }
        fclose($df);
        return ob_get_clean();
    }
}
