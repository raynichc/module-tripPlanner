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

use Gibbon\Domain\Departments\DepartmentGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Forms\Form;
use Gibbon\Module\TripPlanner\Domain\ApproverGateway;
use Gibbon\Module\TripPlanner\Domain\TripGateway;
use Gibbon\Services\Format;
use Gibbon\Tables\DataTable;
use Gibbon\Forms\DatabaseFormFactory;

require_once __DIR__ . '/moduleFunctions.php';

$page->breadcrumbs->add(__('Manage Trip Requests'));

if (!isActionAccessible($guid, $connection2, '/modules/Trip Planner/trips_manage.php')) {
    $page->addError(__('You do not have access to this action.'));
} else {
    $highestAction = getHighestGroupedAction($guid, '/modules/Trip Planner/trips_manage.php', $connection2);
    if ($highestAction != false) { 
        $gibbonPersonID = $gibbon->session->get('gibbonPersonID');

        //Settings
        $settingGateway = $container->get(SettingGateway::class);
        
        $requestApprovalType = $settingGateway->getSettingByScope('Trip Planner', 'requestApprovalType');
        $riskAssessmentApproval = $settingGateway->getSettingByScope('Trip Planner', 'riskAssessmentApproval');
        $eutFilter = $settingGateway->getSettingByScope('Trip Planner', 'expiredUnapprovedFilter');

        //Permissions
        $approverGateway = $container->get(ApproverGateway::class);
        
        $approver = $approverGateway->selectApproverByPerson($gibbonPersonID);
        $isApprover = !empty($approver);
        $finalApprover = $isApprover ? boolval($approver['finalApprover']) : false;

        $ama = ($isApprover && $requestApprovalType == 'Chain Of All')
            || ($riskAssessmentApproval && $finalApprover);

        //Department Data
        $departmentGateway = $container->get(DepartmentGateway::class);
        $departments = $departmentGateway->selectDepartmentsByPerson($gibbonPersonID, 'Coordinator');

        //Relations Filter set up
        $relations = [
            'MR' => 'My Requests',
            'I' => 'Involved',
        ];

        if ($highestAction == 'Manage Trips_full') {
            $relations = ['' => 'All Requests'] + $relations;
        } 

        if ($ama) {
            $relations['AMA'] = 'Awaiting My Approval';
            $relationFilter = 'AMA';
        }
        
        if ($departments->isNotEmpty()) {
            $relations['Department Requests'] = array_reduce($departments->fetchAll(), function ($group, $department) {
                $group['DR' . $department['gibbonDepartmentID']] = $department['name'];
                return $group;
            });
        }

        //Filters
        $relationFilter = $_POST['relationFilter'] ?? $relationFilter ?? 'MR'; //'My Requests' is default, overrided by current value, overrided by post value (i.e. value from filter form).
        $statusFilter = $_POST['statusFilter'] ?? 'Requested';
        $year = $_POST['year'] ?? $gibbon->session->get('gibbonSchoolYearID');

        //Filter Form
        $form = Form::create('tripFilters', $gibbon->session->get('absoluteURL') . '/index.php?q=' . $_GET['q']);
        $form->setFactory(DatabaseFormFactory::create($pdo));
        $form->setTitle(__('Filter'));

        $row = $form->addRow();
            $row->addLabel('relationFilter', 'Relation');
            $row->addSelect('relationFilter')
                ->fromArray($relations)
                ->selected($relationFilter);

        $row = $form->addRow();
            $row->addLabel('year', 'Year');
            $row->addSelectSchoolYear('year')
                ->selected($year);

        $row = $form->addRow();
            $row->addFooter();
            $row->addSubmit();

        print $form->getOutput(); 

        //Trips Data
        $tripGateway = $container->get(TripGateway::class);
        $criteria = $tripGateway->newQueryCriteria(true)
            ->filterBy('status', $statusFilter)
            ->filterBy('year', $year)
            ->fromPOST();

        $trips = $tripGateway->queryTrips($criteria, $gibbonPersonID, $relationFilter, $eutFilter);

        //Trips Table
        $table = DataTable::createPaginated('trips', $criteria);
        $table->setTitle(__('Requests'));

        if ($relationFilter == 'AMA' && $ama) {
            $table->modifyRows(function ($trip, $row) use ($container, $gibbonPersonID) {
                //TODO: Migrate to gateway/SQL
                return needsApproval($container, $gibbonPersonID, $trip['tripPlannerRequestID']) ? $row : null;
            });
        }

        $statusFilters = array_reduce(getStatuses(), function($filters, $status) {
            $filters['status:' . $status] = __('Status') . ': ' . __($status);
            return $filters;
        });
        $table->addMetaData('filterOptions', $statusFilters);
      
        $table->addHeaderAction('add', __('Submit Request'))
          ->displayLabel()
          ->setURL('/modules/Trip Planner/trips_submitRequest.php');
      
        $table->addExpandableColumn('contents')
            ->format(function ($trip) {
                $output = '';

                $output .= formatExpandableSection(__('Description'), $trip['description']);

                return $output;
            });

        $table->addColumn('tripTitle', __('Title'));

        $table->addColumn('owner', __('Owner'))
          ->format(Format::using('name', ['title', 'preferredName', 'surname', 'Staff', false, true]))
          ->sortable('surname');

        $table->addColumn('firstDayOfTrip', __('First Day of Trip'))
            ->format(Format::using('dateReadable', ['firstDayOfTrip']));

        $table->addColumn('status', __('Status'));
                   
        $table->addActionColumn()
          ->addParam('tripPlannerRequestID')
          ->format(function ($trip, $actions) use ($container, $gibbonPersonID)  {
              $actions->addAction('view', __('View Details'))
                ->setURL('/modules/Trip Planner/trips_requestView.php');

            if ($gibbonPersonID == $trip['creatorPersonID'] && !in_array($trip['status'], ['Cancelled', 'Rejected'])) {
                $actions->addAction('edit', __('Edit'))
                ->addParam('mode', 'edit')
                ->setURL('/modules/Trip Planner/trips_submitRequest.php');
            }
            
            if (needsApproval($container, $gibbonPersonID, $trip['tripPlannerRequestID'])) {
                $actions->addAction('approve', __('Approve/Reject'))
                    ->setURL('/modules/Trip Planner/trips_requestApprove.php')
                    ->setIcon('iconTick');
            }
          });
          
          echo $table->render($trips);
    } else {
        $page->addError(__('Highest grouped action could not be determined.'));
    }
}
