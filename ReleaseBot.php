<?php
/* Author : Bruno Drago
 * Gets the list of Release tickets from Jira on "Release Approval Needed" status
 * For each release ticket:
 *   - Checks for Hotfix Flag (not sure what to do with that yet)
 *   - Checks if PR/hash exists
 *   - Curls github and gets the list of commit hashes existing on PR
 *   - Checks if PR has at least 2 approvals and if any review is not approved 
 *   - For each jira issue:
 *      + Checks for ticket status "Releasable"
 *      + Gets the list of existing commits adds to a list of commits existing in jira
 *   - Compares the list of commits from jira with the list from github 
 *   - Checks if security ticket exists and if it is closed
 *   - Checks for blocker tickets
 *   TODO:
 *   - DB changes
 *   - Look for approval comments (josh's etc) or get his approval via slack 
 *   - Checks for build success
 */
ini_set('memory_limit', '1024M');

include "conf.php";

$jql = "assignee = release AND issuetype = Release AND status = 'Release Approval Needed' ORDER BY created ASC";

if($argc > 1) {
	$jql = "id=".$argv[1];
}
$request = "rest/api/2/search?jql=".urlencode($jql);

$Releases = Json_decode(CurlJira($request));

foreach ($Releases->issues as $r) {
	$msg = "";
	$JiraSmartCommits=array();
	$PRCommits=array();
        $commitMsgs=array();
	$squeekyClean=true;
	$msg.= "*".$r->fields->summary."* ";
	$msg.= "https://doorbot.atlassian.net/browse/".$r->key;
	
	if (count($r->fields->customfield_12901) > 0) {
		$msg.= " `HOTFIX`";
	}


	if (count($r->fields->issuelinks)>0) {
		foreach ($r->fields->issuelinks as $blocker) {
			if ($blocker->type->inward == "is blocked by" && isset($blocker->inwardIssue) && count($blocker->inwardIssue) > 0 && !in_array($blocker->inwardIssue->fields->status->name, array("Closed","In Production"))) {
				$msg .= "\n\t\tBlocked By: https://doorbot.atlassian.net/browse/".$blocker->inwardIssue->key." `".$blocker->inwardIssue->fields->status->name."`";
			}
		}
	}

	preg_match_all('([a-z0-9]{40})', $r->fields->customfield_13432, $hashes);

	if (count($hashes[0]) < 1) {
		$msg.= "\n\t`Hash Missing.`";	
		$squeekyClean=false;
		//break;
	}

	$displayHashes = array();	
	foreach ($hashes[0] as $hash) {
		//echo "\n\t*Hash:*\t".substr($hash,0,7);
		//$msg.= "\n\t*Hash:*\t".substr($hash,0,7);
		$displayHashes[] = substr($hash,0,7);
	}	

	preg_match_all('@((https?://)?([-\\w]+\\.[-\\w\\.]+)+\\w(:\\d+)?(/([-\\w/_\\.]*(\\?\\S+)?)?)*)@', $r->fields->customfield_13432, $prs);
	
	if (count($prs[0]) < 1) {
		$msg.= "\n\t\t`Pull Request Missing.`";
		$squeekyClean=false;
		//break;
	}
	foreach ($prs[0] as $pr) {
		$thisPRCommits = array();
		
		//$msg.= "\n\n\t*Pull Request:*\t".$pr;
		//echo "\n\n\t*Pull Request:*\t".$pr;
		//$msg.= "\n\t*Pull Request Commits:*\t";
		$urlpieces = explode("/",str_ireplace("https://github.com/","https://api.github.com/repos/",$pr));
		$url = "";
		for ($i=0; $i < count($urlpieces); $i++) {
	 		if ($urlpieces[$i] == 'pull') { 
				$url .= "pulls/".$urlpieces[++$i];
				//break;
			} else {
				$url .= $urlpieces[$i]."/";
			}
		}	
		$Json = CurlGitHub($url."/commits");

			//echo "\n";
		if (is_array($Json) && count($Json)>0) {
			foreach ($Json as $c) {
				$hash = substr($c->sha,0,7);
				$thisPRCommits[] = $hash;
				$commitMsgs[$hash]=str_replace("\n","",substr($c->commit->message,0,100));
				//echo "\t".$hash;
				if (in_array($hash,$displayHashes)) {	
					$prHash = $hash;
				}
			}
		}

		if (is_null($prHash)) {
			$msg .="\n\t\t".$pr."` last Commit is not in the hash list provided in the release ticket`";
		} else {
			if ($prHash && end($thisPRCommits) != $prHash) {
				$msg .="\n\t\t".$pr."` last Commit is not ".$prHash."`";
				$squeekyClean=false;
			}
		}
		$PRCommits = array_merge($PRCommits, $thisPRCommits); 


		$Json = CurlGitHub($url."/reviews");
		if (is_array($Json) && count($Json)>0) {
			if (count($Json) < 2) {
				$msg .="\n\t\t".$pr." `has less than 2 approvals`";
				$squeekyClean=false;
			}
			foreach ($Json as $rw) {
				if ($rw->state != "APPROVED") {
					$msg .="\n\t\t".$pr." `".$rw->state."`";
				}
			}
		}

	}


	$fixVersion = $r->fields->fixVersions[0]->name?$r->fields->fixVersions[0]->name:null;
	if ($fixVersion === null) { 
		$msg.= "\n\t\t`FixVersion Missing.`";
		$squeekyClean=false;
		//break;
	}	

	//$msg.= "\n\n\t*FixVersion:*\t".$fixVersion;
	//$msg.= "\n\n\t*Security Tasks:*";

	$securitySubtasks=0;
	if (count($r->fields->subtasks) > 0) {
		foreach ($r->fields->subtasks as $subtask) {
			if ($subtask->fields->status->name != "Closed") {		
				$msg.= "\n\t\t[".$subtask->key."] ".$subtask->fields->summary." - `".$subtask->fields->status->name."`";
			}
			if (trim($subtask->fields->summary) == trim("Get Security Sign Off - ".$r->fields->summary)) {
				$securitySubtasks++;
			}
			
		}
	}
	if ($securitySubtasks < 1) {
		$msg.= "\n\t\t`No security tasks found.`";
		$squeekyClean=false;
	}
	//$msg.= "\n\n\t*Tickets:*";
	
	$jql = "project=".$r->fields->project->key." and fixVersion=\"".$fixVersion."\" and id !=".$r->id;
	$request = "rest/api/2/search?jql=".urlencode($jql);

	$Issues = Json_decode(CurlJira($request));
	if (count($Issues->issues) == 0) {
		$msg.= "\n\t\t`No issues associated with the release.`";
		$squeekyClean=false;
	} else {
		foreach ($Issues->issues as $i) {

			$status = $i->fields->status->id;
			//$msg.= "\n\t\t[".$i->key."]";
			if ( $status != "14601" && $status != "6" && $status != "5") {
				$msg.= " \n\t\t`[".$i->key."]` Not in RELEASABLE|RESOLVED|CLOSED status";
			}

			$request = "rest/dev-status/1.0/issue/detail?issueId=".$i->id."&applicationType=github&dataType=repository";
			$Json = Json_decode(CurlJira($request));

			if(isset($Json->detail[0]->repositories[0]->commits)) {
				//$msg.= "\n\t\t*Jira Commits:*\t";
				foreach ($Json->detail[0]->repositories[0]->commits as $c) {
					$JiraSmartCommits[] = $c->displayId; 
					//$msg.= " `".$c->displayId."`";
				}	
			}
/*
			$request = "rest/dev-status/1.0/issue/detail?issueId=".$i->id."&applicationType=github&dataType=pullrequest";
			$Json = Json_decode(CurlJira($request));

			$jprs = $Json->detail[0]->pullRequests;
			if(count($jprs) > 0) {
				foreach ($jprs as $jpr) {
					//$msg.= "\n\t\t".$jpr->url;
        	        		$url = str_ireplace("https://github.com/","https://api.github.com/repos/",str_ireplace("pull","pulls",$jpr->url))."/commits";
        	        		$Json = CurlGitHub($url);
        	        		foreach ($Json as $c) {
        	        		        $JiraSmartCommits[] = substr($c->sha,0,7);
						//$msg.= " `".substr($c->sha,0,7)."`";
        	        		}

				}	
			}	
*/		
		}
	}

	
	//$same = array_intersect($PRCommits,$JiraSmartCommits);
	//if (count($same)> 0) {
	//	$msg.= "\n\n\t*Commits Present in Jira and PR:*\t";
	//	foreach ($same as $c) {
	//		$msg.= "`".$c."` ";
	//	}
	//}

	$diff = array_diff($PRCommits,$JiraSmartCommits);
	if (count($diff)> 0) {
		$squeekyClean=false;
		$msg.= "\n\n\t\t*Commits existing on PR but not in Jira:*\n";
		foreach ($diff as $c) {
			$msg.= "\t\t`".$c."` ".$commitMsgs[$c]."\n";
		}
	}



	if($msg!="" && $squeekyClean) {
		$msg.="\t:white_check_mark:";
	}

	if ($msg!="") {
	
	        $url = "https://hooks.slack.com/services/".$conf['SLACK'];
	        $ch = curl_init();
	        $headers = array(
	            'Accept: application/json',
	            'Content-Type: application/json'
	        );
	
	        $dt = '{"text":'.json_encode($msg).'}';
	
	        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	        curl_setopt($ch, CURLOPT_VERBOSE, 0);
	        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	        curl_setopt($ch, CURLOPT_POSTFIELDS, $dt);
	        curl_setopt($ch, CURLOPT_URL, $url);
	        $result = curl_exec($ch);
	        $ch_error = curl_error($ch);
	
	        if ($ch_error) {
	            echo "cURL Error: $ch_error";
	        }
	        curl_close($ch);
	
	}


}

