<?php
const VERSION =  "00.03Beta";
const EOL = "\r\n";
const DEBUG = false;
/* This PHP code creates a PBN from ACBLLive handrecord data.
 *
 * Input:
 *   api_token (required) - password to allow access to ACBLLive APIs
 *   id (required) - session id (<id>-<event>-<session #>)
 *      ex: 
 *      	NABC171-OPEN-2
 *      	1610209-29L3-1
 *      	1606043-04L3-1
 *  acbl_number (optional) - ACBL player number
 *  	ex:
 *  		k212501
 *  		2212501
 *  		K212501
 *      
 * Output:
 * 	a V2.1-compilant PBN file
 *
 * 	when acbl_number is provided (and found in the session data)
 * 	a "personalized" PBN is created that includes recap data
 * 	for each board played.  This PBN can then be read into
 * 	programs such as
 * 	    Bob Richardson's Double Dummy Solver
 * 	    Ray Spalding's Bridge Composer
 *
 * In an attempt to reduce execution time, only one ACBL Live API
 * is called: the tournament/session API
 */
   parse_str($_SERVER["QUERY_STRING"], $query_array);

   $acbl_number = $_GET["acbl_number"];
   $sess_id = $_GET["id"];
   $api_token = $_GET["api_token"];
   
   $base_url = "http://api.acbl.org/v1/tournament/";

   preg_match('/^[0-9a-zA-Z]+-[0-9a-zA-z]+/',$sess_id, $sarr);
   $event_id=$sarr[0];   // the event id is everything up to the 2nd hypen of the session id

   // make sure we accept k212501, K212501, and 2212501
   $val = ord(ucfirst($acbl_number));  
   $acbl_number[0] = chr(($val > 57) ? $val - 25 : $val);
	   
   $sess_url = $base_url . 'session?&full_monty=1' . '&id=' . $sess_id .  '&api_token=' . $api_token;
   $sess_json = json_decode(file_get_contents($sess_url));


   if (!is_null($acbl_number)) {
	   $pair_info = getPairInfo($sess_json,$acbl_number);
	   if (!is_null($pair_info)) {
		   $orientation = ($pair_info->orientation === 'N-S')  ? 'NS' : 'EW';
		   //$pair_id="&pair_number=2&orientation=e-w&section_label=r";
		   $pair_id = '&pair_number=' . $pair_info->pair_number .
				   '&orientation=' . $pair_info->orientation . 
				   '&section_label=' . $pair_info->section_label;

		   // historical - no longer used
		   $pair_url = $base_url.'section/pair?' .  $pair_id . '&session_id=' . $sess_id .  '&full_monty=1&api_token=' . $api_token;

		   $section_label = $pair_info->section_label;
		   $session_board_results = getSessionBoardResults($sess_json, $section_label);
		   $pair_results_table = getPairResultsTable($session_board_results, $acbl_number);
		   $personalized_pbn = true;
	   } else { // could not find
		   $personalized_pbn = false;
	   }
   } else {
	   $personalized_pbn = false;
   }



    $content  = "% PBN 2.1".EOL;
    $content  .= "% EXPORT".EOL;
    if ($personalized_pbn && !is_null($acbl_number) && !is_null($pair_info)) {
	$vul = str_replace("-","",$bd->vulnerability);
	$player_name =	formatName($acbl_number == $pair_info->pair_acbl[0] ?
				   $pair_info->pair_names[0] : $pair_info->pair_names[1]);

	// This was the initial attempt to set the player name. 
	$content  .= "%ACBL_Name:". str_replace(" ", ".", $player_name). EOL;

	// Although  this is not an official PBN tag, this is what Bob Richardson's DoubleDummy Solver uses
	// to set the player name. Currently (version 11.74), it also recognizes %ACBL_Name
        $content .= '[FixedPlayer "'.$player_name.'"]' . EOL;

	$content  .= "% " . $pair_info->section_label . $pair_info->pair_number.  $pair_info->orientation
		    . " " .  $pair_info->pair_names[0]."(".  $pair_info->pair_acbl[0] . ")-" .
		    $pair_info->pair_names[1]."(".  $pair_info->pair_acbl[1] . ")" .  EOL;
    }

   /* This is an effort to create a filename that matches ACBLLive's Hands-Export PDF
    * The ACBLLive filename format is basically
    * 	<category-letter><tournament_id>-<box_number>.pdf
    * This program attempts to mirror that:
    * 	<category-letter><tournament_id>-<box_number>.pbn
    *
    * It appears that if the tournament category is a sectional or regional,
    * the ACBLLive filename (when exporting a PDF of the handrecord)
    * has a 'S' or 'R' in front of the numeric tournament ID.
    * For an NABC, the tournament ID starts with "NABC" (e.g. NABC171).
    * No category letter is placed in front of the ID.
    * For a North Americ Pairs event, the numeric ID is preceded
    * with 'GP'.
    * If we are creating a personalized PBN, we prepend the player name (with spaces removed)
    *   ex: David.Kopper-NABC171-03161301
    */
   $filename = $personalized_pbn ? str_replace(" ","",$player_name).'-' : '';
   $cat = $sess_json->tournament->category;
   $filename .=  $cat === 'GNT/NAP' ? 'GP' : ($cat === 'NABC' ? '' : $cat[0]);
   $filename .=  $sess_json->tournament->id . '-' .  $sess_json->box_number . '.pbn';

   if (DEBUG) {
    $content  .= "% SESSION URL ".$sess_url.EOL;
   }
    
    $content  .= "% id " . $sess_json->id.EOL;

    $rawdate = $sess_json->start_date;
    $fmtdate = substr($rawdate,0,4) . "." . substr($rawdate,4,2) . "." . substr($rawdate,6,2);

   /* The V2.1 PBN spec says the tags should be in the follwing order:
    * Event 
    * Site 
    * Date 
    * Board 
    * West 
    * North 
    * East 
    * South 
    * Dealer 
    * Vulnerable 
    * Deal 
    * Scoring
    * Declarer 
    * Contract 
    * Result
    */
    $event .= '[Event "'.$fmtdate." ".$sess_json->start_time." ".$sess_json->description.'"]';
    $site .= '[Site "'.$sess_json->tournament->name.'"]';
    $date .= '[Date "'.$fmtdate.'"]';

    if (empty($sess_json->handrecord)) { 
	$content .= $event.EOL;
	$content .= $site.EOL;
	$content .= $date.EOL;
        $content .= '% NO HANDRECORD DATA FOUND!' . EOL;
    }

    foreach ($sess_json->handrecord as $bd)  {
	if ($personalized_pbn && is_null($pairResults = getPairResults($pair_results_table, $bd->board_number))) {continue;}
	$content .= $event.EOL;
	$content .= $site.EOL;
	$content .= $date.EOL;
	$content .= '[Board "'.$bd->board_number.'"]'.EOL;

	if ($personalized_pbn) {
		$bd_orientation =  getBoardOrientation($session_board_results,$bd->board_number,$acbl_number);
		if ($bd_orientation === "N-S") {
			$content .= '[West "' . formatName($pairResults->opponent_pair_names[1]) .'"]'.EOL;
			$content .= '[North "' . formatName($pair_info->pair_names[0]) .'"]'.EOL;
			$content .= '[East "' . formatName($pairResults->opponent_pair_names[0]) .'"]'.EOL;
			$content .= '[South "' . formatName($pair_info->pair_names[1]) .'"]'.EOL;
		} else {
			$content .= '[West "' . formatName($pair_info->pair_names[1]) .'"]'.EOL;
			$content .= '[North "' . formatName($pairResults->opponent_pair_names[0]) .'"]'.EOL;
			$content .= '[East "' . formatName($pair_info->pair_names[0]) .'"]'.EOL;
			$content .= '[South "' . formatName($pairResults->opponent_pair_names[1]) .'"]'.EOL;
		}
	}

	$dealer = substr(ucfirst($bd->dealer),0,1);
	$content .= '[Dealer "'.$dealer.'"]'.EOL;
	$vul = str_replace("-","",$bd->vulnerability);
	$content .= '[Vulnerable "'.$vul.'"]'.EOL;

	$n =  formatDeal($bd->north_spades . "." . $bd->north_hearts . "." . $bd->north_diamonds . "." . $bd->north_clubs);
	$e =  formatDeal($bd->east_spades . "." . $bd->east_hearts . "." . $bd->east_diamonds . "." . $bd->east_clubs);
	$s =  formatDeal($bd->south_spades . "." . $bd->south_hearts . "." . $bd->south_diamonds . "." . $bd->south_clubs);
	$w =  formatDeal($bd->west_spades . "." . $bd->west_hearts . "." . $bd->west_diamonds . "." . $bd->west_clubs);
	$dirarr = array ("N"=>"0","E"=>"1","S"=>"2","W"=>"3");
	$dealarr = array($n,$e,$s,$w);
	$start = $dirarr[$dealer];
	$dealstr = "";
	// Rotate cards so dealer cards come first
	for ($i=0;$i<4;$i++) {
		$dealstr .= $dealarr[($start + $i)%4];
		$dealstr .= ($i==3) ? '' : ' ';
	}
	$content .= '[Deal "' . $dealer . ':' . $dealstr .'"]'.EOL;
	$content .= '[Scoring "' . 'MP2' .'"]'.EOL;
	$content .= '[Lead ""]'.EOL; // at some point, the Lead may be available
	if ($personalized_pbn) {
		$content .= '[ScorePercentage "' . str_replace("-","",$bd_orientation) . ' ' . $pairResults->percentage .'"]'.EOL;
		$declarer =  ucfirst($pairResults->declarer);
		$content .= '[Declarer "' . $declarer[0] .'"]'.EOL;

		$contract = $pairResults->contract;
		// It looks like there is a slight chance for a problem if the opponent declarer
		// could be assigned a score that is not 0-(fixed_player score) 
		// Note that AVG+ or AVG- is usually not a problem because there is 
		// no declarer, so making the score fixed_player-centric is OK.
		// Non-complementary scores should be okay in the ScoreTable
		// because both scores are available
		if (is_numeric($pairResults->score)) {
			// if the side that declared  got a plus score, the contract made
			// score must be declarer score, not the pairs
			if (strchr($bd_orientation, $declarer[0])) {
				//  name player's side declared, keep the score
				$made = $pairResults->score >=0 ? true : false;
				$declarer_score = $pairResults->score;
			} else { // the named player's side did not declare
				$made = $pairResults->score >=0 ? false : true;
				$declarer_score = -$pairResults->score;
			}
		} else { // could be PASS, AVE+, AVE-
			if ($pairResults->score === "PASS") {
				$contract = "PASS";
				$declarer_score = 0;
			} else {
				$declarer_score = $pairResults->score;
			}
		}
		$content .= '[Contract "' . $contract .'"]'.EOL;
		$content .= '[Score "' . $declarer_score .'"]'.EOL;

		$tricks = determineTricks($pairResults->contract,isVul($vul,$declarer),
				$made, $pairResults->score);
		$content .= '[Result "' . ($pairResults->contract != "" ? $tricks : "") . '"]' . EOL;

		
		$content .= '[ScoreTable "Setion\1L;PairId_NS\2R;PairId_EW\2R;Contract\4L;' .
			'Declarer\1L;Result\2R;Score_NS\5R;Score_EW\5R;' . 
		       	'MP_NS\4R;MP_EW\4R;Names_NS;Names_EW"]' . EOL;
		$content .= getScoreTable($section_label, $session_board_results, $bd->board_number, $vul);

	}


	$dd = $bd->double_dummy_north_south;
	if ($dd != false) {
		$ddarr = resultsTable("NS", $dd);

		$dd = $bd->double_dummy_east_west;
		if ($dd != false) {
			$ddarr = array_merge($ddarr, resultsTable("EW", $dd));
		}
		$content .= doubledummytricks($ddarr);

		// One of the few things that come over without formatting needed
		// Ex: "double_dummy_par_score": "-300 5D*-NS-2"
		$content .= optimumContractScore ($bd->double_dummy_par_score);

		$content .= '[OptimumResultTable ""]'.EOL;
		$content .= implode (EOL, $ddarr) . EOL;
	}
	$content .= EOL;

   }
    header('Content-Description: File Transfer');
    header('Content-Type: test/html');
    header('Content-disposition: attachment; filename="'. $filename . '"');
    header('Content-Length: '.strlen($content));
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Pragma: public');
    echo $content;

