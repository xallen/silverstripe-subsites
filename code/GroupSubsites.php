<?php
/**
 * Extension for the Group object to add subsites support
 *
 * @package subsites
 */
class GroupSubsites extends DataObjectDecorator implements PermissionProvider {

	function extraStatics() {
		if(!method_exists('DataObjectDecorator', 'load_extra_statics')) {
			if($this->owner->class != 'Group') return null;
		}
		return array(
			'db' => array(
				'AccessAllSubsites' => 'Boolean',
			),
			'many_many' => array(
				'Subsites' => 'Subsite',
			),
			'defaults' => array(
				'AccessAllSubsites' => 1,
			),
		);
	}
	
	
	/**
	 * Migrations for GroupSubsites data.
	 */
	function requireDefaultRecords() {
		// Migration for Group.SubsiteID data from when Groups only had a single subsite
		$groupFields = DB::getConn()->fieldList('Group');
		
		// Detection of SubsiteID field is the trigger for old-style-subsiteID migration
		if(isset($groupFields['SubsiteID'])) {
			// Migrate subsite-specific data
			DB::query('INSERT INTO "Group_Subsites" ("GroupID", "SubsiteID")
				SELECT "ID", "SubsiteID" FROM "Group" WHERE "SubsiteID" > 0');
				
			// Migrate global-access data
			DB::query('UPDATE "Group" SET "AccessAllSubsites" = 1 WHERE "SubsiteID" = 0');
			
			// Move the field out of the way so that this migration doesn't get executed again
			DB::getConn()->renameField('Group', 'SubsiteID', '_obsolete_SubsiteID');
			
		// No subsite access on anything means that we've just installed the subsites module.
		// Make all previous groups global-access groups
		} else if(!DB::query('SELECT "Group"."ID" FROM "Group" 
			LEFT JOIN "Group_Subsites" ON "Group_Subsites"."GroupID" = "Group"."ID" AND "Group_Subsites"."SubsiteID" > 0
			WHERE "AccessAllSubsites" = 1
			OR "Group_Subsites"."GroupID" IS NOT NULL ')->value()) {
			
			DB::query('UPDATE "Group" SET "AccessAllSubsites" = 1');
		}
	}
	
	function updateCMSFields(&$fields) {
		if($this->owner->canEdit() ){
			// i18n tab
			$fields->findOrMakeTab('Root.Subsites',_t('GroupSubsites.SECURITYTABTITLE','Subsites'));

			$subsites = Subsite::accessible_sites(array('ADMIN', 'SECURITY_SUBSITE_GROUP'), true);
			$subsiteMap = $subsites->toDropdownMap();
			$subsiteMap[-1] = 'Global (Shared)';
			
			// Interface is different if you have the rights to modify subsite group values on
			// all subsites
			if(isset($subsiteMap[0])) {
				$fields->addFieldToTab("Root.Subsites", new OptionsetField("AccessAllSubsites", 
					_t('GroupSubsites.ACCESSRADIOTITLE', 'Give this group access to'),
					array(
						1 => _t('GroupSubsites.ACCESSALL', "All subsites"),
						0 => _t('GroupSubsites.ACCESSONLY', "Only these subsites"),
					)
				));

				unset($subsiteMap[0]);
				$fields->addFieldToTab("Root.Subsites", new CheckboxSetField("Subsites", "",
					$subsiteMap));

			} else {
				if (sizeof($subsiteMap) <= 1) {
					$fields->addFieldToTab("Root.Subsites", new ReadonlyField("SubsitesHuman", 
						_t('GroupSubsites.ACCESSRADIOTITLE', 'Give this group access to'),
						reset($subsiteMap)));
				} else {
					$fields->addFieldToTab("Root.Subsites", new CheckboxSetField("Subsites", 
						_t('GroupSubsites.ACCESSRADIOTITLE', 'Give this group access to'),
						$subsiteMap));
				}
			}
		}
	}

	/**
	 * If this group belongs to a subsite,
	 * append the subsites title to the group title
	 * to make it easy to distinguish in the tree-view
	 * of the security admin interface.
	 */
	function alternateTreeTitle() {
		if($this->owner->AccessAllSubsites) {
			return htmlspecialchars($this->owner->Title, ENT_QUOTES) . ' <i>(global group)</i>';
		} else {
			$subsites = Convert::raw2xml(implode(", ", $this->owner->Subsites()->column('Title')));
			return htmlspecialchars($this->owner->Title) . " <i>($subsites)</i>";
		}
	}

	/**
	 * Update any requests to limit the results to the current site
	 */
	function augmentSQL(SQLQuery &$query) {
		if(Subsite::$disable_subsite_filter) return;
		if(Subsite::$group_subsites_filter) return;
		

		// If you're querying by ID, ignore the sub-site - this is a bit ugly...
		if(!$query->filtersOnID()) {

			if($context = DataObject::context_obj()) $subsiteID = (int)$context->SubsiteID;
			else $subsiteID = (int)Subsite::currentSubsiteID();
			
			// Don't filter by Group_Subsites if we've already done that
			$hasGroupSubsites = false;
			foreach($query->from as $item) if(strpos($item, 'Group_Subsites') !== false) {
				$hasGroupSubsites = true;
				break;
			}
			
			if(!$hasGroupSubsites) {
				if($subsiteID) {
					$query->leftJoin("Group_Subsites", "\"Group_Subsites\".\"GroupID\" 
						= \"Group\".\"ID\" AND \"Group_Subsites\".\"SubsiteID\" = $subsiteID");
					$query->where[] = "(\"Group_Subsites\".\"SubsiteID\" IS NOT NULL OR
						\"Group\".\"AccessAllSubsites\" = 1)";
				} else {
					$query->where[] = "\"Group\".\"AccessAllSubsites\" = 1";
				}
			}
			
			// WORKAROUND for databases that complain about an ORDER BY when the column wasn't selected (e.g. SQL Server)
			if(!$query->select[0] == 'COUNT(*)') {
				$query->orderby = "\"AccessAllSubsites\" DESC" . ($query->orderby ? ', ' : '') . $query->orderby;
			}
		}
	}

	function onBeforeWrite() {
		// New record test approximated by checking whether the ID has changed.
		// Note also that the after write test is only used when we're *not* on a subsite
		if($this->owner->isChanged('ID') && !Subsite::currentSubsiteID()) {
			$this->owner->AccessAllSubsites = 1;
		}
	}
	
	function onAfterWrite() {
		// New record test approximated by checking whether the ID has changed.
		// Note also that the after write test is only used when we're on a subsite
		if($this->owner->isChanged('ID') && $currentSubsiteID = Subsite::currentSubsiteID()) {
			$subsites = $this->owner->Subsites();
			$subsites->add($currentSubsiteID);
		}
	}

	function alternateCanEdit() {
		// Find the sites that this group belongs to and the sites where we have appropriate perm.
		$accessibleSites = Subsite::accessible_sites('CMS_ACCESS_SecurityAdmin')->column('ID');
		$linkedSites = $this->owner->Subsites()->column('ID');
 
		// We are allowed to access this site if at we have CMS_ACCESS_SecurityAdmin permission on
		// at least one of the sites
		return (bool)array_intersect($accessibleSites, $linkedSites);
	}

	function providePermissions() {
		return array(
			'SECURITY_SUBSITE_GROUP' => array(
				'name' => _t('GroupSubsites.MANAGE_SUBSITES', 'Manage subsites for groups'),
				'category' => _t('Permissions.SUBSITES_CATEGORY', 'Subsite roles and access permissions'),
				'help' => _t('GroupSubsites.MANAGE_SUBSITES_HELP', 'Ability to limit the permissions for a group to one or more subsites.'),
				'sort' => 200
			)
		);
	}

}

?>
