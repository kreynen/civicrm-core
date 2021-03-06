<?php
/*
  +--------------------------------------------------------------------+
  | CiviCRM version 4.4                                                |
  +--------------------------------------------------------------------+
  | Copyright CiviCRM LLC (c) 2004-2013                                |
  +--------------------------------------------------------------------+
  | This file is a part of CiviCRM.                                    |
  |                                                                    |
  | CiviCRM is free software; you can copy, modify, and distribute it  |
  | under the terms of the GNU Affero General Public License           |
  | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
  |                                                                    |
  | CiviCRM is distributed in the hope that it will be useful, but     |
  | WITHOUT ANY WARRANTY; without even the implied warranty of         |
  | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
  | See the GNU Affero General Public License for more details.        |
  |                                                                    |
  | You should have received a copy of the GNU Affero General Public   |
  | License and the CiviCRM Licensing Exception along                  |
  | with this program; if not, contact CiviCRM LLC                     |
  | at info[AT]civicrm[DOT]org. If you have questions about the        |
  | GNU Affero General Public License or the licensing of CiviCRM,     |
  | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
  +--------------------------------------------------------------------+
*/

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2013
 * $Id$
 *
 */
class CRM_Contact_BAO_Group extends CRM_Contact_DAO_Group {

  /**
   * class constructor
   */
  function __construct() {
    parent::__construct();
  }

  /**
   * Takes a bunch of params that are needed to match certain criteria and
   * retrieves the relevant objects. Typically the valid params are only
   * group_id. We'll tweak this function to be more full featured over a period
   * of time. This is the inverse function of create. It also stores all the retrieved
   * values in the default array
   *
   * @param array $params   (reference ) an assoc array of name/value pairs
   * @param array $defaults (reference ) an assoc array to hold the flattened values
   *
   * @return object CRM_Contact_BAO_Group object
   * @access public
   * @static
   */
  static function retrieve(&$params, &$defaults) {
    $group = new CRM_Contact_DAO_Group();
    $group->copyValues($params);
    if ($group->find(TRUE)) {
      CRM_Core_DAO::storeValues($group, $defaults);
      return $group;
    }

    return NULL;
  }

  /**
   * Function to delete the group and all the object that connect to
   * this group. Incredibly destructive
   *
   * @param int $id group id
   *
   * @return null
   * @access public
   * @static
   *
   */
  static function discard($id) {
    CRM_Utils_Hook::pre('delete', 'Group', $id, CRM_Core_DAO::$_nullArray);

    $transaction = new CRM_Core_Transaction();

    // added for CRM-1631 and CRM-1794
    // delete all subscribed mails with the selected group id
    $subscribe = new CRM_Mailing_Event_DAO_Subscribe();
    $subscribe->group_id = $id;
    $subscribe->delete();

    // delete all Subscription  records with the selected group id
    $subHistory = new CRM_Contact_DAO_SubscriptionHistory();
    $subHistory->group_id = $id;
    $subHistory->delete();

    // delete all crm_group_contact records with the selected group id
    $groupContact = new CRM_Contact_DAO_GroupContact();
    $groupContact->group_id = $id;
    $groupContact->delete();

    // make all the 'add_to_group_id' field of 'civicrm_uf_group table', pointing to this group, as null
    $params = array(1 => array($id, 'Integer'));
    $query = "UPDATE civicrm_uf_group SET `add_to_group_id`= NULL WHERE `add_to_group_id` = %1";
    CRM_Core_DAO::executeQuery($query, $params);

    $query = "UPDATE civicrm_uf_group SET `limit_listings_group_id`= NULL WHERE `limit_listings_group_id` = %1";
    CRM_Core_DAO::executeQuery($query, $params);

    // make sure u delete all the entries from civicrm_mailing_group and civicrm_campaign_group
    // CRM-6186
    $query = "DELETE FROM civicrm_mailing_group where entity_table = 'civicrm_group' AND entity_id = %1";
    CRM_Core_DAO::executeQuery($query, $params);

    $query = "DELETE FROM civicrm_campaign_group where entity_table = 'civicrm_group' AND entity_id = %1";
    CRM_Core_DAO::executeQuery($query, $params);

    $query = "DELETE FROM civicrm_acl_entity_role where entity_table = 'civicrm_group' AND entity_id = %1";
    CRM_Core_DAO::executeQuery($query, $params);

    if (CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::MULTISITE_PREFERENCES_NAME,
        'is_enabled'
      )) {
      // clear any descendant groups cache if exists
      $finalGroups = CRM_Core_BAO_Cache::deleteGroup('descendant groups for an org');
    }

    // delete from group table
    $group = new CRM_Contact_DAO_Group();
    $group->id = $id;
    $group->delete();

    $transaction->commit();

    CRM_Utils_Hook::post('delete', 'Group', $id, $group);

