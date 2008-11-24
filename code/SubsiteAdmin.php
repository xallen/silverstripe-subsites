<?php
/**
 * Admin interface to manage and create {@link Subsite} instances.
 * 
 * @package subsites
 */
class SubsiteAdmin extends GenericDataAdmin {
	
	static $tree_class = "Subsite";
	static $subitem_class = "Subsite";
	static $data_type = 'Subsite';
	
	static $url_segment = 'subsites';
	
	static $url_rule = '/$Action/$ID/$OtherID';
	
	static $menu_title = 'Subsites';
	
	function performSearch() {
		
	}
	
	function getSearchFields() {
		return singleton('Subsite')->adminSearchFields();
	}
	
	function getLink() {
		return 'admin/subsites/';
	}
	
	function Link() {
		return $this->getLink();
	}
	
	function Results($data = null) {
		if(!$data) $data = $this->requestParams;
		
		$where = '';
		if(isset($data['Name']) && $data['Name']) {
			$SQL_name = Convert::raw2sql($data['Name']);
			$where = "`Title` LIKE '%$SQL_name%'";
		} else {
			$where = "`Title` != ''";
		}
		
		$intranets = DataObject::get('Subsite', $where, "if(ClassName = 'Subsite_Template',0,1), Title");
		if(!$intranets)
			return null;
			
		$html = "<table class=\"ResultTable\"><thead><tr><th>Name</th><th>Domain</th></tr></thead><tbody>";
		
		$numIntranets = 0;
		foreach($intranets as $intranet) {
			$numIntranets++;
			$evenOdd = ($numIntranets % 2) ? 'odd':'even';
			$prefix = ($intranet instanceof Subsite_Template) ? " * " : "";
			$html .= "<tr class=\"$evenOdd\"><td><a class=\"show\" href=\"admin/subsites/show/{$intranet->ID}\">$prefix{$intranet->Title}</a></td><td><a class=\"show\" href=\"admin/subsites/show/{$intranet->ID}\">{$intranet->Subdomain}.{$intranet->Domain}</a></td></tr>";
		}
		$html .= "</tbody></table>";
		return $html;
	}
	
	/**
	 * Returns the form for adding subsites.
	 * @returns Form A nerw form object
	 */
	function AddSubsiteForm() {
		$templates = $this->getIntranetTemplates();
	
		$templateArray = array('' => "(No template)");
		if($templates) {
			$templateArray = $templateArray + $templates->map('ID', 'Title');
		}
		
		return new Form($this, 'AddSubsiteForm', new FieldSet(
			new TextField('Name', 'Name:'),
			new TextField('Subdomain', 'Subdomain:'),
			new DropdownField('Type', 'Type', array(
				'subsite' => 'New site',
				'template' => 'New template',
			)),
			new DropdownField('TemplateID', 'Use template:', $templateArray)//,
			/*new TextField('AdminName', 'Admin name:'),
			new EmailField('AdminEmail', 'Admin email:')*/
		),
		new FieldSet(
			new FormAction('addintranet', 'Add')
		));
	}
	
	public function getIntranetTemplates() {
		return DataObject::get('Subsite_Template', '', 'Title');
	}
	
	function addintranet($data, $form) {
		if($data['Name'] && $data['Subdomain']) {
			/*
			$SQL_email = Convert::raw2sql($data['AdminEmail']);
			$member = DataObject::get_one('Member', "`Email`='$SQL_email'");
		
			if(!$member) {
				$member = Object::create('Member');
				$nameParts = explode(' ', $data['AdminName']);
				$member->FirstName = array_shift($nameParts);
				$member->Surname = join(' ', $nameParts);
				$member->Email = $data['AdminEmail'];
				$member->write();
			}
			*/

			if(isset($data['TemplateID']) && $data['TemplateID']) {
				$template = DataObject::get_by_id('Subsite_Template', $data['TemplateID']);
			} else {
				$template = null;
			}
		
			// Create intranet from existing template
			switch($data['Type']) {
				case 'template':
					if($template) $intranet = $template->duplicate();
					else $intranet = new Subsite_Template();
					
					$intranet->Title = $data['Name'];
					$intranet->write();
					break;

				case 'subsite':
				default:
					if($template) $intranet = $template->createInstance($data['Name'], $data['Subdomain']);		
					else {
						$intranet = new Subsite();
						$intranet->Title = $data['Name'];
						$intranet->Subdomain = $data['Subdomain'];
						$intranet->write();
					}
					
					break;
			}
		
			// NOTE: This stuff is pretty oriwave2-specific...
			$groupObjects = array();
		
			// create Staff, Management and Administrator groups
			$groups = array(
				'Administrators' => array('CL_ADMIN', 'CMS_ACCESS_CMSMain', 'CMS_ACCESS_AssetAdmin', 'CMS_ACCESS_SecurityAdmin', 'CMS_ACCESS_IntranetAdmin'),
				'Management' => array('CL_MGMT'),
				'Staff' => array('CL_STAFF')
			);
			foreach($groups as $name => $perms) {
				$group = new Group();
				$group->SubsiteID = $intranet->ID;
				$group->Title = $name;
				$group->write();
			
				foreach($perms as $perm) {
					Permission::grant($group->ID, $perm);
				}
			
				$groupObjects[$name] = $group;
			}
		
			/*
			$member->Groups()->add($groupObjects['Administrators']);
			*/
		
			Director::redirect('admin/subsites/show/' . $intranet->ID);
		} else {
			echo "You must provide a Name and Subdomain.";
		}
	}

	/**
	 * Use this as an action handler for custom CMS buttons.
	 */
	function callPageMethod2($data, $form) {
		return $this->callPageMethod($data, $form);
	}
}
?>
