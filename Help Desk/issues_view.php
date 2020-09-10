<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/
use Gibbon\Domain\DataSet;
use Gibbon\Forms\DatabaseFormFactory;
use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Domain\User\UserGateway;
use Gibbon\Module\HelpDesk\Domain\IssueGateway;
use Gibbon\Module\HelpDesk\Domain\TechGroupGateway;
use Gibbon\Module\HelpDesk\Domain\TechnicianGateway;
use Gibbon\Domain\System\SettingGateway;

//Module includes
require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(__('Issues'));

if (!isModuleAccessible($guid, $connection2)) {
    //Acess denied
    $page->addError(__('You do not have access to this action.'));
} else {
    if (isset($_GET['return'])) {
        $editLink = null;
        if (isset($_GET['issueID'])) {
            $editLink = $gibbon->session->get('absoluteURL') . '/index.php?q=/modules/' . $gibbon->session->get('module') . '/issues_discussView.php&issueID=' . $_GET['issueID'];
        }
        returnProcess($guid, $_GET['return'], $editLink, null);
    }

    $year = $_GET['year'] ?? $gibbon->session->get('gibbonSchoolYearID');

    $issueGateway = $container->get(IssueGateway::class);
    $criteria = $issueGateway->newQueryCriteria(true)
        ->searchBy($issueGateway->getSearchableColumns(), $_GET['search'] ?? '')
        ->filterBy('year', $year)
        ->sortBy('status', 'ASC')
        ->sortBy('issueID', 'DESC')
        ->fromPOST();
        
    $criteria->addFilterRules([
        'issue' => function ($query, $issue) use ($gibbon) {
            switch($issue) {
                case 'My Issues':
                    $query->where('helpDeskIssue.gibbonPersonID = :gibbonPersonID')
                        ->bindValue('gibbonPersonID', $gibbon->session->get('gibbonPersonID'));
                    break;
                case 'My Assigned':
                    $query->where('techID.gibbonPersonID=:techPersonID')
                        ->bindValue('techPersonID', $gibbon->session->get('gibbonPersonID'));
                    break;
            }
            return $query;
        },
    ]);

    $form = Form::create('searchForm', $gibbon->session->get('absoluteURL') . '/index.php', 'get');
    $form->setFactory(DatabaseFormFactory::create($pdo));

    $form->addHiddenValue('q', '/modules/' . $gibbon->session->get('module') . '/issues_view.php');
    $form->addHiddenValue('address', $gibbon->session->get('address'));

    $form->setClass('noIntBorder fullWidth standardForm');
    $form->setTitle(__('Search & Filter'));

    $row = $form->addRow();
        $row->addLabel('search', __('Search'))
            ->description(__('Issue ID, Name or Description.'));
        $row->addTextField('search')
            ->setValue($criteria->getSearchText());
    
    $row = $form->addRow();
        $row->addLabel('year', __('Year Filter'));
        $row->addSelectSchoolYear('year', 'All')
            ->selected($year);
    
    $row = $form->addRow();
        $row->addSearchSubmit($gibbon->session, __('Clear Filters'));

    echo $form->getOutput();      
    
    $techGroupGateway = $container->get(TechGroupGateway::class);
    $technicianGateway = $container->get(TechnicianGateway::class);
    $userGateway = $container->get(UserGateway::class);

    $isTechnician = $technicianGateway->getTechnicianByPersonID($gibbon->session->get('gibbonPersonID'))->isNotEmpty();

    if ($techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'viewIssue') || $techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'fullAccess')) {
        $issues = $issueGateway->queryIssues($criteria);
    } else if ($isTechnician) {
        $issues = $issueGateway->queryIssues($criteria, 'technician', $gibbon->session->get('gibbonPersonID'));
    } else {
        $issues = $issueGateway->queryIssues($criteria, 'owner', $gibbon->session->get('gibbonPersonID'));
    }
    
    $table = DataTable::createPaginated('issues', $criteria);
    $table->setTitle('Issues');
    
    //FILTERS START
    if ($techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'viewIssue')) {
        $table->addMetaData('filterOptions', ['issue:All'    => __('Issues').': '.__('All')]);
    }
    if ($isTechnician) {
        $table->addMetaData('filterOptions', ['issue:My Assigned'    => __('Issues').': '.__('My Assigned')]);
    }
    $table->addMetaData('filterOptions', ['issue:My Issues'    => __('Issues').': '.__('My Issues')]);
    if ($isTechnician) {
        if ($techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'viewIssueStatus')=='All') {        
            $table->addMetaData('filterOptions', [
                'status:Unassigned'           => __('Status').': '.__('Unassigned'),
                'status:Pending'          => __('Status').': '.__('Pending'),
                'status:Resolved'           => __('Status').': '.__('Resolved')
            ]);
        } else if ($techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'viewIssueStatus')=='UP') {            
            $table->addMetaData('filterOptions', [
                'status:Unassigned'           => __('Status').': '.__('Unassigned'),
                'status:Pending'          => __('Status').': '.__('Pending')
            ]);            
        } else if ($techGroupGateway->getPermissionValue($gibbon->session->get('gibbonPersonID'), 'viewIssueStatus')=='PR') {                    
            $table->addMetaData('filterOptions', [
                'status:Pending'          => __('Status').': '.__('Pending'),
                'status:Resolved'           => __('Status').': '.__('Resolved')
            ]);
        }
    } else {
        $table->addMetaData('filterOptions', [
                'status:Unassigned'           => __('Status').': '.__('Unassigned'),
                'status:Pending'          => __('Status').': '.__('Pending'),
                'status:Resolved'           => __('Status').': '.__('Resolved')
            ]);
    }

    $settingsGateway = $container->get(SettingGateway::class);

    $categoryFilters = explodeTrim($settingsGateway->getSettingByScope($gibbon->session->get('module'), 'issueCategory'));
    foreach  ($categoryFilters as $category) {
        $table->addMetaData('filterOptions', [
            'category:'.$category => __('Category').': '.$category,
        ]);
    }

    $priorityFilters = explodeTrim($settingsGateway->getSettingByScope($gibbon->session->get('module'), 'issuePriority', false));
    foreach  ($priorityFilters as $priority) {
        $table->addMetaData('filterOptions', [
            'priority:'.$priority => __('Priority').': '.$priority,
        ]);
    }
    //FILTERS END
    
    $gibbonPersonID = $gibbon->session->get('gibbonPersonID');
    $table->modifyRows(function($issue, $row) use ($gibbonPersonID, $techGroupGateway, $issueGateway) {
        if ($issue['status'] == 'Resolved') {
            $row->addClass('current');
        } else if ($issue['status'] == 'Unassigned') {
            $row->addClass('error');
        } else if ($issue['status'] == 'Pending') {
            $row->addClass('warning');
        }

        //Potentially could be done better
        if (!$techGroupGateway->getPermissionValue($gibbonPersonID, 'fullAccess') && $issue['status'] == 'Resolved') {
            switch ($issue['privacySetting']) {
                case 'No one':
                    $row = null;
                    break;
                case 'Owner':
                    $row = ($issue['gibbonPersonID'] == $gibbonPersonID) ? $row : null;
                    break;
                case 'Related':
                    $row = $issueGateway->isRelated($issue['issueID'], $gibbonPersonID) ? $row : null;
                    break;
            }
        }

        return $row;
    });

    $table->addHeaderAction('add', __('Create'))
            ->setURL('/modules/' .$gibbon->session->get('module') . '/issues_create.php')
            ->displayLabel();

    $table->addColumn('issueID', __('Issue ID'))
            ->format(Format::using('number', ['issueID'])); 
    $table->addColumn('issueName', __('Name'))
          ->description(__('Description'))
          ->format(function ($issue) {
            return '<strong>' . __($issue['issueName']) . '</strong><br/><small><i>' . __($issue['description']) . '</i></small>';
          });
    $table->addColumn('gibbonPersonID', __('Owner')) 
                ->description(__('Category'))
                ->format(function ($row) use ($userGateway) {
                    $owner = $userGateway->getByID($row['gibbonPersonID']);
                    return Format::name($owner['title'], $owner['preferredName'], $owner['surname'], 'Staff') . '<br/><small><i>'. __($row['category']) . '</i></small>';
                });

    if (!empty($priorityFilters)) {
        $table->addColumn('priority', __($settingsGateway->getSettingByScope($gibbon->session->get('module'), 'issuePriorityName')));
    }
    
    $table->addColumn('technicianID', __('Technician'))
                ->format(function ($row) use ($userGateway) {
                    $tech = $userGateway->getByID($row['techPersonID']);
                    if (empty($tech)) {
                        return "";
                    }
                    return Format::name($tech['title'], $tech['preferredName'], $tech['surname'], 'Staff');
                });         
    $table->addColumn('status', __('Status'))
          ->description(__('Date'))
          ->format(function ($issue) {
            return '<strong>' . __($issue['status']) . '</strong><br/><small><i>' . Format::date($issue['date']) . '</i></small>';
            });
    
    $table->addActionColumn()
            ->addParam('issueID')
            ->format(function ($issues, $actions) use ($gibbon, $techGroupGateway) {
                $moduleName = $gibbon->session->get('module');

                $gibbonPersonID = $gibbon->session->get('gibbonPersonID');
                $isPersonsIssue = $issues['gibbonPersonID'] == $gibbonPersonID;

                $actions->addAction('view', __('Open'))
                        ->setURL('/modules/' . $moduleName . '/issues_discussView.php');

                if ($issues['status'] != 'Resolved') {
                    if ($issues['technicianID'] == null) {
                        if (!$isPersonsIssue && $techGroupGateway->getPermissionValue($gibbonPersonID, 'acceptIssue')) {
                            $actions->addAction('accept', __('Accept'))
                                    ->directLink()
                                    ->setURL('/modules/' . $moduleName . '/issues_acceptProcess.php')
                                    ->setIcon('page_new');
                        }

                        if ($techGroupGateway->getPermissionValue($gibbonPersonID, 'assignIssue')) {
                            $actions->addAction('assign', __('Assign'))
                                    ->setURL('/modules/' . $moduleName . '/issues_assign.php')
                                    ->setIcon('attendance');
                        }
                    } else if ($techGroupGateway->getPermissionValue($gibbonPersonID, 'reassignIssue')) { 
                        $actions->addAction('assign', __('Reassign'))
                                ->setURL('/modules/' . $moduleName . '/issues_assign.php')
                                ->setIcon('attendance');
                    }

                    if($techGroupGateway->getPermissionValue($gibbonPersonID, 'resolveIssue') || $isPersonsIssue) {
                        $actions->addAction('resolve', __('Resolve'))
                                ->directLink()
                                ->setURL('/modules/' . $moduleName . '/issues_resolveProcess.php')
                                ->setIcon('iconTick');
                    }
                } else {
                    if ($techGroupGateway->getPermissionValue($gibbonPersonID, 'reincarnateIssue') || $isPersonsIssue) {
                        $actions->addAction('reincarnate', __('Reincarnate'))
                                ->directLink()
                                ->setURL('/modules/' . $moduleName . '/issues_reincarnateProcess.php')
                                ->setIcon('reincarnate');
                    }
                }
            });
    
    echo $table->render($issues);    
 
}
?>
