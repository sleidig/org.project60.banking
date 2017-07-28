<?php
/*-------------------------------------------------------+
| Project 60 - CiviBanking                               |
| Copyright (C) 2017 SYSTOPIA                            |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL v3 license. You can redistribute it and/or  |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

/**
 *
 * @package org.project60.banking
 * @copyright GNU Affero General Public License
 * $Id$
 *
 */
abstract class CRM_Banking_PluginModel_PostProcessor extends CRM_Banking_PluginModel_BtxBase {

  function __construct($plugin_dao) {
    parent::__construct($plugin_dao);

    // read config, set defaults
    $config = $this->_plugin_config;

    if (!isset($config->require_btx_status_list))      $config->require_btx_status_list = array('processed');
    if (!isset($config->contribution_fields_required)) $config->contribution_fields_required = '';
  }

  /**
   * Postprocess the (already executed) match
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $matcher  the matcher plugin executed
   * @param $context  the matcher context contains cache data and context information
   *
   */
  public abstract function processExecutedMatch(CRM_Banking_Matcher_Suggestion $match, CRM_Banking_PluginModel_Matcher $matcher, CRM_Banking_Matcher_Context $context);

  /**
   * Should this postprocessor spring into action?
   * Evaluates the common 'required' fields in the configuration
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $context  the matcher context contains cache data and context information
   *
   * @return bool     should the this postprocessor be activated
   */
  protected function shouldExecute(CRM_Banking_Matcher_Suggestion $match, CRM_Banking_PluginModel_Matcher $matcher, CRM_Banking_Matcher_Context $context) {
    // check if the btx status is accepted
    $config = $this->_plugin_config;
    $btx_status_id = $context->btx->status_id;
    $btx_status_name = CRM_Core_OptionGroup::getValue('civicrm_banking.bank_tx_status', $context->btx->status_id, 'id', 'String', 'name');
    if (!in_array($btx_status_name, $config->require_btx_status_list)) {
      // TODO: log: NOT IN STATUS
      error_log("NOT THE RIGHT STATUS");
      return FALSE;
    }

    // check required values
    if (!$this->requiredValuesPresent($context->btx)) {
      // TODO: log
      error_log("REQUIRED VALUES MISSING");
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Fetch a named propagation object.
   * @see CRM_Banking_PluginModel_BtxBase::getPropagationValue
   */
  public function getPropagationObject($name, $btx) {
    // in this default implementation, no extra objects are provided
    // please overwrite in the plugin implementation
    switch ($name) {
      case 'contribution':
        return $this->getFirstContribution($btx->context);

      case 'contact':
        return $this->getSoleContact();

      default:
        // nothing to do here
        break;
    }
    return parent::getPropagationObject($name, $btx);
  }

  /**
   * Get the ONE contact this transaction has been associated with. If there are
   *  multiple candidates, NULL is returned
   *
   * @param $context  the matcher context contains cache data and context information
   *
   * @return int      contact_id of the unique contact linked to the transaction, NULL if not exists/unique
   */
  protected function getSoleContactID(CRM_Banking_Matcher_Context $context) {
    $contact_id = NULL;
    $contributions = $this->getContributions($context);
    foreach ($contributions as $contribution) {
      if (empty($contribution['contact_id'])) {
        // log: problem
      }
      if ($contact_id == NULL) {
        $contact_id = $contribution['contact_id'];
      } elseif ($contact_id == $contribution['contact_id']) {
        continue;
      } else {
        // there is more than one contact:
        return NULL;
      }
    }
    return $contact_id;
  }

  /**
   * Get the ONE contact this transaction has been associated with. If there are
   *  multiple candidates, NULL is returned
   *
   * @param $context  the matcher context contains cache data and context information
   *
   * @return contact  contact data or NULL
   */
  protected function getSoleContact(CRM_Banking_Matcher_Context $context) {
    $contact_id = $this->getSoleContactID($context);
    if ($contact_id) {
      return civicrm_api3('Contact', 'getsingle', array('id' => $contact_id));
    } else {
      return NULL;
    }
  }

  /**
   * deliver the first of the eligible contributions
   * overwrites parent::getFirstContribution()
   */
  protected function getFirstContribution($context) {
    $contributions = $this->getContributions($context);
    if (empty($contributions)) {
      return NULL;
    } else {
      return reset($contributions);
    }
  }


  /**
   * Get the list of contributions linked to this trxn ID
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $context  the matcher context contains cache data and context information
   *
   * @return array    contribution IDs
   */
  protected function getContributions(CRM_Banking_Matcher_Context $context) {
    $cache_key = "{$this->_plugin_id}_contributions_{$context->btx->id}";
    $cached_result = $context->getCachedEntry($cache_key);
    if ($cached_result !== NULL) return $cached_result;

    $connected_contribution_ids = $this->getContributionIDs($context);
    if (empty($connected_contribution_ids)) {
      return array();
    }

    // compile a query
    $config = $this->_plugin_config;
    $contribution_query = array(
      'id'           => array('IN' => $connected_contribution_ids),
      'option.limit' => 0,
      'sequential'   => 1);

    // add return clause
    if (!empty($config->contribution_fields_required)) {
      $contribution_query['return'] = $config->contribution_fields_required;
    }

    // query DB
    $result = civicrm_api3('Contribution', 'get', $contribution_query);
    $contributions = $result['values'];

    // cache result
    $context->setCachedEntry($cache_key, $contributions);
    return $contributions;
  }


  /**
   * Get the list of contributions linked to this trxn ID
   *
   * @param $match    the executed match
   * @param $btx      the related transaction
   * @param $context  the matcher context contains cache data and context information
   *
   * @return array    contribution IDs
   */
  protected function getContributionIDs(CRM_Banking_Matcher_Context $context) {
    $match = $context->getExecutedSuggestion();
    $contribution_ids = array();

    // get the single-style ('contribution_id')
    $single_id = $match->getParameter('contribution_id');
    if (is_numeric($single_id)) {
      $contribution_ids[$single_id] = 1;
    }

    // get the multi-style ('contribution_ids')
    $multi_ids = $match->getParameter('contribution_ids');
    if (is_array($multi_ids)) {
      foreach ($multi_ids as $contribution_id) {
        if (is_numeric($contribution_id)) {
          $contribution_ids[$contribution_id] = 1;
        }
      }
    }

    return array_keys($contribution_ids);
  }

  /**
   * Add all given tags to the given contact
   */
  protected function tagContact($contact_id, $tag_names) {
    foreach ($tag_names as $tag_name) {
      $tag = civicrm_api3('Tag', 'get', array(
        'name'     => $tag_name,
        'used_for' => 'civicrm_contact'));
      if (!empty($tag['id'])) {
        civicrm_api3('EntityTag', 'create', array(
          'entity_id'    => $contact_id,
          'entity_table' => 'civicrm_contact',
          'tag_id'       => $tag['id']));
        // TODO: log
      }
    }
  }
}

