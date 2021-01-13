<?php
//This doesnt check for the specific issueID but flushing all the notifications should mean that the test is semiaccurate each time it runs
$I = new AcceptanceTester($scenario);
$I->wantTo('Check permissions when viewing resolved issues');

$I->loginAsTech();
$I->amOnModulePage('Help Desk', 'issues_view.php');
$I->click("Open");
$I->dontSee('', '.error');
$issueID = $I->grabValueFromURL('issueID');
