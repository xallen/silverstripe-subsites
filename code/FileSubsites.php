<?php
/**
 * Extension for the File object to add subsites support
 *
 * @package subsites
 */
class FileSubsites extends DataObjectDecorator {
	
	// If this is set to true, all folders created will be default be
	// considered 'global', unless set otherwise
	static $default_root_folders_global = false;
	
	function extraStatics() {
		if(!method_exists('DataObjectDecorator', 'load_extra_statics') && $this->owner->class != 'File') return null;
		return array(
			'has_one' => array(
				'Subsite' => 'Subsite',
			),
		);
	}
	
	/**
	 * Amends the CMS tree title for folders in the Files & Images section.
	 * Prefixes a '* ' to the folders that are accessible from all subsites.
	 */
	function alternateTreeTitle() {
		if($this->owner->SubsiteID == 0) return " * " . $this->owner->Title;
		else return $this->owner->Title;
	}

	/**
	 * Add subsites-specific fields to the folder editor.
	 */
	function updateCMSFields(FieldSet &$fields) {
	
		/* Establish whether we are dealing with a Folder, and if so, whether we have permissions to change
			the subsite it is owned by. */
		if($this->owner instanceof Folder and Permission::check('SUBSITE_ASSETS_CREATE_SUBSITE')) {
		
			if(Permission::check('SUBSITE_ASSETS_GLOBAL_ACCESS')) $sites = Subsite::accessible_sites('CMS_ACCESS_AssetAdmin', true, 'Main Site', null, true, 'Global (Shared)');
			else $sites = Subsite::accessible_sites('CMS_ACCESS_AssetAdmin', true, 'Main Site');
		
			$dropdownValues = ($sites) ? $sites->toDropdownMap() : array();
			if($sites)$fields->addFieldToTab('Root.Details', new DropdownField("SubsiteID", "Subsite", $dropdownValues));
		}
	}
	
	/**
	 * Update any requests to limit the results to the current site.
	 */
	function augmentSQL(SQLQuery &$query) {
	
		// We will need to know the SubsiteID we are working with ($subsiteID).
		if($context = DataObject::context_obj()) $subsiteID = (int) $context->SubsiteID;
		else $subsiteID = (int) Subsite::currentSubsiteID();
	
		// A list of functions to filter out of the backtrace.
		$function_filters = array('augmentSQL', 'extend', 'extendedSQL', 'getQuery', 'get', 'instance_get');
	
		/* Find the last called function (that doesn't match a filter) and store it in $calling_function. We do
		 * 	this using debug_backtrace(). It should be a lot more accurate to match queries for augmentation this
		 *	way rather than by the query itself. */
		$backtrace = debug_backtrace();
		foreach($backtrace as $key => $trace) {
			if(!in_array($trace['function'], $function_filters)) {
				$calling_function = $trace['function'];
				break;
			}
		}
		
		// Take appropriate action depending on the method operating on this DataObject.
		switch($calling_function) {
		
			case 'ChildFolders': // Used to filter the File and Folder list in AssetAdmin.
			case 'stageChildren': // Used to filter the File and Folder list for the Image and Link WYSIWYG tools.
				// The foreach is an ugly way of getting the first key :-)
				foreach($query->from as $tableName => $info) {
					if(Permission::check('SUBSITE_ASSETS_GLOBAL_ACCESS')) {
						$siteList = array(-1, $subsiteID);
					} else {
						$siteList = array($subsiteID);
					}
					
					$where = "\"$tableName\".\"SubsiteID\" IN (".implode($siteList, ',').")";
					$query->where[] = $where;
					break;
				}
				return;
			case 'numChildren': // COUNT?.
			case 'instance_get_one': // instance_get_one seems to be invoked only when retrieving a single DataObject.
				// NOTE: This is used when testing if a file or folder already exists. Subsites module now correctly avoids duplicate folder names!
			case 'isFieldSortable': // isFieldSortable is a method in TableFieldList.
			case 'sourceItems': // Major player in TableListField.
			case 'TotalCount': // TotalCount is used to count the number of available files.
			default:
				return;
		}
	}

	function onBeforeWrite() {
		
		// Are folders and files globally owned by default, or should we inherit the owner?
		if (!$this->owner->ID && !$this->owner->SubsiteID) {
			if (self::$default_root_folders_global) {
				$this->owner->SubsiteID = -1;
			} else {
				$this->owner->SubsiteID = Subsite::currentSubsiteID();
			}
		}
	}

	function onAfterUpload() {
	
		// Abort write if destination is the root of assets/ and member doesn't have permission to right to the root
		if($this->owner->Parent()->ParentID == '' and !Permission::check('SUBSITE_ASSETS_ROOT_WRITE')) {
			//unlink($this->owner->Parent()->Filename); // Delete the file as we don't need it any longer
			//exit('errorMessage("You do not have permission to create, modify or delete a folder or file in this location.");');
			//exit('statusMessage("You do not have permission to create, modify or delete a folder or file in this location.");');
		}
		
		// Abort write if member cannot write to global assets and destination is global assets
		if($this->owner->Parent()->SubsiteID == -1 and !Permission::check('SUBSITE_ASSETS_GLOBAL_WRITE')) {
			//unlink($this->owner->Parent()->Filename); // Delete the file as we don't need it any longer
			//exit('errorMessage("You do not have permission to create, modify or delete a folder or file in this location.");');
			//exit('statusMessage("You do not have permission to create, modify or delete a folder or file in this location.");'); 
		}
	
		// If we have a parent, use it's subsite as our subsite
		if ($this->owner->Parent()) {
			$this->owner->SubsiteID = $this->owner->Parent()->SubsiteID;
		} else {
			$this->owner->SubsiteID = Subsite::currentSubsiteID();
		}
		$this->owner->write();
	}

	function canEdit() {
		// Check the CMS_ACCESS_SecurityAdmin privileges on the subsite that owns this group
		$subsiteID = Session::get('SubsiteID');
		if($subsiteID && $subsiteID == $this->owner->SubsiteID) {
			return true;
		} else {
			Session::set('SubsiteID', $this->owner->SubsiteID);
			$access = Permission::check('CMS_ACCESS_AssetAdmin');
			Session::set('SubsiteID', $subsiteID);

			return $access;
		}
	}
	
	/**
	 * Return a piece of text to keep DataObject cache keys appropriately specific
	 */
	function cacheKeyComponent() {
		return 'subsite-'.Subsite::currentSubsiteID();
	}
	
}