    // delete the recently created Group
    $groupRecent = array(
      'id' => $id,
      'type' => 'Group',
    );
    CRM_Utils_Recent::del($groupRecent);
  }

  /**
   * Returns an array of the contacts in the given group.
   *
   */
  static function getGroupContacts($id) {
    $params = array(array('group', 'IN', array($id => 1), 0, 0));
    list($contacts, $_) = CRM_Contact_BAO_Query::apiQuery($params, array('contact_id'));
    return $contacts;
  }

  /**
   * Get the count of a members in a group with the specific status
   *
   * @param int $id      group id
   * @param enum $status status of members in group
   *
   * @return int count of members in the group with above status
   * @access public
   */
  static function memberCount($id, $status = 'Added', $countChildGroups = FALSE) {
    $groupContact = new CRM_Contact_DAO_GroupContact();
    $groupIds = array($id);
    if ($countChildGroups) {
      $groupIds = CRM_Contact_BAO_GroupNesting::getDescendentGroupIds($groupIds);
    }
    $count = 0;

    $contacts = self::getGroupContacts($id);

    foreach ($groupIds as $groupId) {

      $groupContacts = self::getGroupContacts($groupId);
      foreach ($groupContacts as $gcontact) {
        if ($groupId != $id) {
          // Loop through main group's contacts
          // and subtract from the count for each contact which
          // matches one in the present group, if it is not the
          // main group
          foreach ($contacts as $contact) {
            if ($contact['contact_id'] == $gcontact['contact_id']) {
              $count--;
            }
          }
        }
      }
      $groupContact->group_id = $groupId;
      if (isset($status)) {
        $groupContact->status = $status;
      }
      $groupContact->_query['condition'] = 'WHERE contact_id NOT IN (SELECT id FROM civicrm_contact WHERE is_deleted = 1)';
      $count += $groupContact->count();
    }
    return $count;
  }

  /**
   * Get the list of member for a group id
   *
   * @param int $lngGroupId this is group id
   *
   * @return array $aMembers this arrray contains the list of members for this group id
   * @access public
   * @static
   */
  static function &getMember($groupID, $useCache = TRUE) {
    $params = array(array('group', 'IN', array($groupID => 1), 0, 0));
    $returnProperties = array('contact_id');
    list($contacts, $_) = CRM_Contact_BAO_Query::apiQuery($params, $returnProperties, NULL, NULL, 0, 0, $useCache);

    $aMembers = array();
    foreach ($contacts as $contact) {
      $aMembers[$contact['contact_id']] = 1;
    }

    return $aMembers;
  }

  /**
   * Returns array of group object(s) matching a set of one or Group properties.
   *
   * @param array       $param             Array of one or more valid property_name=>value pairs.
   *                                       Limits the set of groups returned.
   * @param array       $returnProperties  Which properties should be included in the returned group objects.
   *                                       (member_count should be last element.)
   *
   * @return  An array of group objects.
   *
   * @access public
   *
   * @todo other BAO functions that use returnProperties (e.g. Query Objects) receive the array flipped & filled with 1s and
   * add in essential fields (e.g. id). This should follow a regular pattern like the others
   */
  static function getGroups(
    $params = NULL,
    $returnProperties = NULL,
    $sort = NULL,
    $offset = NULL,
    $rowCount = NULL
  ) {
    $dao = new CRM_Contact_DAO_Group();
    if (!isset($params['is_active'])) {
      $dao->is_active = 1;
    }
    if ($params) {
      foreach ($params as $k => $v) {
        if ($k == 'name' || $k == 'title') {
          $dao->whereAdd($k . ' LIKE "' . CRM_Core_DAO::escapeString($v) . '"');
        }
        elseif ($k == 'group_type') {
          foreach ((array) $v as $type) {
            $dao->whereAdd($k . " LIKE '%" . CRM_Core_DAO::VALUE_SEPARATOR . (int) $type . CRM_Core_DAO::VALUE_SEPARATOR . "%'");
          }
        }
        elseif (is_array($v)) {
          foreach ($v as &$num) {
            $num = (int) $num;
          }
          $dao->whereAdd($k . ' IN (' . implode(',', $v) . ')');
        }
        else {
          $dao->$k = $v;
        }
      }
    }

    if ($offset || $rowCount) {
      $offset = ($offset > 0) ? $offset : 0;
      $rowCount = ($rowCount > 0) ? $rowCount : 25;
      $dao->limit($offset, $rowCount);
    }

    if ($sort) {
      $dao->orderBy($sort);
    }

    // return only specific fields if returnproperties are sent
    if (!empty($returnProperties)) {
      $dao->selectAdd();
      $dao->selectAdd(implode(',', $returnProperties));
    }
    $dao->find();

    $flag = $returnProperties && in_array('member_count', $returnProperties) ? 1 : 0;

    $groups = array();
    while ($dao->fetch()) {
      $group = new CRM_Contact_DAO_Group();
      if ($flag) {
        $dao->member_count = CRM_Contact_BAO_Group::memberCount($dao->id);
      }
      $groups[] = clone($dao);
    }
    return $groups;
  }

  /**
   * make sure that the user has permission to access this group
   *
   * @param int $id   the id of the object
   *
   * @return string   the permission that the user has (or null)
   * @access public
   * @static
   */
  static function checkPermission($id) {
    $allGroups = CRM_Core_PseudoConstant::allGroup();

    $permissions = NULL;
    if (CRM_Core_Permission::check('edit all contacts') ||
      CRM_ACL_API::groupPermission(CRM_ACL_API::EDIT, $id, NULL,
        'civicrm_saved_search', $allGroups
      )
    ) {
      $permissions[] = CRM_Core_Permission::EDIT;
    }

    if (CRM_Core_Permission::check('view all contacts') ||
      CRM_ACL_API::groupPermission(CRM_ACL_API::VIEW, $id, NULL,
        'civicrm_saved_search', $allGroups
      )
    ) {
      $permissions[] = CRM_Core_Permission::VIEW;
    }

    if (!empty($permissions) && CRM_Core_Permission::check('delete contacts')) {
      // Note: using !empty() in if condition, restricts the scope of delete
      // permission to groups/contacts that are editable/viewable.
      // We can remove this !empty condition once we have ACL support for delete functionality.
      $permissions[] = CRM_Core_Permission::DELETE;
    }

    return $permissions;
  }

  /**
   * Create a new group
   *
   * @param array $params     Associative array of parameters
   *
   * @return object|null      The new group BAO (if created)
   * @access public
   * @static
   */
  public static function &create(&$params) {

    if (CRM_Utils_Array::value('id', $params)) {
      CRM_Utils_Hook::pre('edit', 'Group', $params['id'], $params);
    }
    else {
      CRM_Utils_Hook::pre('create', 'Group', NULL, $params);
    }

    // form the name only if missing: CRM-627
    if (!CRM_Utils_Array::value('name', $params) &&
      !CRM_Utils_Array::value('id', $params)
    ) {
      $params['name'] = CRM_Utils_String::titleToVar($params['title']);
    }

    // convert params if array type
    if (isset($params['group_type'])) {
      if (is_array($params['group_type'])) {
        $params['group_type'] = CRM_Core_DAO::VALUE_SEPARATOR . implode(CRM_Core_DAO::VALUE_SEPARATOR,
          array_keys($params['group_type'])
        ) . CRM_Core_DAO::VALUE_SEPARATOR;
      }
    }
    else {
      $params['group_type'] = '';
    }

    $session = CRM_Core_Session::singleton( );
    if ($cid = $session->get('userID')) {
      $params['created_id'] = $cid;
    }

    $group = new CRM_Contact_BAO_Group();
    $group->copyValues($params);

    if (!CRM_Utils_Array::value('id', $params)) {
      $group->name .= "_tmp";
    }
    $group->save();

    if (!$group->id) {
      return NULL;
    }

    if (!CRM_Utils_Array::value('id', $params)) {
      $group->name = substr($group->name, 0, -4) . "_{$group->id}";
    }

    $group->buildClause();
    $group->save();

    // add custom field values
    if (CRM_Utils_Array::value('custom', $params)) {
      CRM_Core_BAO_CustomValueTable::store($params['custom'], 'civicrm_group', $group->id);
    }

    // make the group, child of domain/site group by default.
    $domainGroupID = CRM_Core_BAO_Domain::getGroupId();
    if (CRM_Utils_Array::value('no_parent', $params) !== 1) {
      if (empty($params['parents']) &&
        $domainGroupID != $group->id &&
        CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::MULTISITE_PREFERENCES_NAME,
          'is_enabled'
        ) &&
        !CRM_Contact_BAO_GroupNesting::hasParentGroups($group->id)
      ) {
        // if no parent present and the group doesn't already have any parents,
        // make sure site group goes as parent
        $params['parents'] = array($domainGroupID => 1);
      }
      elseif (array_key_exists('parents', $params) && !is_array($params['parents'])) {
        $params['parents'] = array($params['parents'] => 1);
      }

      if (!empty($params['parents'])) {
        foreach ($params['parents'] as $parentId => $dnc) {
          if ($parentId && !CRM_Contact_BAO_GroupNesting::isParentChild($parentId, $group->id)) {
            CRM_Contact_BAO_GroupNesting::add($parentId, $group->id);
          }
        }
      }

      // clear any descendant groups cache if exists
      $finalGroups = CRM_Core_BAO_Cache::deleteGroup('descendant groups for an org');

      // this is always required, since we don't know when a
      // parent group is removed
      CRM_Contact_BAO_GroupNestingCache::update();

      // update group contact cache for all parent groups
      $parentIds = CRM_Contact_BAO_GroupNesting::getParentGroupIds($group->id);
      foreach ($parentIds as $parentId) {
        CRM_Contact_BAO_GroupContactCache::add($parentId);
      }
    }

    if (CRM_Utils_Array::value('organization_id', $params)) {
      $groupOrg = array();
      $groupOrg = $params;
      $groupOrg['group_id'] = $group->id;
      CRM_Contact_BAO_GroupOrganization::add($groupOrg);
    }

    CRM_Contact_BAO_GroupContactCache::add($group->id);

    if (CRM_Utils_Array::value('id', $params)) {
      CRM_Utils_Hook::post('edit', 'Group', $group->id, $group);
    }
    else {
      CRM_Utils_Hook::post('create', 'Group', $group->id, $group);
    }

    $recentOther = array();
    if (CRM_Core_Permission::check('edit groups')) {
      $recentOther['editUrl'] = CRM_Utils_System::url('civicrm/group', 'reset=1&action=update&id=' . $group->id);
      // currently same permission we are using for delete a group
      $recentOther['deleteUrl'] = CRM_Utils_System::url('civicrm/group', 'reset=1&action=delete&id=' . $group->id);
    }

    // add the recently added group (unless hidden: CRM-6432)
    if (!$group->is_hidden) {
      CRM_Utils_Recent::add($group->title,
        CRM_Utils_System::url('civicrm/group/search', 'reset=1&force=1&context=smog&gid=' . $group->id),
        $group->id,
        'Group',
        NULL,
        NULL,
        $recentOther
      );
    }
    return $group;
  }

  /**
   * given a saved search compute the clause and the tables
   * and store it for future use
   */
  function buildClause() {
    $params = array(array('group', 'IN', array($this->id => 1), 0, 0));

    if (!empty($params)) {
      $tables = $whereTables = array();
      $this->where_clause = CRM_Contact_BAO_Query::getWhereClause($params, NULL, $tables, $whereTables);
      if (!empty($tables)) {
        $this->select_tables = serialize($tables);
      }
      if (!empty($whereTables)) {
        $this->where_tables = serialize($whereTables);
      }
    }

    return;
  }

  /**
   * Defines a new smart group
   *
   * @param array $params     Associative array of parameters
   *
   * @return object|null      The new group BAO (if created)
   * @access public
   * @static
   */
  public static function createSmartGroup(&$params) {
    if (CRM_Utils_Array::value('formValues', $params)) {
      $ssParams = $params;
      unset($ssParams['id']);
      if (isset($ssParams['saved_search_id'])) {
        $ssParams['id'] = $ssParams['saved_search_id'];
      }

      $savedSearch = CRM_Contact_BAO_SavedSearch::create($params);

      $params['saved_search_id'] = $savedSearch->id;
    }
    else {
      return NULL;
    }

    return self::create($params);
  }

  /**
   * update the is_active flag in the db
   *
   * @param int      $id        id of the database record
   * @param boolean  $isActive  value we want to set the is_active field
   *
   * @return Object             DAO object on sucess, null otherwise
   * @static
   */
  static function setIsActive($id, $isActive) {
    return CRM_Core_DAO::setFieldValue('CRM_Contact_DAO_Group', $id, 'is_active', $isActive);
  }

  /**
   * build the condition to retrieve groups.
   *
   * @param string  $groupType type of group(Access/Mailing) OR the key of the group
   * @param boolen  $excludeHidden exclude hidden groups.
   *
   * @return string $condition
   * @static
   */
  static function groupTypeCondition($groupType = NULL, $excludeHidden = TRUE) {
    $value = NULL;
    if ($groupType == 'Mailing') {
      $value = CRM_Core_DAO::VALUE_SEPARATOR . '2' . CRM_Core_DAO::VALUE_SEPARATOR;
    }
    elseif ($groupType == 'Access') {
      $value = CRM_Core_DAO::VALUE_SEPARATOR . '1' . CRM_Core_DAO::VALUE_SEPARATOR;
    }
    elseif (!empty($groupType)){
      // ie we have been given the group key
      $value = CRM_Core_DAO::VALUE_SEPARATOR . $groupType . CRM_Core_DAO::VALUE_SEPARATOR;
    }

    $condition = NULL;
    if ($excludeHidden) {
      $condition = "is_hidden = 0";
    }

    if ($value) {
      if ($condition) {
        $condition .= " AND group_type LIKE '%$value%'";
      }
      else {
        $condition = "group_type LIKE '%$value%'";
      }
    }

    return $condition;
  }

  public function __toString() {
    return $this->title;
  }

  /**
   * This function create the hidden smart group when user perform
   * contact seach and want to send mailing to search contacts.
   *
   * @param  array $params ( reference ) an assoc array of name/value pairs
   *
   * @return array ( smartGroupId, ssId ) smart group id and saved search id
   * @access public
   * @static
   */
  static function createHiddenSmartGroup($params) {
    $ssId = CRM_Utils_Array::value('saved_search_id', $params);

    //add mapping record only for search builder saved search
    $mappingId = NULL;
    if ($params['search_context'] == 'builder') {
      //save the mapping for search builder
      if (!$ssId) {
        //save record in mapping table
        $temp          = array();
        $mappingParams = array('mapping_type' => 'Search Builder');
        $mapping       = CRM_Core_BAO_Mapping::add($mappingParams, $temp);
        $mappingId     = $mapping->id;
      }
      else {
        //get the mapping id from saved search
        $savedSearch = new CRM_Contact_BAO_SavedSearch();
        $savedSearch->id = $ssId;
        $savedSearch->find(TRUE);
        $mappingId = $savedSearch->mapping_id;
      }

      //save mapping fields
      CRM_Core_BAO_Mapping::saveMappingFields($params['form_values'], $mappingId);
    }

    //create/update saved search record.
    $savedSearch = new CRM_Contact_BAO_SavedSearch();
    $savedSearch->id = $ssId;
    $savedSearch->form_values = serialize($params['form_values']);
    $savedSearch->mapping_id = $mappingId;
    $savedSearch->search_custom_id = CRM_Utils_Array::value('search_custom_id', $params);
    $savedSearch->save();

    $ssId = $savedSearch->id;
    if (!$ssId) {
      return NULL;
    }

    $smartGroupId = NULL;
    if (CRM_Utils_Array::value('saved_search_id', $params)) {
      $smartGroupId = CRM_Core_DAO::getFieldValue('CRM_Contact_DAO_Group', $ssId, 'id', 'saved_search_id');
    }
    else {
      //create group only when new saved search.
      $groupParams = array(
        'title' => "Hidden Smart Group {$ssId}",
        'is_active' => CRM_Utils_Array::value('is_active', $params, 1),
        'is_hidden' => CRM_Utils_Array::value('is_hidden', $params, 1),
        'group_type' => CRM_Utils_Array::value('group_type', $params),
        'visibility' => CRM_Utils_Array::value('visibility', $params),
        'saved_search_id' => $ssId,
      );

      $smartGroup = self::create($groupParams);
      $smartGroupId = $smartGroup->id;
    }

    return array($smartGroupId, $ssId);
  }

  /**
   * This function is a wrapper for ajax group selector
   *
   * @param  array   $params associated array for params record id.
   *
   * @return array   $groupList associated array of group list
   * @access public
   */
  public function getGroupListSelector(&$params) {
    // format the params
    $params['offset']   = ($params['page'] - 1) * $params['rp'];
    $params['rowCount'] = $params['rp'];
    $params['sort']     = CRM_Utils_Array::value('sortBy', $params);

    // get groups
    $groups = CRM_Contact_BAO_Group::getGroupList($params);

    //skip total if we are making call to show only children
    if ( !CRM_Utils_Array::value('parent_id', $params) ) {
      // add total
      $params['total'] = CRM_Contact_BAO_Group::getGroupCount($params);

      // get all the groups
      $allGroups = CRM_Core_PseudoConstant::allGroup();
    }

    // format params and add links
    $groupList = array();
    if (!empty($groups)) {
      foreach ($groups as $id => $value) {
        $groupList[$id]['group_id'] = $value['id'];
        $groupList[$id]['group_name'] = $value['title'];
        $groupList[$id]['class'] = $value['class'];

        // append parent names if in search mode
        if ( !CRM_Utils_Array::value('parent_id', $params) &&
        CRM_Utils_Array::value( 'parents', $value ) ) {
          $groupIds = explode(',', $value['parents']);
          $title = array();
          foreach($groupIds as $gId) {
            $title[] = $allGroups[$gId];
          }
          $groupList[$id]['group_name'] .= '<div class="crm-row-parent-name"><em>'.ts('Child of').'</em>: ' . implode(', ', $title) . '</div>';
          $groupList[$id]['class'] = '';
        }

        $groupList[$id]['group_description'] = CRM_Utils_Array::value('description', $value);
        if ( CRM_Utils_Array::value('group_type', $value) ) {
          $groupList[$id]['group_type'] = $value['group_type'];
        }
        else {
          $groupList[$id]['group_type'] = '';
        }
        $groupList[$id]['visibility'] = $value['visibility'];
        $groupList[$id]['links'] = $value['action'];
        $groupList[$id]['org_info'] = CRM_Utils_Array::value('org_info', $value);
        $groupList[$id]['created_by'] = CRM_Utils_Array::value('created_by', $value);

        $groupList[$id]['is_parent'] = $value['is_parent'];
      }
      return $groupList;
    }
  }

  /**
   * This function to get list of groups
   *
   * @param  array   $params associated array for params
   * @access public
   */
  static function getGroupList(&$params) {
    $config = CRM_Core_Config::singleton();

    $whereClause = self::whereClause($params, FALSE);

    //$this->pagerAToZ( $whereClause, $params );

    if (!empty($params['rowCount']) &&
      $params['rowCount'] > 0
    ) {
      $limit = " LIMIT {$params['offset']}, {$params['rowCount']} ";
    }

    $orderBy = ' ORDER BY groups.title asc';
    if (CRM_Utils_Array::value('sort', $params)) {
      $orderBy = ' ORDER BY ' . CRM_Utils_Array::value('sort', $params);
    }

    $select = $from = $where = "";
    $groupOrg = FALSE;
    if (CRM_Core_Permission::check('administer Multiple Organizations') &&
      CRM_Core_Permission::isMultisiteEnabled()
    ) {
      $select = ", contact.display_name as org_name, contact.id as org_id";
      $from = " LEFT JOIN civicrm_group_organization gOrg
                               ON gOrg.group_id = groups.id
                        LEFT JOIN civicrm_contact contact
                               ON contact.id = gOrg.organization_id ";

      //get the Organization ID
      $orgID = CRM_Utils_Request::retrieve('oid', 'Positive', CRM_Core_DAO::$_nullObject);
      if ($orgID) {
        $where = " AND gOrg.organization_id = {$orgID}";
      }

      $groupOrg = TRUE;
    }

    $query = "
        SELECT groups.*, createdBy.sort_name as created_by {$select}
        FROM  civicrm_group groups
              LEFT JOIN civicrm_contact createdBy
                     ON createdBy.id = groups.created_id
              {$from}
        WHERE $whereClause {$where}
        {$orderBy}
        {$limit}";

    $object = CRM_Core_DAO::executeQuery($query, $params, TRUE, 'CRM_Contact_DAO_Group');

    //FIXME CRM-4418, now we are handling delete separately
    //if we introduce 'delete for group' make sure to handle here.
    $groupPermissions = array(CRM_Core_Permission::VIEW);
    if (CRM_Core_Permission::check('edit groups')) {
      $groupPermissions[] = CRM_Core_Permission::EDIT;
      $groupPermissions[] = CRM_Core_Permission::DELETE;
    }

    // CRM-9936
    $reservedPermission = CRM_Core_Permission::check('administer reserved groups');

    $links = self::actionLinks();

    $allTypes = CRM_Core_OptionGroup::values('group_type');
    $values = array();

    while ($object->fetch()) {
      $permission = CRM_Contact_BAO_Group::checkPermission($object->id, $object->title);
      //@todo CRM-12209 introduced an ACL check in the whereClause function
      // it may be that this checking is now obsolete - or that what remains
      // should be removed to the whereClause (which is also accessed by getCount)

      if ($permission) {
        $newLinks = $links;
        $values[$object->id] = array();
        CRM_Core_DAO::storeValues($object, $values[$object->id]);
        if ($object->saved_search_id) {
          $values[$object->id]['title'] .= ' (' . ts('Smart Group') . ')';
          // check if custom search, if so fix view link
          $customSearchID = CRM_Core_DAO::getFieldValue(
            'CRM_Contact_DAO_SavedSearch',
            $object->saved_search_id,
            'search_custom_id'
          );

          if ($customSearchID) {
            $newLinks[CRM_Core_Action::VIEW]['url'] = 'civicrm/contact/search/custom';
            $newLinks[CRM_Core_Action::VIEW]['qs'] = "reset=1&force=1&ssID={$object->saved_search_id}";
          }
        }

        $action = array_sum(array_keys($newLinks));

        // CRM-9936
        if (array_key_exists('is_reserved', $object)) {
          //if group is reserved and I don't have reserved permission, suppress delete/edit
          if ($object->is_reserved && !$reservedPermission) {
            $action -= CRM_Core_Action::DELETE;
            $action -= CRM_Core_Action::UPDATE;
            $action -= CRM_Core_Action::DISABLE;
          }
        }

        $values[$object->id]['class'] = '';
        if (array_key_exists('is_active', $object)) {
          if ($object->is_active) {
            $action -= CRM_Core_Action::ENABLE;
          }
          else {
            $values[$object->id]['class'] = 'disabled';
            $action -= CRM_Core_Action::VIEW;
            $action -= CRM_Core_Action::DISABLE;
          }
        }

        $action = $action & CRM_Core_Action::mask($groupPermissions);

        $values[$object->id]['visibility'] = CRM_Contact_DAO_Group::tsEnum('visibility',
          $values[$object->id]['visibility']
        );
        if (isset($values[$object->id]['group_type'])) {
          $groupTypes = explode(CRM_Core_DAO::VALUE_SEPARATOR,
            substr($values[$object->id]['group_type'], 1, -1)
          );
          $types = array();
          foreach ($groupTypes as $type) {
            $types[] = CRM_Utils_Array::value($type, $allTypes);
          }
          $values[$object->id]['group_type'] = implode(', ', $types);
        }
        $values[$object->id]['action'] = CRM_Core_Action::formLink($newLinks,
          $action,
          array(
            'id' => $object->id,
            'ssid' => $object->saved_search_id,
          )
        );

        // If group has children, add class for link to view children
        $values[$object->id]['is_parent'] = false;
        if (array_key_exists('children', $values[$object->id])) {
          $values[$object->id]['class'] = "crm-group-parent";
          $values[$object->id]['is_parent'] = true;
        }

        // If group is a child, add child class
        if (array_key_exists('parents', $values[$object->id])) {
          $values[$object->id]['class'] = "crm-group-child";
        }

        if (array_key_exists('children', $values[$object->id])
        && array_key_exists('parents', $values[$object->id])) {
          $values[$object->id]['class'] = "crm-group-child crm-group-parent";
        }

        if ($groupOrg) {
          if ($object->org_id) {
            $contactUrl = CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid={$object->org_id}");
            $values[$object->id]['org_info'] = "<a href='{$contactUrl}'>{$object->org_name}</a>";
          }
          else {
            $values[$object->id]['org_info'] = ''; // Empty cell
          }
        }
        else {
          $values[$object->id]['org_info'] = NULL; // Collapsed column if all cells are NULL
        }
        if ($object->created_id) {
          $contactUrl = CRM_Utils_System::url('civicrm/contact/view', "reset=1&cid={$object->created_id}");
          $values[$object->id]['created_by'] = "<a href='{$contactUrl}'>{$object->created_by}</a>";
        }
      }
    }

    return $values;
  }

  /**
   * This function to get hierarchical list of groups (parent followed by children)
   *
   * @param  array   $groupIDs array of group ids
   *
   * @access public
   */
  static function getGroupsHierarchy (
    $groupIDs,
    $parents  = NULL,
    $spacer = '<span class="child-indent"></span>',
    $titleOnly = FALSE
   ) {
    if (empty($groupIDs)) {
      return array();
    }

    $groupIdString = '(' . implode(',', array_keys($groupIDs)) . ')';
     // <span class="child-icon"></span>
     // need to return id, title (w/ spacer), description, visibility

     // We need to build a list of tags ordered by hierarchy and sorted by
     // name. The heirarchy will be communicated by an accumulation of
     // separators in front of the name to give it a visual offset.
     // Instead of recursively making mysql queries, we'll make one big
     // query and build the heirarchy with the algorithm below.
     $groups = array();
     $args = array(1 => array($groupIdString, 'String'));
     $query = "
SELECT id, title, description, visibility, parents
FROM   civicrm_group
WHERE  id IN $groupIdString
";
     if ($parents) {
       // group can have > 1 parent so parents may be comma separated list (eg. '1,2,5'). We just grab and match on 1st parent.
       $parentArray = explode(',', $parents);
       $parent = $parentArray[0];
       $args[2] = array($parent, 'Integer');
       $query .= " AND SUBSTRING_INDEX(parents, ',', 1) = %2";
     }
     $query .= " ORDER BY title";
     $dao = CRM_Core_DAO::executeQuery($query, $args);

     // Sort the groups into the correct storage by the parent
     // $roots represent the current leaf nodes that need to be checked for
     // children. $rows represent the unplaced nodes
     $roots = $rows = $allGroups = array();
     while ($dao->fetch()) {
       $allGroups[$dao->id] = array('title' => $dao->title,
                                    'visibility' => $dao->visibility,
                                    'description' => $dao->description);

       if ($dao->parents == $parents) {
         $roots[] = array('id' => $dao->id,
                          'prefix' => '',
                          'title' => $dao->title);
       }
       else {
         // group can have > 1 parent so $dao->parents may be comma separated list (eg. '1,2,5'). Grab and match on 1st parent.
         $parentArray = explode(',', $dao->parents);
         $parent = $parentArray[0];
         $rows[] = array('id' => $dao->id,
                          'prefix' => '',
                          'title' => $dao->title,
                          'parents' => $parent);
       }
     }
     $dao->free();
     // While we have nodes left to build, shift the first (alphabetically)
     // node of the list, place it in our groups list and loop through the
     // list of unplaced nodes to find its children. We make a copy to
     // iterate through because we must modify the unplaced nodes list
     // during the loop.
     while (count($roots)) {
       $new_roots         = array();
       $current_rows      = $rows;
       $root              = array_shift($roots);
       $groups[$root['id']] = array($root['prefix'], $root['title']);

       // As you find the children, append them to the end of the new set
       // of roots (maintain alphabetical ordering). Also remove the node
       // from the set of unplaced nodes.
       if (is_array($current_rows)) {
         foreach ($current_rows as $key => $row) {
           if ($row['parents'] == $root['id']) {
             $new_roots[] = array('id' => $row['id'], 'prefix' => $groups[$root['id']][0] . $spacer, 'title' => $row['title']);
             unset($rows[$key]);
           }
         }
       }

       //As a group, insert the new roots into the beginning of the roots
       //list. This maintains the hierarchical ordering of the tags.
       $roots = array_merge($new_roots, $roots);
     }

     // Prefix titles with the calcuated spacing to give the visual
     // appearance of ordering when transformed into HTML in the form layer. Add description and visibility.
     $groupsReturn = array();
     foreach ($groups as $key=>$value) {
       if ($titleOnly) {
         $groupsReturn[$key] = $value[0] . $value[1];
       } else {
         $groupsReturn[$key] = array(
           'title' => $value[0] . $value[1],
           'description' => $allGroups[$key]['description'],
           'visibility' => $allGroups[$key]['visibility'],
         );
       }
     }

     return $groupsReturn;
  }

  static function getGroupCount(&$params) {
    $whereClause = self::whereClause($params, FALSE);
    $query = "SELECT COUNT(*) FROM civicrm_group groups";

    if (CRM_Utils_Array::value('created_by', $params)) {
      $query .= "
INNER JOIN civicrm_contact createdBy
       ON createdBy.id = groups.created_id";
    }
    $query .= "
WHERE {$whereClause}";
    return CRM_Core_DAO::singleValueQuery($query, $params);
  }

  function whereClause(&$params, $sortBy = TRUE, $excludeHidden = TRUE) {
    $values = array();
    $clauses = array();

    $title = CRM_Utils_Array::value('title', $params);
    if ($title) {
      $clauses[] = "groups.title LIKE %1";
      if (strpos($title, '%') !== FALSE) {
        $params[1] = array($title, 'String', FALSE);
      }
      else {
        $params[1] = array($title, 'String', TRUE);
      }
    }

    $groupType = CRM_Utils_Array::value('group_type', $params);
    if ($groupType) {
      $types = explode(',', $groupType);
      if (!empty($types)) {
        $clauses[]  = 'groups.group_type LIKE %2';
        $typeString = CRM_Core_DAO::VALUE_SEPARATOR . implode(CRM_Core_DAO::VALUE_SEPARATOR, $types) . CRM_Core_DAO::VALUE_SEPARATOR;
        $params[2]  = array($typeString, 'String', TRUE);
      }
    }

    $visibility = CRM_Utils_Array::value('visibility', $params);
    if ($visibility) {
      $clauses[] = 'groups.visibility = %3';
      $params[3] = array($visibility, 'String');
    }

    $groupStatus = CRM_Utils_Array::value('status', $params);
    if ($groupStatus) {
      switch ($groupStatus) {
        case 1:
          $clauses[] = 'groups.is_active = 1';
          $params[4] = array($groupStatus, 'Integer');
          break;

        case 2:
          $clauses[] = 'groups.is_active = 0';
          $params[4] = array($groupStatus, 'Integer');
          break;

        case 3:
          $clauses[] = '(groups.is_active = 0 OR groups.is_active = 1 )';
          break;
      }
    }

    $parentsOnly = CRM_Utils_Array::value('parentsOnly', $params);
    if ($parentsOnly) {
      $clauses[] = 'groups.parents IS NULL';
    }

    // only show child groups of a specific parent group
    $parent_id = CRM_Utils_Array::value('parent_id', $params);
    if ($parent_id) {
      $clauses[] = 'groups.id IN (SELECT child_group_id FROM civicrm_group_nesting WHERE parent_group_id = %5)';
      $params[5] = array($parent_id, 'Integer');
    }

    if ($createdBy = CRM_Utils_Array::value('created_by', $params)) {
      $clauses[] = "createdBy.sort_name LIKE %6";
      if (strpos($createdBy, '%') !== FALSE) {
        $params[6] = array($createdBy, 'String', FALSE);
      }
      else {
        $params[6] = array($createdBy, 'String', TRUE);
      }
    }

    /*
      if ( $sortBy &&
      $this->_sortByCharacter !== null ) {
      $clauses[] =
      "groups.title LIKE '" .
      strtolower(CRM_Core_DAO::escapeWildCardString($this->_sortByCharacter)) .
      "%'";
      }

      // dont do a the below assignement when doing a
      // AtoZ pager clause
      if ( $sortBy ) {
      if ( count( $clauses ) > 1 ) {
      $this->assign( 'isSearch', 1 );
      } else {
      $this->assign( 'isSearch', 0 );
      }
      }
    */


    if (empty($clauses)) {
      $clauses[] = 'groups.is_active = 1';
    }

    if ($excludeHidden) {
      $clauses[] = 'groups.is_hidden = 0';
    }
    //CRM-12209
    if (!CRM_Core_Permission::check('view all contacts')) {
      //get the allowed groups for the current user
      $groups = CRM_ACL_API::group(CRM_ACL_API::VIEW);
      if (!empty( $groups)) {
        $groupList = implode( ', ', array_values( $groups ) );
        $clauses[] = "groups.id IN ( $groupList ) ";
      }
    }

    return implode(' AND ', $clauses);
  }

  /**
   * Function to define action links
   *
   * @return array $links array of action links
   * @access public
   */
  static function actionLinks() {
    $links = array(
      CRM_Core_Action::VIEW => array(
        'name' => ts('Contacts'),
        'url' => 'civicrm/group/search',
        'qs' => 'reset=1&force=1&context=smog&gid=%%id%%',
        'title' => ts('Group Contacts'),
      ),
      CRM_Core_Action::UPDATE => array(
        'name' => ts('Settings'),
        'url' => 'civicrm/group',
        'qs' => 'reset=1&action=update&id=%%id%%',
        'title' => ts('Edit Group'),
      ),
      CRM_Core_Action::DISABLE => array(
        'name' => ts('Disable'),
        'extra' => 'onclick = "enableDisable( %%id%%,\'' . 'CRM_Contact_BAO_Group' . '\',\'' . 'enable-disable' . '\' );"',
        'ref' => 'disable-action',
        'title' => ts('Disable Group'),
      ),
      CRM_Core_Action::ENABLE => array(
        'name' => ts('Enable'),
        'extra' => 'onclick = "enableDisable( %%id%%,\'' . 'CRM_Contact_BAO_Group' . '\',\'' . 'disable-enable' . '\' );"',
        'ref' => 'enable-action',
        'title' => ts('Enable Group'),
      ),
      CRM_Core_Action::DELETE => array(
        'name' => ts('Delete'),
        'url' => 'civicrm/group',
        'qs' => 'reset=1&action=delete&id=%%id%%',
        'title' => ts('Delete Group'),
      ),
    );

    return $links;
  }

  function pagerAtoZ($whereClause, $whereParams) {
    $query = "
        SELECT DISTINCT UPPER(LEFT(groups.title, 1)) as sort_name
        FROM  civicrm_group groups
        WHERE $whereClause
        ORDER BY LEFT(groups.title, 1)
            ";
    $dao = CRM_Core_DAO::executeQuery($query, $whereParams);

    return CRM_Utils_PagerAToZ::getAToZBar($dao, $this->_sortByCharacter, TRUE);
  }
}

