<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');

try{
  $writeDB = DB::connectWriteDB();
  $readDB = DB:: connectReadDB();
}
catch(PDOException $ex ){

  error_log('Connection error - '.$ex, 0);

  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage('Database connection error');
  $response->send();
  exit();
}

//begin auth script
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ){
  $response = new Response();
  $response->setHttpStatusCode(401);
  $response->setSuccess(false);
  (!isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage('Access token is missing from the header') : false);
  (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage('Access token cannot be blank') : false);
  $response->send();
  exit();
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];

try{
  $query = $writeDB->prepare('SELECT user_id, access_token_expiry, user_active, login_attempts
                              FROM rest_sessions, rest_users
                              WHERE rest_sessions.user_id = rest_users.id
                              AND access_token = :accesstoken');
  $query->bindParam(':accesstoken', $accesstoken, PDO::PARAM_STR);
  $query->execute();

  $rowcount = $query->rowCount();

  if($rowcount === 0){
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage('Invalid access token');
    $response->send();
    exit();
  }

  $row = $query->fetch(PDO::FETCH_ASSOC);

  $returned_userid = $row['user_id'];
  $returned_accesstokenexpiry = $row['access_token_expiry'];
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

  if(strtotime($returned_accesstokenexpiry) < time()){
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    $response->addMessage('Access token expired');
    $response->send();
    exit();
  }

}
catch(PDOException $ex){
  $response = new Response();
  $response->setHttpStatusCode(500);
  $response->setSuccess(false);
  $response->addMessage('There was an issue authenticating. Pleae try again');
  $response->send();
  exit();
}

//end auth script

if(array_key_exists('taskid', $_GET)){

  $taskid = $_GET['taskid'];

  if($taskid == '' || !is_numeric($taskid)){
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage('Task ID cannot be blank and must be numeric');
    $response->send();
    exit();
  }

  if($_SERVER['REQUEST_METHOD'] === 'GET'){

      try{

        $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed
                                   FROM rest_tasks
                                   WHERE id = :taskid
                                   AND user_id = :userid');
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowcount = $query->rowCount();

        if($rowcount === 0){

          $response = new Response();
          $response->setHttpStatusCode(404);
          $response->setSuccess(false);
          $response->addMessage('Task not found');
          $response->send();
          exit();


        }


        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'] );
            $taskArray[] = $task->returnTaskAsArray();
        }

          $returnData = array();
          $returnData['rows_returned'] = $rowcount;
          $returnData['tasks'] = $taskArray;

          $response = new Response();
          $response->setHttpStatusCode(200);
          $response->setSuccess(true);
          $response->toCache(true);
          $response->setData($returnData);
          $response->send();
          exit();



      }

      catch(PDOException $ex){

        error_log('Database query error - '.$ex, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('Database query error');
        $response->send();
        exit();

      }

      catch(TASKException $ex){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit();

      }







  }else if ($_SERVER['REQUEST_METHOD'] === 'DELETE'){

      try{

        $query = $writeDB->prepare('DELETE FROM rest_tasks WHERE id = :taskid AND user_id = :userid');
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowcount = $query->rowCount();

        if($rowcount === 0){

          $response = new Response();
          $response->setHttpStatusCode(404);
          $response->setSuccess(false);
          $response->addMessage('Task not found');
          $response->send();
          exit();
        }

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->addMessage('Task deleted');
        $response->send();
        exit();

      }

      catch(PDOException $ex){

        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('Failed to delete task');
        $response->send();
        exit();

      }







  }else if ($_SERVER['REQUEST_METHOD'] === 'PATCH'){

    try{

      if($_SERVER['CONTENT_TYPE'] !=='application/json'){
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

      $title_updated = false;
      $description_updated = false;
      $deadline_updated = false;
      $completed_updated = false;

      $queryFields ='';

      if(isset($jsondata->title)){
        $title_updated =true;
        $queryFields .= 'title =:title,';
      }
      if(isset($jsondata->description)){
        $description_updated =true;
        $queryFields .= 'description =:description,';
      }
      if(isset($jsondata->deadline)){
        $deadline_updated =true;
        $queryFields .= 'deadline = STR_TO_DATE(:deadline, "%d/%m/%Y %H:%i"),';
      }
      if(isset($jsondata->completed)){
        $completed_updated =true;
        $queryFields .= 'completed =:completed,';
      }

      $queryFields = rtrim($queryFields, ',');

      if($title_updated === false && $description_updated === false && $deadline_updated === false && $completed_updated === false){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('No task fields provided');
        $response->send();
        exit();
      }

      $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed
                                  FROM rest_tasks
                                  WHERE id = :taskid
                                  AND user_id = :userid');
      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowcount = $query->rowCount();

      if($rowcount === 0){
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage('No task found to update');
        $response->send();
        exit();
      }

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
      }

      $queryString = 'update rest_tasks set '.$queryFields.' where id = :taskid and user_id = :userid';
      $query = $writeDB->prepare($queryString);

      if($title_updated === true){
        $task->setTitle($jsondata->title);
        $up_title = $task->getTitle();
        $query->bindParam(':title', $up_title, PDO::PARAM_STR);
      }
      if($description_updated === true){
        $task->setDescription($jsondata->description);
        $up_description = $task->getDescription();
        $query->bindParam(':description', $up_description, PDO::PARAM_STR);
      }
      if($deadline_updated === true){
        $task->setDeadline($jsondata->deadline);
        $up_deadline = $task->getDeadline();
        $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
      }
      if($completed_updated === true){
        $task->setCompleted($jsondata->completed);
        $up_completed = $task->getCompleted();
        $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
      }

      $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowcount = $query->rowCount();

      if($rowcount === 0){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage('Task not updated');
        $response->send();
        exit();
      }


      $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed
                                  FROM rest_tasks
                                  WHERE id = :taskid
                                  AND user_id = :userid');
       $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
       $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
       $query->execute();

       $rowcount = $query->rowCount();

       if($rowcount === 0){
         $response = new Response();
         $response->setHttpStatusCode(404);
         $response->setSuccess(false);
         $response->addMessage('No task found after update');
         $response->send();
         exit();
       }

       $taskArray = array();

       while($row = $query->fetch(PDO::FETCH_ASSOC)){

         $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);
         $taskArray[] = $task->returnTaskAsArray();
       }

       $returnData = array();
       $returnData['rows_returned'] = $rowcount;
       $returnData['tasks'] = $taskArray;

       $response = new Response();
       $response->setHttpStatusCode(200);
       $response->setSuccess(true);
       $response->addMessage('Task updated');
       $response->setData($returnData);
       $response->send();
       exit();

    }
    catch(TASKException $ex){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit();
    }
    catch(PDOException $ex){
      error_log('Database query error - '.$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage('Failed to update task. Check your data for errors');
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
elseif(array_key_exists('completed', $_GET)){
    $completed = $_GET['completed'];

    if($completed !== 'Y' && $completed !== 'N'){

      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage('Completed filter must be Y or N');
      $response->send();
      exit();
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){

      try{
        $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed
                                   FROM rest_tasks
                                   WHERE completed = :completed
                                   AND user_id = :userid');
        $query->bindParam(':completed', $completed, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowcount = $query->rowCount();
        $taskArray = array();

        while($row =$query->fetch(PDO::FETCH_ASSOC)){
          $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'] );
          $taskArray[] = $task->returnTaskAsArray();
        }

        $returnData = array();
        $returnData['rows_returned'] =$rowcount;
        $returnData['tasks'] = $taskArray;

        $response = new Response();
        $response->setHttpStatusCode(200);
        $response->setSuccess(true);
        $response->toCache(true);
        $response->setData($returnData);
        $response->send();
        exit();
      }
      catch(TASKException $ex){
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage($ex->getMessage());
        $response->send();
        exit();
      }
      catch(PDOException $ex){
        error_log('Database query error - '.$ex, 0);
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage('Failed to get tasks');
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
elseif(array_key_exists('page', $_GET)){
  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    $page = $_GET['page'];

    if($page == '' || !is_numeric($page)){
      $response = new Response();
      $response->setHttpStatusCode(400);
      $response->setSuccess(false);
      $response->addMessage('Page number cannot be blank and must be numeric');
      $response->send();
      exit();
    }

    $limitperpage = 20;

    try{
      $query = $readDB->prepare('SELECT COUNT(id) AS totalNoOfTasks
                                 FROM rest_tasks
                                 WHERE user_id = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $row = $query->fetch(PDO::FETCH_ASSOC);
      $tasksCount = intval($row['totalNoOfTasks']);
      $numOfPages = ceil($tasksCount/$limitperpage);

      if($numOfPages == 0){
        $numOfPages = 1;
      }
      if($page > $numOfPages || $page == 0){
        $response = new Response();
        $response->setHttpStatusCode(404);
        $response->setSuccess(false);
        $response->addMessage('Page not found');
        $response->send();
        exit();
      }

      $offset = ($page == 1 ? 0 : (20*($page-1)));

      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i"), completed
                                 FROM rest_tasks
                                 WHERE user_id = :userid
                                 LIMIT :pglimit
                                 OFFSET :offset');

      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->bindParam(':pglimit', $limitperpage, PDO::PARAM_INT);
      $query->bindParam(':offset', $offset, PDO::PARAM_INT);
      $query->execute();

      $rowcount = $query->rowCount();
      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
        $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed']);

        $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData = array();
      $returnData['rows_returned'] = $rowcount;
      $returnData['total_rows'] = $tasksCount;
      $returnData['total_pages'] = $numOfPages;
      ($page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false);
      ($page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false);
      $returnData['tasks'] = $taskArray;

      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit();

    }
    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit();
    }
    catch(PDOException $ex){
      error_log('Database query error -'.$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage('Failed to get tasks');
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
elseif(empty($_GET)){

  if($_SERVER['REQUEST_METHOD'] === 'GET'){
    try{

      $query = $readDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed
                                 FROM rest_tasks
                                 WHERE user_id = :userid');
      $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
      $query->execute();

      $rowcount = $query->rowCount();
      $taskArray = array();

      while($row = $query->fetch(PDO::FETCH_ASSOC)){
          $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed'] );
          $taskArray[] = $task->returnTaskAsArray();
      }

      $returnData =array();
      $returnData['rows_returned'] = $rowcount;
      $returnData['tasks'] = $taskArray;

      $response = new Response();
      $response->setHttpStatusCode(200);
      $response->setSuccess(true);
      $response->toCache(true);
      $response->setData($returnData);
      $response->send();
      exit();

      }

    catch(TaskException $ex){
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage($ex->getMessage());
      $response->send();
      exit();
    }
    catch(PDOException $ex){
      error_log('Database query error - '.$ex, 0);
      $response = new Response();
      $response->setHttpStatusCode(500);
      $response->setSuccess(false);
      $response->addMessage('Failed to get tasks');
      $response->send();
      exit();
    }


  }elseif($_SERVER['REQUEST_METHOD'] === 'POST'){

        try{
          if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage('Content type is not set to json');
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

          if(!isset($jsondata->title) || !isset($jsondata->completed)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            (!isset($jsondata->title) ? $response->addMessage('Title field is mandatory and must be provided') : false);
            (!isset($jsondata->completed) ? $response->addMessage('Completed field is mandatory and must be provided') : false);
            $response->send();
            exit();
          }

          $newTask = new Task(null, $jsondata->title, (isset($jsondata->description) ? $jsondata->description : null), (isset($jsondata->deadline) ? $jsondata->deadline : null), $jsondata->completed );
          $title = $newTask->getTitle();
          $description = $newTask->getDescription();
          $deadline = $newTask->getDeadline();
          $completed = $newTask->getCompleted();

          $query = $writeDB->prepare('INSERT INTO rest_tasks (title, description, deadline, completed, user_id)
                                      VALUES (:title, :description, STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), :completed, :userid)');
          $query->bindParam('title', $title, PDO::PARAM_STR);
          $query->bindParam('description', $description, PDO::PARAM_STR);
          $query->bindParam('deadline', $deadline, PDO::PARAM_STR);
          $query->bindParam('completed', $completed, PDO::PARAM_STR);
          $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
          $query->execute();

          $rowcount = $query->rowCount();

          if($rowcount === 0){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage('Failed to create task');
            $response->send();
            exit();
          }

        $lastTaskID = $writeDB->lastInsertID();

        $query = $writeDB->prepare('SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") as deadline, completed
                                    FROM rest_tasks
                                    WHERE id = :taskid
                                    AND user_id = :userid');
        $query->bindParam(':taskid', $lastTaskID, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowcount = $query->rowCount();

        if($rowcount === 0){
          $response = new Response();
          $response->setHttpStatusCode(500);
          $response->setSuccess(false);
          $response->addMessage('Failed to retrieve task after creation');
          $response->send();
          exit();
        }

        $taskArray = array();

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
          $task = new Task($row['id'], $row['title'], $row['description'], $row['deadline'], $row['completed']);

          $taskArray[] = $task->returnTaskAsArray();
        }

        $returnData = array();
        $returnData['rows_returned'] = $rowcount;
        $returnData['tasks'] = $taskArray;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->addMessage('Task created');
        $response->setData($returnData);
        $response->send();
        exit();
        }
        catch(TaskException $ex){
          $response = new Response();
          $response->setHttpStatusCode(500);
          $response->setSuccess(false);
          $response->addMessage($ex->getMessage());
          $response->send();
          exit();
        }
        catch(PDOException $ex){
          error_log('Database query error - '.$ex, 0);
          $response = new Response();
          $response->setHttpStatusCode(500);
          $response->setSuccess(false);
          $response->addMessage('Failed to get tasks');
          $response->send();
          exit();
        }




  }else{
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage('Request method not allowed');
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
