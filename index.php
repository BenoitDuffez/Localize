<?php

require_once(__DIR__.'/classes/Authentication.php');
require_once(__DIR__.'/classes/File_IO.php');
require_once(__DIR__.'/classes/URL.php');
require_once(__DIR__.'/classes/Language_Android.php');
require_once(__DIR__.'/classes/Phrase_Android_Plurals.php');
require_once(__DIR__.'/classes/Phrase_Android_String.php');
require_once(__DIR__.'/classes/Phrase_Android_StringArray.php');
require_once(__DIR__.'/classes/Repository.php');
require_once(__DIR__.'/classes/UI.php');
require_once(__DIR__.'/classes/UI_Alert.php');
require_once(__DIR__.'/classes/User.php');
require_once(__DIR__.'/classes/Database.php');
require_once(__DIR__.'/classes/Edit.php');
require_once(__DIR__.'/libs/password_compat.php');

Authentication::init();
UI::init(Authentication::getUserTimezone());
Database::init();

if (isset($_GET['v'])) {
    UI::redirectToURL(URL::toProject(UI::validateID($_GET['v'], true)));
    exit;
}
elseif (UI::isPage('sign_up')) {
    if (UI::isAction('sign_up')) {
        $data = UI::getDataPOST('sign_up');

        $data_type = isset($data['type']) ? intval($data['type']) : 0;
        if (!Authentication::isAllowSignUpDevelopers()) {
            $data_type = User::TYPE_TRANSLATOR;
        }
        $data_username = isset($data['username']) ? trim($data['username']) : '';
        $data_password1 = isset($data['password1']) ? trim($data['password1']) : '';
        $data_password2 = isset($data['password2']) ? trim($data['password2']) : '';

        if ($data_password1 == $data_password2) {
            if (mb_strlen($data_password1) >= 6) {
                if (mb_strlen($data_username) >= 3) {
                    if ($data_type == User::TYPE_TRANSLATOR || $data_type == User::TYPE_DEVELOPER) {
                        $data_password = password_hash($data_password1, PASSWORD_BCRYPT);
                        try {
                            Database::insert("INSERT INTO users (username, password, type, join_date) VALUES (".Database::escape($data_username).", ".Database::escape($data_password).", ".intval($data_type).", ".time().")");
                            $alert = new UI_Alert('<p>Your free account has been created!</p><p>Please sign in by entering your username and password in the top-right corner.</p>', UI_Alert::TYPE_SUCCESS);
                        }
                        catch (Exception $e) {
                            $alert = new UI_Alert('<p>It seems this username is already taken. Please try another one.</p>', UI_Alert::TYPE_WARNING);
                        }
                    }
                    else {
                        $alert = new UI_Alert('<p>Please choose one of the account types!</p>', UI_Alert::TYPE_WARNING);
                    }
                }
                else {
                    $alert = new UI_Alert('<p>Your username must be at least 3 characters long!</p>', UI_Alert::TYPE_WARNING);
                }
            }
            else {
                $alert = new UI_Alert('<p>Your password must be at least 6 characters long!</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The two passwords did not match!</p>', UI_Alert::TYPE_WARNING);
        }

        echo UI::getPage(UI::PAGE_SIGN_UP, array($alert));
    }
    else {
        echo UI::getPage(UI::PAGE_SIGN_UP);
    }
}
elseif (UI::isPage('contact')) {
    echo UI::getPage(UI::PAGE_CONTACT);
}
elseif (UI::isPage('help')) {
    echo UI::getPage(UI::PAGE_HELP);
}
elseif (UI::isPage('settings')) {
    if (UI::isAction('settings')) {
        $data = UI::getDataPOST('settings');
        $realName = isset($data['realName']) ? trim($data['realName']) : '';
        $nativeLanguages = isset($data['nativeLanguage']) ? $data['nativeLanguage'] : array();
        $localeCountry = isset($data['country']) ? trim($data['country']) : '';
        $localeTimezone = isset($data['timezone'][$localeCountry]) ? trim($data['timezone'][$localeCountry]) : '';
        Database::updateSettings(Authentication::getUserID(), $realName, $nativeLanguages, $localeCountry, $localeTimezone);
        $userObject = Authentication::getUser();
        if (!empty($userObject)) {
            $userObject->setRealName($realName);
            $userObject->setCountry($localeCountry);
            $userObject->setTimezone($localeTimezone);
            Authentication::updateUserInfo($userObject);
        }
        $alert = new UI_Alert('<p>Your settings have been updated.</p>', UI_Alert::TYPE_SUCCESS);
        echo UI::getPage(UI::PAGE_SETTINGS, array($alert));
    }
    else {
        echo UI::getPage(UI::PAGE_SETTINGS);
    }
}
elseif (UI::isPage('sign_out')) {
    Authentication::signOut();
    $alert = new UI_Alert('You have successfully been signed out!', UI_Alert::TYPE_SUCCESS);
    echo UI::getPage(UI::PAGE_INDEX, array($alert));
}
elseif (UI::isPage('project')) {
    echo UI::getPage(UI::PAGE_PROJECT);
}
elseif (UI::isPage('language')) {
    $alert = NULL;
    if (UI::isAction('updatePhrases')) {
        $languageID = UI::validateID(UI::getDataGET('language'), true);
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $repository = new Repository($repositoryID, $repositoryData['name'], $repositoryData['visibility'], $repositoryData['defaultLanguage']);
            $role = Database::getRepositoryRole(Authentication::getUserID(), $repositoryID);
            $permissions = $repository->getPermissions(Authentication::getUserID(), $role);

            if (Authentication::getUserID() > 0 && !$permissions->isInvitationMissing()) {
                $data = UI::getDataPOST('updatePhrases');
                if (isset($data['edits']) && is_array($data['edits']) && isset($data['previous']) && is_array($data['previous'])) {
                    Authentication::saveCachedEdits($repositoryID, $languageID, $data['edits']);
                    $editData = array();
                    $counter = 0;
                    foreach ($data['edits'] as $phraseID => $phraseSubKeys) {
                        foreach ($phraseSubKeys as $phraseSubKey => $phraseSuggestedValue) {
                            $previousValue = isset($data['previous'][$phraseID][$phraseSubKey]) ? trim($data['previous'][$phraseID][$phraseSubKey]) : '';
                            $phraseSuggestedValue = trim($phraseSuggestedValue);
                            if ($phraseSuggestedValue != '' && $phraseSuggestedValue != $previousValue) {
                                $editData[] = new Edit(URL::decodeID($phraseID), $phraseSubKey, $phraseSuggestedValue);
                                $counter++;
                            }
                        }
                    }
                    if (!empty($editData)) {
                        Database::submitEdits($repositoryID, $languageID, Authentication::getUserID(), $editData);
                        $alert = new UI_Alert('<p>Thank you very much!</p><p>Your modifications to '.$counter.' phrases have been submitted to this project.</p><p>They will now be reviewed by the project owners.</p>', UI_Alert::TYPE_SUCCESS);
                    }
                    else {
                        $alert = new UI_Alert('<p>You did change any phrase. Please try again!</p>', UI_Alert::TYPE_WARNING);
                    }
                }
                else {
                    $alert = new UI_Alert('<p>You did not send any modifications. Please try again!</p>', UI_Alert::TYPE_WARNING);
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to submit changes to this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_PROJECT);
    }
    else {
        echo UI::getPage(UI::PAGE_PROJECT, array($alert));
    }
}
elseif (UI::isPage('review')) {
    $alert = NULL;
    if (UI::isAction('review')) {
        $languageID = UI::validateID(UI::getDataGET('language'), true);
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_MODERATOR);
            if ($isAllowed) {
                $data = UI::getDataPOST('review');
                $data_action = isset($data['action']) ? trim($data['action']) : '';
                $data_editID = isset($data['editID']) ? UI::validateID($data['editID'], true) : '';
                $data_phraseObject = isset($data['phraseObject']) ? unserialize(base64_decode($data['phraseObject'])) : NULL;
                $data_phraseKey = isset($data['phraseKey']) ? trim($data['phraseKey']) : '';
                $data_phraseSubKey = isset($data['phraseSubKey']) ? trim($data['phraseSubKey']) : '';
                $data_contributorID = isset($data['contributorID']) ? UI::validateID($data['contributorID'], true) : '';
                $data_newValue = isset($data['newValue']) ? trim($data['newValue']) : '';
                $data_referenceValue = isset($data['referenceValue']) ? trim($data['referenceValue']) : '';
                if (!empty($data_phraseObject)) {
                    switch ($data_action) {
                        case 'approve':
                            $placeholdersReference = Phrase_Android::getPlaceholders($data_referenceValue);
                            $placeholdersNew = Phrase_Android::getPlaceholders($data_newValue);
                            if (Phrase::arePlaceholdersMatching($placeholdersReference, $placeholdersNew) || $languageID == $repositoryData['defaultLanguage']) { // placeholders (except their order) may only be changed in default language
                                $data_phraseObject->setPhraseValue($data_phraseSubKey, $data_newValue);
                                Database::updatePhrase($repositoryID, $languageID, $data_phraseKey, $data_phraseObject->getPayload());
                                Database::updateContributor($repositoryID, $data_contributorID);
                                Database::deleteEdit($data_editID);
                                Authentication::setCachedLanguageProgress($repositoryID, NULL); // unset cached version of this repository's progress
                            }
                            else {
                                $alert = new UI_Alert('<p>The placeholders must match with those of the reference phrase.</p>', UI_Alert::TYPE_WARNING);
                            }
                            break;
                        case 'reviewLater':
                            Database::postponeEdit($data_editID);
                            break;
                        case 'reject':
                            Database::deleteEdit($data_editID);
                            break;
						case 'approveAllFromThisContributor':
							Database::approveEditsByContributor($repositoryID, $languageID, $data_contributorID);
							break;
                        case 'rejectAllFromThisContributor':
                            Database::deleteEditsByContributor($data_contributorID);
                            break;
                    }
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to review contributions for this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_REVIEW);
    }
    else {
        echo UI::getPage(UI::PAGE_REVIEW, array($alert));
    }
}
elseif (UI::isPage('invitations')) {
    $alert = NULL;
    if (UI::isAction('invitations')) {
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_ADMINISTRATOR);
            if ($isAllowed) {
                $data = UI::getDataPOST('invitations');
                $data_userID = UI::validateID($data['userID'], true);
                $data_accept = isset($data['accept']) ? intval(trim($data['accept'])) : 0;
                $data_role = isset($data['role']) ? intval(trim($data['role'])) : 0;
                if ($data_userID > 0 && ($data_accept == 1 || $data_accept == -1) && $data_role > 0) {
                    Database::reviewInvitation($repositoryID, $data_userID, $data_accept == 1, $data_role);
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to review invitation requests for this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_INVITATIONS);
    }
    else {
        echo UI::getPage(UI::PAGE_INVITATIONS, array($alert));
    }
}
elseif (UI::isPage('export')) {
    $alert = NULL;
    if (UI::isAction('export')) {
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $repositoryDefaultLanguage = $repositoryData['defaultLanguage'];
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_DEVELOPER);
            if ($isAllowed) {
                $data = UI::getDataPOST('export');
                $filename = isset($data['filename']) ? trim($data['filename']) : '';
                if (File_IO::isFilenameValid($filename)) {
					$htmlEscaping = intval(trim($data['htmlEscaping']));
					$htmlEscapingEnabled = FALSE;
					if ($htmlEscaping == File_IO::HTML_ESCAPING_GETTEXT) {
						$htmlEscapingEnabled = FALSE;
						$htmlEscapingValid = TRUE;
					}
					elseif ($htmlEscaping == File_IO::HTML_ESCAPING_HTML_FROMHTML) {
						$htmlEscapingEnabled = TRUE;
						$htmlEscapingValid = TRUE;
					}
					else {
						$htmlEscapingValid = FALSE;
					}
					if ($htmlEscapingValid) {
						$repository = new Repository($repositoryID, $repositoryData['name'], $repositoryData['visibility'], $repositoryData['defaultLanguage']);
						$repository->loadLanguages(true, Repository::SORT_ALL_LANGUAGES, Repository::LOAD_ALL_LANGUAGES);
						File_IO::exportRepository($repository, $filename, $htmlEscapingEnabled);
						exit;
					}
					else {
						$alert = new UI_Alert('<p>Please choose your preferred way of HTML escaping.</p>', UI_Alert::TYPE_WARNING);
					}
                }
                else {
                    $alert = new UI_Alert('<p>Please enter a valid filename.</p>', UI_Alert::TYPE_WARNING);
                }

            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to export files from this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_PROJECT);
    }
    else {
        echo UI::getPage(UI::PAGE_PROJECT, array($alert));
    }
}
elseif (UI::isPage('import')) {
    $alert = NULL;
    if (UI::isAction('import')) {
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $repositoryDefaultLanguage = $repositoryData['defaultLanguage'];
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_DEVELOPER);
            if ($isAllowed) {
                $data = UI::getDataPOST('import');
                $overwriteMode = isset($data['overwrite']) ? intval(trim($data['overwrite'])) : 0;
                $languageID = isset($data['languageID']) ? intval(trim($data['languageID'])) : 0;
                if ($overwriteMode == 0 || $overwriteMode == 1) {
                    if ($languageID > 0) {
                        try {
                            $languageNameFull = Language::getLanguageNameFull($languageID);
                            $importResult = File_IO::importXML($repositoryID, $_FILES['importFileXML']);
                            if (isset($importResult) && is_array($importResult)) {
                                Database::addPhrases($repositoryID, $languageID, $importResult, $overwriteMode == 1);
                                Authentication::setCachedLanguageProgress($repositoryID, NULL); // unset cached version of this repository's progress
                                $alert = new UI_Alert('<p>You have imported '.count($importResult).' phrases to '.$languageNameFull.'.</p>', UI_Alert::TYPE_SUCCESS);
                            }
                            else {
                                switch ($importResult) {
                                    case File_IO::UPLOAD_ERROR_NO_TRANSLATIONS_FOUND:
                                        $alert = new UI_Alert('<p>No translations were found in the uploaded XML file.</p>', UI_Alert::TYPE_WARNING);
                                        break;
                                    case File_IO::UPLOAD_ERROR_COULD_NOT_OPEN:
                                        $alert = new UI_Alert('<p>Could not open the XML file. Please try again!</p>', UI_Alert::TYPE_WARNING);
                                        break;
                                    case File_IO::UPLOAD_ERROR_COULD_NOT_PROCESS:
                                        $alert = new UI_Alert('<p>Could not process the XML file. Please try again!</p>', UI_Alert::TYPE_WARNING);
                                        break;
                                    case File_IO::UPLOAD_ERROR_TOO_LARGE:
                                        $alert = new UI_Alert('<p>The XML file that you tried to upload was too large.</p>', UI_Alert::TYPE_WARNING);
                                        break;
                                    case File_IO::UPLOAD_ERROR_XML_INVALID:
                                        $alert = new UI_Alert('<p>Invalid XML in the uploaded file.</p>', UI_Alert::TYPE_WARNING);
                                        break;
                                    default:
                                        $alert = new UI_Alert('<p>Unknown error. Please try again!</p>', UI_Alert::TYPE_WARNING);
                                }
                            }
                        }
                        catch (Exception $e) {
                            $alert = new UI_Alert('<p>Could not find the language that you have selected.</p>', UI_Alert::TYPE_WARNING);
                        }
                    }
                    else {
                        $alert = new UI_Alert('<p>Please choose one of the languages from the list.</p>', UI_Alert::TYPE_WARNING);
                    }
                }
                else {
                    $alert = new UI_Alert('<p>Please choose if you want to overwrite existing phrases or not.</p>', UI_Alert::TYPE_WARNING);
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to import phrases to this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_PROJECT);
    }
    else {
        echo UI::getPage(UI::PAGE_PROJECT, array($alert));
    }
}
elseif (UI::isPage('add_phrase')) {
    $alert = NULL;
    if (UI::isAction('add_phrase')) {
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $repositoryDefaultLanguage = $repositoryData['defaultLanguage'];
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_DEVELOPER);
            if ($isAllowed) {
                $data = UI::getDataPOST('add_phrase');
                $phraseType = isset($data['type']) ? intval(trim($data['type'])) : 0;
                $phraseKey = isset($data['key']) ? trim($data['key']) : '';
                if (Phrase_Android::isPhraseKeyValid($phraseKey)) {
                    if ($phraseType == 1) { // string
                        $phraseValue = isset($data['string']) ? trim($data['string']) : '';
                        if (!empty($phraseValue)) {
                            $phrasePayload = Phrase_Android_String::getPayloadFromValue($phraseValue);
                            try {
                                Database::addPhrase($repositoryID, $repositoryDefaultLanguage, $phraseKey, $phrasePayload);
                                $alert = new UI_Alert('<p>The new phrase has successfully been added.</p>', UI_Alert::TYPE_SUCCESS);
                            }
                            catch (Exception $e) {
                                $alert = new UI_Alert('<p>The new phrase could not be added.</p><p>It seems there is already a phrase with the same key.</p>', UI_Alert::TYPE_WARNING);
                            }
                        }
                        else {
                            $alert = new UI_Alert('<p>The phrase must not be empty.</p>', UI_Alert::TYPE_WARNING);
                        }
                    }
                    elseif ($phraseType == 2) { // string-array
                        if (isset($data['string_array']) && is_array($data['string_array']) && count($data['string_array']) > 0) {
                            $phraseValues = array();
                            $phraseValuesCandidates = $data['string_array'];
                            $phraseValuesComplete = true;
                            foreach ($phraseValuesCandidates as $phraseValuesCandidate) {
                                $phraseValuesCandidate = trim($phraseValuesCandidate);
                                if (!empty($phraseValuesCandidate)) {
                                    $phraseValues[] = $phraseValuesCandidate;
                                }
                                else {
                                    $phraseValuesComplete = false;
                                }
                            }
                            if ($phraseValuesComplete) {
                                $phrasePayload = Phrase_Android_StringArray::getPayloadFromValue($phraseValues);
                                try {
                                    Database::addPhrase($repositoryID, $repositoryDefaultLanguage, $phraseKey, $phrasePayload);
                                    $alert = new UI_Alert('<p>The new phrase has successfully been added.</p>', UI_Alert::TYPE_SUCCESS);
                                }
                                catch (Exception $e) {
                                    $alert = new UI_Alert('<p>The new phrase could not be added.</p><p>It seems there is already a phrase with the same key.</p>', UI_Alert::TYPE_WARNING);
                                }
                            }
                            else {
                                $alert = new UI_Alert('<p>New phrases must not be empty.</p>', UI_Alert::TYPE_WARNING);
                            }
                        }
                    }
                    elseif ($phraseType == 3) { // plurals
                        if (isset($data['plurals']) && is_array($data['plurals']) && count($data['plurals']) > 0) {
                            $phraseValues = array();
                            $phraseValuesCandidates = $data['plurals'];
                            $phraseValuesComplete = true;
                            foreach ($phraseValuesCandidates as $phraseValuesKey => $phraseValuesCandidate) {
                                $phraseValuesCandidate = trim($phraseValuesCandidate);
                                if (!empty($phraseValuesCandidate)) {
                                    $phraseValues[$phraseValuesKey] = $phraseValuesCandidate;
                                }
                                else {
                                    $phraseValuesComplete = false;
                                }
                            }
                            if ($phraseValuesComplete) {
                                $phrasePayload = Phrase_Android_Plurals::getPayloadFromValue($phraseValues);
                                try {
                                    Database::addPhrase($repositoryID, $repositoryDefaultLanguage, $phraseKey, $phrasePayload);
                                    $alert = new UI_Alert('<p>The new phrase has successfully been added.</p>', UI_Alert::TYPE_SUCCESS);
                                }
                                catch (Exception $e) {
                                    $alert = new UI_Alert('<p>The new phrase could not be added.</p><p>It seems there is already a phrase with the same key.</p>', UI_Alert::TYPE_WARNING);
                                }
                            }
                            else {
                                $alert = new UI_Alert('<p>New phrases must not be empty.</p>', UI_Alert::TYPE_WARNING);
                            }
                        }
                    }
                }
                else {
                    $alert = new UI_Alert('<p>Please enter a valid phrase key.</p>', UI_Alert::TYPE_WARNING);
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to add new phrases to this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_PROJECT);
    }
    else {
        echo UI::getPage(UI::PAGE_PROJECT, array($alert));
    }
}
elseif (UI::isPage('create_project') && Authentication::isSignedIn()) {
    if (UI::isAction('create_project')) {
        $data = UI::getDataPOST('create_project');

        $data_visibility = isset($data['visibility']) ? intval($data['visibility']) : 0;
        $data_name = isset($data['name']) ? trim($data['name']) : '';
        $data_defaultLanguage = isset($data['defaultLanguage']) ? intval($data['defaultLanguage']) : 0;
        $data_editRepositoryID = isset($data['editRepositoryID']) ? UI::validateID($data['editRepositoryID'], true) : 0;
        if ($data_editRepositoryID > 0) {
            $repositoryData = Database::getRepositoryData($data_editRepositoryID);
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $data_editRepositoryID, $repositoryData, Repository::ROLE_ADMINISTRATOR);
        }
        else {
            $isAllowed = true;
        }

        if ($data_visibility == Repository::VISIBILITY_PUBLIC || $data_visibility == Repository::VISIBILITY_PRIVATE) {
            $allLanguages = Language::getList();
            if (in_array($data_defaultLanguage, $allLanguages)) {
                if (mb_strlen($data_name) >= 3) {
                    if ($data_editRepositoryID <= 0 || $isAllowed) {
                        if ($data_editRepositoryID > 0) { // edit project
                            Database::update("UPDATE repositories SET name = ".Database::escape($data_name).", visibility = ".intval($data_visibility).", defaultLanguage = ".intval($data_defaultLanguage)." WHERE id = ".intval($data_editRepositoryID));
                            header('Location: '.URL::toProject($data_editRepositoryID));
                        }
                        else { // create project
                            Database::insert("INSERT INTO repositories (name, visibility, defaultLanguage, creation_date) VALUES (".Database::escape($data_name).", ".intval($data_visibility).", ".intval($data_defaultLanguage).", ".time().")");
                            $newProjectID = Database::getLastInsertID(Database::TABLE_REPOSITORIES_SEQUENCE);
                            if ($newProjectID > 0) {
                                Database::insert("INSERT INTO roles (userID, repositoryID, role) VALUES (".intval(Authentication::getUser()->getID()).", ".intval($newProjectID).", ".Repository::ROLE_ADMINISTRATOR.")");
                                header('Location: '.URL::toProject($newProjectID));
                            }
                            else {
                                throw new Exception('Project could not be created');
                            }
                        }
                    }
                    else {
                        $alert = new UI_Alert('<p>You are not allowed to edit this project!</p>', UI_Alert::TYPE_WARNING);
                    }
                }
                else {
                    $alert = new UI_Alert('<p>Your project\'s name must be at least 3 characters long!</p>', UI_Alert::TYPE_WARNING);
                }
            }
            else {
                $alert = new UI_Alert('<p>Please choose one of the languages from the list!</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>Please choose one of the visibility levels from the list!</p>', UI_Alert::TYPE_WARNING);
        }

        echo UI::getPage(UI::PAGE_CREATE_PROJECT, array($alert));
    }
    else {
        echo UI::getPage(UI::PAGE_CREATE_PROJECT);
    }
}
elseif (UI::isPage('phrase')) {
    $alert = NULL;
    if (UI::isAction('phraseChange')) {
        $repositoryID = UI::validateID(UI::getDataGET('project'), true);
        $repositoryData = Database::getRepositoryData($repositoryID);
        if (!empty($repositoryData)) {
            $isAllowed = Repository::hasUserPermissions(Authentication::getUserID(), $repositoryID, $repositoryData, Repository::ROLE_DEVELOPER);
            if ($isAllowed) {
                $data = UI::getDataPOST('phraseChange');
                if (isset($data['phraseKey']) && isset($data['action'])) {
                    if ($data['action'] == 'untranslate') {
                        Database::phraseUntranslate($repositoryID, $data['phraseKey'], $repositoryData['defaultLanguage']);
                        $alert = new UI_Alert('<p>You have successfully removed all translations for this phrase!</p>', UI_Alert::TYPE_SUCCESS);
                    }
                    elseif ($data['action'] == 'delete') {
                        Database::phraseDelete($repositoryID, $data['phraseKey']);
                        $alert = new UI_Alert('<p>You have successfully deleted the phrase from the project completely!</p>', UI_Alert::TYPE_SUCCESS);
                    }
                }
            }
            else {
                $alert = new UI_Alert('<p>You are not allowed to edit phrases for this project.</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>The project could not be found.</p>', UI_Alert::TYPE_WARNING);
        }
    }
    if (empty($alert)) {
        echo UI::getPage(UI::PAGE_PHRASE);
    }
    else {
        echo UI::getPage(UI::PAGE_PHRASE, array($alert));
    }
}
else {
    if (UI::isAction('sign_in')) {
        $data = UI::getDataPOST('sign_in');

        $data_username = isset($data['username']) ? trim($data['username']) : '';
        $data_password = isset($data['password']) ? trim($data['password']) : '';
        $data_returnURL = '';
        $data_returnURL_Base64 = isset($data['returnURL']) ? trim($data['returnURL']) : '';
        if ($data_returnURL_Base64 != '') {
            $temp = base64_decode($data_returnURL_Base64);
            if ($temp !== false) {
                $data_returnURL = $temp;
            }
        }

        $userData = Database::selectFirst("SELECT id, username, password, real_name, localeCountry, localeTimezone, type, join_date FROM users WHERE username = ".Database::escape($data_username));
        if (!empty($userData)) {
            if (isset($userData['password']) && password_verify($data_password, $userData['password'])) {
                $userObject = new User($userData['id'], $userData['type'], $userData['username'], $userData['real_name'], $userData['localeCountry'], $userData['localeTimezone'], $userData['join_date']);
                Authentication::signIn($userObject);
                $pendingEdits = Database::getPendingEditsByUser($userData['id']);
                Authentication::restoreCachedEdits($pendingEdits);
                Database::setLastLogin($userData['id'], time());
                if (empty($data_returnURL) || !URL::isProject($data_returnURL)) {
                    $alert = new UI_Alert('<p>You have successfully been signed in!</p>', UI_Alert::TYPE_SUCCESS);
                }
                else {
                    UI::redirectToURL($data_returnURL);
                }
            }
            else {
                $alert = new UI_Alert('<p>Please check your password again!</p>', UI_Alert::TYPE_WARNING);
            }
        }
        else {
            $alert = new UI_Alert('<p>We could not find any user with this name!</p>', UI_Alert::TYPE_WARNING);
        }

        if (Authentication::isSignedIn()) {
            echo UI::getPage(UI::PAGE_DASHBOARD, array($alert));
        }
        else {
            echo UI::getPage(UI::PAGE_INDEX, array($alert));
        }
    }
    else {
        if (Authentication::isSignedIn()) {
            $alert = NULL;
            if (UI::isAction('requestInvitation')) {
                $data = UI::getDataPOST('requestInvitation');
                $data_repositoryID = isset($data['repositoryID']) ? UI::validateID($data['repositoryID'], true) : 0;
                $data_userID = Authentication::getUserID();
                if ($data_repositoryID > 0 && $data_userID > 0) {
                    try {
                        Database::requestInvitation($data_repositoryID, $data_userID);
                        $alert = new UI_Alert('<p>Your request for an invitation has been sent.</p>', UI_Alert::TYPE_SUCCESS);
                    }
                    catch (Exception $e) {
                        $alert = new UI_Alert('<p>You have already requested an invitation. Please check the status on your dashboard.</p>', UI_Alert::TYPE_WARNING);
                    }
                }
            }
            if (empty($alert)) {
                echo UI::getPage(UI::PAGE_DASHBOARD);
            }
            else {
                echo UI::getPage(UI::PAGE_DASHBOARD, array($alert));
            }
        }
        else {
            echo UI::getPage(UI::PAGE_INDEX);
        }
    }
}

?>