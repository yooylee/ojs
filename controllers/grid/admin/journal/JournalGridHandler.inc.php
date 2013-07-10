<?php

/**
 * @file controllers/grid/admin/journal/JournalGridHandler.inc.php
 *
 * Copyright (c) 2000-2013 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class JournalGridHandler
 * @ingroup controllers_grid_admin_journal
 *
 * @brief Handle journal grid requests.
 */

import('lib.pkp.controllers.grid.admin.context.ContextGridHandler');

import('controllers.grid.admin.journal.JournalGridRow');
import('controllers.grid.admin.journal.form.JournalSiteSettingsForm');

class JournalGridHandler extends ContextGridHandler {
	/**
	 * Constructor
	 */
	function JournalGridHandler() {
		parent::ContextGridHandler();
	}


	//
	// Implement template methods from PKPHandler.
	//
	/**
	 * @see PKPHandler::initialize()
	 */
	function initialize($request) {
		// Load user-related translations.
		AppLocale::requireComponents(
			LOCALE_COMPONENT_APP_ADMIN,
			LOCALE_COMPONENT_APP_MANAGER,
			LOCALE_COMPONENT_APP_COMMON
		);

		parent::initialize($request);

		// Basic grid configuration.
		$this->setTitle('journal.journals');
	}


	//
	// Implement methods from GridHandler.
	//
	/**
	 * @see GridHandler::getRowInstance()
	 * @return UserGridRow
	 */
	function getRowInstance() {
		return new JournalGridRow();
	}

	/**
	 * @see GridHandler::loadData()
	 * @param $request PKPRequest
	 * @return array Grid data.
	 */
	function loadData($request) {
		// Get all journals.
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journals = $journalDao->getAll();

		return $journals->toAssociativeArray();
	}

	/**
	 * @see lib/pkp/classes/controllers/grid/GridHandler::setDataElementSequence()
	 */
	function setDataElementSequence($request, $rowId, &$journal, $newSequence) {
		$journalDao = DAORegistry::getDAO('JournalDAO'); /* @var $journalDao JournalDAO */
		$journal->setSequence($newSequence);
		$journalDao->updateObject($journal);
	}


	//
	// Public grid actions.
	//
	/**
	 * Edit an existing journal.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function editContext($args, $request) {
		// Get the journal ID. (Not the same as the context!)
		$journalId = $request->getUserVar('rowId');

		// Form handling.
		$settingsForm = new JournalSiteSettingsForm(!isset($journalId) || empty($journalId) ? null : $journalId);
		$settingsForm->initData();
		$json = new JSONMessage(true, $settingsForm->fetch($args, $request));

		return $json->getString();
	}

	/**
	 * Update an existing journal.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function updateContext($args, $request) {
		// Identify the context Id.
		$contextId = $request->getUserVar('contextId');

		// Form handling.
		$settingsForm = new JournalSiteSettingsForm($contextId);
		$settingsForm->readInputData();

		if (!$settingsForm->validate()) {
			$json = new JSONMessage(false);
			return $json->getString();
		}

		PluginRegistry::loadCategory('blocks');

		// The context settings form will return a context path in two cases:
		// 1 - if a new context was created;
		// 2 - if a press path of an existing context was edited.
		$newContextPath = $settingsForm->execute($request);

		// Create the notification.
		$notificationMgr = new NotificationManager();
		$user = $request->getUser();
		$notificationMgr->createTrivialNotification($user->getId());

		// Check for the two cases above.
		if ($newContextPath) {
			$context = $request->getContext();

			if (is_null($contextId)) {
				// CASE 1: new press created.
				// Create notification related to payment method configuration.
				$contextDao = Application::getContextDAO();
				$newContext = $contextDao->getByPath($newContextPath);
				$notificationMgr->createNotification($request, null, NOTIFICATION_TYPE_CONFIGURE_PAYMENT_METHOD,
					$newContext->getId(), ASSOC_TYPE_JOURNAL, $newContext->getId(), NOTIFICATION_LEVEL_NORMAL);

				// redirect and set the parameter to open the press
				// setting wizard modal after redirection.
				return $this->_getRedirectEvent($request, $newContextPath, true);
			} else {
				// CASE 2: check if user is in the context of
				// the press being edited.
				if ($context->getId() == $contextId) {
					return $this->_getRedirectEvent($request, $newContextPath, false);
				}
			}
		}
		return DAO::getDataChangedEvent($contextId);
	}

	/**
	 * Delete a journal.
	 * @param $args array
	 * @param $request PKPRequest
	 * @return string Serialized JSON object
	 */
	function deleteContext($args, $request) {
		// Identify the journal Id.
		$journalId = $request->getUserVar('rowId');
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journal = $journalDao->getById($journalId);

		if ($journal) {
			$journalDao->deleteById($journalId);

			// Delete journal file tree
			// FIXME move this somewhere better.
			import('lib.pkp.classes.file.FileManager');
			$fileManager = new FileManager($journalId);
			$journalPath = Config::getVar('files', 'files_dir') . '/journals/' . $journalId;
			$fileManager->rmtree($journalPath);

			import('classes.file.PublicFileManager');
			$publicFileManager = new PublicFileManager();
			$publicFileManager->rmtree($publicFileManager->getJournalFilesPath($journalId));

			return DAO::getDataChangedEvent($journalId);
		}

		$json = new JSONMessage(false);
		return $json->getString();
	}
}

?>
