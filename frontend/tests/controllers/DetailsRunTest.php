<?php

/**
 * Description of DetailsRunTest
 *
 * @author joemmanuel
 */

require_once 'ProblemsFactory.php';
require_once 'ContestsFactory.php';
require_once 'RunsFactory.php';

class DetailsRunTest extends OmegaupTestCase {
	
	public function testShowRunDetailsValid() {
		
		// Get a problem
		$problemData = ProblemsFactory::createProblem();

		// Get a contest 
		$contestData = ContestsFactory::createContest();

		// Add the problem to the contest
		ContestsFactory::addProblemToContest($problemData, $contestData);

		// Create our contestant
		$contestant = UserFactory::createUser();
		
		// Create a run
		$runData = RunsFactory::createRun($problemData, $contestData, $contestant);
		
		// Prepare request
		$r = new Request();
		$r["auth_token"] = $this->login($contestant);
		$r["run_alias"] = $runData["response"]["guid"];
		
		// Call API
		$response = RunController::apiDetails($r);
		
		$this->assertEquals($r["run_alias"], $response["guid"]);
        $this->assertEquals("JE", $response["veredict"]);
        $this->assertEquals("new", $response["status"]);
		
	}
}

