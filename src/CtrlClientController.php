<?php

namespace Phnxdgtl\CtrlClient;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Typesense\Client as TypesenseClient;

use Yajra\DataTables\DataTables;

use Intervention\Image\Facades\Image;
use Intervention\Image\Exception\NotReadableException;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Artisan;

class CtrlClientController extends Controller
{

	const VERSION = 'dev';

	protected $filesystem_disk = null;

    public function test() {
		
		if (class_exists('\App\Models\Ctrl\Post')) {
			dd("Ctrl/Post exists");
		} else {
			dd("Ctrl/Post does not exist");
		}
		
        return 'Hello world';
    }

	/**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
		/**
		 * If we've defined a specific 'ctrl' disk in the filesystems config, use that.
		 * Otherwise, use the default one. 
		 * This allows us to separate out files managed by CTRL (such as images)
		 * from other files that might be used by the site.
		 * This could include existing local images, from before CTRL was used.
		 */
		if (config()->has('filesystems.disks.ctrl')) {
			$this->filesystem_disk = 'ctrl';
		} else {
			$this->filesystem_disk = config('filesystems.default');
		}
	}


	/**
	 * Return data about an object
     * @param Request $request 
     * @return mixed 
     */
    public function getObjectData(Request $request) {

		$input = $this->validateRequest($request, [
			'ctrl_table_name'        => 'required',
			'ctrl_object_id'         => 'required',
			'ctrl_object_properties' => 'required',
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
		 * object_properties here is an array of column_name=>field_type pairs
		 **/		
		$object_properties = array_merge($request->input('ctrl_object_properties') ?? [], ['id'=>'number']);

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */

		/**
		 * Only select the required headers, for speed;
		 * we may not want to do this when loading an eloquent model?
		 */

		 /**
		  * array_filter here filters out any properties without a "column";
		  * -- but will this mean that we can't edit many-to-many relationships?
		  */
		$select = array_filter(array_keys($object_properties));

		$model = $this->getModelNameFromTableName($input['table_name']);
		if (class_exists($model)) {		
			$object_data = $model::select($select)->findOrFail($input['object_id']);
		} else {
			$object_data = DB::table($input['table_name'])->select($select)->find($input['object_id']);
		}

		/**
		 * Now... we want to thumbnail any images, so that we can render them in the preview when editing
		 */
		foreach ($object_properties as $column=>$field_type) {
			if ($field_type == 'image') {
				$path                 = $object_data->$column;
				$thumbnail_name       = sprintf('%s/%s', $object_data->id, $column);

				/**
				 * Don't set this, as we end up overwriting an image path, with a full URL
				 */
				// $object_data->$column = $this->getThumbnameUrlFromImagePath($path, 1200, 800, $thumbnail_name);
				/**
				 * Instead, generate a specific thumbnail value for the image preview:
				 */
				$object_data->{$column.'_thumbnail'} = $this->getThumbnameUrlFromImagePath($path, 1200, 800, $thumbnail_name);

			}
		}

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
			// 'ctrl_field'        => 'required', // Do we even use this? I don't believe so
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
			'ctrl_field'        => 'required', // Do we even use this? I don't believe so
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
						->where("$join_table.$local_key", $object_id)
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
			// 'ctrl_field'        => 'required', // Do we even use this? I don't believe so
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
		 * If a field can't be null, convert any null values to '' or zero
		 */
		array_walk($data, function(&$item, $key) use ($table_name) {
			if (is_null($item)) {
				$columns = DB::select("
								SELECT IS_NULLABLE, NUMERIC_PRECISION
								FROM INFORMATION_SCHEMA.COLUMNS
								WHERE TABLE_NAME = '{$table_name}'
								AND COLUMN_NAME = '{$key}'
							");
				$is_nullable = $columns[0]->IS_NULLABLE ?? false;
				$is_numeric  = $columns[0]->NUMERIC_PRECISION ?? false;
				if ($is_nullable != 'YES') {
					if ($is_numeric) {
						$item = 0;
					} else {
						$item = '';
					}					
				}
			}
		});

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
	 * Delete an object
     * @param Request $request 
     * @return mixed 
     */
    public function deleteObject(Request $request) {
		
		/**
		 * TODO: use validation here
		 */
		$table_name = $request->input('ctrl_table_name');
		$object_id  = $request->input('ctrl_object_id');

		/**
		 * If we have a custom model, use Eloquent. Otherwise, just run a DB query
		 */
		$model = $this->getModelNameFromTableName($table_name);
		if (class_exists($model)) {						
			$object = $model::findOrFail($object_id);	
			$object->delete();	
		} else {
			DB::table($table_name)->where('id', $object_id)->delete();
		}		

		return response()->json([
			'success'   => true
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
		/**
		 * table_headers here is an array of column_name=>field_type pairs
		 **/		
		$table_headers = array_merge($request->input('ctrl_table_headers') ?? [], [
			'id'=>['field_type'=>'number']
		]);

		$filters = $request->input('ctrl_filters');
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
		$data->select(array_keys($table_headers));
		$datatables = DataTables::of($data);
		
		/**
		 * Add an ID for each row, this allows us to (eg) highlight the last-edited row
		 */
		$datatables->setRowId('id-{{$id}}');
		
		/**
		 * Add datatable buttons
		 */
		$row_buttons = $request->input('ctrl_row_buttons') ?? [];	
        if ($row_buttons) {
            $datatables->addColumn('action', function ($row) use ($row_buttons) {						
				array_walk($row_buttons, function(&$value, $key) use ($row) {
					$value = str_replace('_id_', $row->id, $value);
				});
                return view('ctrl::row-buttons', ['row_buttons'=>$row_buttons]);
            });
        }

		/**
		 * Process certain columns so that they're rendered differently	
		 * Also allow us to render HTML in some columns; see https://yajrabox.com/docs/laravel-datatables/master/xss#raw	
		 */
		$raw_columns = [];
		foreach ($table_headers as $column=>$column_data) {
			$field_type = $column_data['field_type'];
			
			if (!empty($column_data['source_table'])) {
				/**
				 * This indicates that this is a relationship, so pull the related value
				 */	
				$datatables->editColumn($column, function($object) use ($column_data) {				
					$source_table  = $column_data['source_table'];
					$source_column = $column_data['source_column'];
					$foreign_key   = $column_data['foreign_key'];
					$local_key     = $column_data['local_key'];
					return DB::table($source_table)
								->where($foreign_key, $object->$local_key)
								->value($source_column);
				});
			}
			else if (in_array($field_type, ['text', 'textarea', 'wysiwyg'])) {
				$datatables->editColumn($column, function($object) use ($column) {				
					return Str::words(html_entity_decode(strip_tags($object->$column)), 15, '...');	    		
				});
			} else if (in_array($field_type, ['checkbox'])) {
				$datatables->editColumn($column, function($object) use ($column) {				
					return sprintf('<i class="fa-regular fa-lg fa-square%s"></i>', !empty($object->$column) ? '-check' : '');
				});
				$raw_columns[] = $column;
			} else if (in_array($field_type, ['image'])) {
				$datatables->editColumn($column, function($object) use ($column) {				
					$path  = $object->$column;
					$thumbnail_name = sprintf('%s/%s', $object->id, $column);							
					$image_tag = $this->getThumbnameUrlFromImagePath($path, 50, 50, $thumbnail_name, '<img src="%s">');			
					return $image_tag;	
					// If we can't load an image we could return this, but... why wouldn't we be able to?
					// return sprintf('<i class="far fa-image"></i>');
				});
				$raw_columns[] = $column;
			} else if (in_array($field_type, ['date'])) {
				$datatables->editColumn($column, function($object) use ($column) {				
					return \Carbon\Carbon::parse($object->$column)->format('d/m/Y');
				});
			} else if (in_array($field_type, ['file'])) {
				/**
				 * Just return the filename, not the path
				 * We could trim it as well, but realistically, we rarely use filenames as headers
				 */
				$datatables->editColumn($column, function($object) use ($column) {	
					$path_parts = pathinfo($object->$column);		
					return $path_parts['basename'];
				});
			}
		}
		
		if ($raw_columns) {
			$datatables->rawColumns($raw_columns);
		}

		return $datatables->toJson();
	}

	protected function getThumbnameUrlFromImagePath($path, $width, $height, $thumbnail_name, $wrapper = null) {
		/**
		 * We want to load the image, and send the URL of a thumbnail to the server
		 * It's difficult to know exactly how the image path here relates to a physical file
		 * but let's assume that it's stored on the public disk, if it's not a full URL
		 * 
		 * NO: use Storage properly. Load the image from whichever disk we're using:
		 */
		
		if (!$path) {
			return false;
		}

		$thumbnail_format = 'jpg';
				
		/**
		 * Establish a unique path for this thumbnail
		*/
		$thumbnail = implode('/', [
			'ctrl-thumbnails',
			$thumbnail_name,
			$width,
			$height,
			md5($path).'.'.$thumbnail_format,
		]);			
			
		/**
		 * If we don't already have a thumbnail stored on the ctrl disk (ie, the thumbnail/image store), create one
		 */
		if (!Storage::disk($this->filesystem_disk)->exists($thumbnail)) {
			Log::debug("no thumbnail");
			Log::debug("Path is ", [$path]);			
		
			/**
			 * If we're using a remote disk, and working with a path (as opposed to a URL), then we need to
			 * generate the full URL so that Intervention can generate the thumbnail:
			 */
			if (
				filter_var($path, FILTER_VALIDATE_URL) === FALSE
				&&
				config(sprintf('filesystems.disks.%s.driver', $this->filesystem_disk)) != 'local')
			{				
				$path = Storage::disk($this->filesystem_disk)->url($path);
				Log::debug("Path is now ", [$path]);
			}
			
			try {	
				Log::debug("Trying to load $path");	
				$image     = Image::make($path)->fit($width, $height);
			} catch(NotReadableException $e) {
				/**
				 * We might be in the local "packages" folder when developing locally
				 * Usually this will be the main vendor folder though:
				 */
				$package_path = dirname(__FILE__);
				if ($width > 100 || $height > 100) {				
					$missing_image = '/assets/image-not-found.png';
				} else {
					$missing_image = '/assets/image-not-found-small.png';
				}
				$missing_image_path = $package_path.$missing_image;
				$image              = Image::make($missing_image_path)->fit($width, $height);
				$thumbnail = implode('/', [
					'ctrl-thumbnails',
					$thumbnail_name,
					$width,
					$height,
					'ctrl-image-missing.png',
				]);
			}

			Storage::disk($this->filesystem_disk)->put($thumbnail, $image->stream($thumbnail_format, 60), 'public');
		}
	
		/**
		 * I like the idea of using a temporary URL here BUT it breaks the MDB file upload plugin,
		 * which will only render an image preview if the URL ends in .png or similar
		 */
		$image_url = Storage::disk($this->filesystem_disk)->url(
			$thumbnail, now()->addMinutes(5)
		);

		if ($wrapper) {
			return sprintf($wrapper, $image_url);		
		} else {
			return $image_url;
		}
		
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
						"); 
			$data[$table_name] = $columns;
		}
		return response()->json($data, 200);
	}

	
	/**
	 * Trigger a sync to Typesense, lifted from a previous artisan command
	 * @return void 
	 */
    protected function buildTypesenseIndex(Request $request) {
        
		$validator = Validator::make($request->all(), [
			'ctrl_fresh'      => 'nullable',
			'ctrl_title'      => 'nullable',
			'ctrl_table_name' => 'nullable',
			'ctrl_column'     => 'nullable',
			'ctrl_url'        => 'nullable',
			'ctrl_schema'     => 'required',
		]);

		if ($validator->fails()) {
			return response()->json($validator->errors(), 404);
		}

		$schema_name = $request->input('ctrl_schema');
		$title       = $request->input('ctrl_title');
		$taxonomy    = $request->input('ctrl_taxonomy');
		$table_name  = $request->input('ctrl_table_name');
		$column      = $request->input('ctrl_column');
		$url         = $request->input('ctrl_url');
		$fresh       = $request->input('ctrl_fresh');

        if (!$schema_name) {
            trigger_error("You need to specify a schema name when running this command manually. This correct schema is sent automatically when triggering an index from the server");
        }

        if (
            (
                (
                    (!$table_name || !$column)
                    &&
                    !$title
                )
                || !$url
            )
            && !$fresh
        ) {
            trigger_error("We need to specify the title of the item (or, a table_name and column), plus a url, unless we're wiping the current index via --fresh");
            exit();
        }

        Log::info(sprintf("Processing schema %s", $schema_name));

		$client      = $this->getTypesenseClient();

        if (!$this->schemaExists($client, $schema_name)) {
            if (!$fresh) { // Don't state the obvious!
                Log::info(sprintf("Schema %s does not exist", $schema_name));
            }
            $this->createSchema($client, $schema_name);
            Log::info(sprintf("Schema %s created", $schema_name));

            /**
             * I think we need to refresh the client here, so that we're aware of the new schema?
             */
            // TODO: this doesn't work. Review this when we next create a new index...
            $client = $this->getTypesenseClient();

        } else if ($fresh) {  
            Log::debug(sprintf("DELETING SCHEMA NAME %s as --fresh is set to %s", $schema_name, $fresh));          
            $client->collections[$schema_name]->delete();
            Log::info(sprintf("All documents from schema %s have been deleted", $schema_name));
            return response()->json([
				'success' => true
			], 200);
        }
        
        /**
         * If we're just adding a fixed item to the index from the Ctrl Server (eg, a link to a list of Things)
         * then add it here. Otherwise, we use table_name and column to pull actual data from the database
         */
        $documents = [];
        if ($title) {
            $log = sprintf("Adding record with title %s. URL format is %s", $title, $url);
            $documents[] = [
                'id'       => sprintf('%s', Str::slug($title)),
                'title'    => $title,
                'taxonomy' => $taxonomy,
                'url'      => $url
            ];
        } else {

            $log = sprintf("Pulling column %s from table %s. URL format is %s", $table_name, $column, $url);
            
            $records = DB::table($table_name)->select('id', $column)->get();

            if (count($records) > 0) {
                foreach ($records as $record) {
                    $documents[] = [
                        'id'       => sprintf('%s-%s', $table_name, $record->id),
                        'title'    => $record->$column,
                        'taxonomy' => $taxonomy,
                        'url'      => str_replace('_id_', $record->id, $url)
                    ];
                }                            
            }
        }
        Log::debug($log);
        if ($documents) {
            $client->collections[$schema_name]->documents->import($documents, ['action' => 'upsert']);
        }

        Log::info("Search indexed");
		return response()->json([
			'success' => true
		], 200);

    }

    protected function getTypesenseClient() {
        $host   = env('TYPESENSE_HOST', false);
        $key    = env('TYPESENSE_KEY', false);

        if (!$host || !$key) {
            if (!$host) {
                trigger_error("No TYPESENSE_HOST found in .env");
            }
            if (!$key) {
                trigger_error("No TYPESENSE_KEY found in .env");
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

    protected function schemaExists($client, $schema_name) {
        try {
            $client->collections[$schema_name]->retrieve();
        } catch (\Typesense\Exceptions\ObjectNotFound $e) {
            return false;
        } catch (\Exception $e) {
            trigger_error(sprintf("Error connecting to Typesense: %s", $e->getMessage()));
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

	/**
	 * Get the version of the client library we're using
     * @param Request $request 
     * @return mixed 
     */
    public function getClientVersion(Request $request) {
		return response()->json([
			'version' => self::VERSION
		], 200);
	}

	/**
	 * Trigger a call to the Artisan command that will generate an export file and save it to the Storage disk
	 * @return void 
	 */
	public function exportData(Request $request) {
		$input = $this->validateRequest($request, [
			'ctrl_table_name'        => 'required',
			'ctrl_file_name'         => 'required',
		]);

		/**
		 * If we have validation_errors, return them as a response
		 * It's annoying to have to do this here, but we need to return a response, which
		 * we obviously can't do from within the validateRequest function
		 */
		if (!empty($input['validation_errors'])) {
			return response()->json($input['validation_errors'], 404);
		}

		Artisan::queue('ctrl:csv', [
			'direction'  => 'export',
			'table_name' => $input['table_name'],
			'file_name'  => $input['file_name'],
		]);
		
		return response()->json([
			'success' => true
		], 200);

	}

}
