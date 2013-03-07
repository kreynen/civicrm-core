<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.3                                                |
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
 * This class stores logic for managing CiviCRM extensions.
 *
 * @package CRM
 * @copyright CiviCRM LLC (c) 2004-2013
 * $Id$
 *
 */
class CRM_Extension_Manager_Module extends CRM_Extension_Manager_Base {

  public function __construct(CRM_Extension_Mapper $mapper) {
    parent::__construct(FALSE);
    $this->mapper = $mapper;
  }

  public function onPreInstall(CRM_Extension_Info $info) {
    $this->callHook($info, 'install');
    $this->callHook($info, 'enable');
  }

  private function callHook(CRM_Extension_Info $info, $hookName) {
    try {
      $file = $this->mapper->keyToPath($info->key);
    } catch (CRM_Extension_Exception $e) {
      return;
    }
    if (!file_exists($file)) {
      return;
    }
    include_once $file;
    $fnName = "{$info->file}_civicrm_{$hookName}";
    if (function_exists($fnName)) {
      $fnName();
    }
  }

  public function onPreUninstall(CRM_Extension_Info $info) {
    $this->callHook($info, 'uninstall');
    return TRUE;
  }

  public function onPostUninstall(CRM_Extension_Info $info) {
    // Remove references to the module's permissions.
    $config = CRM_Core_Config::singleton();
    if ($config->userPermissionClass->isModulePermissionSupported()) {
      $config->userPermissionClass->uninstallPermissions($info->file);
    } // else: don't care because no permissions were installed!
  }

  public function onPreDisable(CRM_Extension_Info $info) {
    $this->callHook($info, 'disable');
  }

  public function onPreEnable(CRM_Extension_Info $info) {
    $this->callHook($info, 'enable');
  }
}

