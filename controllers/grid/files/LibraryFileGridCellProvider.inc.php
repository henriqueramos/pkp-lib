<?php

/**
 * @file controllers/grid/files/LibraryFileGridCellProvider.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LibraryFileGridCellProvider
 * @ingroup controllers_grid_settings_library
 *
 * @brief Subclass for a LibraryFile grid column's cell provider
 */

use PKP\controllers\grid\GridCellProvider;
use PKP\controllers\grid\GridColumn;
use PKP\controllers\grid\GridHandler;

class LibraryFileGridCellProvider extends GridCellProvider
{
    /**
     * Extracts variables for a given column from a data element
     * so that they may be assigned to template before rendering.
     *
     * @param $row GridRow
     * @param $column GridColumn
     *
     * @return array
     */
    public function getTemplateVarsFromRowColumn($row, $column)
    {
        $element = & $row->getData();
        $columnId = $column->getId();
        assert($element instanceof \PKP\core\DataObject && !empty($columnId));
        switch ($columnId) {
            case 'files':
                // handled by our link action.
                return ['label' => ''];
        }
    }

    /**
     * Get cell actions associated with this row/column combination
     *
     * @param $row GridRow
     * @param $column GridColumn
     *
     * @return array an array of LinkAction instances
     */
    public function getCellActions($request, $row, $column, $position = GridHandler::GRID_ACTION_POSITION_DEFAULT)
    {
        switch ($column->getId()) {
            case 'files':
                $element = $row->getData();
                assert(is_a($element, 'LibraryFile'));
                // Create the cell action to download a file.
                import('lib.pkp.controllers.api.file.linkAction.DownloadLibraryFileLinkAction');
                return [new DownloadLibraryFileLinkAction($request, $element)];
        }
        return parent::getCellActions($request, $row, $column, $position);
    }
}
