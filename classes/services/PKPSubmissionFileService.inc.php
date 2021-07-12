<?php
/**
 * @file classes/services/PKPSubmissionFileService.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PKPSubmissionFileService
 * @ingroup services
 *
 * @brief Helper class that encapsulates business logic for publications
 */

namespace PKP\services;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\db\DAOResultFactory;
use PKP\log\SubmissionEmailLogEntry;
use PKP\log\SubmissionFileEventLogEntry;
use PKP\log\SubmissionFileLog;
use PKP\log\SubmissionLog;
use PKP\mail\SubmissionMailTemplate;
use PKP\notification\PKPNotification;
use PKP\plugins\HookRegistry;
use PKP\security\Role;
use PKP\services\interfaces\EntityPropertyInterface;
use PKP\services\interfaces\EntityReadInterface;
use PKP\services\interfaces\EntityWriteInterface;
use PKP\services\queryBuilders\PKPSubmissionFileQueryBuilder;
use PKP\submissionFile\SubmissionFile;
use PKP\validation\ValidatorFactory;

class PKPSubmissionFileService implements EntityPropertyInterface, EntityReadInterface, EntityWriteInterface
{
    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::get()
     */
    public function get($id)
    {
        return DAORegistry::getDAO('SubmissionFileDAO')->getById($id);
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::getCount()
     */
    public function getCount($args = [])
    {
        return $this->getQueryBuilder($args)->getCount();
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::getIds()
     */
    public function getIds($args = [])
    {
        return $this->getQueryBuilder($args)->getIds();
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::getMany()
     *
     * @param null|mixed $args
     */
    public function getMany($args = null)
    {
        $submissionFileQO = $this
            ->getQueryBuilder($args)
            ->getQuery()
            ->join('submissions as s', 's.submission_id', '=', 'sf.submission_id')
            ->join('files as f', 'f.file_id', '=', 'sf.file_id')
            ->select(['sf.*', 'f.*', 's.locale as locale']);
        $submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
        $result = $submissionFileDao->retrieve($submissionFileQO->toSql(), $submissionFileQO->getBindings());
        $queryResults = new DAOResultFactory($result, $submissionFileDao, '_fromRow');

        return $queryResults->toIterator();
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::getMax()
     *
     * @param null|mixed $args
     */
    public function getMax($args = null)
    {
        return $this->getCount($args);
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityReadInterface::getQueryBuilder()
     */
    public function getQueryBuilder($args = [])
    {
        $defaultArgs = [
            'assocTypes' => [],
            'assocIds' => [],
            'fileIds' => [],
            'fileStages' => [],
            'genreIds' => [],
            'includeDependentFiles' => false,
            'reviewIds' => [],
            'reviewRoundIds' => [],
            'submissionIds' => [],
            'uploaderUserIds' => [],
        ];

        $args = array_merge($defaultArgs, $args);

        $submissionFileQB = new PKPSubmissionFileQueryBuilder();
        $submissionFileQB
            ->filterByAssoc($args['assocTypes'], $args['assocIds'])
            ->filterByFileIds($args['fileIds'])
            ->filterByFileStages($args['fileStages'])
            ->filterByGenreIds($args['genreIds'])
            ->filterByReviewIds($args['reviewIds'])
            ->filterByReviewRoundIds($args['reviewRoundIds'])
            ->filterBySubmissionIds($args['submissionIds'])
            ->filterByUploaderUserIds($args['uploaderUserIds'])
            ->includeDependentFiles($args['includeDependentFiles']);

        HookRegistry::call('SubmissionFile::getMany::queryBuilder', [&$submissionFileQB, $args]);

        return $submissionFileQB;
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getProperties()
     *
     * @param null|mixed $args
     */
    public function getProperties($submissionFile, $props, $args = null)
    {
        $request = $args['request'];
        $submission = $args['submission'];
        $dispatcher = $request->getDispatcher();

        $values = [];

        foreach ($props as $prop) {
            switch ($prop) {
                case '_href':
                    $values[$prop] = $dispatcher->url(
                        $request,
                        \PKPApplication::ROUTE_API,
                        $request->getContext()->getData('urlPath'),
                        'submissions/' . $submission->getId() . '/files/' . $submissionFile->getId()
                    );
                    break;
                case 'dependentFiles':
                    $dependentFilesIterator = Services::get('submissionFile')->getMany([
                        'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
                        'assocIds' => [$submissionFile->getId()],
                        'submissionIds' => [$submission->getId()],
                        'fileStages' => [SubmissionFile::SUBMISSION_FILE_DEPENDENT],
                        'includeDependentFiles' => true,
                    ]);
                    $dependentFiles = [];
                    foreach ($dependentFilesIterator as $dependentFile) {
                        $dependentFiles[] = $this->getFullProperties($dependentFile, $args);
                    }
                    $values[$prop] = $dependentFiles;
                    break;
                case 'documentType':
                    $values[$prop] = Services::get('file')->getDocumentType($submissionFile->getData('mimetype'));
                    break;
                case 'revisions':
                    $files = [];
                    $revisions = DAORegistry::getDAO('SubmissionFileDAO')->getRevisions($submissionFile->getId());
                    foreach ($revisions as $revision) {
                        if ($revision->fileId === $submissionFile->getData('fileId')) {
                            continue;
                        }
                        $files[] = [
                            'documentType' => Services::get('file')->getDocumentType($revision->mimetype),
                            'fileId' => $revision->fileId,
                            'mimetype' => $revision->mimetype,
                            'path' => $revision->path,
                            'url' => $dispatcher->url(
                                $request,
                                \PKPApplication::ROUTE_COMPONENT,
                                $request->getContext()->getData('urlPath'),
                                'api.file.FileApiHandler',
                                'downloadFile',
                                null,
                                [
                                    'fileId' => $revision->fileId,
                                    'submissionFileId' => $submissionFile->getId(),
                                    'submissionId' => $submissionFile->getData('submissionId'),
                                    'stageId' => $this->getWorkflowStageId($submissionFile),
                                ]
                            ),
                        ];
                    }
                    $values[$prop] = $files;
                    break;
                case 'url':
                    $values[$prop] = $dispatcher->url(
                        $request,
                        \PKPApplication::ROUTE_COMPONENT,
                        $request->getContext()->getData('urlPath'),
                        'api.file.FileApiHandler',
                        'downloadFile',
                        null,
                        [
                            'submissionFileId' => $submissionFile->getId(),
                            'submissionId' => $submissionFile->getData('submissionId'),
                            'stageId' => $this->getWorkflowStageId($submissionFile),
                        ]
                    );
                    break;
                default:
                    $values[$prop] = $submissionFile->getData($prop);
                    break;
            }
        }

        $values = Services::get('schema')->addMissingMultilingualValues(PKPSchemaService::SCHEMA_SUBMISSION_FILE, $values, $request->getContext()->getSupportedFormLocales());

        HookRegistry::call('SubmissionFile::getProperties', [&$values, $submissionFile, $props, $args]);

        ksort($values);

        return $values;
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getSummaryProperties()
     *
     * @param null|mixed $args
     */
    public function getSummaryProperties($submissionFile, $args = null)
    {
        $props = Services::get('schema')->getSummaryProps(PKPSchemaService::SCHEMA_SUBMISSION_FILE);

        return $this->getProperties($submissionFile, $props, $args);
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityPropertyInterface::getFullProperties()
     *
     * @param null|mixed $args
     */
    public function getFullProperties($submissionFile, $args = null)
    {
        $props = Services::get('schema')->getFullProps(PKPSchemaService::SCHEMA_SUBMISSION_FILE);

        return $this->getProperties($submissionFile, $props, $args);
    }

    /**
     * @copydoc \PKP\services\interfaces\EntityWriteInterface::validate()
     */
    public function validate($action, $props, $allowedLocales, $primaryLocale)
    {
        \AppLocale::requireComponents(
            LOCALE_COMPONENT_PKP_MANAGER,
            LOCALE_COMPONENT_APP_MANAGER
        );
        $schemaService = Services::get('schema');

        $validator = ValidatorFactory::make(
            $props,
            $schemaService->getValidationRules(PKPSchemaService::SCHEMA_SUBMISSION_FILE, $allowedLocales)
        );

        // Check required fields if we're adding a context
        ValidatorFactory::required(
            $validator,
            $action,
            $schemaService->getRequiredProps(PKPSchemaService::SCHEMA_SUBMISSION_FILE),
            $schemaService->getMultilingualProps(PKPSchemaService::SCHEMA_SUBMISSION_FILE),
            $allowedLocales,
            $primaryLocale
        );

        // Check for input from disallowed locales
        ValidatorFactory::allowedLocales($validator, $schemaService->getMultilingualProps(PKPSchemaService::SCHEMA_SUBMISSION_FILE), $allowedLocales);

        // Do not allow the uploaderUserId or createdAt properties to be modified
        if ($action === EntityWriteInterface::VALIDATE_ACTION_EDIT) {
            $validator->after(function ($validator) use ($props) {
                if (!empty($props['uploaderUserId']) && !$validator->errors()->get('uploaderUserId')) {
                    $validator->errors()->add('uploaderUserId', __('submission.file.notAllowedUploaderUserId'));
                }
                if (!empty($props['createdAt']) && !$validator->errors()->get('createdAt')) {
                    $validator->errors()->add('createdAt', __('api.files.400.notAllowedCreatedAt'));
                }
            });
        }

        // Make sure that file stage and assocType match
        if (!empty($props['assocType'])) {
            $validator->after(function ($validator) use ($props) {
                if (empty($props['fileStage'])) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.noFileStageId'));
                } elseif ($props['assocType'] === ASSOC_TYPE_REVIEW_ROUND && !in_array($props['fileStage'], [SubmissionFile::SUBMISSION_FILE_REVIEW_FILE, SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION, SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_FILE, SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION])) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.badReviewRoundAssocType'));
                } elseif ($props['assocType'] === ASSOC_TYPE_REVIEW_ASSIGNMENT && $props['fileStage'] !== SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.badReviewAssignmentAssocType'));
                } elseif ($props['assocType'] === ASSOC_TYPE_SUBMISSION_FILE && $props['fileStage'] !== SubmissionFile::SUBMISSION_FILE_DEPENDENT) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.badDependentFileAssocType'));
                } elseif ($props['assocType'] === ASSOC_TYPE_NOTE && $props['fileStage'] !== SubmissionFile::SUBMISSION_FILE_NOTE) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.badNoteAssocType'));
                } elseif ($props['assocType'] === ASSOC_TYPE_REPRESENTATION && $props['fileStage'] !== SubmissionFile::SUBMISSION_FILE_PROOF) {
                    $validator->errors()->add('assocType', __('api.submissionFiles.400.badRepresentationAssocType'));
                }
            });
        }

        if ($validator->fails()) {
            $errors = $schemaService->formatValidationErrors($validator->errors(), $schemaService->get(PKPSchemaService::SCHEMA_SUBMISSION_FILE), $allowedLocales);
        }

        HookRegistry::call('SubmissionFile::validate', [&$errors, $action, $props, $allowedLocales, $primaryLocale]);

        return $errors;
    }

    /**
     * @copydoc \PKP\services\entityProperties\EntityWriteInterface::add()
     */
    public function add($submissionFile, $request)
    {
        $submissionFile->setData('createdAt', Core::getCurrentDate());
        $submissionFile->setData('updatedAt', Core::getCurrentDate());
        $id = DAORegistry::getDAO('SubmissionFileDAO')->insertObject($submissionFile);
        $submissionFile = $this->get($id);

        $submission = Repo::submission()->get($submissionFile->getData('submissionId'));

        SubmissionFileLog::logEvent(
            $request,
            $submissionFile,
            SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_UPLOAD,
            'submission.event.fileUploaded',
            [
                'fileStage' => $submissionFile->getData('fileStage'),
                'sourceSubmissionFileId' => $submissionFile->getData('sourceSubmissionFileId'),
                'submissionFileId' => $submissionFile->getId(),
                'fileId' => $submissionFile->getData('fileId'),
                'submissionId' => $submissionFile->getData('submissionId'),
                'originalFileName' => $submissionFile->getLocalizedData('name'),
                'username' => $request->getUser()->getUsername(),
            ]
        );

        $user = $request->getUser();
        SubmissionLog::logEvent(
            $request,
            $submission,
            SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_REVISION_UPLOAD,
            'submission.event.fileRevised',
            [
                'fileStage' => $submissionFile->getFileStage(),
                'submissionFileId' => $submissionFile->getId(),
                'fileId' => $submissionFile->getData('fileId'),
                'submissionId' => $submissionFile->getData('submissionId'),
                'username' => $user->getUsername(),
                'name' => $submissionFile->getLocalizedData('name'),
            ]
        );

        // Update status and notifications when revisions have been uploaded
        if (in_array($submissionFile->getData('fileStage'), [SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION, SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION])) {
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
            $reviewRound = $reviewRoundDao->getById($submissionFile->getData('assocId'));
            if (!$reviewRound) {
                throw new \Exception('Submission file added to review round that does not exist.');
            }

            $reviewRoundDao->updateStatus($reviewRound);

            // Update author notifications
            $authorUserIds = [];
            $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
            $authorAssignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionFile->getData('submissionId'), Role::ROLE_ID_AUTHOR);
            while ($assignment = $authorAssignments->next()) {
                if ($assignment->getStageId() == $reviewRound->getStageId()) {
                    $authorUserIds[] = (int) $assignment->getUserId();
                }
            }
            $notificationMgr = new \NotificationManager();
            $notificationMgr->updateNotification(
                $request,
                [PKPNotification::NOTIFICATION_TYPE_PENDING_INTERNAL_REVISIONS, PKPNotification::NOTIFICATION_TYPE_PENDING_EXTERNAL_REVISIONS],
                $authorUserIds,
                ASSOC_TYPE_SUBMISSION,
                $submissionFile->getData('submissionId')
            );

            // Notify editors if the file is uploaded by an author
            if (in_array($submissionFile->getData('uploaderUserId'), $authorUserIds)) {
                if (!$submission) {
                    throw new \Exception('Submission file added to submission that does not exist.');
                }

                $context = $request->getContext();
                if ($context->getId() != $submission->getData('contextId')) {
                    $context = Services::get('context')->get($submission->getData('contextId'));
                }

                $uploader = $request->getUser();
                if ($uploader->getId() != $submissionFile->getData('uploaderUserId')) {
                    $uploader = Services::get('user')->get($submissionFile->getData('uploaderUserId'));
                }

                // Fetch the latest notification email timestamp
                $submissionEmailLogDao = DAORegistry::getDAO('SubmissionEmailLogDAO'); /** @var SubmissionEmailLogDAO $submissionEmailLogDao */
                $submissionEmails = $submissionEmailLogDao->getByEventType($submission->getId(), SubmissionEmailLogEntry::SUBMISSION_EMAIL_AUTHOR_NOTIFY_REVISED_VERSION);
                $lastNotification = null;
                $sentDates = [];
                if ($submissionEmails) {
                    while ($email = $submissionEmails->next()) {
                        if ($email->getDateSent()) {
                            $sentDates[] = $email->getDateSent();
                        }
                    }
                    if (!empty($sentDates)) {
                        $lastNotification = max(array_map('strtotime', $sentDates));
                    }
                }

                $mail = new SubmissionMailTemplate($submission, 'REVISED_VERSION_NOTIFY');
                $mail->setEventType(SubmissionEmailLogEntry::SUBMISSION_EMAIL_AUTHOR_NOTIFY_REVISED_VERSION);
                $mail->setReplyTo($context->getData('contactEmail'), $context->getData('contactName'));
                // Get editors assigned to the submission, consider also the recommendOnly editors
                $userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */
                $editorsStageAssignments = $stageAssignmentDao->getEditorsAssignedToStage($submission->getId(), $reviewRound->getStageId());
                foreach ($editorsStageAssignments as $editorsStageAssignment) {
                    $editor = $userDao->getById($editorsStageAssignment->getUserId());
                    // IF no prior notification exists
                    // OR if editor has logged in after the last revision upload
                    // OR the last upload and notification was sent more than a day ago,
                    // THEN send a new notification
                    if (is_null($lastNotification) || strtotime($editor->getDateLastLogin()) > $lastNotification || strtotime('-1 day') > $lastNotification) {
                        $mail->addRecipient($editor->getEmail(), $editor->getFullName());
                    }
                }
                // Get uploader name
                $mail->assignParams([
                    'authorName' => $uploader->getFullName(),
                    'editorialContactSignature' => $context->getData('contactName'),
                    'submissionUrl' => $request->getDispatcher()->url(
                        $request,
                        \PKPApplication::ROUTE_PAGE,
                        null,
                        'workflow',
                        'index',
                        [
                            $submission->getId(),
                            $reviewRound->getStageId(),
                        ]
                    ),
                ]);

                if ($mail->getRecipients()) {
                    if (!$mail->send($request)) {
                        $notificationMgr = new \NotificationManager();
                        $notificationMgr->createTrivialNotification($request->getUser()->getId(), PKPNotification::NOTIFICATION_TYPE_ERROR, ['contents' => __('email.compose.error')]);
                    }
                }
            }
        }

        HookRegistry::call('SubmissionFile::add', [&$submissionFile, $request]);

        return $submissionFile;
    }

    /**
     * @copydoc \PKP\services\entityProperties\EntityWriteInterface::edit()
     */
    public function edit($submissionFile, $params, $request)
    {
        $newFileUploaded = !empty($params['fileId']) && $params['fileId'] !== $submissionFile->getData('fileId');
        $submissionFile->_data = array_merge($submissionFile->_data, $params);
        $submissionFile->setData('updatedAt', Core::getCurrentDate());

        HookRegistry::call('SubmissionFile::edit', [&$submissionFile, $submissionFile, $params, $request]);

        DAORegistry::getDAO('SubmissionFileDAO')->updateObject($submissionFile);

        SubmissionFileLog::logEvent(
            $request,
            $submissionFile,
            $newFileUploaded ? SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_REVISION_UPLOAD : SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_EDIT,
            $newFileUploaded ? 'submission.event.revisionUploaded' : 'submission.event.fileEdited',
            [
                'fileStage' => $submissionFile->getData('fileStage'),
                'sourceSubmissionFileId' => $submissionFile->getData('sourceSubmissionFileId'),
                'submissionFileId' => $submissionFile->getId(),
                'fileId' => $submissionFile->getData('fileId'),
                'submissionId' => $submissionFile->getData('submissionId'),
                'originalFileName' => $submissionFile->getLocalizedData('name'),
                'username' => $request->getUser()->getUsername(),
            ]
        );

        $user = $request->getUser();
        $submission = Repo::submission()->get($submissionFile->getData('submissionId'));
        SubmissionLog::logEvent(
            $request,
            $submission,
            $newFileUploaded ? SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_REVISION_UPLOAD : SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_EDIT,
            $newFileUploaded ? 'submission.event.revisionUploaded' : 'submission.event.fileEdited',
            [
                'fileStage' => $submissionFile->getFileStage(),
                'sourceSubmissionFileId' => $submissionFile->getData('sourceSubmissionFileId'),
                'submissionFileId' => $submissionFile->getId(),
                'fileId' => $submissionFile->getData('fileId'),
                'submissionId' => $submissionFile->getData('submissionId'),
                'username' => $user->getUsername(),
                'originalFileName' => $submissionFile->getLocalizedData('name'),
                'name' => $submissionFile->getLocalizedData('name'),
            ]
        );

        return $this->get($submissionFile->getId());
    }

    /**
     * @copydoc \PKP\services\entityProperties\EntityWriteInterface::delete()
     */
    public function delete($submissionFile)
    {
        $submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /** @var SubmissionFileDAO $submissionFileDao */

        HookRegistry::call('SubmissionFile::delete::before', [&$submissionFile]);

        // Delete dependent files
        $dependentFilesIterator = $this->getMany([
            'includeDependentFiles' => true,
            'fileStages' => [SubmissionFile::SUBMISSION_FILE_DEPENDENT],
            'assocTypes' => [ASSOC_TYPE_SUBMISSION_FILE],
            'assocIds' => [$submissionFile->getId()],
        ]);
        foreach ($dependentFilesIterator as $dependentFile) {
            $this->delete($dependentFile);
        }

        // Delete review round associations
        if ($submissionFile->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION) {
            $reviewRoundDao = DAORegistry::getDAO('ReviewRoundDAO'); /** @var ReviewRoundDAO $reviewRoundDao */
            $reviewRound = $reviewRoundDao->getBySubmissionFileId($submissionFile->getId());
            $submissionFileDao->deleteReviewRoundAssignment($submissionFile->getId());
            $reviewRoundDao->updateStatus($reviewRound);
        }

        // Delete notes for this submission file
        $noteDao = DAORegistry::getDAO('NoteDAO'); /** @var NoteDAO $noteDao */
        $noteDao->deleteByAssoc(ASSOC_TYPE_SUBMISSION_FILE, $submissionFile->getId());

        // Update tasks
        $notificationMgr = new NotificationManager();
        switch ($submissionFile->getData('fileStage')) {
            case SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION:
                $authorUserIds = [];
                $stageAssignmentDao = DAORegistry::getDAO('StageAssignmentDAO'); /** @var StageAssignmentDAO $stageAssignmentDao */
                $submitterAssignments = $stageAssignmentDao->getBySubmissionAndRoleId($submissionFile->getData('submissionId'), Role::ROLE_ID_AUTHOR);
                while ($assignment = $submitterAssignments->next()) {
                    $authorUserIds[] = $assignment->getUserId();
                }
                $notificationMgr->updateNotification(
                    Application::get()->getRequest(),
                    [PKPNotification::NOTIFICATION_TYPE_PENDING_INTERNAL_REVISIONS, PKPNotification::NOTIFICATION_TYPE_PENDING_EXTERNAL_REVISIONS],
                    $authorUserIds,
                    ASSOC_TYPE_SUBMISSION,
                    $submissionFile->getData('submissionId')
                );
                break;

            case SubmissionFile::SUBMISSION_FILE_COPYEDIT:
                $notificationMgr->updateNotification(
                    Application::get()->getRequest(),
                    [PKPNotification::NOTIFICATION_TYPE_ASSIGN_COPYEDITOR, PKPNotification::NOTIFICATION_TYPE_AWAITING_COPYEDITS],
                    null,
                    ASSOC_TYPE_SUBMISSION,
                    $submissionFile->getData('submissionId')
                );
                break;
        }

        // Get all revision files before they are deleted in SubmissionFileDAO::deleteObject
        $revisions = $submissionFileDao->getRevisions($submissionFile->getId());

        // Delete the submission file
        $submissionFileDao->deleteObject($submissionFile);

        // Delete all files not referenced by other files
        foreach ($revisions as $revision) {
            $countFileShares = $this->getCount([
                'fileIds' => [$revision->fileId],
                'includeDependentFiles' => true,
            ]);
            if (!$countFileShares) {
                Services::get('file')->delete($revision->fileId);
            }
        }

        // Log the deletion
        SubmissionFileLog::logEvent(
            Application::get()->getRequest(),
            $submissionFile,
            SubmissionFileEventLogEntry::SUBMISSION_LOG_FILE_DELETE,
            'submission.event.fileDeleted',
            [
                'fileStage' => $submissionFile->getData('fileStage'),
                'sourceSubmissionFileId' => $submissionFile->getData('sourceSubmissionFileId'),
                'submissionFileId' => $submissionFile->getId(),
                'submissionId' => $submissionFile->getData('submissionId'),
                'username' => Application::get()->getRequest()->getUser()->getUsername(),
            ]
        );

        HookRegistry::call('SubmissionFile::delete', [&$submissionFile]);
    }
}