// $session_board_results is the all the results for the section
// This function extracts just the results for the "fixed_player" pair
// and builds / returns the PairResults table
// This, in part, keeps us from needing the Pair API
function getPairResultsTable ($session_board_results, $acbl_number) {
	if ($session_board_results == NULL) {return NULL;}
	$results_table = array();
	foreach($session_board_results as $results) {
	   if (($acbl_number == $results->pair_acbl[0]) || ($acbl_number == $results->pair_acbl[1])) {
		   array_push ($results_table, $results);
	   }
	}
	return  $results_table;
}

// Get the pair results from the pair_results table
// Since the board results for the pair appear to be in order,
// It would probably be more efficient just to pop off the
// results object from the array, eliminating the need
// for this function.
function getPairResults ($arr, $board_number) {
	foreach($arr as $results) {
	   if ($board_number == $results->board_number) {
		   return ($results);
	   }
	  }
	return  NULL;
}

// The pair info we need (this could have been extracted from
// the Pair API), is mostly given in any board the pair plays.
// The exception is this does not include the section label.
// So, we geet that from the section header and add it to 
// what we are calling the info object.
function getPairInfo ($json, $acbl_number) {
	foreach($json->sections as $section) {
		foreach($section->section_results as $results) {
		   if (($acbl_number == $results->players[0]) || ($acbl_number == $results->players[1])) {
			   // now, find the first occurence of the player in the board results array
			   // we do this since the section_results does not give all the pair info
			   // we need
			foreach($section->board_results as $info) {
			   if (($acbl_number == $info->pair_acbl[0]) || ($acbl_number == $info->pair_acbl[1])) {
				    $info_obj = (array)$info;
				    // add section_label (section letter), since it is not kept in
				    // the sections board results table
				    $info_obj['section_label'] = $section->section_label;
				    // Not sure it is necessary to cast the json object as an array
				    // and then back to an object, but it appears to work.
				    $info_obj = (object)$info_obj;
				    return $info_obj;
			   }
			}
		   }
	        }
        }
	return  NULL;
}