function CurlJira($request) {
             
	global $conf; 
	$url = "https://doorbot.atlassian.net/".$request;
	$ch = curl_init();
	$headers = array(
	    'Accept: application/json',
	    'Content-Type: application/json'
	);
	 
	 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_USERPWD, $conf['JIRA']);
	$result = curl_exec($ch);
	$ch_error = curl_error($ch);
	 
	if ($ch_error) {
	    echo "cURL Error: $ch_error";
	} else {
	    return $result;
	}
	curl_close($ch);

}
 
function CurlGitHub($pull_request) {
         
	global $conf;     
	$ch = curl_init();
	$headers = array(
	    'Accept: application/json',
	    'Content-Type: application/json',
	    'User-Agent: ReleaseBot',
	    'Authorization: token '.$conf['GITHUB']
	);
 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_VERBOSE, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_HEADER, true);
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");
	curl_setopt($ch, CURLOPT_URL, $pull_request);
	$response = curl_exec($ch);


	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	$header = substr($response, 0, $header_size);
	$body = json_decode(substr($response, $header_size));
	preg_match('/Link:.*/',$header, $matches);
	$inner = array();
	if(count($matches)>0){
		$pages = explode(",", $matches[0]);
		if (count($pages)>1){
			for($i=0;$i<count($pages);$i++){
				if(strstr($pages[$i], 'rel="next"')){
					$links = explode(">", $pages[$i]);
					$url = trim(str_replace("Link:","",str_replace("<","",$links[0])));
					$inner = CurlGitHub($url);
					foreach($inner as $o){
						$body[]=$o;
					}
				}
			}
		}
	}
	

	$ch_error = curl_error($ch);
	 
	if ($ch_error) {
	    echo "cURL Error: $ch_error";
	} else {
	    return $body;
	}
	curl_close($ch);

}
 
?>
