<?php

namespace Phnxdgtl\CtrlClient;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

use Yajra\DataTables\DataTables;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Artisan;

class CtrlClientController extends Controller
{
    public function test() {
		
		if (class_exists('\App\Models\Ctrl\Post')) {
			dd("Ctrl/Post exists");
		} else {
			dd("Ctrl/Post does not exist");
		}
		
        return 'Hello world';
    }

	/**
	 * Return data about an object
     * @param Request $request 
     * @return mixed 
     */
    public function getObjectData(Request $request) {

		$input = $this->validateRequest($request, [
			'ctrl_table_name' => 'required',
			'ctrl_object_id'  => 'required',
		]);

		/**
		 * If we have validation_errors, return them as a response
		 * It's annoying to have to do this here, but we need to return a response, which
		 * we obviously can't do from within the validateRequest function
		 */
		if (!empty($input['validation_errors'])) {
			return response()->json($input['validation_errors'], 404);
		}

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($input['table_name']);
		if (class_exists($model)) {		
			$object_data = $model::findOrFail($input['object_id']);
		} else {
			$object_data = DB::table($input['table_name'])->find($input['object_id']);
		}

		/**
		 * TODO: consider limiting this endpoint to only return certain fields, as per tableData
		 */
		return response()->json($object_data);
	}

	/**
	 * Given the name of a table, what's the corresponding Ctrl class called? 
	 * @param mixed $table_name 
	 * @return string 
	 */
	protected function getModelNameFromTableName($table_name) {
		return '\App\Models\Ctrl\\'.Str::studly(Str::singular($table_name));
	}

	/**
	 * Validate the request
	 * @param Request $input 
	 * @param array $rules The Validation rules
	 * @return void 
	 */
	protected function validateRequest($request, $rules) {
		/**
		 * Validate the request
		 * Also prevent Laravel from removing underscores in variable names, from https://stackoverflow.com/a/69765275/1463965
		 */		
		$keys             = array_keys($rules);
		$customAttributes = array_combine($keys, $keys);
		$validator        = Validator::make($request->all(), $rules, [], $customAttributes);
		/**
		 * If validation fails, return the errors; these are then returned as a response
		 * but we obviously can't return a response() directly from within this function
		 */
		if ($validator->fails()) {
			return [
				'validation_errors'=>$validator->errors()
			];
		}
		/**
		 * Return the validated array of input values, filtered
		 * to remove the 'ctrl_' prefix.
		 * TODO: is this prefix really necessary?
		 */
		$data = $validator->validated();
		foreach ($data as $key=>$value) {
			$key_with_prefix     		= $key;
			$key_without_prefix  		= str_replace('ctrl_', '', $key_with_prefix);
			$data[$key_without_prefix]  = $value;
			unset($data[$key_with_prefix]);
		}
		return $data;
	}

	/**
	 * Get all possible values for a table column
     * @param Request $request 
     * @return mixed 
     */
    public function getPossibleValues(Request $request) {

		$validator = Validator::make($request->all(), [
			'ctrl_table_name'   => 'required',
			'ctrl_field'        => 'required',
			'ctrl_source_table' => 'required',
			'ctrl_source_value' => 'required',
			'ctrl_source_label' => 'required',
			'ctrl_object_id'    => 'nullable',
			'ctrl_limit'        => 'nullable',
			'ctrl_search'       => 'nullable',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 404);
		}

		$data = $validator->validated();

		/**
		 * Extract variables, removing the _ctrl prefix
		 * I'm not sure whether using the prefix is really necessary TBH
		 * TODO: move this validation stuff (and the extract code) into a function
		 */
		foreach ($data as $variable=>$value) {
			${str_replace('ctrl_', '', $variable)} = $value;
		}

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {	
			if ($object_id) {	
				$object = $model::findOrFail($object_id);
			} else {
				$object = new $model;
			}
		}
		/**
		 * This is where we'd check for a "custom value" hook I think?
		 * OR can we use a standard Eloquent mutator?
		 * Something like, if ($object && hook_exist($model) ?
		 * Hooks are TBC, though, so we don't yet use $object at all
		 * or $identifier, I don't think?
		 */

		$records = DB::table($source_table);

		if (!empty($search)) {
			$records->where($source_label, 'like', "%$search%");
		}

		if (!empty($limit)) {
			$records->take($limit);
		}
		$data = $records->pluck($source_label, $source_value);

		return response()->json($data);
	}

