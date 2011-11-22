<?php

	/**
	 * @package toolkit
	 */
	/**
	 * The `ResourcesPage` abstract class controls the way "Datasource"
	 * and "Events" index pages are displayed in the backend. It extends the
	 * `AdministrationPage` class.
	 *
	 * @since Symphony 2.3
	 * @see toolkit.AdministrationPage
	 */
	require_once(TOOLKIT . '/class.administrationpage.php');
	require_once(TOOLKIT . '/class.resourcemanager.php');
	require_once(CONTENT . '/class.sortable.php');

	Abstract Class ResourcesPage extends AdministrationPage{

		/**
		 * This method is invoked from the `Sortable` class and it contains the
		 * logic for sorting (or unsorting) the resource index. It provides a basic
		 * wrapper to the `ResourceManager`'s `fetch()` method.
		 *
		 * @see toolkit.ResourceManager#getSortingField
		 * @see toolkit.ResourceManager#getSortingOrder
		 * @see toolkit.ResourceManager#fetch
		 * @param string $sort
		 *  The field to sort on which should match one of the table's column names.
		 *  If this is not provided the default will be determined by
		 *  `ResourceManager::getSortingField`
		 * @param string $order
		 *  The direction to sort in, either 'asc' or 'desc'. If this is not provided
		 *  the value will be determined by `ResourceManager::getSortingOrder`.
		 * @param array $params
		 *  An associative array of params (usually populated from the URL) that this
		 *  function uses. The current implementation will use `type` and `unsort` keys
		 * @return array
		 *  An associative of the resource as determined by `ResourceManager::fetch`
		 */
		public function sort(&$sort, &$order, array $params){
			$type = $params['type'];

			// If `?unsort` is appended to the URL, then sorting information are reverted
			// to their defaults
			if(isset($params['unsort'])) {
				ResourceManager::setSortingField($type, 'name', false);
				ResourceManager::setSortingOrder($type, 'asc');

				redirect(Administration::instance()->getCurrentPageURL());
			}

			// By default, sorting information are retrieved from
			// the filesystem and stored inside the `Configuration` object
			if(is_null($sort) && is_null($order)){
				$sort = ResourceManager::getSortingField($type);
				$order = ResourceManager::getSortingOrder($type);
			}
			// If the sorting field or order differs from what is saved,
			// update the config file and reload the page
			else if($sort != ResourceManager::getSortingField($type) || $order != ResourceManager::getSortingOrder($type)){
				ResourceManager::setSortingField($type, $sort, false);
				ResourceManager::setSortingOrder($type, $order);

				redirect(Administration::instance()->getCurrentPageURL());
			}

			return ResourceManager::fetch($params['type'], array(), array(), $sort . ' ' . $order);
		}

		/**
		 * This function creates an array of all page titles in the system.
		 *
		 * @return array
		 *  An array of page titles
		 */
		public function pagesFlatView(){
			$pages = PageManager::fetch(false, array('id'));

			foreach($pages as &$p) {
				$p['title'] = PageManager::resolvePageTitle($p['id']);
			}

			return $pages;
		}

		/**
		 * This function contains the minimal amount of logic for generating the
		 * index table of a given `$resource_type`. The table has name, source, pages
		 * release date and author columns. The values for these columns are determined
		 * by the resource's `about()` method.
		 *
		 * As Datasources types can be installed using Providers, the Source column
		 * can be overridden with a Datasource's `getSourceColumn` method (if it exists).
		 *
		 * @param integer $resource_type
		 *  Either `RESOURCE_TYPE_EVENT` or `RESOURCE_TYPE_DATASOURCE`
		 */
		public function __viewIndex($resource_type){
		}

		/**
		 * This function is called from the resources index when a user uses the
		 * With Selected, or Apply, menu. The type of resource is given by
		 * `$resource_type`. At this time the only two valid values,
		 * `RESOURCE_TYPE_EVENT` or `RESOURCE_TYPE_DATASOURCE`.
		 *
		 * The function handles 'delete', 'attach', 'detach', 'attach all',
		 * 'detach all' actions.
		 *
		 * @param integer $resource_type
		 *  Either `RESOURCE_TYPE_EVENT` or `RESOURCE_TYPE_DATASOURCE`
		 */
		public function __actionIndex($resource_type){
			$manager = ResourceManager::getManagerFromType($resource_type);

			/**
			 * Extensions can listen for any custom actions that were added
			 * through `AddCustomPreferenceFieldsets` or `AddCustomActions`
			 * delegates.
			 *
			 * @delegate CustomActions
			 * @since Symphony 2.3.2
			 * @param string $context
			 * '/blueprints/datasources/' or '/blueprints/events/'
			 */
			Symphony::ExtensionManager()->notifyMembers('CustomActions', $_REQUEST['symphony-page']);

			if (isset($_POST['action']) && is_array($_POST['action'])) {
				$checked = ($_POST['items']) ? @array_keys($_POST['items']) : NULL;

				if (is_array($checked) && !empty($checked)) {

					if ($_POST['with-selected'] == 'delete') {
						$canProceed = true;

						foreach($checked as $handle) {
							$path = call_user_func(array($manager, '__getDriverPath'), $handle);

							if (!General::deleteFile($path)) {
								$folder = str_replace(DOCROOT, '', $path);
								$folder = str_replace('/' . basename($path), '', $folder);

								$this->pageAlert(
									__('Failed to delete %s.', array('<code>' . basename($path) . '</code>'))
									. ' ' . __('Please check permissions on %s', array('<code>' . $folder . '</code>'))
									, Alert::ERROR
								);
								$canProceed = false;
							}
							else {
								$pages = ResourceManager::getAttachedPages($resource_type, $handle);
								foreach($pages as $page) {
									ResourceManager::detach($resource_type, $handle, $page['id']);
								}
							}
						}

						if ($canProceed) redirect(Administration::instance()->getCurrentPageURL());
					}
					else if(preg_match('/^(at|de)?tach-(to|from)-page-/', $_POST['with-selected'])) {

						if (substr($_POST['with-selected'], 0, 6) == 'detach') {
							$page = str_replace('detach-from-page-', '', $_POST['with-selected']);

							foreach($checked as $handle) {
								ResourceManager::detach($resource_type, $handle, $page);
							}
						}
						else {
							$page = str_replace('attach-to-page-', '', $_POST['with-selected']);

							foreach($checked as $handle) {
								ResourceManager::attach($resource_type, $handle, $page);
							}
						}

						if($canProceed) redirect(Administration::instance()->getCurrentPageURL());
					}
					else if(preg_match('/^(at|de)?tach-all-pages$/', $_POST['with-selected'])) {
						$pages = PageManager::fetch(false, array('id'));

						if (substr($_POST['with-selected'], 0, 6) == 'detach') {
							foreach($checked as $handle) {
								foreach($pages as $page) {
									ResourceManager::detach($resource_type, $handle, $page['id']);
								}
							}
						}
						else {
							foreach($checked as $handle) {
								foreach($pages as $page) {
									ResourceManager::attach($resource_type, $handle, $page['id']);
								}
							}
						}

						redirect(Administration::instance()->getCurrentPageURL());
					}

				}
			}
		}

		/**
		 * Returns the path to the component-template by looking at the
		 * `WORKSPACE/template/` directory, then at the `TEMPLATES`
		 * directory for the convention `*.tpl`. If the template
		 * is not found, false is returned
		 *
		 * @param string $name
		 *  Name of the template
		 * @return mixed
		 *  String, which is the path to the template if the template is found,
		 *  false otherwise
		 */
		protected function getTemplate($name) {
			$format = '%s/%s.tpl';
			if(file_exists($template = sprintf($format, WORKSPACE . '/template', $name)))
				return $template;
			elseif(file_exists($template = sprintf($format, TEMPLATE, $name)))
				return $template;
			else
				return false;
		}

	}
