<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\Actions\Columns;

use Piwik\Piwik;
use Piwik\Plugins\Actions\Segment;
use Piwik\Plugin\Dimension\VisitDimension;
use Piwik\Tracker\Action;
use Piwik\Tracker\Request;
use Piwik\Tracker\Visitor;

class EntryPageUrl extends VisitDimension
{
    protected $columnName = 'visit_entry_idaction_url';

    protected $columnType = 'INTEGER(11) UNSIGNED NULL  DEFAULT NULL';

    protected function configureSegments()
    {
        $segment = new Segment();
        $segment->setSegment('entryPageUrl');
        $segment->setName('Actions_ColumnEntryPageURL');
        $this->addSegment($segment);
    }

    /**
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onNewVisit(Request $request, Visitor $visitor, $action)
    {
        $idActionUrl = false;

        if (!empty($action)) {
            $idActionUrl = $action->getIdActionUrlForEntryAndExitIds();
        }

        if($idActionUrl === false) {
            return false;
        }

        return (int) $idActionUrl;
    }

    /*
     * @param Request $request
     * @param Visitor $visitor
     * @param Action|null $action
     * @return mixed
     */
    public function onExistingVisit(Request $request, Visitor $visitor, $action)
    {
        $idAction = $visitor->getVisitorColumn('visit_entry_idaction_url');

        if (is_null($idAction) && !empty($action)) {
            $idAction = $action->getIdActionUrlForEntryAndExitIds();
            if (!empty($idAction)) {
                return $idAction;
            }
        }

        return false;
    }

    public function getName()
    {
        return Piwik::translate('Actions_ColumnEntryPageURL');
    }

}