	/**
	 * Get all related values for an object (ie, the IDs of all Posts written by an Author)
     * @param Request $request 
     * @return mixed 
     */
    public function getRelatedValues(Request $request) {
		
		$validator = Validator::make($request->all(), [
			'ctrl_table_name'   => 'required',
			'ctrl_field'        => 'required',
			'ctrl_source_table' => 'required',
			'ctrl_source_value' => 'required',
			'ctrl_source_label' => 'required',
			'ctrl_join_table'   => 'nullable',
			'ctrl_local_key'    => 'nullable',
			'ctrl_foreign_key'  => 'nullable',
			'ctrl_parent_key'   => 'nullable',
			'ctrl_related_key'  => 'nullable',
			'ctrl_object_id'    => 'required',
				// We only pull related values if we're editing an existing object
				// (a new object obviously has no existing values, related or otherwise)
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 404);
		}

		$data = $validator->validated();

		/**
		 * Extract variables, removing the _ctrl prefix
		 * I'm not sure whether using the prefix is really necessary TBH
		 * TODO: move this validation stuff (and the extract code) into a function
		 */
		foreach ($data as $variable=>$value) {
			${str_replace('ctrl_', '', $variable)} = $value;
		}
		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {	
			$object = $model::findOrFail($object_id);
		}

		/**
		 * Again, hooks (and how we use Eloquent) are TBC here. Just use a standard DB connector for now
		 * NOTE: we're using $local_key with a dual purposes here, which is wrong. If we're listing all
		 * POSTS written by an AUTHOR, we know we want to select all ID values (the local key of the column-less property)
		 * from the POSTS table, where the AUTHOR_ID column (the foreign key) matches the ID of the Author (the local key).
		 * But how do we know that it's the ID value we want to select? The fact that it's 'id', which matches the local key,
		 * is actually academic. We should actually be passing this as a parameter similar to 'string' in getPossibleValues,
		 * but we can only ASSUME that it should be 'id', I don't think our model actually confirms this.
		 * UPDATE: this is the parent_key, I think? Review this.
		 */
		if ($join_table) {
			/**
			 * I'm honestly not sure if we're using parent_key and related_key properly here; time will tell!			 
			 * (That is, once we start to test this against real-world databases, we'll see if anything breaks)
			 * They're both 'id' at the moment, but I may have them the wrong way around here
			 */
			$data = DB::table($source_table)						
						->join($join_table, "$join_table.$foreign_key", '=', "$source_table.$parent_key")
						->join($table_name, "$join_table.$local_key", '=', "$table_name.$related_key")
						->where($local_key, $object_id)
						->pluck("$source_table.$source_label", "$source_table.$related_key");
		} else {
			$data = DB::table($source_table)->where($foreign_key, $object_id)->pluck($source_label, $local_key);
		}
		

		return response()->json($data);
	}

	/**
	 * SAVE the related values for an object (ie, the IDs of all Posts written by an Author)
     * @param Request $request 
     * @return mixed 
     */
    public function putRelatedValues(Request $request) {

		$validator = Validator::make($request->all(), [
			'ctrl_table_name'   => 'required',
			'ctrl_field'        => 'required',
			'ctrl_source_table' => 'required',
			'ctrl_source_value' => 'required',
			'ctrl_source_label' => 'nullable',
			'ctrl_join_table'   => 'nullable',
			'ctrl_local_key'    => 'nullable',
			'ctrl_foreign_key'  => 'nullable',
			'ctrl_parent_key'   => 'nullable',
			'ctrl_related_key'  => 'nullable',
			'ctrl_object_id'    => 'required',
			'ctrl_values'		=> 'required'
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 404);
		}

		$data = $validator->validated();

		/**
		 * Extract variables, removing the _ctrl prefix
		 * I'm not sure whether using the prefix is really necessary TBH
		 * TODO: move this validation stuff (and the extract code) into a function
		 */
		foreach ($data as $variable=>$value) {
			${str_replace('ctrl_', '', $variable)} = $value;
		}

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {	
			if ($object_id) {	
				$object = $model::findOrFail($object_id);
			} else {
				$object = new $model;
			}
		}

		/**
		 * Again, hooks (and how we use Eloquent) are TBC here. Just use a standard DB connector for now
		 **/
		if ($join_table) {
			// Update the join table, not the source table (which in this instance should be target table TODO)
			DB::table($join_table)->where($local_key, $object_id)->delete();
			if ($values) {
				foreach ($values as $value) {
					DB::table($join_table)->insert([
						$foreign_key => $value,
						$local_key   => $object_id,
					]);
				}
			}
		} else {
			// Delete all existing records? This just handles one-to-many, not many-to-many...
			DB::table($source_table)->where($foreign_key, $object_id)->update([$foreign_key => null]);
			if ($values) {
				DB::table($source_table)->whereIn($local_key, $values)->update([$foreign_key=>$object_id]);
			}
		}
		

		return response()->json(['success'=>true]);
	}

