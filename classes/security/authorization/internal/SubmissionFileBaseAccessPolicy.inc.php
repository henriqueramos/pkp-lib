<?php
/**
 * @file classes/security/authorization/internal/SubmissionFileBaseAccessPolicy.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SubmissionFileBaseAccessPolicy
 * @ingroup security_authorization_internal
 *
 * @brief Abstract class for submission file access policies.
 *
 */

namespace PKP\security\authorization\internal;

use APP\facades\Repo;
use PKP\security\authorization\AuthorizationPolicy;

class SubmissionFileBaseAccessPolicy extends AuthorizationPolicy
{
    /** @var PKPRequest */
    public $_request;

    /** @var int Submission file id */
    public $_submissionFileId;

    /**
     * Constructor
     *
     * @param $request PKPRequest
     * @param $submissionFileId int If passed, this policy will try to
     * get the submission file from this data.
     */
    public function __construct($request, $submissionFileId = null)
    {
        parent::__construct('user.authorization.submissionFile');
        $this->_request = $request;
        $this->_submissionFileId = $submissionFileId;
    }


    //
    // Private methods
    //
    /**
     * Get a cache of submission files. Used because many policy subclasses
     * may be combined to fetch a single submission file.
     *
     * @return array
     */
    public function &_getCache()
    {
        static $cache;
        if (!isset($cache)) {
            $cache = [];
        }
        return $cache;
    }


    //
    // Protected methods
    //
    /**
     * Get the requested submission file.
     *
     * @param $request PKPRequest
     *
     * @return SubmissionFile
     */
    public function getSubmissionFile($request)
    {
        // Get the identifying info from the request
        if (is_null($this->_submissionFileId)) {
            $this->_submissionFileId = (int) $request->getUserVar('submissionFileId');
            assert($this->_submissionFileId > 0);
        }

        // Fetch the object, caching if possible
        $cache = & $this->_getCache();
        if (!isset($cache[$this->_submissionFileId])) {
            $cache[$this->_submissionFileId] = Repo::submissionFiles()->get($this->_submissionFileId);
        }

        return $cache[$this->_submissionFileId];
    }

    /**
     * Get the current request object.
     *
     * @return PKPRequest
     */
    public function getRequest()
    {
        return $this->_request;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\security\authorization\internal\SubmissionFileBaseAccessPolicy', '\SubmissionFileBaseAccessPolicy');
}