// fetching the session board results so we don't have to
// traverse the entire json structure multiple times.
function getSessionBoardResults ($json, $label) {
	foreach($json->sections as $section) {
		if ($label == $section->section_label) {
			return ($section->board_results);
	        }
        }
	return  NULL;
}

/*  This function is possibly the world's uggliest code.
 *  There must be a cleaner way to calculate the number of
 *  tricks taken by declarer.
 *  It is needed for the PBN result (number of tricks) parameter -
 *  both the "Result" line and in the ScoreTable.
 *  The electronics (Bridgemate/Bridgepad)essentially capture
 *  this in their Result field with values of =, +<nbr>, -<nbr>
 *  If the ACBLLive API made this available, it would be a simple
 *  matter to add  this value to 6 + contract level.
 */
function determineTricks ($contract, $vul, $made, $score) {
	if ($contract == "") {return 0;}
	$doubled = $redoubled = false;
	if (substr($contract, -2) == "xx") {$redoubled = true;}
	elseif (substr($contract, -1) == "x") {$doubled = true;}
	$level = $contract[0];
	$denom = $contract[1];
	$trick_value = ($denom == 'C' || $denom == 'D') ? 20 : 30;
	$major =  ($denom == 'H') || ($denom == 'S');
	$minor =  ($denom == 'C') || ($denom == 'D');
	$tricks_required = $contract[0] + 6; 
	$in_slam = ($level == 6);

	if ($made && ($level == 7)) {return 13;}

	if (!$doubled  && !$redoubled) {
		if ($made) {
			if (abs($score) < 400) {
				return  intval((abs($score) - 50) / $trick_value) + 6;
			} elseif (abs($score) < 920) {
			       	return  intval((abs($score) - ($vul ? 500 : 300)) / $trick_value) + 6;
			} elseif (abs($score) < 1480 || (($denom == 'C'  || $denom == 'D') && abs($score) < 950)) {
			       	return  intval((abs($score) - ($vul ? 1250 : 800)) / $trick_value) + 6;
			}
		} else { // went set
			return intval($tricks_required - abs($score) / ($vul ? 100 : 50));
		}

	}
	elseif ($doubled) {
		if ($made) {
			if ($in_slam) {  // small slam
				$doubled_value = ($vul ? 1250 : 800) + 12 *
				       	($minor ? 20 : 30) + 50; 
				if ($denom == 'N') {$doubled_value += 20;}
				$extra_tricks = (abs($score) - $doubled_value) / ($vul ? 200 : 100);
				return intval($tricks_required  + $extra_tricks);
			}
			$in_game = ($level >= 2 & $denom == 'N') ||
			       	(($level >=2) && $major) || (($level >=3) && $minor);
			if ($in_game) {
				$doubled_value = ($vul ? 500 : 300) + 2 * $level *
				       	($minor ? 20 : 30) + 50; 
				if ($denom == 'N') {$doubled_value += 20;}
				$extra_tricks = (abs($score) - $doubled_value) / ($vul ? 200 : 100);
				return intval($tricks_required  + $extra_tricks);
			}
			// doubled, but not to game (i.e. 1 level or 2C/D)
			$doubled_value = 2 * $level * ($minor ? 20 : 30) + 50; 
			if ($denom == 'N') {$doubled_value += 20;}
			$extra_tricks = (abs($score) - $doubled_value) / ($vul ? 200 : 100);
			return intval($tricks_required  + $extra_tricks);
		} else { // went set
			$tricks_set = $vul ? (abs($score) + 100) / 300 :
				intval((abs($score) + 400)/300);
			return intval($tricks_required - $tricks_set);
		} 
       	} else { // $redoubled
		if ($made) {
			if ($in_slam) {  // small slam
				$redoubled_value = ($vul ? 1250 : 800) + 24 *
				       	($minor ? 20 : 30) + 100; 
				if ($denom == 'N') {$redoubled_value += 40;}
				$extra_tricks = (abs($score) - $redoubled_value) / ($vul ? 400 : 200);
				return intval($tricks_required  + $extra_tricks);
			}
			$in_game = $level > 1 || ($denom != 'C' && $denom != 'D');
			if ($in_game) {
				$redoubled_value = ($vul ? 500 : 300) + 4 * $level *
				       	($minor ? 20 : 30) + 100; 
				if ($denom == 'N') {$redoubled_value += 40;}
				$extra_tricks = (abs($score) - $redoubled_value) / ($vul ? 400 : 200);
				return intval($tricks_required  + $extra_tricks);
			}
			// redoubled, but not to game (i.e. 1C/D)
			$redoubled_value = 230; 
			$extra_tricks = (abs($score) - $redoubled_value) / ($vul ? 400 : 200);
			return intval($tricks_required  + $extra_tricks);
		} else { // went set
			$tricks_set = $vul ? (abs($score) + 200) / 600 :
				intval((abs($score) + 800)/600);
			return intval($tricks_required - $tricks_set);
		} 
	}
	return (-1); // should not happen
}