	/**
	 * Save object data
     * @param Request $request 
     * @return mixed 
     */
    public function putObjectData(Request $request) {
		
		/**
		 * Use validation here
		 */
		$table_name = $request->input('ctrl_table_name');
		$object_id  = $request->input('ctrl_object_id');
		$data       = $request->input('ctrl_data');

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {						
			/**
			 * Don't require each object to have a $guarded or $fillable attribute
			 */
			$model::unguard();
			// We could possibly use updateOrCreate here I think? Will that work with a null 'id'	
			if ($object_id) {
				$object = $model::findOrFail($object_id);				
				$object->update($data);			
			} else {
				$object = $model::create($data);
				$object_id = $object->id;
			}
		} else {
			// As above, we could potentially use upsert here
			if ($object_id) {
				$object = DB::table($table_name)->where('id', $object_id);
				$object->update($data);							
			} else {
				$object_id = DB::table($table_name)->insertGetId($data);
			}
		}		

		return response()->json([
			'success'   => true,
			'object_id' => $object_id
		]);
	}

    /**
	 * Return data in a Datatables format
     * @param Request $request 
     * @return mixed 
     */
    public function getTableData(Request $request) {

		// TODO: add validation here
		
		$table_name    = $request->input('ctrl_table_name');		
		$table_headers = array_merge($request->input('ctrl_table_headers') ?? [], ['id']);

		/**
		 * TODO: ctrl_filters here should definitely just be the filtered querystring.
		 * Why are we getting odd DataTable values in it, like 'order'
		 */

		$filters = $request->input('ctrl_filters');
		Log::debug("Filters", $filters);
		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {			
			$data = $model::query();			
		} else {
			$data = DB::table($table_name);
		}

		if ($filters) {
			/**
			 * There could be an "elegant" laravel solution here, using Arr:divide or Arr:flatten,
			 * but TBH we might just be overcomplicating it for the sake of using Laravel:
			 */
			foreach ($filters as $filter_key=>$filter_value) {
				if (!is_array($filter_value)) {
					/**
					 * TODO: how do we tell the difference between a DataTables QS parameter
					 * and a genuine filter? Shouldn't "our" filters be keyed properly?
					 * SEE ABOVE, there's an issue here
					 */
					Log::debug(sprintf("Filtering where %s is %s", $filter_key, $filter_value));
					$data->where($filter_key, $filter_value);
				}
			}			
		}
		
		/**
		 * Only select the required headers, for speed;
		 * we may not want to do this when loading an eloquent model?
		 */
		$data->select($table_headers);
		$datatables = DataTables::of($data);
		
		/**
		 * Add an ID for each row, this allows us to (eg) highlight the last-edited row
		 */
		$datatables->setRowId('id-{{$id}}');
		
		$row_buttons = $request->input('ctrl_row_buttons') ?? [];	
        if ($row_buttons) {
            $datatables->addColumn('action', function ($row) use ($row_buttons) {						
				array_walk($row_buttons, function(&$value, $key) use ($row) {
					$value = str_replace('_id_', $row->id, $value);
				});
                return view('ctrl::row-buttons', ['row_buttons'=>$row_buttons]);
            });
        }
		return $datatables->toJson();
	}

    /**
	 * Return the structure of the database
     * @return JsonResponse 
     * @throws BindingResolutionException 
     */
    public function getDatabaseStructure() {

		$data          = [];

		$tables        = DB::select('SHOW TABLES');
        foreach ($tables as $table) {
			/**
			 * The DB::select() above returns objects with a "Tables_in_ctrl_client" key,
			 * so we need to pull out the object value to get the actual table name
			 */
			$table_name = current(get_object_vars($table));

			/**
			 * Ignore some tables, we'll never want to manage these
			 */
			if (in_array($table_name, [
				'migrations',
				'jobs',
				'failed_jobs',
				'password_resets',
				'personal_access_tokens'
			])) {
				continue;
			}

			/**
			 * Pull out the list of columns. I don't believe we can use bindings with SHOW TABLES, for some reason.			 
			 */
			$columns = DB::select("
							SHOW COLUMNS
							FROM {$table_name}
							WHERE Field != 'id'
							AND Field != 'updated_at'
							AND Field != 'created_at'
						"); 
			$data[$table_name] = $columns;
		}
		return response()->json($data, 200);
	}

	/**
	 * Trigger a sync to Typesense via an Artisan command (so that we can queue it)
	 * @return void 
	 */
	public function syncSearch(Request $request) {

		$validator = Validator::make($request->all(), [
			'ctrl_fresh'      => 'nullable',
			'ctrl_table_name' => 'required',
			'ctrl_column'     => 'required',
			'ctrl_url_format' => 'required',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 404);
		}

		$data = $validator->validated();

		/**
		 * Extract variables, removing the _ctrl prefix
		 * I'm not sure whether using the prefix is really necessary TBH
		 * TODO: move this validation stuff (and the extract code) into a function
		 */
		foreach ($data as $variable=>$value) {
			${str_replace('ctrl_', '', $variable)} = $value;
		}

		$parameters = [
			'args' => ['search', $table_name, $column, $url_format]
		];

		if ($fresh) {
			$parameters['--fresh'] = true;
		}

		Artisan::queue('ctrl', $parameters);

		return response()->json([
			'success' => true
		], 200);
	}

}
