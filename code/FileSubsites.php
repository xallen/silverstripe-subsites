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
	 * Update any requests to limit the results to the current site
	 */
	function augmentSQL(SQLQuery &$query) {

		// We will need to know the SubsiteID we are working with ($subsiteID)
		if($context = DataObject::context_obj()) $subsiteID = (int) $context->SubsiteID;
		else $subsiteID = (int) Subsite::currentSubsiteID();
	
		/* Return if Name AND ParentID is in query. Most likely will be a call to find duplicate file names. We 
		 *	absolutely NEED to make sure these aren't affected by SubsiteID or duplicate file names and
		 *	folders will cause total, unholy mayhem. It was a rare occurance, but a real show stopper, and a
		 *	total P.I.T.A to diagnose :-P I'm sorry this is such an ugly kludge! */
		if(preg_match('/(\'|"|`|)Name(\'|"|`|).* AND .*(\'|"|`|)ParentID(\'|"|`|)/', $query->where[0])) return;
		
		/* Here we test for queries from TreeDropdownField and its subclasses. These queries are always(?) related
		 *	to retrieving a list of Files and Folders for a subsite for TreeDropdownFields. E.g. LinkForm, 
		 *	PictureStrip in WYSIWYG editor. */
		if($query->where and $query->where[0] == '"File"."ParentID" = 0 AND "File"."ID" != 0') {
		
			/* Filter by SubsiteID, otherwise. If global assets are enabled, we want those too. */
			if(Permission::check('SUBSITE_ASSETS_GLOBAL_ACCESS')) $siteList = array(-1, $subsiteID);
			else $siteList = array($subsiteID);
			$query->where[] = "\"SubsiteID\" IN (".implode($siteList, ',').")";
		}
		
		// If you're querying by ID, ignore the sub-site - this is a bit ugly... (but it was WAYYYYYYYYY worse)
		// (If no WHERE clauses, or no mention of ."ID")
		if(!$query->where || !preg_match('/\.(\'|"|`|)ID(\'|"|`|)/', $query->where[0])) {

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
			
			$isCounting = strpos($query->select[0], 'COUNT') !== false;

			// Ordering when deleting or counting doesn't apply
			if(!$query->delete && !$isCounting) {
				$query->orderby = "\"SubsiteID\"" . ($query->orderby ? ', ' : '') . $query->orderby;
			}			
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
		}
		
		// Abort write if member cannot write to global assets and destination is global assets
		if($this->owner->Parent()->SubsiteID == -1 and !Permission::check('SUBSITE_ASSETS_GLOBAL_WRITE')) {
			//unlink($this->owner->Parent()->Filename); // Delete the file as we don't need it any longer
			//exit('errorMessage("You do not have permission to create, modify or delete a folder or file in this location.");');
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