function isVul ($vul, $declarer) {
	if ($vul === "Both") { return true; }
	if ($vul === "None") { return false; }
	if ($declarer === "North" || $declarer === "South") {
		return ($vul === "NS") ? true : false;
	}  else { // East or West
		return ($vul === "EW") ? true : false;
	}
}

function optimumContractScore ($par) {
   preg_match('/^([\-\+][0-9]+) ([0-9][a-zA-Z]+[\*]*)\-([a-zA-Z]+)/',$par, $arr);
   $str= '[OptimumContract "' . $arr[2] . '-' .$arr[3] . '"]'.EOL;
   $str.= '[OptimumScore "' . $arr[3] . ' ' .$arr[1] . '"]'.EOL;
   return $str;
}

/* As fars as I know, the DoubleDummyTricks tag is a non-standard PBN
 * tag that pre-dates the OptimumResultTable.
 * It consists of 20 hexadecimal numbers. The first 5 are
 * the number of tricks (0-13) that North can take in NT, 
 * spades, hearts, diamonds, and clubs.  This is repeated for
 * South, East, and West.
 * Ex:  [DoubleDummyTricks "11116111169ccc79ccb7"]
 */
function doubledummytricks ($resultstable) {
	$str = "";
	foreach($resultstable as $tricks) {
		$str .= dechex(explode(" ",$tricks)[2]);
	}
	return  '[DoubleDummyTricks "' . $str .  '"]'.EOL;
	return $str;
}

