<?php
/*	
 ------------------------------------------------------------
 *															*
 *	PEWS (pew! pew!) - PHP Easy WebFinger Server 1.5.2  	*
 *															*
 *	This script enables webfinger support on a server that	*
 *	handles one or more domains. 							*
 *															*
 *	by Josh Panter <joshu at unfettered dot net>			*
 *															*
 ------------------------------------------------------------
*/
/*
	CONFIG
*/
// Set an alternate location for the data store. note: no trailing slash
define( 'PEWS_DATA_STORE', 'store' );
// force query and server hosts to match, maybe
define( 'PEWS_DOMAIN_STRICT', false );
// allow a user to edit their own data?
define( 'PEWS_USER_SELF_EDIT', true );
// Begin PEWS server //------------------ DO NOT EDIT ANYTHING BELOW THIS LINE (Unless you REALLY mean it!) ------------------//
$req = $_SERVER['REQUEST_METHOD'];
if ($req === 'GET') {
	// are we receiving a JSON object?
	function isValidJSON($str) {
	   json_decode($str);
	   return json_last_error() == JSON_ERROR_NONE;
	}
	$json_params = file_get_contents("php://input");
	if (strlen($json_params) > 0 && isValidJSON($json_params)) {
		$rels = false;
		$json_object = true;
		$json_params = str_replace("{", "", $json_params);
		$json_params = str_replace("}", "", $json_params);
		$json_params  = explode(',', $json_params);
		foreach($json_params as $jp) {
			$jp = str_replace('"', '', $jp);
			$jp  = explode(':', $jp, 2);
			if($jp[0]=='resource') $_GET['resource']=$jp[1];
			if($jp[0]=='rel') $rels[]=$jp[1];
		}
	} else { $json_object = false; }
	// JSON object or not, ready to process data
	if( isset($_GET['resource'])) {
		$subject  = explode(':', $_GET['resource']);
		if($subject[0] === 'acct') {
			if(strpos($subject[1], '@')) {
				$acct = explode('@', $subject[1]);
				$user = preg_replace('/^((\.*)(\/*))*/', '', $acct[0]);
				$host = preg_replace('/^((\.*)(\/*))*/', '', $acct[1]);
				if(PEWS_DOMAIN_STRICT && $host !== $_SERVER['HTTP_HOST']) {
					http_response_code(400);
					header("Content-Type: application/json");
					print json_encode(array(
						'statusCode' => 400,
						'message'    => "Query and server hosts do not match."
					), JSON_UNESCAPED_SLASHES);
					die();
				}
			} else {
				$user = preg_replace('/^((\.*)(\/*))*/', '', $subject[1]);
				$host = $_SERVER['HTTP_HOST']; 
			}
			$acct_file = PEWS_DATA_STORE."/".$host."/".$user.".json";
			// is there an account on file?
			if (file_exists($acct_file)) {
				// retrieve resource file and remove PEWS info
				$data = json_decode(file_get_contents($acct_file), true);
				if(isset($data['PEWS'])) unset($data['PEWS']);
				// check for rel request
				if($json_object == false) {
					if( isset($_GET['rel'])) {
						// disect string for multiple 'rel' values
						$query  = explode('&', $_SERVER['QUERY_STRING']);
						$array = array();
						foreach( $query as $param ) {
							list($key, $value) = explode('=', $param, 2);
							if($key == 'rel') {
								$array[urldecode($key)][] = urldecode($value);
								$rels = $array['rel'];
							}
						}
					}
				} 
				if(isset($rels) && $rels !== false) {
					// check resource data against rel request
					$links = $data['links'];
					$result = null;
					foreach($rels as $rel) {
						foreach($links as $link) if($link['rel'] == $rel) $result[] = $link;
					}
					$data['links'] = ($result == null) ? $data['links'] : $result;
					$return = $data;


				} else {
					$return = $data;
				}
				// set return headers, response code, and return data
	 			header('Access-Control-Allow-Origin: *');
				http_response_code(200);
		    } else {
				http_response_code(404);
				$return['statusCode'] = 404;
				$return['message']    = 'Account ['.$subject[1].'] not found.';
		    }
		} else {
			http_response_code(400);
			$return['statusCode'] = 400;
			$return['message']    = 'Malformed query: ['.$subject[0].'] not recognized.';
		}
	} else {
		http_response_code(400);
		$return['statusCode'] = 400;
		$return['message']    = "Missing 'resource' parameter, please check your query.";
	}
	header("Content-Type: application/json");
	print json_encode($return, JSON_UNESCAPED_SLASHES);
	die();
// Begin PEWS manager
} elseif ($req === 'POST') {
	// are we receiving a JSON object?
	function isValidJSON($str) {
	   json_decode($str);
	   return json_last_error() == JSON_ERROR_NONE;
	}
	$json_params = file_get_contents("php://input");
	if (strlen($json_params) > 0 && isValidJSON($json_params))
  		$_POST = json_decode($json_params, true);
	// JSON object or not, ready to process data
	if(isset($_POST['pass'])) {
		$pass = $_POST['pass'];
		if (isset($_POST['auth'])) {
			$user = $_POST['auth'];
			$auth = pews_auth($user, $pass, true);
			if(!$auth['is']) {
				http_response_code(401);
				$return['info'] = $auth['info'];
			} else {
				if($auth['class'] == 'admin') $return = pews_manager(true, null);
				else $return = pews_manager(false, $pass);
			}
		} else $return = pews_manager(false, $pass);
	} else {
		http_response_code(403);
		$return['info'] = 'forbidden';
	}
	header("Content-Type: application/json");
	print json_encode($return, JSON_UNESCAPED_SLASHES);
	die();
} else {
	header("Content-Type: application/json");
	http_response_code(405);
	print json_encode(array(
			'statusCode' => 405,
			'info' => 'method not allowed'
	), JSON_UNESCAPED_SLASHES);
	die();
}

