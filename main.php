<?php

	//$tlog = fopen("./uploads/speedLog", "w");
	date_default_timezone_set('UTC');
	$dat = new DateTime('now');
	$dfmat = "%s.u";
	//fwrite($tlog, "Load started at " . $dat->format("H:i:s") . "\n");
	
	$SITE_ROOT = "";
	$DBSERVER = "";
	
	
	if(preg_match("/main.php/", $_SERVER["SCRIPT_NAME"]))
	{
		//IIS
		$SITE_ROOT = str_replace("/main.php", "", $_SERVER["PHP_SELF"]);
	}
	else
	{
		//Apache
		$SITE_ROOT = str_replace($_SERVER["DOCUMENT_ROOT"], "", $_SERVER["SCRIPT_FILENAME"]);
		$SITE_ROOT = str_replace("/main.php", "", $SITE_ROOT);
	}


	include ("./utils/HttpUtils.php");
	include ("./Auth/AuthManager.php");
	include './db/dbConnection.php';
	
	$url = preg_replace("/\\$SITE_ROOT\/|\?.*$/i", "", (array_key_exists("REQUEST_URI", $_SERVER) ? $_SERVER["REQUEST_URI"] : $_SERVER["HTTP_X_ORIGINAL_URL"])); //strip off site root and GET query
	$url = rtrim($url, "/");
	$url = urldecode($url);
	
	if(file_exists("ec/logs/ec.txt") && file_exists("ec/settings.php"))
	{
		include "./ec/settings.php";
		$ik = explode(",", file_get_contents("ec/logs/ec.txt"));
		$str = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $ik[1], base64_decode($settings), MCRYPT_MODE_CBC, base64_decode($ik[0]));
		$arr = explode(",", $str);
		$DBSERVER = trim($arr[0]);
		$DBUSER = trim($arr[1]);
		$DBPASS = trim($arr[2]);
		$DBNAME = trim($arr[3]);
	}
	else if(!preg_match("/(test|.*\.css|.*\.js|.*\.png|.*\.jpg)$/", $url))
	{
		header("Location: http://{$_SERVER["HTTP_HOST"]}{$SITE_ROOT}/test");
	}
	
	
	include "./Classes/PageSettings.php";
	include ("./Classes/Logger.php");
	
	/*
	 * Ec Class declatratioions
	 */
	 
	include("./Classes/EcProject.php");
	include("./Classes/EcTable.php");
	include("./Classes/EcField.php");
	include ("./Classes/EcOption.php");
	include ("./Classes/EcEntry.php");
	/*
	 * End of Ec Class definitions
	 */ 

	function handleError($errno, $errstr, $errfile, $errline, array $errcontext)
	{
		// error was suppressed with the @-operator
		if (0 === error_reporting()) {
			return false;
		}
	
		throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
	}
	set_error_handler('handleError', E_ALL);
	

	$DEFAULT_OUT = "xml";
	$log = new Logger("Ec2");
	$db = false;

	
	
	/* class and function definitions */
	
	function escapeTSV($string)
	{
		$string = str_replace("\n", "\\n", $string);
		$string = str_replace("\r", "\\r", $string);
		$string = str_replace("\t", "\\t", $string);
		return $string;
	}

	
	function setupDb()
	{
		global $DBUSER, $DBNAME;
		try{
			$db = new dbConnection($_POST["un"], $_POST["pwd"]);
		}catch(Exception $e)
		{
			$_GET["redir"] = "pwd";
			siteTest();
			return ;
		}
		if(!$db)
		{
			echo "DB not connected";
			return;
		}
		
		/*if(array_key_exists("create", $_POST) && $_POST["create"] == "true")
		{
			$res = $db->do_query("CREATE DATABASE $DBNAME;");
			if($res !== true) echo $res;
			return;
			$res = $db->do_query("GRANT SELECT, INSERT, UPDATE, DELETE on $DBNAME to $DBUSER;");
			if($res !== true) echo $res;
			return;
			$res = $db->do_query("USE $DBNAME;");
			if($res !== true) echo $res;
			return;	
		}*/
		
		$sql = file_get_contents("./db/epicollect2.sql");
		
		$qrys = explode("~", $sql);
		
		for($i = 0 ; $i < count($qrys); $i++)
		{
			if($qrys[$i] != "")
			{
				
				$res = $db->do_multi_query($qrys[$i]);
				if($res !== true && !preg_match("/already exists|Duplicate entry .* for key/", $res))
				{
					siteHome();
					return;
				}
			}
		}
		siteHome();
	}
	
	function assocToDelimStr($arr, $delim)
	{
		$str = implode($delim, array_keys($arr[0])) . "\r\n";
		for($i = 0; $i < count($arr); $i++)
		{
			$str .= implode($delim, array_values($arr[$i])) . "\r\n";
		}
		return $str;
	}
	
	function getTimestamp()
	{
		$date = new DateTime("now", new DateTimeZone("UTC"));
		return $date->getTimestamp();
	}
	
	function regexEscape($s)
	{
		$s = str_replace("/" , "\/" , $s);
		return $s;
	}
	
	function applyTemplate($baseUri, $targetUri = false, $templateVars = array())
	{
		global $auth, $SITE_ROOT;
		
		$template = file_get_contents("./html/$baseUri");
		$templateVars["SITE_ROOT"] = ltrim($SITE_ROOT, "\\");
		$templateVars["uid"] = md5($_SERVER["HTTP_HOST"]);
		// Is there a user logged in?
		
		if($auth && $auth->isLoggedIn())
		{
			//if so put the user's name and a logout option in the login section
			$template = str_replace("{#loggedIn#}", 'Logged in as ' . $auth->getUserNickname() . ' (' . $auth->getUserEmail() .  ') <a href="javascript:logout();">Sign out</a> | <a href="updateUser.html">Update User</a>', $template);
		}
		// else show the login link
		else
		{
			$template = str_replace("{#loggedIn#}", '<a href="{#SITE_ROOT#}/login.php">Sign in</a>', $template);
		}
		// work out breadcrumbs
		//$template = str_replace("{#breadcrumbs#}", '', $template);
		
		$script = "";
		$sections = array();
		if($targetUri)
		{
			if(file_exists("./html/$targetUri"))
			{
				$data = file_get_contents("./html/$targetUri");
				
				$fPos = 0;
				$iStart = 0;
				$iEnd = 0;
				$sEnd = 0;
				$id = '';
				
				while($fPos <= strlen($data) && $fPos >= 0)
				{
					//echo "--";
					// find {{
					$iStart = strpos($data, "{{", $fPos);
					
					if($iStart===false || $iStart < $fPos) break;
					//echo $iStart;
					//get identifier (to }})
					$iEnd = strpos($data, '}}', $iStart);
					
					//echo $iEnd;
					$id = substr($data, $iStart + 2, ($iEnd-2) - ($iStart));
					//find matching end {{/}}
					$sEnd = strpos($data, "{{/$id}}", $iEnd);
					$sections[$id] = substr($data, $iEnd + 2, $sEnd - ($iEnd + 2));
					
					$fPos = $sEnd + strlen($id) + 3;
					//echo ("$fPos --- " . strlen($data) . " $id :: ");
				}
				
			}
			else
			{
				$sections["script"] = "";
				$sections["main"] = "<h1>404 - page not found</h1>
				<p>Sorry, the page you were looking for could not be found.</p>";
				header("HTTP/1.1 404 Page not found");
			}
			foreach(array_keys($sections) as $sec)
			{
				// do processing
				$template = str_replace("{#$sec#}", $sections[$sec], $template);
			}
		}
		if($templateVars)
		{
			foreach(array_keys($templateVars) as $sec)
			{
				// do processing
				$template = str_replace("{#$sec#}", $templateVars[$sec], $template);
			}
		}
		
		$template = preg_replace("/\{#[a-z0-9_]+#\}/i", "", $template);
		return $template;
	}
	
	function mimeType($f)
	{
		$mimeTypes = array(
			"ico" => "image/x-icon",
			"png" => "image/png",
			"gif" => "image/gif",
			"jpg" => "image/jpeg",
			"css" => "text/css",
			"html" => "text/html",
			"js" => "text/javascript",
			"json" => "text/javascript",
			"xml" => "text/xml",
			"php" => "text/html",
			"mp4" => "video/mp4"
		);
		
		$f = preg_replace("/\?.*$/", "", $f);
		$ext = substr($f, strrpos($f, ".") +1);
		if(array_key_exists($ext, $mimeTypes))
		{
			return $mimeTypes[$ext];
		}
		else
		{
			return "text/plain";
		}
	}
	
	/* end of class and function definitions */
	
	/* handlers	*/
	
	function defaultHandler()
	{
		global $url;
		header("Content-type: " . mimeType($url));
		echo applyTemplate('base.html', "./" . $url);
	}
		
	function uploadHandlerFromExt()
	{
		$flog = fopen('fileUploadLog.log', 'w');
		if($_SERVER["REQUEST_METHOD"] == "POST")
		{
			if(count($_FILES) > 0)
			{
				$keys = array_keys($_FILES);
				foreach($keys as $key)
				{
					if($_FILES[$key]['error'] > 0)
					{
						fwrite($flog, $key . " error : " .$_FILES[$key]['error']);
					}
					else
					{
						if(preg_match( "/.(png|gif|rtf|docx?|pdf|jpg|jpeg|txt|avi|mpeg|mpg|mov|mp3|wav)$/i", $_FILES[$key]['name']))
						{
							move_uploaded_file($_FILES[$key]['tmp_name'], "ec/uploads/{$_FILES[$key]['name']}");
							echo  "{\"success\" : true , \"msg\":\"ec/uploads/{$_FILES[$key]['name']}\"}";
						}
						else
						{
							echo  " error : file type not allowed";
							
						}
					}
				}
			}
			else
			{
				echo "No file submitted";
			}
		}
		else
		{
			echo "Incorrect method";
		}
		fclose($flog);
	}
	
	function projectHome()
	{
		global $auth, $url, $SITE_ROOT;
		
		
		$url = preg_replace("/\/$/", "", $url);
		
		$prj = new EcProject();
		if(array_key_exists('name', $_GET))
		{
			$prj->name = $_GET["name"];
		}
		else
		{
			$prj->name = preg_replace("/\.(xml|json)$/", "", $url);
		}
		$prj->fetch();
		
		if(!$prj->id)
		{
			$vals = array("error" => "Project could not be found");
			echo applyTemplate("base.html","./404.html",$vals);
			return;
		}
		
		//echo strtoupper($_SERVER["REQUEST_METHOD"]);
		
		if(strtoupper($_SERVER["REQUEST_METHOD"]) == "GET")
		{
			if(array_key_exists("HTTP_ACCEPT", $_SERVER)) $format = substr($_SERVER["HTTP_ACCEPT"], strpos($_SERVER["HTTP_ACCEPT"], "/") + 1);
			$ext = substr($url, strrpos($url, ".") + 1);
			$format = $ext != "" ? $ext : $format;
			switch($format){
				case "xml":
					header("Cache-Control: no-cache, must-revalidate");
					header ("Content-type: text/xml; charset=utf-8;");
					echo $prj->toXML();
					break;
				default:
					header ("Content-type: text/html;");
				
					try{
						//$userMenu = '<h2>View Data</h2><span class="menuItem"><img src="images/map.png" alt="Map" /><br />View Map</span><span class="menuItem"><img src="images/form_view.png" alt="List" /><br />List Data</span>';
						//$adminMenu = '<h2>Project Administration</h2><span class="menuItem"><a href="./' . $prj->name . '/formBuilder.html"><img src="'.$SITE_ROOT.'/images/form_small.png" alt="Form" /><br />Create or Edit Form(s)</a></span><span class="menuItem"><a href="editProject.html?name='.$prj->name.'"><img src="'.$SITE_ROOT.'/images/homepage_update.png" alt="Home" /><br />Update Project</a></span>';
						$tblList = "";
						foreach($prj->tables as $tbl)
						{
							$tblList .= "<div class=\"tblDiv\"><a class=\"tblName\" href=\"{$prj->name}/{$tbl->name}\">{$tbl->name}</a><a href=\"{$prj->name}/{$tbl->name}\">View All Data</a> | <form name=\"{$tbl->name}SearchForm\" action=\"./{$prj->name}/{$tbl->name}\" method=\"GET\"> Search for {$tbl->key} <input type=\"text\" name=\"{$tbl->key}\" /> <a href=\"javascript:document.{$tbl->name}SearchForm.submit();\">Search</a></form></div>";
						}
						
						$imgName = $prj->image ? $prj->image : "images/projectPlaceholder.png";
						
						$imgSize = getimagesize($imgName);
						
						$vals =  array(
							"projectName" => $prj->name,
							"projectDescription" => $prj->description && $prj->description != "" ? $prj->description : "Project homepage for {$prj->name}",
							"projectImage" => $imgName,
							"imageWidth" => $imgSize[0],
							"imageHeight" =>$imgSize[1],
							"tables" => $tblList,
							"adminMenu" => "",
							"userMenu" => ""
						);
						
						echo applyTemplate("base.html","./projectHomeUser.html",$vals);
						break;
				}
				catch(Exception $e)
				{
					$vals = array("error" => $e);
					echo applyTemplate("base.html","./error.html",$vals);
				}
			}
		}
		elseif(strtoupper($_SERVER["REQUEST_METHOD"]) == "POST") //
		{
			//echo "POST";
			// update project
			$prj->description = $_POST["description"];
			$prj->image = $_POST["image"];
			$prj->isPublic = array_key_exists("isPublic", $_POST) && $_POST["isPublic"] == "on" ?  1 : 0;
			$prj->isListed =  array_key_exists("isListed", $_POST) && $_POST["isListed"] == "on" ?  1 : 0;
			$prj->publicSubmission =  array_key_exists("publicSubmission", $_POST) && $_POST["publicSubmission"] == "on" ?  1 : 0;
			
			$res = $prj->id ? $prj->push() : $prj->post();
			if($res !== true)
			{
				echo $res;
			}
			
			if($_POST["admins"] && $res === true)
			{
				$res = $prj->setAdmins($_POST["admins"]);
			}
			
			if($_POST["users"] && $res === true)
			{
				$res = $prj->setUsers($_POST["users"]);
			}
			
			if($_POST["submitters"] && $res === true)
			{
				$res = $prj->setSubmitters($_POST["submitters"]);
			}
			echo $res;
		}		
	}
	
	function siteTest()
	{
		$res = array();
		global $DBSERVER, $DBUSER, $DBNAME;
		
		$doit = true;
		if($DBSERVER == "")
		{
			$res["dbStatus"] = "fail";
			$res["dbResult"] = "No database server specified, please amend the file ec/settings.php and so that \$DBSERVER equals the name of the MySQL server";
			$doit = false;
		}
		else if($DBUSER == "")
		{
			$res["dbStatus"] = "fail";
			$res["dbResult"] = "No database user specified, please amend the file ec/settings.php so that \$DBUSER and \$DBPASS equal the credentials for MySQL server";
			$doit = false;
		}
		else if($DBNAME == "")
		{
			$res["dbStatus"] = "fail";
			$res["dbResult"] = "No database name specified, please amend the file ec/settings.php so that \$DBNAME equals the name of the MySQL database";
			$doit = false;
		}
		
		if($doit && !(array_key_exists("edit", $_GET) && $_GET["edit"] === "true"))
		{
			if(array_key_exists("redir", $_GET) && $_GET["redir"] === "true") $res["redirMsg"] = "	<p class=\"message\">You have been brought to this page because of a fatal error opening the home page</p>";
			if(array_key_exists("redir", $_GET) && $_GET["redir"] === "pwd") $res["redirMsg"] = "	<p class=\"message\">The username and password you entered were incorrect, please try again.</p>";
			
			$db = false;
			try{
				$db = new dbConnection();
				$res["dbStatus"] = "succeed";
				$res["dbResult"] = "Connected";
			}
			catch(Exception $ex)
			{
				if(preg_match('/28000\/1045/', $ex))
				{
					$res["dbStatus"] = "fail";
					$res["dbResult"] = "DB Server found, but the combination of the username and password invalid. <a href=\"./test?edit=true\">Edit Settings</a>";
				}
				elseif(preg_match('/42000\/1044/', $ex))
				{
					$res["dbStatus"] = "fail";
					$res["dbResult"] = "DB Server found, but the database specified does not exist or the user specified does not have access to the database. <a href=\"./test?edit=true\">Edit Settings</a>";
				}
				else
				{
					$res["dbStatus"] = "fail";
					$res["dbResult"] =  "Could not find the DB Server ";
				}
			}
			
			if($db)
			{
				$dbNameRes = $db->do_query("SHOW DATABASES");
				if($dbNameRes !== true)
				{
					echo $dbNameRes;
					return;
				}
				while($arr = $db->get_row_array())
				{
					
					if( $arr['Database'] == $DBNAME)
					{
						$res["dbStatus"] = "succeed";
						$res["dbResult"] = "";
						break;
					}
					else
					{
						$res["dbStatus"] = "fail";
						$res["dbResult"] = "DB Server found, but the database '$DBNAME' does not exist.<br />";
					}
				}
				
				$res["dbPermStatus"] = "fail";
				$res["dbPermResults"] = "";
				$res["dbTableStatus"] = "fail";
				
				if($res["dbStatus"] == "succeed")
				{
					$dbres = $db->do_query("SHOW GRANTS FOR $DBUSER;");
					if($dbres !== true)
					{
						$res["dbPermResults"] = $res;
					}
					else
					{
						$perms = array("SELECT", "INSERT", "UPDATE", "DELETE", "EXECUTE");
						$res ["dbPermResults"] = "Permssions not set, the user $DBUSER requires SELECT, UPDATE, INSERT, DELETE and EXECUTE permissions on the database $DBNAME";
						while($arr = $db->get_row_array())
						{
							$_g = implode(" -- ", $arr) . "<br />";
							if(preg_match("/ON (`?$DBNAME`?|\*\.\*)/", $_g))
							{
								if(preg_match("/ALL PERMISSIONS/i", $_g))
								{
									$res["dbPermStatus"] = "fail";
									$res["dbPermResults"] = "The user account $DBUSER by the website should only have SELECT, INSERT, UPDATE, DELETE and EXECUTE priviliges on $DBNAME";
									break;
								}
								for($_p = 0; $_p < count($perms); $_p++)
								{
									if(preg_match("/{$perms[$_p]}/i", $_g)) // &&  preg_match("/INSERT/", $_g) &&  preg_match("/UPDATE/", $_g) &&  preg_match("/DELETE/", $_g) &&  preg_match("/EXECUTE/", $_g))
									{
										unset($perms[$_p]);
										$perms = array_values($perms);
										$_p--;
									}
								}
							}
						}
						if(count($perms) == 0)
						{
							$res["dbPermStatus"] = "succeed";
							$res["dbPermResults"] = "Permssions Correct";
						}
						else 
						{
							$res ["dbPermResults"] = "Permssions not set, the user $DBUSER is missing " . implode(", ", $perms) .  " permissions on the database $DBNAME";
						}
					}
				}
			}
			
			if($db && $res["dbPermStatus"] == "succeed")
			{
				
				$tblTemplate = array(
					"device" => false,
					"deviceuser" => false,
					"enterprise" => false,
					"entry" => false,
					"entryvalue" => false,
					"entryvaluehistory" => false,
					"field" => false,
					"fieldtype" => false,
					"form" => false,
					"option" => false,
					"project" => false,
					"role" => false,
					"user" => false,
					"userprojectpermission" => false	
				);
				
				$dres = $db->do_query("SHOW TABLES");
				if($dres !== true)
				{
					$res["dbTableStatus"] = "fail";
					$res["dbTableResult"] = "EpiCollect Database is not set up correctly";
				}
				else
				{
					$i = 0;
					while($arr = $db->get_row_array())
					{
						$tblTemplate[$arr["Tables_in_{$DBNAME}"]] = true;
						$i++;
					}
					if($i == 0)
					{
						$res["dbTableStatus"] = "fail";
						$res["dbTableResult"] = "Database is blank,  enter an administrator username and password for the database to create the database tables<br />
				<form method=\"post\" action=\"createDB\">
					<b>Username : </b><input name=\"un\" type=\"text\" /> <b>Password : </b><input name=\"pwd\" type=\"password\" /> <input type=\"hidden\" name=\"create\" value=\"true\" /><input type=\"submit\" value=\"Create Database\" name=\"Submit\" />
				</form>";
					}
					else
					{
						$done = true;
						foreach($tblTemplate as $key => $val)
						{
							$done &= $val;
						}
						
						if($done)
						{
							$res["dbTableStatus"] = "succeed";
							$res["dbTableResult"] = "EpiCollect Database ready";
						}
						else
						{
							$res["dbTableStatus"] = "fail";
							$res["dbTableResult"] = "EpiCollect Database is not set up correctly";
						}
					}
				}
				
			}
			
			$res["endStatus"] = array_key_exists("dbTableStatus", $res) ? ($res["dbTableStatus"] == "fail" ? "fail" : "") : "fail";
			$res["endMsg"] = ($res["endStatus"] == "fail" ? "The MySQL database is not ready, please correct the errors in red above and refresh this page. <a href = \"/test?edit=true\">Configuration tool</a>" : "You are now ready to create EpiCollect projects, place xml project definitions in {$_SERVER["PHP_SELF"]}/xml and visit the <a href=\"createProject.html\">create project</a> page");
			echo applyTemplate("base.html", "testResults.html", $res);
		}
		else
		{
			echo applyTemplate("base.html", "setup.html", $res);
		}
	}
	
	function siteHome()
	{
		$vals = array();
		
		try{
			$db = new dbConnection;
		}
		catch(Exception $e)
		{
			global $SITE_ROOT;
			$server = trim($_SERVER["HTTP_HOST"], "/");
			$root = trim($SITE_ROOT, "/");
			header("location: http://$server/$root/test?redir=true");
		}
		
		$res = $db->do_query("SELECT name, count(entry.idEntry) as ttl, x.ttl as ttl24 FROM project left join entry on project.name = entry.projectName left join (select count(idEntry) as ttl, projectName from entry where created > ((UNIX_TIMESTAMP() - 86400)*1000) group by projectName) x on project.name = x.projectName group by project.name");
		if($res !== true)
		{
			$vals["projects"] = "<p class=\"error\">Database is not set up correctly, go to the <a href=\"test\">test page</a> to establish the problem.</p>";
			echo applyTemplate("base.html","./index.html",$vals);
			return;
		}
		$vals["projects"] = "<h1>Projects on this server</h1>" ;
		
		$i = 0;
		
		while($row = $db->get_row_array())
		{
			$vals["projects"]  .= "<div class=\"project\"><a href=\"{#SITE_ROOT#}/{$row["name"]}\">{$row["name"]}</a></div><div class=\"total\">{$row["ttl"]} entries with <b>" . ($row["ttl24"] ? $row["ttl24"] : "0") ."</b> in the last 24 hours </div>";
			$i++;
		}
		
		if($i == 0)
		{
			$vals["projects"] = "<p>No projects exist on this server put the project definition in the <b>xml</b> folder and then <a href=\"createProject.html	\">create a project</a></p>";
		}
		else
		{
			
		}
		
		echo applyTemplate("base.html","./index.html",$vals);
	}
	
	
	
	function uploadData()
	{
		global $auth, $url;
		$flog = fopen('ec/uploads/fileUploadLog.log', 'a');
		$prj = new EcProject();
		$prj->name = preg_replace("/\/upload\.?(xml|json)?$/", "", $url);
		
		$prj->fetch();
		
		if($_SERVER["REQUEST_METHOD"] == "POST"){
			if(count($_FILES) > 0)
			{
				foreach($_FILES as $file){
					
					if(preg_match("/.+\.xml$/", $file["name"])){
						$ts = new DateTime("now", new DateTimeZone("UTC"));
						$ts = $ts->getTimestamp();
					
						
						$fn = "$ts-{$file["name"]}";
						
						for($i = 1; file_exists("../ec/rescue/{$fn}"); $i++)
						{
							$fn = "$ts-$i-{$file['name']}";
						}
						move_uploaded_file($file['tmp_name'], "./ec/rescue/{$fn}");
						
						$res = $prj->parseEntries(file_get_contents("./ec/rescue/{$fn}"));
						
						if(preg_match("/(CHROME|FIREFOX)/i", $_SERVER["HTTP_USER_AGENT"]))
						{
							echo $res;
						}
						else
						{
							fwrite($flog, "$res\r\m");
							echo ($res === true ? "1" : "0");
						}
					}
					else if(preg_match("/\.(png|gif|rtf|docx?|pdf|jpg|jpeg|txt|avi|mpe?g|mov|mpe?g?3|wav|mpe?g?4)$/", $file['name']))
					{
						
						try{
							//if(!fileExists("./uploads/{$prj->name}")) mkdir("./uploads/{$prj->name}");
							
							move_uploaded_file($file['tmp_name'], "./ec/uploads/{$prj->name}~" . ($_REQUEST["type"] == "thumbnail" ? "tn~" : "" ) ."{$file['name']}");
							fwrite($flog, $file['name'] . " copied to uploads directory\n");
							echo 1;
						}
						catch(Exception $e)
						{
							fwrite($flog, $e . "\r\n");
							echo "0";
						}
					}
					else
					{
						fwrite($flog, $file['name'] . " error : file type not allowed\r\n");
						echo "0";
					}
				}
				
			}
			else
			{
				fwrite($flog, "data : " . serialize($_POST) . "\r\n");
				$tn = $_POST["table"];
				unset($_POST["table"]);
				
				try
				{
					$ent = new EcEntry($prj->tables[$tn]);
					if(array_key_exists("ecPhoneID", $_POST))
					{
						$ent->deviceId = $_POST["ecPhoneID"];
					}
					else
					{
						$ent->deviceId = "web";
					}
					if(array_key_exists("ecTimeCreated", $_POST))
					{
						$ent->created = $_POST["ecTimeCreated"];
					}
					else
					{
						$d = new DateTime('now', new DateTimeZone('UTC'));
						$ent->created = $d->getTimestamp();
					}
					$ent->project = $prj;
					
					foreach($prj->tables[$tn]->fields as $key => $fld){
						if($fld->type == 'gps' || $fld->type == 'location')
						{
							$lat = "{$key}_lat";
							$lon = "{$key}_lon";
							$alt = "{$key}_alt";
							$acc = "{$key}_acc";
							$src = "{$key}_provider";
							
							$ent->values[$key] = array(
								'latitude' => (string)$_POST[$lat],
								'longitude' => (string)$_POST[$lon],
								'altitude' => (string)$_POST[$alt],
								'accuracy' => (string) $_POST[$acc], 
								'provider' => (string)$_POST[$src]
							);
						}
						else if(!array_key_exists($key, $_POST))
						{
							$ent->values[$key] = "";
							continue;
						}
						else if($fld->type != "branch")
						{
							$ent->values[$key] = (string)$_POST[$key];
						}
					}
					fwrite($flog, "posting ... \r\n");
					$res = $ent->post();
					fwrite($flog, "response : $res \r\n");
					
					if($res === true)
					{
						header("HTTP/1.1 200 OK");
						echo 1;
					}
					else
					{
						header("HTTP/1.1 405 Bad Request");
						fwrite($flog, "error : $res\r\n");
						echo $res;
					}
				}
				catch(Exception $e)
				{
					fwrite($flog, "error : " . $e->getMessage() . "\r\n");
					$msg = $e->getMessage();
					if(preg_match("/^Message/", $msg))
					{
						header("HTTP/1.1 405 $msg");
					}
					else
					{
						header("HTTP/1.1 405 Bad Request");
					}
					echo $msg;
				}
			}
		}
		fclose($flog);
	}
	
	function getChildEntries($survey, $tbl, $entry, &$res, $stopTbl = false)
	{
	//	global $survey;
		
		foreach($survey->tables as $subTbl)
		{
			
			if(($subTbl->number <= $tbl->number && $subTbl->branchOf != $tbl->name)||($stopTbl !== false && $subTbl->number > $stopTbl && $subTbl->branchOf != $tbl->name)){continue;}
			
			foreach($subTbl->fields as $fld)
			{
				if($fld->name == $tbl->key && !array_key_exists($subTbl->name, $res))
				{
					
					$res[$subTbl->name] = $subTbl->get(Array($tbl->key => $entry));
					//print_r($res[$subTbl->name]);
					foreach($res[$subTbl->name][$subTbl->name] as $sEntry)
					{
						
						getChildEntries($survey, $subTbl, $sEntry[$subTbl->key][$subTbl->key], $res, $stopTbl);
						
					}
					
				}
				
			}
		}
		
	}
	
	function downloadData()
	{
		//TODO: Dowload data from server, including gzipped media files
		global $auth, $url, $SITE_ROOT;
		header("Cache-Control: none,  must-revalidate");
		
		$flog = fopen('ec/uploads/fileUploadLog.log', 'a');
		$survey = new EcProject();
		$survey->name = preg_replace("/\/download\.?(xml|json)?$/", "", $url);
		
		$survey->fetch();
		
		$lastUpdated = $survey->getLastUpdated();
		$qString = $_SERVER["QUERY_STRING"];
		
		$baseFn = md5($qString);
		//the root of the working directory is the Script filename minus everthing after the last \
		//NOTE: This will be the same for EC+ as the upload directory is project-independant
		$pos = max(strrpos($_SERVER["SCRIPT_FILENAME"], "\\") ,strrpos($_SERVER["SCRIPT_FILENAME"], "/"));
		$root =substr($_SERVER["SCRIPT_FILENAME"], 0, $pos);
		$wwwroot = "http://{$_SERVER["HTTP_HOST"]}$SITE_ROOT";

		$startTbl = (array_key_exists('select_table', $_GET) ? $_GET["table"] : false);
		$endTbl = (array_key_exists('select_table', $_GET) ? $_GET["select_table"] :  $_GET["table"]);
		$entry = (array_key_exists('entry', $_GET) ? $_GET["entry"] : false);
		$dataType = (array_key_exists('type', $_GET) ? $_GET["type"] : "data");
		$xml = !(array_key_exists('xml', $_GET) && $_GET['xml'] === "false"); 
		
		$files_added = 0;
		
		$delim = "\t";
		$rowDelim = "\n";
		
		$tbls = array();
		$branches = array();
		
		function addBranch($branch, $branchOf)
		{
			global $branches;
			if(!array_key_exists($branchOf, $branches))
			{
				$branches[$branchOf] = array();
			}
			array_push($branches[$branchOf], $branch);
		}
		
		$n = $startTbl ? $survey->tables[$startTbl]->number : 1;
		$end = $endTbl ? $survey->tables[$endTbl]->number : count($survey->tables);
		
		// if we're doing a select_table query we don't want the data from the first table, as we already have that entry.
		if(array_key_exists('select_table', $_GET)) $n++;
		
		//for each table between startTbl and end Tbl (or that is a branch of a table we want)
		//we'll loop through the table array to establish which tables we need
		foreach($survey->tables as $name => $tbl)
		{
			//first off is $tbl is already in $tbls we can skip it
			if(array_key_exists($name, $tbls))
			{
				continue;
			}
			// first check if the table has a number between $n and $end
			else if(($tbl->number >= $n && $tbl->number <= $end))
			{
				$tbls[$tbl->number] = $name;
			}
			//else if it is a branch form
			//TODO : Not needed for EC2, but must work for EC+
			/*else if($tbl->branchOf)
			{
				//if the parent table is in the list add it.
				if(array_key_exists($tbl->branchOf, $tbls))
				{
					addBranch($name, $tbls->branchOf);
				}
				//if not see if the parent table should be in the list, if it is add it and if not skip and move on
				else if ($survey->tables[$tbl->branchOf]->number >= $n || $survey->tables[$tbl->branchOf]->number <= $n)
				{
					$tbls[$tables[$tbl->branchOf]->number] =  $tbl->branchOf;
					//addBranch($tbl->name, $tbls->branchOf);
				}
				//else continue;
			} */
		}
		
		//criteria
		$cField = false;
		$cVals = array();
		if($entry)
		{
			$cField = $survey->tables[$startTbl]->key;
			$cVals[0] = $entry;
		}
		
		$nxtCVals = array();
			
		//for each main table we're intersted in (i.e. main tables between stat and end table)
		//$ts = new DateTime("now", new DateTimeZone("UTC"));
		//$ts = $ts->getTimestamp();
		if($dataType == "data" && $xml)
		{
			header("Content-type: text/xml");
			$fxn = "$root\\ec\\uploads\\{$baseFn}.xml";
			$fx_url = "$wwwroot/ec/uploads/{$baseFn}.xml";
			if(file_exists($fxn))
			{
				header("Location: $fx_url");
				return;
			}
			$fxml = fopen("$fxn", "w+");
			fwrite($fxml,"<?xml version=\"1.0\"?><entries>");
			
		}
		else if($dataType == "data")
		{
			header("Content-type: text/plain");
			$txn = "$root\\ec\\uploads\\{$baseFn}.tsv";
			$ts_url = "$wwwroot/ec/uploads/{$baseFn}.tsv";
			if(file_exists($txn))
			{
				header("Location: $ts_url");
				return;
			}
			
			$tsv = fopen($txn, "w+");	
		}
		else
		{
		
			$zfn = "$root\\ec\\uploads\\arc{$baseFn}.zip";
			$zrl = "$wwwroot/ec/uploads/arc{$baseFn}.zip";
			
			if(file_exists($zfn))
			{
				header("Location: $zrl");
				return;
			}
			
			$arc = new ZipArchive;
			$x = $arc->open($zfn, ZipArchive::CREATE);
			if(!$x) die("Could not create the zip file.");
		}
		
		
		for($t = $n; $t <= $end && $t < count($survey->tables); $t++)
		{
			if($dataType == "data" && $xml)
			{
				fwrite($fxml, "<table><table_name>{$tbls[$t]}</table_name>");
			}
	
			for($c = 0; $c < count($cVals) || $c < 1; $c++)
			{
				
				$res = false;
				
				if($entry && count($cVals) == 0) break;
				$args = array();
					
				if($entry) $args[$cField] = $cVals[$c];
					
				$res = $survey->tables[$tbls[$t]]->get($args);
				
				if(count($res) == 0) continue;
				
				foreach(array_keys($res[$tbls[$t]])as $ent)
				{
					if($dataType == "data")
					{ 
						if($xml)
						{
							fwrite($fxml,"\t\t<entry>\n");
							foreach(array_keys($res[$tbls[$t]][$ent]) as $fld)
							{
								if($fld == "childEntries") continue;
								if(array_key_exists($fld, $survey->tables[$tbls[$t]]->fields) && preg_match("/^(gps|location)$/i", $survey->tables[$tbls[$t]]->fields[$fld]->type))
								{
									$gpsObj = json_decode($res[$tbls[$t]][$ent][$fld]);
									fwrite($fxml,"\t\t\t<{$fld}_lat>{$gpsObj->latitude}</{$fld}_lat>\n");
									fwrite($fxml,"\t\t\t<{$fld}_lon>{$gpsObj->longitude}</{$fld}_lon>\n");
									fwrite($fxml,"\t\t\t<{$fld}_acc>{$gpsObj->accuracy}</{$fld}_acc>\n");
									fwrite($fxml,"\t\t\t<{$fld}_provider>{$gpsObj->provider}</{$fld}_provider>\n");
									fwrite($fxml,"\t\t\t<{$fld}_alt>{$gpsObj->altitude}</{$fld}_alt>\n");
								}
								else
								{
									fwrite($fxml,"\t\t\t<$fld>" . str_replace("&", "&amp;", $res[$tbls[$t]][$ent][$fld]) . "</$fld>\n");
								}
							}
							fwrite($fxml, "\t\t</entry>\n");
						}
						else
						{
							fwrite($tsv, "{$tbls[$t]}$delim");
							foreach(array_keys($res[$tbls[$t]][$ent]) as $fld)
							{
								if($fld == "childEntries") continue;
								if(array_key_exists($fld, $survey->tables[$tbls[$t]]->fields) && preg_match("/^(gps|location)$/i", $survey->tables[$tbls[$t]]->fields[$fld]->type))
								{
									$gpsObj = json_decode($res[$tbls[$t]][$ent][$fld]);
									fwrite($tsv,"{$fld}_lat{$delim}{$gpsObj->latitude}{$delim}");
									fwrite($tsv,"{$fld}_lon{$delim}{$gpsObj->longitude}{$delim}");
									fwrite($tsv,"{$fld}_acc{$delim}{$gpsObj->accuracy}{$delim}");
									fwrite($tsv,"{$fld}_provider{$delim}{$gpsObj->provider}{$delim}");
									fwrite($tsv,"{$fld}_alt{$delim}{$gpsObj->altitude}{$delim}");
								}
								else
								{
									fwrite($tsv,  "$fld$delim" . escapeTSV($res[$tbls[$t]][$ent][$fld]). $delim);
								}
							}
							fwrite($tsv,  $rowDelim);
						}
						
					}
					elseif(strtolower($_GET["type"]) == "thumbnail")
					{
						foreach(array_keys($res[$tbls[$t]][$ent]) as $fld)
						{
							if($fld == "childEntries" || !array_key_exists($fld, $survey->tables[$tbls[$t]]->fields)) continue;
							if($survey->tables[$tbls[$t]]->fields[$fld]->type == "photo" && $res[$tbls[$t]][$ent][$fld] != "" && file_exists("$root\\ec\\uploads\\tn_".$res[$tbls[$t]][$ent][$fld]))
							{
								if(!$arc->addFile( "$root\\ec\\uploads\\tn_" . $res[$tbls[$t]][$ent][$fld], $res[$tbls[$t]][$ent][$fld])) die("fail -- \\ec\\uploads\\tn_" . $res[$tbls[$t]][$ent][$fld]);
								$files_added++;
							}
						}
					}
					elseif(strtolower($_GET["type"]) == "full_image")
					{
						foreach(array_keys($res[$tbls[$t]][$ent]) as $fld)
						{
							if($fld == "childEntries" || !array_key_exists($fld, $survey->tables[$tbls[$t]]->fields)) continue;
							if($survey->tables[$tbls[$t]]->fields[$fld]->type == "photo" && $res[$tbls[$t]][$ent][$fld] != "" && file_exists("$root\\ec\\uploads\\".$res[$tbls[$t]][$ent][$fld]))
							{
								if(!$arc->addFile( "$root\\ec\\uploads\\" . $res[$tbls[$t]][$ent][$fld], $res[$tbls[$t]][$ent][$fld])) die("fail -- \\ec\\uploads\\" . $res[$tbls[$t]][$ent][$fld]);
								$files_added++;
							}
						}
					}
					else
					{
						foreach(array_keys($res[$tbls[$t]][$ent]) as $fld)
						{
							if($fld == "childEntries" || !array_key_exists($fld, $survey->tables[$tbls[$t]]->fields)) continue;
							if($survey->tables[$tbls[$t]]->fields[$fld]->type == $_GET["type"] && $res[$tbls[$t]][$ent][$fld] != "" && file_exists("$root\\ec\\uploads\\".$res[$tbls[$t]][$ent][$fld]))
							{
								if(!$arc->addFile( "$root\\ec\\uploads\\" . $res[$tbls[$t]][$ent][$fld], $res[$tbls[$t]][$ent][$fld])) die("fail -- \\ec\\uploads\\" . $res[$tbls[$t]][$ent][$fld]);
								$files_added++;
							}
						}
					}
					
				}
				 
				if($ent && !array_key_exists($res[$tbls[$t]][$ent][$survey->tables[$tbls[$t]]->key], $nxtCVals))
				{
					$nxtCVals[$res[$tbls[$t]][$ent][$survey->tables[$tbls[$t]]->key]] = true;
				}
				
				
			}
			if($dataType == "data" && $xml)
			{
				fwrite($fxml,  "</table>");
			}
		}
		
		
		if($entry)
		{
			$cField = $survey->tables[$tbls[$t]]->key;
			$cVals = array_keys($nxtCVals);			
			$nxtCVals = array();
		}
	
	
		if($dataType == "data" && $xml)
		{
				fwrite($fxml,  "</entries>");
				fclose($fxml);
				header("Location: $fx_url");
				return;
		}
		elseif ($dataType == "data")
		{
			fclose($tsv);
			header("Location: $ts_url");
			return;
		}
		else
		{
			//close zip files
			$err = $arc->close();
			if($files_added === 0)
			{
				echo "no files";
				return;
			}
			
			if(!$err==true) {
				echo "fail expecting $files_added files"; 
				return;
			} 
			//echo $zfn;
			//echo $zrl;
			header("Location: $zrl");
			return;
		}
	}
	
	
	function formHandler()
	{
		
		global $url, $auth;		
		
		$format = substr($_SERVER["HTTP_ACCEPT"], strpos($_SERVER["HTTP_ACCEPT"], "/") + 1);
		$ext = substr($url, strrpos($url, ".") + 1);
		$format = $ext != "" ? $ext : $format;
		
		$prj = new EcProject();
		$pNameEnd = strpos($url, "/");
		$prj->name = substr($url, 0, $pNameEnd);
		$prj->fetch();
		$extStart = strpos($url, ".");
		$frmName = rtrim(substr($url, $pNameEnd + 1, ($extStart > 0 ?  $extStart : strlen($url)) - $pNameEnd - 1), "/");
		
		global $url, $auth;
		if($_SERVER["REQUEST_METHOD"] == "POST")
		{
			header("Cache-Control: no-cache, must-revalidate");
			$ent = $prj->tables[$frmName]->createEntry();
			
			$ent->created = $_POST["created"];
			$ent->deviceId = $_POST["DeviceID"];
			$ent->uploaded = getTimestamp();
			$ent->user = 0;
			foreach(array_keys($ent->values) as $key)
			{
				if(array_key_exists($key, $_POST))
				{
					$ent->values[$key] = $_POST[$key];
				}
				elseif (!$prj->tables[$frmName]->fields[$key]->required && !$prj->tables[$frmName]->fields[$key]->key)
				{
					$ent->values[$key] = "";
				}
				else
				{
					header("HTTP/1.1 405 Bad Request");
					echo "{\"success\":false, \"msg\":\"$key is a required field\"}";
					return;
				}
			}
			try
			{
				$res = $ent->post();
				echo "{\"success\":" . ($res === true ? "true": "false") .  ", \"msg\":\"" . ($res==="true" ? "success" : $res) . "\"}";
			}
			catch(Exception $e)
			{
				header("HTTP/1.1 409 Conflict");
				echo $e->getMessage();
			}
		}
		elseif($_SERVER["REQUEST_METHOD"] == "DELETE")
		{
			echo "delete form";
		}
		else
		{
			$offset = array_key_exists('start', $_GET) ? $_GET['start'] : 0;
			$limit = array_key_exists('limit', $_GET) ? $_GET['limit'] : 0;;
			
			switch($format){
				case "json":
					header("Cache-Control: no-cache, must-revalidate");
					header("Content-Type: application/json");
					if(array_key_exists("mode", $_GET) && $_GET["mode"] == "list")
					{
						$arr = array();
						foreach($_GET as $k => $v)
						{
							if(array_key_exists($k, $prj->tables[$frmName]->fields))
							{
								$arr[$k] = $v;
							}
							
						}
						$arr = $prj->tables[$frmName]->get($arr, $offset, $limit, $_GET["sort"], $_GET["dir"]);
						echo json_encode($arr);
						break;
					}
					else
					{
						
						echo $prj->tables[$frmName]->toJson();
						break;
					}
					break;
				case "xml":
					header("Cache-Control: no-cache, must-revalidate");
					header("Content-Type: text/xml");
					if(array_key_exists("mode", $_GET) && $_GET["mode"] == "list")
					{
						
						$arr = $prj->tables[$frmName]->get(false, $offset, $limit);
						foreach($arr["$frmName"] as $ent)
						{
						
							echo "<entry>";
							foreach($ent as $key => $value)
							{
								if(array_key_exists($key, $prj->tables[$frmName]->fields) && ($prj->tables[$frmName]->fields[$key]->type == "gps" || $prj->tables[$frmName]->fields[$key]->type == "location" ))
								{
									$gps = json_decode($value);
									foreach($gps as $gkey => $gval)
									{
										$suf = ($gkey != "provider" ? substr($gkey, 0, 3) : $key);
										echo "\t\t\t<{$key}_{$suf}>" . str_replace("&", "&amp;", $gval) . "</{$key}_{$suf}>\n";
									}
								}
								else
								{
									echo "<$key>$value</$key>";
								}
							}
							echo "</entry>";
						}
						break;
					}
					else
					{
						echo $prj->tables[$frmName]->toXml();
						break;
					}
				case "csv":
						header("Cache-Control: no-cache, must-revalidate");
						//header("Content-Type: text/csv");
						$arr = $prj->tables[$frmName]->get(false, $offset, $limit);
						$arr = $arr[$frmName];
						echo assocToDelimStr($arr, ",");
						break;
					break;
				case "tsv":
						header("Cache-Control: no-cache, must-revalidate");
						//header("Content-Type: text/tsv");
						$arr = $prj->tables[$frmName]->get(false, $offset, $limit);
						$arr = $arr[$frmName];
						echo assocToDelimStr($arr, "\t");
						break;
					break;
				default:
					header("Cache-control: max-age=864000");
					//TODO: xml get/add/update for forms/tables from the website
					$referer = array_key_exists("HTTP_REFERER", $_SERVER) ? $_SERVER["HTTP_REFERER"] : "";
					if(!array_key_exists("formCrumbs", $_SESSION) || !$prj->getPreviousTable($frmName) || !preg_match("/{$prj->name}\//", $referer))
					{
						$_SESSION["formCrumbs"] = array();
					}
					$p = "";
					if(array_key_exists("prevForm", $_GET))
					{	
						
						$pKey = $prj->tables[$_GET["prevForm"]]->key;
						$_SESSION["formCrumbs"][$_GET["prevForm"]] =  $_GET[$pKey];
						//if we've come back up a step we need to remove the entry. We assume that the crumbs are in the correct order to 
						//draw them in the correct order
					}
					
					foreach($_SESSION["formCrumbs"] as $k => $v)
					{
						if($prj->tables[$k]->number >= $prj->tables[$frmName]->number)
						{
							unset($_SESSION["formCrumbs"][$k]);
						}
						else
						{
							$p .= "&gt; <a href=\"{$k}\">{$k} : $v </a>";
						}
					}
					$vars = array("prevForm" => $p,"projectName" => $prj->name, "formName" => $frmName, "adder" => "true", "admin" =>  "true" );
					echo applyTemplate("base.html", "./FormHome.html", $vars);
					break;
			}
		}
	}
	
	function entryHandler()
	{	
		global $auth, $url;
		
		header("Cache-Control: no-cache, must-revalidate");
		
		$prjEnd = strpos($url, "/");
		$frmEnd =  strpos($url, "/", $prjEnd+1);
		$prjName = substr($url,0,$prjEnd);
		$frmName = substr($url,$prjEnd + 1,$frmEnd - $prjEnd - 1);
		$entId = urldecode(substr($url, $frmEnd + 1));

		$prj = new EcProject();
		$prj->name = $prjName;
		$prj->fetch();

		$ent = new EcEntry($prj->tables[$frmName]);
		$ent->key = $entId;
		$r = $ent->fetch();
	
		//TODO: xml get/add/update for records from the website
		if($_SERVER["REQUEST_METHOD"] == "DELETE")
		{
			if($r === true)
			{
				try
				{
					$ent->delete();
				}catch(Exception $e)
				{
					if(preg_match("/^Message\s?:/", $e->getMessage()))
					{
						header("HTTP/1.1 409 Conflict");
					}
					else
					{
						header("HTTP/1.1 500 Internal Server Error");
					}
					echo $e->getMessage();
				}
			}
			else{
				echo $r;
			}
		}
		else if($_SERVER["REQUEST_METHOD"] == "PUT")
		{
			if($r === true)
			{
				$request_vars = array();
				parse_str(file_get_contents("php://input"), $request_vars);
				
				foreach($request_vars as $key => $value)
				{
					if(array_key_exists($key, $prj->tables[$frmName]->fields))
					{
						$ent->values[$key] = $value;
					}
				}
				
				$r = $ent->put();
				if($r !== true) echo $r;
			}
			else{
				echo $r;
			}
		}
	}
	
	
	function updateUser()
	{
		global $auth;
		
		echo applyTemplate("base.html", "./updateUser.html", $auth->getEcUser());
	}
	
	function saveUser()
	{
		global $auth;
		$qry = "CALL updateUser(" . $auth->getEcUserId() . ",'{$_POST["name"]}','{$_POST["email"]}')";
		$db = new dbConnection();
		$res = $db->do_query($qry);
		
		if($res === true)
		{
			echo '{"success" : true, "msg" : "User updated successfully"}';
		}
		else
		{
			echo '{"success" : false, "msg" : "'.$res.'"}';
		}
	}
	
	function uploadProjectXML()
	{
		global $SITE_ROOT;
		
		$prj = new EcProject();
		$newfn = "ec/xml/" . $_FILES["projectXML"]["name"];
		move_uploaded_file($_FILES["projectXML"]["tmp_name"], $newfn);
		$prj->parse(file_get_contents($newfn));
		
		$res = $prj->post();
		if($res === true)
		{
			$server = trim($_SERVER["HTTP_HOST"], "/");
			$root = trim($SITE_ROOT, "/");
			header("location: http://$server/$root/editProject.html?name={$prj->name}");
		}
		else
		{
			$vals = array("error" => $res);
			echo applyTemplate("base.html","./error.html",$vals);
		}
	}
	
	function createFromXml()
	{
		global $url, $SITE_ROOT;
		
		$prj = new EcProject();
		$xmlFn = "ec/xml/{$_GET["xml"]}";
		
		$prj->parse(file_get_contents($xmlFn));
		
		$res = $prj->post();
		if($res === true)
		{
			$server = trim($_SERVER["HTTP_HOST"], "/");
			$root = trim($SITE_ROOT, "/");
			header ("location: http://$server/$root/" . preg_replace("/create.*$/", $prj->name, $url));
		}
		else
		{
			$vals = array("error" => $res);
			echo applyTemplate("base.html","error.html",$vals);
		}
	}
	
	function listXml()
	{
		//List XML files
		
		$h = opendir("ec/xml");
		$tbl =  "<table id=\"projectTable\"><tr><th>File</th><th>Validation Result</th><th>Create</th><td>&nbsp;</td></tr>";
		$n = "";
		while($fn = readdir($h))
		{
			if(!preg_match("/^\.|.*\.xsd$/", $fn))
			{
				$e = false;
				$v = validate($fn, $n);
				if($v === true)
				{
					$p = new EcProject;
					$p->name = $n;
					$res = $p->fetch();
					if($res !== true) echo $res;
					$e = count($p->tables) > 0;
				}
				
				$tbl .= "<tr id=\"{$n}row\"><td>$fn</td><td>" . ($v === true ? "$n - <span class=\"success\" >Valid</span>" : "$n - <span class=\"failure\" >Invalid</span> <a href=\"javascript:expand('{$n}res', '{$n}row')\">Show errors</a><div id=\"{$n}res\" class=\"verrors\">$v</div>") . ($e === true ?  "</td><td>Project already exists : <a class=\"button\" href=\"$n\">homepage</a></td><td>&nbsp;</td></tr>" : ($v ===true ? "</td><td><a class=\"button\" href=\"create?xml=$fn\">Create Project</a></td><td>&nbsp;</td></tr>" : "</td><td></td><td>&nbsp;</td></tr>"));
			}
		}
		$tbl.= "</table>";
		return $tbl;
		//TODO: for each get the project name and work out if the project exists.
	}
	
	function projectCreator()
	{
		if(array_key_exists("xml", $_FILES))
		{
			move_uploaded_file($_FILES["xml"]["tmp_name"], "ec/xml/{$_FILES["xml"]["name"]}");
		}
		
		$vals = array();
		$vals["xmlFolder"] = getcwd() . "/xml";
		$vals["projects"] = listXML();
		echo applyTemplate("base.html","create.html", $vals);
	}
	
	function validate($fn = false, &$name = null)
	{
		global $SITE_ROOT;
		
		$isValid = true;
		$msgs = array();
		if(!$fn) $fn = $_GET["filename"];
		
		$xml = file_get_contents("./ec/xml/$fn");
		
		$prj = new EcProject;
		try{
			$prj->parse($xml);
		}
		catch(Exception $err)
		{
			array_push($msgs, "The XML for this project is invalid : " . $err->getMessage());
		}
		
		if(!$prj->name || $prj->name == "")
		{
			array_push($msgs, "This project does not have a name, please include a projectName attribute in the model tag.");
		}
		
		if(!$prj->ecVersionNumber || $prj->ecVersionNumber == "")
		{
			array_push($msgs, "Projects must specify a version");
		}
		
		if(count($prj->tables) == 0) array_push($msgs, "A project must contain at least one table.");
		
		foreach($prj->tables as $tbl)
		{
			if(!$tbl->name || $tbl->name == "") array_push($msgs, "Each form must have a name.");
			if(!$tbl->key || $tbl->key == "")
			{
				array_push($msgs, "Each form must have a unique key field.");
			}
			elseif(!$tbl->fields[$tbl->key])
			{
				array_push($msgs, "The form {$tbl->name} does not have a field called {$tbl->key}, please specify another key field.");
			}
			elseif(!preg_match("/input|barcode/", $tbl->fields[$tbl->key]->type))
			{
				array_push($msgs, "The field {$tbl->key} in the form {$tbl->name} is a {$tbl->fields[$tbl->key]->type} field. All key fields must be either text inputs or barcodes.");
			}
			
			//array_push($msgs, "<b>$tbl->name</b>"); 
			foreach($tbl->fields as $fld)
			{
				if(preg_match("/^[0-9]/", $fld->name))
				{
					$isValid = false;
					array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has an invalid name, field names cannot start with a number");
				}
				if(!$fld->label)
				{
					$isValid = false;
					array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has no label. All fields must have a label and the label must not be null. If you have added a label to the field please make sure the tags are all in lower case i.e. <label>...</label> not <Label>...</Label>");
				}
				//TODO: check ID and Label are both set and that the Id is unique
				if($fld->jump)
				{
					//break the jump up into it's parts
					$jBits = explode(",", $fld->jump);
					if(count($jBits) % 2 != 0)
					{
						$isValid = false;
						array_push($msgs, "The field called {$fld->name} in the form {$tbl->name} has an invalid jump attribute. All jumps should be in the format value,target");
					}
					
					for($i = 0; $i + 1 < count($jBits); $i += 2)
					{
						
						//check that the jump destination exists in the current form
						if($jBits[$i] != "End" && !array_key_exists($jBits[$i], $tbl->fields))
						{
							$isValid = false;
							array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has an invalid jump statement the field {$jBits[$i]} that is the target when the value is {$jBits[$i+1]} does not exist in this form");
						}
						//check that the jump value exists in the form
						if( $fld->type == "select1" || $fld->type == "radio")
						{
							$tval = preg_replace('/^!/', '',$jBits[$i + 1]);
							if(!($jBits[$i + 1] == "all" ||  (preg_match('/^[0-9]+$/',$tval) && (intval($tval) <= count($fld->options)))))
							{
								$isValid = false;
								array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has an invalid jump statement the jump to {$jBits[$i]} is set to happen when {$jBits[$i+1]}. If the field type is {$fld->type} the target must be between 0 and " . (count($fld->options) -1) . " for this field options the criteria must be a valid index of an element or 'all'");
							}
						}
						elseif($fld->type == "select")
						{
							$found = false;
							for($o = 0; $o < count($fld->options); $o++)
							{
								
								if(preg_match("/^!?" . $fld->options[$o]->value."$/", $jBits[$i +1 ]))
								{
									$found = true;
									break;
								}
							}
							if(!$found)
							{
								$isValid = false;
								array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has an invalid jump statement the jump to {$jBits[$i]} is set to happen when this field is {$jBits[$i+1]}. This value does not exist as an option.");
							}
						}
					}
				}
				if($fld->type == "group")
				{
					//make sure the group form exists
					if(!$fld->group_form)
					{
						array_push($msgs, "The field {$fld->name} is a group form but has no group_form attribute.");
					}
					elseif(!array_key_exists($fld->group_form, $prj->tables))
					{
						$isValid = false;
						array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has the form {$fld->group_form} set as it's group form, but the form {$fld->group_form} doesn not exist.");
					}
				}
				if($fld->type == "branch")
				{
					//make sure the branch form exists
					if(!array_key_exists($fld->branch_form, $prj->tables))
					{
						$isValid = false;
						array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has the form {$fld->branch_form} set as it's branch form, but the form {$fld->branch_form} doesn not exist.");
					}
				}
				if($fld->regex)
				{
					//make sure the REGEX is a valid Regex
					try
					{
						preg_match("/" . $fld->regex . "/", "12345");
					}
					catch(Exception $err)
					{
						
						array_push($msgs, "The field {$fld->name} in the form {$tbl->name} has an invalid regular expression in it's regex attribute \"($fld->regex)\".");
					}
					
				}
				
				
			}
		}
		$name = $prj->name;
		return count($msgs) == 0 ? true : "<ol><li>" . implode("</li><li>", $msgs) . "</li></ol>";
	}
	
	function uploadProjectXMLUpdate()
	{
		global $SITE_ROOT, $auth, $url;
		
		//get project details
		$prj = new EcProject();
		if(array_key_exists ( "name" ,  $_GET)) $prj->name = $_GET["name"];
		else
		{
			$pNameEnd = strpos($url, "/");
			$prj->name = substr($url, 0, $pNameEnd);
		};
		
		$prj->fetch();
	
		$uId = $auth->getEcUserId();
		$uLvl = $uId ? $prj->checkPermission($uId) : 0;
		if($uLvl == 3)		
		{
			$newfn = "ec/uploads/xml/" . $_FILES["projectXML"]["name"];
			move_uploaded_file($_FILES["projectXML"]["tmp_name"], $newfn);
			$prj->parse(file_get_contents($newfn));
			
			$pNameEnd = strpos($url, "/");
			$prj->name = substr($url, 0, $pNameEnd);
			
			$res = $prj->push();
			if($res === true)
			{
				$server = trim($_SERVER["HTTP_HOST"], "/");
				$root = trim($SITE_ROOT, "/");
				header("location: http://$server/$root/editProject.html?name={$prj->name}");
			}
			else
			{
				$vals = array("error" => $res);
				echo applyTemplate("./base.html","./error.html",$vals);
			}
		}
		else
		{
			echo applyTemplate("./base.html","./error.html",array("error" => "You do not have permission to update this project"));
			return;
		}
	}
	
	function createProject()
	{
		global $auth, $url;
		
		header("Cache-Control: no-cache, must-revalidate");
		
		$vals =  array(
			"title" =>  "Create Project", 
			"projectId" => "",
			"projectName" => "",
			"projectDescription" => "",
			"projectImage" => "",
			"projectIsPublic" => "1",
			"projectIsListed" => "1",
			"projectPublicSubmission" =>"1",
			"projectAdmins" => "['{$_SESSION["Email"]}', true]",
			"projectUsers" => "",
			"projectSubmitters" => "",
			"xmlUrl" => "/uploadProject"
		);
		
		echo applyTemplate("./base.html","./createProject.html",$vals);
	
	}
	
	
	function editProject()
	{
		global $auth, $url;
		
		//get project details
		$prj = new EcProject();
		if(array_key_exists ( "name" ,  $_GET)) $prj->name = $_GET["name"];
		else
		{
			$pNameEnd = strpos($url, "/");
			$prj->name = substr($url, 0, $pNameEnd);
		}
		
		$vals = array();
		
		try{
			$prj->fetch();
		
			$uId = $auth->getEcUserId();
			$uLvl = $uId ? $prj->checkPermission($uId) : 0;
			if($uLvl == 3) //if the user is a project administator
			{
				$admins = "";
				foreach($prj->getAdmins() as $adm) $admins .= "['$adm', true],";
				$users = "";
				foreach($prj->getUsers() as $usr) $users .= "['$usr', true],";
				$submitters = "";
				foreach($prj->getSubmitters() as $sub) $submitters .= "['$sub', true],";
				$vals =  array(
					"title" =>  "Update {$prj->name}", 
					"projectId" => $prj->id,
					"projectName" => $prj->name,
					"projectDescription" => str_replace("\"", "\\\"", $prj->description),
					"projectImage" => $prj->image,
					"projectIsPublic" => $prj->isPublic,
					"projectIsListed" => $prj->isListed,
					"projectPublicSubmission" => $prj->publicSubmission,
					"projectAdmins" => rtrim($admins, ","),
					"projectUsers" => rtrim($users, ","),
					"projectSubmitters" => rtrim($submitters, ","),
					"xmlUrl" => $prj->name . "/updateProject"
				);
				
				echo applyTemplate("./base.html","./createProject.html",$vals);
			}
			else
			{
				echo applyTemplate("./base.html","./error.html",array("error" => "You do not have permission to update this project"));
				return;
			}
		}
		catch(Exception $e)
		{
			
			$vals = array("error" => $e);
			echo applyTemplate("./base.html","./error.html",$vals);
		}
	}
	
	function formBuilder()
	{
		echo applyTemplate("./base.html" , "./createOrEditForm.html");
	}
	
	function getControlTypes()
	{
		$db = new dbConnection();
		$res = $db->do_query("SELECT * FROM FieldType");
		
		if($res === true)
		{
			$arr = array();
			while ($a = $db->get_row_array())
			{
				array_push($arr, $a);
			}
			
			header ("Content-type: application/json");
			echo json_encode(array("controlTypes" => $arr));
		}
		
		
	}
	
	function uploadMedia()
	{
		global $url, $SITE_ROOT;
		$pNameEnd = strpos($url, "/");
		$pname = substr($url, 0, $pNameEnd);
		$extStart = strpos($url, ".");
		$fNameEnd = strpos($url, "/", $pNameEnd + 1);
		$frmName = rtrim(substr($url, $pNameEnd + 1, $fNameEnd - $pNameEnd), "/");
		
		$tvals = array("project" => $pname,"form" => $frmName);
		
		if(array_key_exists("newfile", $_FILES) && $_FILES["newfile"]["error"] == 0)
		{
			if(preg_match("/\.(png|gif|jpe?g)$/", $_FILES["newfile"]["name"]))
			{
				$fn = "ec/uploads/{$pname}~".$_FILES["newfile"]["name"];
				move_uploaded_file($_FILES["newfile"]["tmp_name"], $fn);
				
				$tnfn = str_replace("~", "~tn~", $fn);
				
				$imgSize = getimagesize($fn);
				
				$scl = min(384/$imgSize[0], 512/$imgSize[1]);
				$nw = $imgSize[0] * $scl;$nh =  $imgSize[1] * $scl;
				
				if(preg_match("/\.jpe?g$/", $fn))
				{
					$img = imagecreatefromjpeg($fn);
				}
				elseif(preg_match("/\.gif$/", $fn))
				{
					$img = imagecreatefromgif($fn);
				}
				elseif(preg_match("/\.png$/", $fn))
				{
					$img = imagecreatefrompng($fn);
					imagealphablending($img, true); // setting alpha blending on
					imagesavealpha($img, true); // save alphablending setting (important)
				}
				else
				{
					echo "not supported";
					return;
				}
				
				$thn = imagecreatetruecolor($nw,$nh);
				imagecopyresampled($thn, $img, 0,0, 0,0, $nw, $nh, $imgSize[0], $imgSize[1]);
				
				
				if(preg_match("/\.jpe?g$/", $fn))
				{
					imagejpeg($thn, $tnfn, 95);
				}
				elseif(preg_match("/\.gif$/", $fn))
				{
					imagegif($thn, $tnfn);
				}
				elseif(preg_match("/\.png$/", $fn))
				{
					imagepng($thn, $tnfn);
				}
				
				$tvals["mediaTag"] = "<img src=\"$SITE_ROOT/{$tnfn}\" />";
			}
			elseif(preg_match("/\.(mov||mpe?g?[34])$/", $_FILES["newfile"]["name"]))
			{
				//audio/video handler
				$fn = "ec/uploads/{$pname}~".$_FILES["newfile"]["name"];
				move_uploaded_file($_FILES["newfile"]["tmp_name"], $fn);
				
				$tvals["mediaTag"] = "<a href=\"$SITE_ROOT/{$pname}~{$fn}\" >View File</a>";
			}
		}
		
		if(array_key_exists("fn", $_GET))
		{
			$fn = "ec/uploads/{$pname}~tn~".$_GET["fn"];
			$tvals["mediaTag"] = "<img src=\"$SITE_ROOT/{$fn}\" height=\"150\" />";
			$tvals["fn"] = str_replace("ec/uploads/", "", $fn);
		}
	
		echo applyTemplate("./uploadIFrame.html", "./base.html", $tvals);
	}
	
	function getMedia()
	{
		global $url;
		
		if(preg_match('~tn~', $url) )
		{
			//if the image is a thumbnail just try and open it
			header("Content-type: " . mimeType($url));
			echo file_get_contents("./" . $url);
		}
		else
		{
			if(file_exists("./$url"))
			{
				header("Content-type: " . mimeType($url));
				echo file_get_contents("./" . $url);
			}
			else
			{
				$u = str_replace("~", "~tn~", $url);
				header("Content-type: " . mimeType($u));
				echo file_get_contents("./" . $u);
			}
		}
	}
	
	function getXML()
	{
		if(array_key_exists('name', $_GET))
		{
			$prj = new EcProject();
			$prj->name = $_GET["name"];
			$prj->fetch();
			//print_r($prj);
			echo $prj->toXML();
			
		}
	}

	function projectSummary()
	{
		global $url, $auth;
		
		$prj = new EcProject();
		$prj->name = substr($url, 0, strpos($url, "/"));
		$prj->fetch();
		$sum = $prj->getSummary();
		
		echo "{\"forms\" : ". json_encode($sum) . "}";
	}
	
	function projectUsage()
	{
		global $url, $auth;
		
		$prj = new EcProject();
		$prj->name = substr($url, 0, strpos($url, "/"));
		$prj->fetch();
		
		if(!$prj->isPublic && $prj->checkPermission($auth->getEcUserId()) < 2) return "access denied)";
		
		$sum = $prj->getUsage();
		header("Content-type: text/plain");
		echo $sum; //"{\"forms\" : ". json_encode($sum) . "}";
	}
	
	function writeSettings()
	{
		global $DBSERVER, $DBUSER, $DBPASS, $DBNAME, $SITE_ROOT;
		
		
		$sf = fopen("ec/settings.php", "w");
		$ef = fopen("ec/logs/ec.txt", "w");
		
		$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		
		$key = md5($_SERVER["REMOTE_ADDR"] . $_SERVER["REQUEST_TIME"]);
		
		fwrite($ef, base64_encode($iv).",$key");
		fclose($ef);
		
		$settings = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key, $_POST["serverName"].",". $_POST["userName"].",". $_POST["password"].",". $_POST["dbName"], MCRYPT_MODE_CBC, $iv));
		
		$str = "<?php \$settings = '$settings'; ?>";
		
		fwrite($sf, $str);
		fclose($sf);
		
		header("Location: http://{$_SERVER["HTTP_HOST"]}/$SITE_ROOT/");
	}
	
	
	
	function userHandler()
	{
		global $url, $auth, $SITE_ROOT;

		//if(!(strstr($_SERVER["HTTP_REFERER"], "/createProject.html"))) return;
		
		$qry = str_replace("user/", "", $url);
		
		$db = new dbConnection();
		$sql = "Select openid from user where Email = '$qry'";
		//echo $qry;
		$res = $db->do_query($sql);
		if($res === true)
		{
			if($arr = $db->get_row_array())
			{
				if($arr["openid"])
				{
					echo "true";
				}
			}
			else
			{
				echo "false";
			}
		}
	}
	/* end handlers */
	
	/*
	 * The page rules array defines how to handle certain urls, if a page rule
	 * hasn't been defined then then the script should return a 404 error (this
	 * is in order to protect files that should not be open to public view such
	 * as log files which may contain restricted data)
	 */
	
	
	$pageRules = array(
		//static file handlers
		"" => new PageRule("index.html", 'siteHome'),
		"index.html?" => new PageRule("index.html", 'siteHome'),
		"[a-zA-Z0-1]+\.html" => new PageRule(null, 'defaultHandler'),
		"images/.+" => new PageRule(),
		"favicon\..+" => new PageRule(),
		"Ext/.+" => new PageRule(),
		"js/.+" => new PageRule(),
		"css/EpiCollect2.css" => new PageRule(),
		"css/EpiSkins.css" => new PageRule(),
		"EpiCollect2.js" => new PageRule(),
		
		//project handlers		
		"createProject.html" => new PageRule(null, 'projectCreator', true),
		"create" => new PageRule(null, 'createFromXml', true),
		"projectHome.html" => new PageRule(null, 'projectHome'),
		"editProject.html" => new PageRule(null, 'editProject'),
		"createOrEditForm.html" => new PageRule(null ,'defaultHandler', true),
		"uploadProject" =>new PageRule(null, 'uploadProjectXML', true),
		"getForm" => new PageRule(null, 'getXML',	 true),
		"validate" => new PageRule(null, 'validate',false),
		//"listXML" => new PageRule(null, 'listXML',false),
		//login handlers
		"Auth/loginCallback.php" => new PageRule(null,'loginCallbackHandler'),
		"login.php" => new PageRule(null,'loginHandler'),
		"logout.html" => new PageRule(null, 'logoutHandler'),
		"chooseProvider.html" => new PageRule(null, 'chooseProvider'),
		
		//user handlers
		"updateUser.html" => new PageRule(null, 'updateUser', true),
		"saveUser" =>new PageRule(null, 'saveUser', true),
		"user/.*?" => new PageRule(null, 'userHandler', true),
		
		//generic, dynamic handlers		
		"getControls" =>  new PageRule(null, 'getControlTypes'),
		"uploadFile.php" => new PageRule(null, 'uploadHandlerFromExt'),
		"ec/uploads/.+\.(jpg)|(mp4)$" => new PageRule(null, 'getMedia'),
		"ec/uploads/.+" => new PageRule(null, null),
			
		//to API
		"[a-zA-Z0-9_-]*(\.xml|\.json|\.tsv|\.csv|/)?" =>new PageRule(null, 'projectHome'),
		"[a-zA-Z0-9_-]*/upload" =>new PageRule(null, 'uploadData'),
		"[a-zA-Z0-9_-]*/download" =>new PageRule(null, 'downloadData'),
		"[a-zA-Z0-9_-]*/summary" =>new PageRule(null, 'projectSummary'),
		"[a-zA-Z0-9_-]*/usage" =>  new PageRule(null, 'projectUsage'),
		"[a-zA-Z0-9_-]*/formBuilder(\.html)?" =>  new PageRule(null, 'formBuilder'),
		"[a-zA-Z0-9_-]*/updateProject" =>new PageRule(null, 'uploadProjectXMLUpdate', true),
		"[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*/uploadMedia" =>new PageRule(null, 'uploadMedia'),
		"[a-zA-Z0-9_-]*/editProject.html" =>new PageRule(null, 'editProject', true),
		"[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*(\.xml|\.json|\.tsv|\.csv|/)?" => new PageRule(null, 'formHandler'),
		//"[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*/usage" => new  => new PageRule(null, formUsage),
		"[^/\.]*/[^/\.]*/[^/\.]*(\.xml|\.json|/)?" => new PageRule(null, 'entryHandler'),
		
		//forTesting
		"uploadTest.html" => new PageRule(null, 'defaultHandler', true),
		"test" => new PageRule(null, 'siteTest', false),
		"createDB" => new PageRule(null, 'setupDB', true),
		"writeSettings" => new PageRule(null, 'writeSettings', true)
	);
	
	$d = new DateTime();
	$i = $dat->format("su") - $d->format("su");
	
	$rule = false;

	if(array_key_exists($url, $pageRules))
	{
		$rule = $pageRules[$url];
	}
	else
	{
		
		foreach(array_keys($pageRules) as $key)
		{
			if(preg_match("/^".regexEscape($key)."$/", $url))
			{
				//echo $key;
				$rule = $pageRules[$key];
				break;
			}
		}
	}
	
	if($rule)
	{
		if($rule->redirect)
		{
			$url = $rule->redirect;
		}
		if($rule->handler)
		{
			$h = $rule->handler;
			if($h != 'defaultHandler') @session_start();
			$h();
		}
		else
		{
			//static files
			header("Content-type: " . mimeType($url));
			header("Cache-Control: public; max-age=86400;");
			echo file_get_contents("./" . $url);
		}	
	}
	else
	{
		
		$parts = explode("/", $url);	
		echo applyTemplate("./base.html", "./404.html");
	}
	
	$d = new DateTime();
	$i = $dat->format("su") - $d->format("su");
	
?>