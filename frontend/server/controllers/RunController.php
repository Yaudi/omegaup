<?php

/**
 * RunController
 *
 * @author joemmanuel
 */
class RunController extends Controller {

	public static $grader = null;
	private static $practice = false;
	private static $problem = null;
	private static $contest = null;

	/**
	 * Creates an instance of Grader if not already created
	 */
	private static function initialize() {

		if (is_null(self::$grader)) {
			// Create new grader
			self::$grader = new Grader();
		}

		// Set practice mode OFF by default
		self::$practice = false;
	}

	/**
	 * 
	 * Validates Create Run request 
	 * 
	 * @param Request $r
	 * @return type
	 * @throws ApiException
	 * @throws InvalidDatabaseOperationException
	 * @throws NotAllowedToSubmitException
	 * @throws InvalidParameterException
	 * @throws ForbiddenAccessException
	 */
	private static function validateCreateRequest(Request $r) {

		try {
			Validators::isStringNonEmpty($r["problem_alias"], "problem_alias");

			// Check that problem exists
			self::$problem = ProblemsDAO::getByAlias($r["problem_alias"]);

			Validators::isInEnum($r["language"], "language", array('kp', 'kj', 'c', 'cpp', 'java', 'py', 'rb', 'pl', 'cs', 'p'));
			Validators::isStringNonEmpty($r["source"], "source");

			// Check for practice, there is no contest info in this scenario
			if ($r["contest_alias"] == "" && (Authorization::IsSystemAdmin($r["current_user_id"]) || time() > ProblemsDAO::getPracticeDeadline(self::$problem->getProblemId()))) {
				if (!RunsDAO::IsRunInsideSubmissionGap(
								null, self::$problem->getProblemId(), $r["current_user_id"])
						&& !Authorization::IsSystemAdmin($r["current_user_id"])) {
					throw new NotAllowedToSubmitException();
				}

				self::$practice = true;
				return;
			}

			// Validate contest
			Validators::isStringNonEmpty($r["contest_alias"], "contest_alias");
			self::$contest = ContestsDAO::getByAlias($r["contest_alias"]);

			// Validate that the combination contest_id problem_id is valid            
			if (!ContestProblemsDAO::getByPK(
							self::$contest->getContestId(), self::$problem->getProblemId()
			)) {
				throw new InvalidParameterException("problem_alias and contest_alias combination is invalid.");
			}

			// Contest admins can skip following checks
			if (!Authorization::IsContestAdmin($r["current_user_id"], self::$contest)) {
				// Before submit something, contestant had to open the problem/contest
				if (!ContestsUsersDAO::getByPK($r["current_user_id"], self::$contest->getContestId())) {
					throw new ForbiddenAccessException("Unable to submit run: You must open the problem before trying to submit a solution.");
				}

				// Validate that the run is timely inside contest
				if (!self::$contest->isInsideContest($r["current_user_id"])) {
					throw new ForbiddenAccessException("Unable to submit run: Contest time has expired or not started yet.");
				}

				// Validate if contest is private then the user should be registered
				if (self::$contest->getPublic() == 0
						&& is_null(ContestsUsersDAO::getByPK(
										$r["current_user_id"], self::$contest->getContestId()))) {
					throw new ForbiddenAccessException("Unable to submit run: You are not registered to this contest.");
				}

				// Validate if the user is allowed to submit given the submissions_gap 			
				if (!RunsDAO::IsRunInsideSubmissionGap(
								self::$contest->getContestId(), self::$problem->getProblemId(), $r["current_user_id"])) {
					throw new NotAllowedToSubmitException("Unable to submit run: You have to wait " . self::$contest->getSubmissionsGap() . " seconds between consecutive submissions.");
				}
			}
		} catch (ApiException $apiException) {
			// Propagate ApiException
			throw $apiException;
		} catch (Exception $e) {
			// Operation failed in the data layer			
			throw new InvalidDatabaseOperationException($e);
		}
	}