// Most of the names are like "John Jones",  but there are exceptions
// We replace any hyphen or space with a period, so we can handle names like
// 	Kareem Abdul-Jabal, Jay Whipple III and Jo Anna Standsby
function formatName ($name) {
	return (str_replace (" ",".",str_replace("-",".",$name)));
}

// The score is the N-S score.  So, the declarer and whether the score was positive
// determines whether or not the declarer made his contract.
function madeContract ($declarer, $score) {
	if (($declarer == "N" || $declarer == "S") && $score > 0) {return true;}
	if (($declarer == "E" || $declarer == "W") && $score < 0) {return true;}
	return false;
}

//  Board results are there as a N-S orientation and E-W orientation
//  We need EW results also, since the E-W orientation score and MP is not
//  always 0-NSscore and Top - NSmatchpoints
function getEWBoardResults ($arr, $board_number, $pair_number) {
	foreach($arr as $results) {
		if ($board_number == $results->board_number && 
			$results->orientation == "E-W" &&
		        $results->opponent_pair_number == $pair_number) {
			return $results;
		}
	}
	return NULL;
}

// If the movement is Howell, the orientation may change. So, we have
// to lookup the board each time and see what the orientation is
function getBoardOrientation ($arr, $board_number, $player_number) {
	foreach($arr as $results) {
		if ($board_number == $results->board_number) {
			if ($player_number == $results->pair_acbl[0] ||
				$player_number == $results->pair_acbl[1] ) { 
					return $results->orientation;
			}
		}
	}
	return  "";
}

