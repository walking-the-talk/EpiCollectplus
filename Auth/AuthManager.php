<?php
 require "openid.php";

 if (isset($_REQUEST['_SESSION'])) die("Computer says no!");

 class AuthManager
 {
  // Version 2, ignores OAuth, and useses open ID to authenticate to Google. As Android is our main platform
  // this makes sense, there is potential for extensibility to other OpenID providers.
  private $openid;
  
  function __construct()
  {
   $this->openid = new LightOpenID;
   $this->openid->identity = array_key_exists("openid", $_SESSION) ? $_SESSION["openid"] : "";
   $this->openid->required = array('namePerson/first', 'namePerson/last', 'contact/email', 'contact/country/home', 'pref/language');
  }
  
  function login()
  {
   //print_r($_SESSION);
   if(!$this->openid->mode)
   {
	if(!$this->openid->identity)$this->openid->identity = 'https://www.google.com/accounts/o8/id';
	header('Location: ' . $this->openid->authUrl());
	return false;
   }
   else if ($this->openid->mode === "cancel")
   {
	return false;
   }
   else
   {
	if($this->openid->validate())
	{
	 $_SESSION["openid"] = $this->openid->identity;
	 $this->populateSesssionInfo();
	
	 if(!isset($_SESSION["Email"]) || $_SESSION["Email"] == "")
	 {
	  $arr = $this->openid->getAttributes(); 
	  $db2 = new dbConnection();
	  $qry = "INSERT INTO user (FirstName, LastName, Email, openId, language) VALUES ('{$arr["namePerson/first"]}','{$arr["namePerson/last"]}','{$arr["contact/email"]}','{$_SESSION["openid"]}','{$arr['pref/language']}') " .
	   "ON DUPLICATE KEY UPDATE FirstName = '{$arr["namePerson/first"]}', LastName = '{$arr["namePerson/last"]}', openId = '{$_SESSION["openid"]}', language = '{$arr['pref/language']}'";
	  $res = $db2->do_query($qry);
	  if($res !== true) echo $res;
	  //$db2->__destruct();
	  $this->populateSesssionInfo();
	 }
	 return true;
	}
	else
	{
	 return false;
	}
   }
  }
  
  function logout()
  {
  
   unset($_SESSION["openid"]);
   session_unset();
   session_destroy();
  
  }
  
  function isLoggedIn()
  {
   
   return isset($_SESSION["openid"]) && $_SESSION["openid"] != "" ;
   
  }
  
  private function populateSesssionInfo()
  {
   $db = new dbConnection();
   $qry = "SELECT idUsers as userId, FirstName, LastName, Email, language FROM user WHERE openId = '{$_SESSION['openid']}'";
   $err = $db->do_query($qry);
   if($err === true)
   {
	if($arr = $db->get_row_array())
	{
	 foreach(array_keys($arr) as $key)
	 {
	  $_SESSION[$key] = $arr[$key];
	 }
	}
   }
  }
  
  function getUserNickname()
  {
   //$arr = $this->openid->getAttributes();
   
   return "{$_SESSION["FirstName"]} {$_SESSION["LastName"]}";
  }
  
  function getEcUserId()
  {
   return array_key_exists("userId", $_SESSION) ? $_SESSION["userId"] : "";
  }
  
  function getUserEmail()
  {
   //$arr = $this->openid->getAttributes();
//   echo $arr["contact/email"];
   return $_SESSION["Email"];
  }
 }
?>