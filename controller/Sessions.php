<?php

require_once('db.php');
require_once('../model/Response.php');

try{

  $writeDB = DB::connectWriteDB();

}
catch(PDOException $ex){
  error_log('Connection Error'.$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage('Database connection error');
  $response->send();
  exit();
}

//delete a session / refresh a session
if(array_key_exists("sessionid", $_GET)){

  $sessionID = $_GET['sessionid'];

  if($sessionID === '' || !is_numeric($sessionID)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    ($sessionID === '' ? $response->addMessage('Session ID cannot be blank') : false);
    (!is_numeric($sessionID) ? $response->addMessage('Session ID must be numeric') : false);
    $response->send();
    exit();
  }

  if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){

      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage('Access token is missing from header') : false);
      (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage('Access token cannot be blank') : false);
      $response->send();
      exit();
  }

  $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

  if($_SERVER['REQUEST_METHOD'] === 'DELETE'){

    try{

      $query = $writeDB->prepare('DELETE FROM rest_sessions WHERE id = :sessionid AND access_token = :accesstoken');
      $query->bindParam(':sessionid', $sessionID, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      $query->execute();

      $rowcount = $query->rowCount();

      if($rowcount === 0){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Failed to log out of this session using access token provided');
        $response->send();
        exit();
      }

      $returnData = array();
      $returnData['session_id'] = intval($sessionID);

      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage('Successfully logged out');
      $response->setData($returnData);
      $response->send();
      exit();



    }
    catch(PDOException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage('There was an issue logging out. Please try again');
      $response->send();
      exit();
    }
}
  elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){

    if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage('Content type header not set to json');
      $response->send();
      exit();
    }

    $rawPatchData = file_get_contents('php://input');

    if(!$jsondata = json_decode($rawPatchData)){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage('Request body is not valid json');
      $response->send();
      exit();
    }

    if(!isset($jsondata->refresh_token) || strlen($jsondata->refresh_token) < 1){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      (!isset($jsondata->refresh_token) ? $response->addMessage('Refresh token not supplied') : false);
      (strlen($jsondata->refresh_token) < 1 ? $response->addMessage('Refresh token cannot be blank') : false);
      $response->send();
      exit();
    }

    try{

      $refreshtoken = $jsondata->refresh_token;

      $query = $writeDB->prepare('SELECT rest_sessions.id AS sessionid, rest_sessions.user_id, access_token, refresh_token, user_active, login_attempts, access_token_expiry, refresh_token_expiry
                                  FROM rest_sessions, rest_users
                                  WHERE rest_users.id = rest_sessions.user_id
                                  AND rest_sessions.id = :sessionid
                                  AND rest_sessions.access_token = :accesstoken
                                  AND rest_sessions.refresh_token = :refreshtoken');
      $query->bindParam(':sessionid', $sessionID, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
      $query->execute();

      $rowcount = $query->rowCount();

      if($rowcount === 0){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('Access token or refresh token is incorrect for session id');
        $response->send();
        exit();
      }

      $row = $query->fetch(PDO::FETCH_ASSOC);

      $returned_sessionid = $row['sessionid'];
      $returned_userid = $row['user_id'];
      $returned_accesstoken = $row['access_token'];
      $returned_refreshtoken = $row['refresh_token'];
      $returned_useractive = $row['user_active'];
      $returned_loginattempts = $row['login_attempts'];
      $returned_accesstokenexpiry = $row['access_token_expiry'];
      $returned_refreshtokenexpiry = $row['refresh_token_expiry'];

      if($returned_useractive !== 'Y'){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('User account is not active');
        $response->send();
        exit();
      }

      if($returned_loginattempts >= 3){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('User account is currently locked out');
        $response->send();
        exit();
      }

      if(strtotime($returned_refreshtokenexpiry) < time()){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('Refresh token has expired. Please login again');
        $response->send();
        exit();
      }

      $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
      $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

      $access_token_expiry_seconds = 1200;
      $refresh_token_expiry_seconds = 1209600;

      $query = $writeDB->prepare('UPDATE rest_sessions SET access_token = :accesstoken, access_token_expiry = date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), refresh_token = :refreshtoken, refresh_token_expiry = date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND)
                                  WHERE id = :sessionid
                                  AND user_id = :userid
                                  AND access_token = :returnedaccesstoken
                                  AND refresh_token = :returnedrefreshtoken');
      $query->bindParam(':sessionid', $returned_sessionid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
      $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
      $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
      $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
      $query->bindParam(':returnedaccesstoken', $returned_accesstoken, PDO::PARAM_STR);
      $query->bindParam(':returnedrefreshtoken', $returned_refreshtoken, PDO::PARAM_STR);
      $query->execute();


      $rowcount = $query->rowCount();

      if($rowcount === 0){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage('Access token could not be refreshed. Please login again');
        $response->send();
        exit();
      }

      $returnData = array();
      $returnData['sessionid'] = $returned_sessionid;
      $returnData['access_token'] = $accesstoken;
      $returnData['$access_token_expiry'] = $access_token_expiry_seconds;
      $returnData['refresh_token'] = $refreshtoken;
      $returnData['refresh_token_expiry'] = $refresh_token_expiry_seconds;

      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->addMessage('Token refreshed');
      $response->setData($returnData);
      $response->send();
      exit();




    }
    catch(PDOException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage('There was an issue refreshing access token. Please login again');
      $response->send();
      exit();
    }
  }
  else{
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage('Request method not allowed');
    $response->send();
    exit();
  }
}



//create a session
elseif(empty($_GET)){

  if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage('Request method not allowed');
    $response->send();
    exit();
  }

  //help prevent a brute force attack
  sleep(1);

  if($_SERVER['CONTENT_TYPE'] !=='application/json'){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage('Content type header not set to json');
    $response->send();
    exit();
  }

  $rawPostData = file_get_contents('php://input');

  if(!$jsondata = json_decode($rawPostData)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage('Request body is not valid json');
    $response->send();
    exit();
  }

  if(!isset($jsondata->username) || !isset($jsondata->password)){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    (!isset($jsondata->username) ? $response->addMessage('Username not supplied') : false);
    (!isset($jsondata->password) ? $response->addMessage('Password not supplied') : false);
    $response->send();
    exit();
  }

  if(strlen($jsondata->username) < 1 || strlen($jsondata->username) >255 || strlen($jsondata->password) < 1 || strlen($jsondata->password) > 255){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    (strlen($jsondata->username) < 1 ? $response->addMessage('Username not provided') : false);
    (strlen($jsondata->username) > 255 ? $response->addMessage('Username cannot be more than 255 characters') : false);
    (strlen($jsondata->password) < 1 ? $response->addMessage('Password not provided') : false);
    (strlen($jsondata->password) > 255 ? $response->addMessage('Password cannot be more than 255 characters') : false);
    $response->send();
    exit();
  }

  try{
    $username = $jsondata->username;
    $password = $jsondata->password;

    $query = $writeDB->prepare('SELECT * FROM rest_users
                                WHERE username = :username');
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowcount = $query->rowCount();

    if($rowcount === 0){
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage('Username or password is incorrect');
      $response->send();
      exit();
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_id = $row['id'];
    $returned_fullname = $row['full_name'];
    $returned_username = $row['username'];
    $returned_password = $row['password'];
    $returned_useractive = $row['user_active'];
    $returned_loginattempts = $row['login_attempts'];

    if($returned_useractive !== 'Y'){
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage('User account not active');
      $response->send();
      exit();
    }

    if($returned_loginattempts >= 3){
      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage('User account is currently locked out');
      $response->send();
      exit();
    }

    if(!password_verify($password, $returned_password)){

      $query = $writeDB->prepare('UPDATE rest_users SET login_attempts = login_attempts + 1
                                  WHERE id = :id');
      $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
      $query->execute();

      $response = new Response();
      $response->setHttpStatusCode(401);
      $response->setSuccess(false);
      $response->addMessage('Username or password is incorrect');
      $response->send();
      exit();
    }

    //creating tokens
     $accesstoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
     $refreshtoken = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

     //create expiry time
     $access_token_expiry_seconds = 1200;
     $refresh_token_expiry_seconds = 1209600;
  }
  catch(PDOException $ex){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('There was an issue logging in');
    $response->send();
    exit();
  }

  try{

    $writeDB->beginTransaction();

    $query = $writeDB->prepare('UPDATE rest_users SET login_attempts = 0
                                WHERE id = :id');
    $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
    $query->execute();

    $query = $writeDB->prepare('insert into rest_sessions (user_id, access_token, access_token_expiry, refresh_token, refresh_token_expiry) values (:userid, :accesstoken, date_add(NOW(), INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(), INTERVAL :refreshtokenexpiryseconds SECOND))');

    /*('INSERT INTO rest_sessions (user_id, access_token, access_token_expiry, refresh_token, refresh_token_expiry,)
                                VALUES (:userid, :accesstoken, date_add(NOW(),
                                INTERVAL :accesstokenexpiryseconds SECOND), :refreshtoken, date_add(NOW(),
                                INTERVAL :refreshtokenexpiryseconds SECOND))');
                                */
    $query->bindParam(':userid', $returned_id, PDO::PARAM_INT);
    $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
    $query->bindParam(':accesstokenexpiryseconds', $access_token_expiry_seconds, PDO::PARAM_INT);
    $query->bindParam(':refreshtoken', $refreshtoken, PDO::PARAM_STR);
    $query->bindParam(':refreshtokenexpiryseconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
    $query->execute();

    $lastSessionID = $writeDB->lastInsertID();

    $writeDB->commit();

    $returnData = array();
    $returnData['session_id'] = intval($lastSessionID);
    $returnData['access_token'] = $accesstoken;
    $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
    $returnData['refresh_token'] = $refreshtoken;
    $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->setData($returnData);
    $response->send();
    exit();
  }
  catch(PDOException $ex){
    $writeDB->rollBack();
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage('There was an issue logging in. Please try again');
    $response->send();
    exit();
  }


}
else{
  $response = new Response();
  $response->setHttpStatusCode(404);
  $response->setSuccess(false);
  $response->addMessage('Endpoint not found');
  $response->send();
  exit();
}




 ?>