	/**
	 * Create a new run 
	 * 
	 * @param Request $r
	 * @return array
	 * @throws Exception
	 * @throws InvalidDatabaseOperationException
	 * @throws InvalidFilesystemOperationException
	 */
	public static function apiCreate(Request $r) {

		// Init
		self::initialize();

		// Authenticate user
		self::authenticateRequest($r);

		// Validate request
		self::validateCreateRequest($r);

		Logger::log("New run being submitted !!");
		$response = array();

		if (self::$practice) {
			$submit_delay = 0;
			$contest_id = null;
			$test = 0;
		} else {
			//check the kind of penalty_time_start for this contest
			$penalty_time_start = self::$contest->getPenaltyTimeStart();

			switch ($penalty_time_start) {
				case "contest":
					// submit_delay is calculated from the start
					// of the contest
					$start = self::$contest->getStartTime();
					break;

				case "problem":
					// submit delay is calculated from the 
					// time the user opened the problem
					$opened = ContestProblemOpenedDAO::getByPK(
									self::$contest->getContestId(), self::$problem->getProblemId(), $r["current_user_id"]
					);

					if (is_null($opened)) {
						//holy moly, he is submitting a run 
						//and he hasnt even opened the problem
						//what should be done here?
						Logger::error("User is submitting a run and he has not even opened the problem");
						throw new Exception("User is submitting a run and he has not even opened the problem");
					}

					$start = $opened->getOpenTime();
					break;

				case "none":
					//we dont care
					$start = null;
					break;

				default:
					Logger::error("penalty_time_start for this contests is not a valid option, asuming `none`.");
					$start = null;
			}

			if (!is_null($start)) {
				//ok, what time is it now?
				$c_time = time();
				$start = strtotime($start);

				//asuming submit_delay is in minutes
				$submit_delay = (int) (( $c_time - $start ) / 60);
			} else {
				$submit_delay = 0;
			}

			$contest_id = self::$contest->getContestId();
			$test = Authorization::IsContestAdmin($r["current_user_id"], self::$contest) ? 1 : 0;
		}

		// Populate new run object
		$run = new Runs(array(
					"user_id" => $r["current_user_id"],
					"problem_id" => self::$problem->getProblemId(),
					"contest_id" => $contest_id,
					"language" => $r["language"],
					"source" => $r["source"],
					"status" => "new",
					"runtime" => 0,
					"memory" => 0,
					"score" => 0,
					"contest_score" => 0,
					"ip" => $_SERVER['REMOTE_ADDR'],
					"submit_delay" => $submit_delay, /* based on penalty_time_start */
					"guid" => md5(uniqid(rand(), true)),
					"veredict" => "JE",
					"test" => $test
				));

		try {
			// Push run into DB
			RunsDAO::save($run);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		try {
			// Create file for the run        
			$filepath = RUNS_PATH . DIRECTORY_SEPARATOR . $run->getGuid();
			FileHandler::CreateFile($filepath, $r["source"]);
		} catch (Exception $e) {
			throw new InvalidFilesystemOperationException($e);
		}

		// Call Grader
		try {
			self::$grader->Grade($run->getRunId());
		} catch (Exception $e) {
			Logger::error("Call to Grader::grade() failed:");
			Logger::error($e);
			throw new InvalidFilesystemOperationException($e);
		}

		if (self::$practice) {
			$response['submission_deadline'] = 0;
		} else {
			// Add remaining time to the response
			try {
				$contest_user = ContestsUsersDAO::getByPK($r["current_user_id"], self::$contest->getContestId());

				if (self::$contest->getWindowLength() === null) {
					$response['submission_deadline'] = strtotime(self::$contest->getFinishTime());
				} else {
					$response['submission_deadline'] = min(strtotime(self::$contest->getFinishTime()), strtotime($contest_user->getAccessTime()) + self::$contest->getWindowLength() * 60);
				}
			} catch (Exception $e) {
				// Operation failed in the data layer
				throw new InvalidDatabaseOperationException($e);
			}
		}

		// Happy ending
		$response["guid"] = $run->getGuid();
		$response["status"] = "ok";

		if (!self::$practice) {
			/// @todo Invalidate cache only when this run changes a user's score
			///       (by improving, adding penalties, etc)
			self::InvalidateScoreboardCache(self::$contest->getContestId());
		}

		return $response;
	}

	/**
	 * Any new run can potentially change the scoreboard.
	 * When a new run is submitted, the scoreboard cache snapshot is deleted
	 * 
	 * @param int $contest_id
	 */
	private static function InvalidateScoreboardCache($contest_id) {
		// Invalidar cache del contestant
		$contestantScoreboardCache = new Cache(Cache::CONTESTANT_SCOREBOARD_PREFIX, $contest_id);
		$contestantScoreboardCache->delete();

		// Invalidar cache del admin
		$adminScoreboardCache = new Cache(Cache::ADMIN_SCOREBOARD_PREFIX, $contest_id);
		$adminScoreboardCache->delete();
	}

	/**
	 * Gets details of a run
	 * 
	 * @param Request $r
	 * @return array
	 * @throws ApiException
	 * @throws InvalidDatabaseOperationException
	 * @throws NotFoundException
	 * @throws ForbiddenAccessException
	 * @throws InvalidFilesystemOperationException
	 */
	public static function apiDetails(Request $r) {

		// Get the user who is calling this API
		self::authenticateRequest($r);
		
		Validators::isStringNonEmpty($r["run_alias"], "run_alias");

		try {
			// If user is not judge, must be the run's owner.
			$myRun = RunsDAO::getByAlias($r["run_alias"]);
		} catch (Exception $e) {
			// Operation failed in the data layer
			throw new InvalidDatabaseOperationException($e);
		}

		if (is_null($myRun)) {
			throw new NotFoundException();
		}

		if (!(Authorization::CanViewRun($r["current_user_id"], $myRun))) {
			throw new ForbiddenAccessException();
		}

		// Fill response
		$relevant_columns = array("guid", "language", "status", "veredict", "runtime", "memory", "score", "contest_score", "time", "submit_delay");
		$filtered = $myRun->asFilteredArray($relevant_columns);
		$filtered['time'] = strtotime($filtered['time']);
		$filtered['score'] = round((float) $filtered['score'], 4);
		$filtered['contest_score'] = round((float) $filtered['contest_score'], 2);

		$response = $filtered;

		try {
			// Get source code
			$filepath = RUNS_PATH . DIRECTORY_SEPARATOR . $myRun->getGuid();
			$response["source"] = FileHandler::ReadFile($filepath);
		} catch (Exception $e) {
			throw new InvalidFilesystemOperationException($e);
		}
		
		return $response;
	}

}