function pews_auth( $resource, $key, $admin ) {
	$resource = pews_parse_account_string( $resource );
	$acct_file = PEWS_DATA_STORE ."/". $resource['host'] . "/" . $resource['user'].".json";
	// is there an account on file?
	if(file_exists($acct_file)) {
		$data = json_decode(file_get_contents($acct_file), true);
		$userData = $data['PEWS'];
		$class = $userData['class'];
		$lock = $userData['pass'];
		if(strpos($lock, 'pews-hashed') === false ) {
			$hashit = pews_hash_pass($acct_file);
			if(!$hashit['is'] ) die($hashit['info']);
			if($lock == $key ) {
				$return['is'] = true;
				$return['info'] = $hashit['info'];
				$return['class'] = $class;
			} else {
				$return['is'] = false;
				$return['info'] = 'bad password';
			}
		} else {
			$hashLock = explode(':', $lock);
			$hashLock = $hashLock[1];
			if(password_verify($key, $hashLock)) {
				$return['is'] = true;
				$return['class'] = $class;
			} else {
				$return['is'] = false;
				$return['info'] = 'bad password';
			}
		}
	} else {
		$return['is'] = false;
		$return['info'] = 'bad user name';
	}
	return $return;
}
function pews_hash_pass($acct_file) {
	$data = json_decode(file_get_contents($acct_file), true);
	if($data == false) {
		$return['is'] = false;
		$return['info'] = 'Could not read auth file';
	} else {
		$userData = $data['PEWS'];
		$class = $userData['class'];
		$lock = $userData['pass'];
		$to_hash = 0;

		if(strpos($lock, 'pews-hashed') === false) {
			$to_hash++;
			$hash = password_hash( $lock, PASSWORD_DEFAULT);
			$data['PEWS'] = array('class' => $class, 'pass' => 'pews-hashed:'.$hash);
		}

		if($to_hash == 0) {
			$return['is'] = true; 
			$return['info'] = 'Nothing to hash';
		} else {
			$data = json_encode($data, JSON_UNESCAPED_SLASHES);
			$success = file_put_contents( $acct_file, $data );
			if($success === false) {
				$return['is'] = false;
				$return['info'] = 'Could not write to auth file';
			} else {
				$return['is'] = true;
				$return['info'] = 'password hashed';
			}
		}
	}
	return $return;
}
function pews_manager( $auth, $password ) {
	// add a new host to the server TODO url validations, etc
	if(isset($_POST['addHost'])) {
		if($auth) {
			$resource = pews_parse_account_string( $_POST['addHost'] );
			$new = PEWS_DATA_STORE . '/' . $resource['host'];
			if (!file_exists($new)){
				$make = mkdir($new);
				if(!$make) {
					http_response_code(500);
					$return['statusCode'] = 500;
					$return['message'] = 'host not created';
				} else {
				chmod( $new, 0755 );
					http_response_code(201);
					$return['statusCode'] = 201;
					$return['message'] = 'host: '. $resource['host'] .' successfully added';
				}
			} else {
				http_response_code(200);
				$return['statusCode'] = 200;
				$return['message'] = 'host already present';
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
		return $return;
	// delete a host AND all resources
	} elseif(isset($_POST['delHost'])) {
		if($auth) {
			$resource = pews_parse_account_string( $_POST['delHost'] );
			$old = PEWS_DATA_STORE . '/' . $resource['host'];
			if (file_exists($old)) {
				$files = glob($old.'/*');
				foreach($files as $file) {
				  if(is_file($file))
					unlink($file);
				}
				$destroy = rmdir($old);
				if(!$destroy) {
					http_response_code(500);
					$return['statusCode'] = 500;
					$return['message'] = 'host not destroyed, but the accounts probably were.';
				} else {
					http_response_code(200);
					$return['statusCode'] = 200;
					$return['message'] = 'host: '.$resource['host'].' successfully removed';
				}
			} else {
				http_response_code(200);
				$return['statusCode'] = 200;
				$return['message'] = 'host already absent';
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
		return $return;
	// Add a new resource account!
	} elseif(isset($_POST['addResource'])) {
		if($auth) {
			$resource = pews_parse_account_string( $_POST['addResource'] );
			$newHost = PEWS_DATA_STORE . '/' . $resource['host'];
			if (!file_exists($newHost)){
				http_response_code(404);
				$response['statusCode'] = '404';
				$response['message'] = 'The host '. $resource['host'] .' is not present, and must be on this system before resource accounts are added to it.';
			} else {
				$newUser = $newHost .'/'. $resource['user'] .'.json';
				if (!file_exists($newUser)){
					$class 	= isset($_POST['setClass']) && ($_POST['setClass'] === 'admin' || $_POST['setClass'] === 'user') ? 
								$_POST['setClass'] : 
									'user';
					$pass= isset($_POST['setPass']) ? 'pews-hashed:'.password_hash($_POST['setPass'], PASSWORD_DEFAULT) : 'pewpewpassword';
					$data['PEWS'] = array( 'class' => $class, 'pass' => $pass );
					$data['subject'] = 'acct:'. $resource['acct'];
					if(isset($_POST['setAliases'])) {
						$aliases = $_POST['setAliases'];
						$data['aliases'] = is_array($aliases) ? $aliases : array($aliases);
					}
					if(isset($_POST['setProps'])) {
						if(is_array($_POST['setProps'])) {
							$data['properties'] = $_POST['setProps'];
						} elseif(isset($_POST['setPropKey']) && isset($_POST['setPropVal'])) {
							$data['properties'] = array($_POST['setPropKey'] => $_POST['setPropVal']);
						}
					}
					if(isset($_POST['setLinks'])) {
						if(is_array($_POST['setLinks'])) {
							$data['links'] = $_POST['setLinks'];
						} elseif(isset($_POST['setLinkRel']) && isset($_POST['setLinkHref'])) {
							$link['rel'] = $_POST['setLinkRel'];
							$link['href'] = $_POST['setLinkHref'];
							$link['type'] = isset($_POST['setLinkType']) ? $_POST['setLinkType'] : null;
							$link['titles'] = isset($_POST['setLinkTitles']) && is_array($_POST['setLinkTitles']) ? 
												$_POST['setLinkTitles'] : 
													isset($_POST['setLinkTitleLang']) && isset($_POST['setLinkTitle']) ? 
														array($_POST['setLinkTitleLang'] => $_POST['setLinkTitle']) :
															null;
							$link['properties'] = isset($_POST['setLinkProps']) && is_array($_POST['setLinkProps']) ? 
												$_POST['setLinkProps'] :
													isset($_POST['setLinkPropKey']) && isset($_POST['setLinkPropVal']) ? 
														array($_POST['setLinkPropKey'] => $_POST['setLinkPropVal']) :
															null;
							foreach($link as $k => $v) {
								if($v == null) unset($link[$k]);
							}

							$data['links'] = $link;							
						}
					}
					// Create the resource!!!
					$success = file_put_contents( $newUser, json_encode($data, JSON_UNESCAPED_SLASHES) );
					if(!$success) {
						http_response_code(500);
						$return['statusCode'] = 500;
						$return['message'] = 'Resource not created';
					} else {
					chmod( $newUser, 0755 );
						http_response_code(201);
						$return['statusCode'] = 201;
						$return['message'] = 'Resource: '.$resource['acct'].' successfully added';
					}
				} else {
					http_response_code(200);
					$return['statusCode'] = 200;
					$return['message'] = 'Resource already present';
				}
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
		return $return;
	// Remove a resource/account from the server
	} elseif(isset($_POST['delResource'])) {
		if($auth) {
			$resource = pews_parse_account_string( $_POST['delResource'] );
			$acct_file = PEWS_DATA_STORE ."/". $resource['host'] ."/". $resource['user'] .".json";
			if (file_exists($acct_file)) {
					$destroy = unlink($acct_file);
					if(!$destroy) {
						http_response_code(500);
						$return['statusCode'] = 500;
						$return['message'] = 'Server Error: resource not destroyed.';
					} else {
						http_response_code(200);
						$return['statusCode'] = 200;
						$return['message'] = 'Acct: '. $resource['acct'] .' successfully removed';
					}
			} else {
				http_response_code(200);
				$return['statusCode'] = 200;
				$return['message'] = 'Acct already absent';
			}
		} else {
			http_response_code(403);
			$return['info'] = 'forbidden';
		}
		return $return;
	// adding an alias to a resource
	} elseif(isset($_POST['addAlias'])) {
		$resource = pews_parse_account_string( $_POST['addAlias'] );
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource['acct'], $password, false );
				$auth = $reauth['is'];
			case true:
				if(isset($_POST['newAlias'])) {
					$newAlias = $_POST['newAlias'];
					$acct_file = PEWS_DATA_STORE . '/' . $resource['host'] .'/'. $resource['user'] . '.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$aliases = isset($data['aliases']) ? $data['aliases'] : array();
						$aliases[] = $newAlias;
						$data['aliases'] = $aliases;
						$data = json_encode($data, JSON_UNESCAPED_SLASHES);
						$success = file_put_contents( $acct_file, $data );
						if($success === false) {
							$return['is'] = false;
							$return['info'] = 'Could not write to resource file';
						} else {
							$return['is'] = true;
							$return['info'] = 'Alias: '.$newAlias.' added to '.$resource['acct'];
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account: '.$resource['acct'].' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing newAlias, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can add an alias if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	// remove an alias from a resource
	} elseif(isset($_POST['delAlias'])) {
		$resource = pews_parse_account_string( $_POST['delAlias'] );
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource['acct'], $password, false );
				$auth = $reauth['is'];
			case true:
				if(isset($_POST['oldAlias'])) {
					$oldAlias = $_POST['oldAlias'];
					$acct_file = PEWS_DATA_STORE . '/' . $resource['host'] .'/'. $resource['user'] . '.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$aliases = isset($data['aliases']) ? $data['aliases'] : null;
						if($aliases !== null && in_array( $oldAlias, $aliases ) ) { 
							$oldAliasArray[] = $oldAlias;
							$newAliasesArray = array_diff( $aliases , $oldAliasArray);
							if(empty($newAliasesArray)) { 
								unset ($data['aliases']);
							} else {
								$data['aliases'] = $newAliasesArray;
							}
							$data = json_encode($data, JSON_UNESCAPED_SLASHES);
							$success = file_put_contents( $acct_file, $data );
							if($success === false) {
								http_response_code(500);
								$return['is'] = false;
								$return['info'] = 'Could not write to resource file';
							} else {
								$return['is'] = true;
								$return['info'] = 'Alias: '.$oldAlias.' removed '.$resource['acct'];
							}
						} else {
							http_response_code(100);
							$return['is'] = false;
							$return['info'] = 'Nothing to do: Alias '.$oldAlias.' not found.';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account: '.$resource['acct'].' not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing oldAlias, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can remove an alias if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	} elseif(isset($_POST['addProp'])) {
	   // Do Something    
	} elseif(isset($_POST['editProp'])) {
	   // Do Something    
	} elseif(isset($_POST['delProp'])) {
	   // Do Something    
	} elseif(isset($_POST['addLink'])) {
	   // Do Something    
	} elseif(isset($_POST['editLink'])) {
	   // Do Something    
	} elseif(isset($_POST['delLink'])) {
	   // Update a Password   
	} elseif(isset($_POST['updatePass'])) {
		$resource = pews_parse_account_string( $_POST['updatePass'] );
		switch ($auth) {
			case false:
				$reauth = pews_auth( $resource['acct'], $password, false );
				$auth = $reauth['is'];
			case true:
				if(isset($_POST['newPass'])) {
					$newPass = $_POST['newPass'];
					$acct_file = PEWS_DATA_STORE .'/'. $resource['host'] .'/'. $resource['user'] .'.json';
					if (file_exists($acct_file)) {
						$data = json_decode(file_get_contents($acct_file), true);
						$userData = $data['PEWS'];
						$class = $userData['class'];
						$hash = password_hash( $newPass, PASSWORD_DEFAULT);
						$data['PEWS'] = array('class' => $class, 'pass' => 'pews-hashed:'.$hash);
						$data = json_encode($data, JSON_UNESCAPED_SLASHES);
						$success = file_put_contents( $acct_file, $data );
						if($success === false) {
							$return['is'] = false;
							$return['info'] = 'Could not write to auth file';
						} else {
							$return['is'] = true;
							$return['info'] = 'password updated';
						}
					} else {
						http_response_code(404);
						$return['statusCode'] = 404;
						$return['message']    = 'Account ['. $resource['acct'] .'] not found.';
					}
				} else {
					http_response_code(400);
					$return['statusCode'] = 400;
					$return['message']    = "Missing newPass, please check your query,";
				}
				break;
			default:
				http_response_code(401);
				$return['statusCode'] = 401;
				$return['message']    = "You can change your own password if you know your credentials";
				$return['info'] = $reauth['info'];
		}
	} else {
		http_response_code(400);
		$return['statusCode'] = 400;
		$return['message']    = "Missing parameter, please check your query,";
	}
	return $return;
}
function pews_parse_account_string ( $acct ) {
	if(strpos($acct, '@')) {
		$parts = explode('@', $acct[1]);
		$user = preg_replace('/^((\.*)(\/*))*/', '', $parts[0]);
		$host = preg_replace('/^((\.*)(\/*))*/', '', $parts[1]);
//		if(PEWS_DOMAIN_STRICT && $host !== $_SERVER['HTTP_HOST']) {
//			http_response_code(400);
//			header("Content-Type: application/json");
//			print json_encode(array(
//				'statusCode' => 400,
//				'message'    => "Query and server hosts do not match."
//			), JSON_UNESCAPED_SLASHES);
//			die();
//		}
	} else {
		$user = preg_replace('/^((\.*)(\/*))*/', '', $str);
		$host = $_SERVER['HTTP_HOST'];
		$acct = $user . '@' . $host;
	}
	$return['user'] = $user;
	$return['host'] = $host;
	$return['acct'] = $acct;
	return $return;
}
?>
