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

if($_SERVER['REQUEST_METHOD'] !=='POST'){
  $response = new Response();
  $response->setHttpStatusCode(405);
  $response->setSuccess(false);
  $response->addMessage('Request method not allowed');
  $response->send();
  exit();
}

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

if(!isset($jsondata->full_name) || !isset($jsondata->username) || !isset($jsondata->password)){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  (!isset($jsondata->full_name) ? $response->addMessage('Full name not supplied') : false );
  (!isset($jsondata->username) ? $response->addMessage('Username not supplied') : false );
  (!isset($jsondata->password) ? $response->addMessage('Password not supplied') : false );
  $response->send();
  exit();
}

if(strlen($jsondata->full_name) < 1 || strlen($jsondata->full_name) > 255 || strlen($jsondata->username) < 1 || strlen($jsondata->username) > 255 || strlen($jsondata->password) < 1 || strlen($jsondata->password) > 255){
  $response = new Response();
  $response->setHttpStatusCode(400);
  $response->setSuccess(false);
  (strlen($jsondata->full_name) < 1 ? $response->addMessage('Full name cannot be blank') : false );
  (strlen($jsondata->full_name) > 255 ? $response->addMessage('Full name cannot be more than 255 characters') : false );
  (strlen($jsondata->username) < 1 ? $response->addMessage('Username cannot be blank') : false );
  (strlen($jsondata->username) > 255 ? $response->addMessage('Username cannot be more than 255 characters') : false );
  (strlen($jsondata->password) < 1 ? $response->addMessage('Password cannot be blank') : false );
  (strlen($jsondata->password) > 255 ? $response->addMessage('Password cannot be more than 255 characters') : false );
  $response->send();
  exit();
}

$fullname = trim($jsondata->full_name);
$username = trim($jsondata->username);
$password = $jsondata->password;


try{

  $query = $writeDB->prepare('SELECT id FROM rest_users
                              WHERE username = :username');
  $query->bindParam(':username', $username, PDO::PARAM_STR);
  $query->execute();

  $rowcount = $query->rowCount();

  if($rowcount !== 0){
    $response = new Response();
    $response->setHttpStatusCode(409);
    $response->setSuccess(false);
    $response->addMessage('Username already exists');
    $response->send();
    exit();
  }

$hashed_password = password_hash($password, PASSWORD_DEFAULT);

$query = $writeDB->prepare('INSERT INTO rest_users(full_name, username, password)
                            VALUES (:fullname, :username, :password)');
$query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
$query->bindParam(':username', $username, PDO::PARAM_STR);
$query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
$query->execute();

$rowcount = $query->rowCount();

if($rowcount === 0){
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage('There was an issue creating a user. Please try again.');
  $response->send();
  exit();
}

$lastUserID = $writeDB->lastInsertID();

$returnData = array();
$returnData['user_id'] = $lastUserID;
$returnData['Fullname'] = $fullname;
$returnData['username'] = $username;

$response = new Response();
$response->setHttpStatusCode(201);
$response->setSuccess(true);
$response->addMessage('User created');
$response->setData($returnData);
$response->send();
exit();
}

catch(PDOException $ex){
  error_log('Database query error'.$ex, 0);
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage('There was an error creating a user account. Please try again.');
  $response->send();
  exit();
}







 ?>