// We need both copies (NS and EW orientation) of all results for this board
// Note:  a ScoreTable row consists of
//    Section  PairID_NS  PairID_EW Contract Declarer Result(tricks) Score_NS Score_EW MP_NS MP_EW Names_NS Names_EW
function getScoreTable ($section_label, $arr, $board_number, $vul) {
	$str = "";
	$board_arr = $arr;
	foreach($arr as $results) {
		if ($board_number == $results->board_number && $results->orientation == "N-S") {
			$EWresults = getEWBoardResults ($board_arr, $board_number, $results->pair_number);
			$str .= $section_label .  ' ';
			$str .= sprintf("%-2d %-2d",  $results->pair_number, 
				$EWresults->pair_number);
			
			$contract = $results->contract;
			if ($results->score === "PASS") {$contract = "PASS";}
			// if there is no contract (empty string), use hyphen as a place holder
			$str .= sprintf(" %-4s", $contract ? $contract : "-");

			// if there is no declarer (empty string), use hyphen as a place holder
			$str .= sprintf(" %1s", $results->declarer ? ucfirst($results->declarer)[0] : '-');

			$tricks =  determineTricks($results->contract, isVul($vul, $results->declarer),
					madeContract(ucfirst($results->declarer)[0], $results->score), 
					$results->score);
			if ($tricks == 0) {
				$str .= '  -';
			} else {
				$str .= sprintf(" %2d", $tricks);
			}
			$score = $results->score;
			if ($score === "PASS") {$score = 0;}
			$str .= is_numeric($score) ?
					sprintf(" %5d", $score) : sprintf(" %5s", $score); 
			$score = $EWresults->score;
			if ($score === "PASS") {$score = 0;}
			$str .= is_numeric($score) ?
					sprintf(" %5d", $score) : sprintf(" %5s", $score); 
			$str .= sprintf(" %-5.2f %5.2f",
					$results->match_points, $EWresults->match_points);
			$str .= sprintf(" %-30s", formatName($results->pair_names[0])
					. '-' .  formatName($results->pair_names[1]))
				.  ' ' .  formatName($EWresults->pair_names[0])
				.  '-' .  formatName($EWresults->pair_names[1]);
			$str .= EOL;
		}
	}
	return  $str;
}

// The API card values have a ten as "10", have spaces, and have "-----" for a void
// So, to put it into PBN style format, we need to replace "10" with "T", and remove
// spaces and "-----" 
function formatDeal ($dealstr) {
	$outstr =  str_replace ("10","T",str_replace(" ","",str_replace("-","",$dealstr)));
	return $outstr;
}


// This clever trick (not my invention) gives the
// sort value of Notrump, spades, hearts, diamonds,
// and clubs so we can order them correctly
function getSortOrder($c) {
    $sortOrder = array('N','S','H','D','C');
    $pos = array_search($c, $sortOrder);
    return $pos !== false ? $pos : 99999;
}

// Sort by direction - denomination - number of tricks
function tblsort($a, $b) {
	if( $a[0] < $b[0] ) {
		return -1;
	}elseif( $a[0] == $b[0] ) {
		$aval = getSortOrder($a[2]);
		$bval = getSortOrder($b[2]);
		if ($aval < $bval) { return -1;}
		return ($aval > $bval) ? 1 : 0;
	}else {
		return 1;
	}
}

/* This function takes the 20 double_dummy_makes values,
 * formats them and sorts them by direction/denomination 
 * giving 5 values for each direction in N-S-H-D-C order
 * This is used for the OptimumResultsTable and the
 * DoubleDummyTicks value
 *   N S 9
 *   N H 6
 *   . . .
 *   S C 8
 *   . . .
 *   E NT 4
 *   . . .
 *   W NT 4
 *   . . .
 *   W C 4
 */
function resultsTable($dir, $double_dummy_makes) {
	$tblArr = array();
	$str = "";
	$makearr = explode(" ",$double_dummy_makes);
	$str = "";
	foreach($makearr as $value){
	  // The double dummy values could be in several different forms. Ex:
	  // 4C    2/3S    -/1NT    1/-NT  D5   S4/2 NT2/5
		  // 
	  // We must handle them all
		  // arr[0] will have total match
		  // arr[1] will have first number or suit (or hyphen)
		  // arr[2] will have (optionally) a slash
		  // arr[3] will have the second number or suit (or hyphen)
		  // arr[4] will have (optionally) a slash
		  // arr[5] will have the second number or suit (or hypen)
	  // 4C      2/3S        -/1NT        1/-NT      D5     S4/2      NT2/5
	  // 4||C    2|/|3||S    -|/|1||NT    1|/|-||NT  D||5   S||4|/|2  NT||2|/|5

	  //                     1              2         3           4       5
	  if (preg_match("/([-0-9A-Za-z][Tt]*)([\/]*)([-0-9A-Za-z][Tt]*)([\/]*)([-0-9a-zA-Z]*[Tt]*)/", $value, $arr)) {
		  if (count ($arr) != 6) {continue;}
	          if (($arr[1] == '-') || ($arr[3] == '-')) {continue;} // shows up again
		  $suit = $arr[3];
		  $NorE_tricks = $SorW_level = "";
		  if ($arr[2] != "/") {
			  if (ctype_digit($arr[3])) {
				  $NorE_tricks = $SorW_tricks =  $arr[3];
				  $suit = $arr[1];
				  if ($arr[4] == "/") {
					  $SorW_tricks =  $arr[5];
				  }
			  } else {
				  $NorE_tricks = $SorW_tricks =  $arr[1] + 6;
			  }
		  }
		  else { // the second character is a slash
			  $suit = $arr[5];
			  $NorE_tricks = ctype_digit($arr[1]) ? $arr[1] + 6 :  6;
			  $SorW_tricks = ctype_digit($arr[3]) ? $arr[3] + 6 :  6;
		  }
	  }
	  if ($NorE_tricks != "") {array_push($tblArr,  $dir[0] . ' ' . $suit . ' ' . $NorE_tricks);}
	  if ($NorE_tricks != "") {array_push($tblArr,  ($dir == "NS" ? 'S' : 'W') . ' ' . "$suit" . ' ' . $SorW_tricks);}

	 }
	usort($tblArr, tblsort);
	return $tblArr;
   }
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title>Create PBN from API request</title>
</head>

<body>
</body>

