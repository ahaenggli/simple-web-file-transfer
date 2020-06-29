<?php
// Melde alle PHP Fehler (siehe Changelog)
//error_reporting(E_ALL);
/** Konfiguration  */
$sso_str='https://'.$_SERVER['HTTP_HOST'].''; // ohne "/" am Ende (url zum nas)
$sso_red='https://'.$_SERVER['HTTP_HOST'].'/'; // so wie erfasst bei SSO-Server (redirect)
$sso_app="013675635df2f5dcaab6e20fb1c87d89"; // key vom nas

$myBaseLink = 'https://'.$_SERVER['HTTP_HOST'].'/';

$page_title = 'simple web file transfer';
$page_slogan = '`\'refresh\'`';
$page_dict =  '<h2>Drag & Drop - oder hier klicken</h2>';

$ds = DIRECTORY_SEPARATOR;
$targetPath = dirname( __FILE__ ) . "{$ds}uploads{$ds}";
$uploadPath = null;

$uploadlinks = [];
$downloadlinks = [];

if(file_exists("./config.php")) require_once("./config.php");
if(file_exists('./uploadlinks.php')) require('./uploadlinks.php');
if(file_exists('./downloadlinks.php')) require('./downloadlinks.php');

// kein Zeit-Limit
//ignore_user_abort(true);
set_time_limit(0);

header("Expires: 0");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$dir = new DirectoryIterator($targetPath);
/** globale funktionen */
function httpGet ($url){
  $ch=curl_init();
  curl_setopt($ch, CURLOPT_URL,$url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER,true);
  curl_setopt($ch, CURLOPT_HEADER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST,false);//for testing,ignore checking CA
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER,false);
  $output=curl_exec($ch);
  curl_close($ch);
  return $output;
}

function returnResponse($info = null, $status = "error", $cd = 404) {
  http_response_code($cd);
  die (json_encode(["status" => $status,"info" => $info]));
};

// Session ab jetzt
session_start();

// CSRF-Token generieren falls inexistent
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF Token
$_CSRFTOKEN = $_SESSION['csrf_token'];
$_USER = NULL;
$_allowUpload = false;

// Einfach aber effektiv
if(isset($_GET['csrf_token']) && !isset($_POST['csrf_token'])) $_POST['csrf_token']  = $_GET['csrf_token'];
if(isset($_POST['csrf_token'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) returnResponse("csrf_token invalid 1", "error", 403); 
if(isset($_GET['page']) && !isset($_POST['page'])) $_POST['page']  = $_GET['page'];
// Direkter Page-Aufruf ohne csrf_token? Nein.
if(isset($_POST['page'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) returnResponse("csrf_token invalid ".$_CSRFTOKEN, "error", 403); 

//Page: sso, nichts, upload, concat, folder-zeugs
if(!in_array($_POST['page'], ['sso', 'sso-logout', 'upload', 'concat', 'create_uploadFolder', 'create_uploadlink', 'create_downloadlink', 'remove_uploadlink', 'remove_downloadlink']) && !empty($_POST['page'])) returnResponse("page not found", "error", 404); 
$_Page = $_POST['page'];

//SSO Access-Token
if($_Page == 'sso-logout') unset($_SESSION["sso_accesstoken"]);
if(isset($_SESSION["sso_accesstoken"])) $sso_accesstoken = $_SESSION["sso_accesstoken"]; 
if(!isset($_POST['page']) && isset($_POST['sso_accesstoken'])){returnResponse("sso not found", "error", 404); }
elseif(isset($_POST['sso_accesstoken'])) $sso_accesstoken=$_POST['sso_accesstoken'];

$uploadlink = '';
$folderhash  = '';

if(isset($sso_accesstoken)){
  //var_dump($sso_accesstoken);

  $resp= httpGet(filter_var($sso_str."/webman/sso/SSOAccessToken.cgi?action=exchange&access_token=".$sso_accesstoken, FILTER_SANITIZE_URL));
  $json_resp= json_decode($resp, true);
  if($json_resp["success"]==true){
    $_USER = $json_resp["data"];
    $_SESSION["sso_accesstoken"] = $sso_accesstoken;      
    if($_Page == 'sso'){ http_response_code(200); echo json_encode($_USER); exit;} 
  }else {
    unset($_SESSION["sso_accesstoken"]);
    unset($_POST["sso_accesstoken"]);   
  }       
}

// Hätte vorher abgehandelt werden müssen...
if($_Page == 'sso') returnResponse("sso invalid", "error", 403); 

if(isset($_POST['uploadlink']) && !empty($_POST['uploadlink'])) $_GET['uploadlink'] = $_POST['uploadlink'];
if(isset($_GET['uploadlink']) && !empty($_GET['uploadlink'])){
  
  if(isset($uploadlinks[$_GET['uploadlink']])) {
    $uploadlink = $uploadlinks[$_GET['uploadlink']];
    $folderhash = $_GET['uploadlink'];
    $uploadPath = realpath($uploadlink).$ds;
  }
  else  returnResponse("token invalid", "error", 403); 
  
  if(empty($uploadlink)) returnResponse("token invalid", "error", 403);
}

// Upload i.O. falls eingelogg ODER FolderUpload-Token ist i.O.
if(!$_allowUpload && $_USER !== NULL) $_allowUpload = true;
elseif(!empty($uploadlink))  $_allowUpload = true;

$cd = '';

if(isset($_POST['cd']) && !empty($_POST['cd']) && $_allowUpload) $_GET['cd'] = $_POST['cd'];
if(isset($_GET['cd']) && !empty($_GET['cd']) && $_allowUpload){ 
 $tmp = realpath($targetPath.$_GET['cd'].$ds).$ds;
 if(!empty($uploadlink)) $tmp = realpath($uploadlink.$_GET['cd'].$ds).$ds;
 //die($uploadlink);

 if(!empty($uploadlink) && strpos($tmp, $uploadlink) === false) returnResponse("token ft invalid", "error", 403);
 if(!empty($uploadPath)  && strpos($tmp, $uploadPath) === false)  returnResponse("token up invalid", "error", 403);
 if(file_exists($tmp)) {
   $cd = $_GET['cd'];
   $uploadPath = $tmp;   
 }else returnResponse("cd invalid", "error", 403);
}

if(empty($uploadPath) && $_USER !== NULL) $uploadPath = $targetPath;


if(isset($_GET['download'])){
  if(!array_key_exists($_GET['download'], $downloadlinks)) returnResponse("downloadlink invalid", 404);
  $file = $downloadlinks[$_GET['download']];
  if(!file_exists($file)) returnResponse("downloadlink invalid", 404);
  
  // damit kein out of memory kommt
  $memory_limit = ini_get('memory_limit');
  if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {    
      if ($matches[2] == 'M') {
          $memory_limit = $matches[1]*1024*1024; // nnnM -> nnn MB          
          //die(var_dump($memory_limit));
      } else if ($matches[2] == 'K') {
          $memory_limit = $matches[1] * 1024; // nnnK -> nnn KB
      }
  }
  
  //die(var_dump($memory_limit));
  $chunk_size = $memory_limit*0.8;
  if($memory_limit < 0) $chunk_size = $filesize;
  //exit;
  if (ob_get_level()) ob_end_clean();
  $filesize = filesize($file);

  
  $offset = 0;
  $length = $filesize;
  
  if ( isset($_SERVER['HTTP_RANGE']) ) {
      // if the HTTP_RANGE header is set we're dealing with partial content  
      $partialContent = true;  
      // find the requested range
      // this might be too simplistic, apparently the client can request
      // multiple ranges, which can become pretty complex, so ignore it for now
      preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $matches);  
      $offset = intval($matches[1]);
      $length = (($matches[2]) ? intval($matches[2]) : $filesize) - $offset;
  } else {
      $partialContent = false;
      header('HTTP/1.1 200 OK');
  }
   
  if ( $partialContent ) {
      // output the right headers for partial content 
      header('HTTP/1.1 206 Partial Content');  
      header('Content-Range: bytes ' . $offset . '-' . ($offset + $length -1 ) . '/' . $filesize);
  }
  
  // output the regular HTTP headers
  header('Content-Description: File Transfer');
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.basename($file).'"');
  header('Accept-Ranges: bytes');
  
  if ( $partialContent ) {
  // don't forget to send the data too
  $file = fopen($file, 'r'); 
  // seek to the requested offset, this is 0 if it's not a partial content request
  fseek($file, $offset);  

  //echo fread($file, $length);

  while ($length > 0)
  {
      if($chunk_size >= $length) $chunk_size = $length;
      echo fread($file, $chunk_size);
      $chunk_size -= $length;      
  }
  fclose($file);
} else readfile($file);

  exit;
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . $filesize);
  
  exit;
}


if($_Page == 'create_uploadlink'){
 if(!empty($_POST['folder']) && $_USER != NULL){
   $tmp = realpath($targetPath.$_POST['folder'].$ds);
   if(is_dir($tmp)){
   if(file_exists('./uploadlinks.php')) include('./uploadlinks.php');
    $key = array_search($tmp, $uploadlinks);
    if($key  === false){      
      while($key === false || array_key_exists($key, $uploadlinks)) $key = bin2hex(random_bytes(16));
      $uploadlinks[$key] = $tmp;
      $txt = '<?php $uploadlinks = [';
      foreach($uploadlinks as $k=>$v) if(is_dir($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
      $txt .=']; ?>';
      file_put_contents('./uploadlinks.php', $txt,  LOCK_EX);           
    }

    die($key);
   }
  
 }
 returnResponse("Keine Berechtigung", "error", 403);
}

if($_Page == 'create_downloadlink'){
  if(!empty($_POST['file']) && $_USER != NULL){
    
    $tmp = realpath($targetPath.$_POST['file']);
    //die($tmp);
    if(is_file($tmp)){
    if(file_exists('./downloadlinks.php')) include('./downloadlinks.php');
     $key = array_search($tmp, $downloadlinks);
     if($key  === false){      
       while($key === false || array_key_exists($key, $downloadlinks)) $key = bin2hex(random_bytes(16));
       $downloadlinks[$key] = $tmp;
       $txt = '<?php $downloadlinks = [';
       foreach($downloadlinks as $k=>$v) if(is_file($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
       $txt .=']; ?>';
       file_put_contents('./downloadlinks.php', $txt);           
     }
 
     die($key);
    }
   
  }
  returnResponse("Keine Berechtigung", "error", 403);
 }

 
if(!empty($_GET['remove_uploadlink']) && $_USER != NULL){    
    $key = $_GET['remove_uploadlink'];
    if(file_exists('./uploadlinks.php')) include('./uploadlinks.php');
    if(!array_key_exists($key, $uploadlinks)) {}//returnResponse($key." invalid", 404);
    else{
      unset($uploadlinks[$key]);      
      //var_dump($uploadlinks);      
      $txt = '<?php $uploadlinks = [';
      foreach($uploadlinks as $k=>$v) if(is_dir($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
      $txt .=']; ?>';
      file_put_contents('./uploadlinks.php', $txt,  LOCK_EX);               
      //var_dump($txt);
      //die();
    }            
    //die();
}

if(!empty($_GET['remove_downloadlink']) && $_USER != NULL){    
  $key = $_GET['remove_downloadlink'];
  if(file_exists('./downloadlinks.php')) include('./downloadlinks.php');
  if(!array_key_exists($key, $downloadlinks)) {}//returnResponse($key." invalid", 404);
  else{
    unset($downloadlinks[$key]);            
    $txt = '<?php $downloadlinks = [';
    foreach($downloadlinks as $k=>$v) if(is_file($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
    $txt .=']; ?>';
    file_put_contents('./downloadlinks.php', $txt,  LOCK_EX);               
  }            
}


if($_Page == 'create_uploadFolder' && $_allowUpload){
  $foldername = $_POST['folder'];
  $tmp = $uploadPath.$foldername.$ds;
  if(!empty($uploadlink) && strpos($tmp, $uploadlink) === false) returnResponse("token ft invalid", "error", 403);
  if(!empty($uploadPath)  && strpos($tmp, $uploadPath) === false)  returnResponse("token up invalid", "error", 403);
  if(!file_exists($tmp)) mkdir($tmp, 0644);
}

// Upload-Page
if($_Page == 'upload'){  
  if(!$_allowUpload) returnResponse("allowUpload invalid", "error", 403);
  // chunk variables
  $fileId = $_POST['dzuuid'];
  $chunkIndex = $_POST['dzchunkindex'] + 1;
  $chunkTotal = $_POST['dztotalchunkcount'];
  if($chunkIndex > $chunkTotal) returnResponse(null, "An error occurred", 415);
  // file path variables
  $fileType = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  $fileSize = $_FILES["file"]["size"];
  $filename = "{$fileId}-{$chunkIndex}.tmp";

  $targetFile = $uploadPath . $filename;
  
  //$returnResponse("nok");
  move_uploaded_file($_FILES['file']['tmp_name'], $targetFile);

  // Be sure that the file has been uploaded
  if (!file_exists($targetFile) ) returnResponse(null, "An error occurred", 415);

  // Read and write for owner, read for everybody else
  chmod($targetFile, 0644) or returnResponse(null, "Error: could not set permissions", 415);

  returnResponse(null, "success", 200);
}

// Nach Upload der Chunks -> Konkateniere diese in richtiger Reihenfolge
if($_Page == 'concat'){
    if(!$_allowUpload) returnResponse("allowUpload invalid", "error", 403);
      // get variables
    $fileId = $_POST['dzuuid'];
    $chunkTotal = $_POST['dztotalchunkcount'];
    $fullpath = $_POST['fullpath'];
    $fullpath = str_replace('/', $ds, $fullpath);
    $fullpath = str_replace('\\', $ds, $fullpath);
    $fullpath = str_replace($ds.$ds, $ds, $fullpath);
    //$fullpath = str_replace($ds, '_', $fullpath);
    // file path variables
    $fileType = $_POST['dzfilename'];
    if($fileType == $fullpath) $fullpath = null;
    if($fullpath != 'undefined' && !empty($fullpath)) {
      $fileType = $fullpath;//.$ds.$fileType;
      mkdir(dirname($uploadPath.$ds.$fileType), 0777, true);
    }

    // Teile durchgehen und zusammensetzen
    for ($i = 1; $i <= $chunkTotal; $i++) {
      // target temp file
      $temp_file_path = realpath("{$uploadPath}{$fileId}-{$i}.tmp") or returnResponse("error: chunk lost.",415);
      
      $chunk_size = 10*1024*1024;

      $handle = fopen($temp_file_path, "r");
      while (!feof($handle))
        {
            $chunk = fread($handle,$chunk_size);
            file_put_contents("{$uploadPath}{$fileType}", $chunk, FILE_APPEND | LOCK_EX);
        }

        fclose($handle);


      // copy chunk
      //$chunk = file_get_contents($temp_file_path);
      //if ( empty($chunk) ) returnResponse("Error: chunk empty", 415);
      // add chunk to main file
      //file_put_contents("{$uploadPath}{$fileType}", $chunk, FILE_APPEND | LOCK_EX);


      // delete chunk
      unlink($temp_file_path);
      if ( file_exists($temp_file_path) ) returnResponse("error: temp not deleted", 415);
    }

  exit;
}

if(isset($_GET['delete']) && !empty($_GET['delete']) && $_allowUpload){
  $tmp = realpath($uploadPath.$ds.$_GET['delete']);  
  if(!empty($uploadlink) && strpos($tmp, $uploadlink) === false) returnResponse("token ft invalid", "error", 403);
  if(!empty($uploadPath)  && strpos($tmp, $uploadPath) === false)  returnResponse("token up invalid", "error", 403);
  if(is_file($tmp)) unlink($tmp);
  elseif(is_dir($tmp)){
     function delTree($dir) {
      $files = array_diff(scandir($dir), array('.','..'));
       foreach ($files as $file) {
         (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
       }
       return rmdir($dir);
     } 
     delTree($tmp);
  }
  else die($tmp);
}

?><!DOCTYPE html>
<html>
<head>  
  <meta charset="utf-8">  
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=10.0, user-scalable=yes">

  <title><?php echo $page_title;?></title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.7.0/min/dropzone.min.css">  
  <link rel="stylesheet" href="//cdn.datatables.net/1.10.21/css/jquery.dataTables.min.css">  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/babel-polyfill/7.8.7/polyfill.min.js"></script>
  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.7.0/min/dropzone.min.js"></script>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
  <script src="https://cdn.datatables.net/1.10.21/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.datatables.net/select/1.3.1/js/dataTables.select.min.js"></script>
  <style>.right {text-align: right;}</style>
</head>
<body>

<div class="jumbotron text-center">
  
  
  <h1>simple web file transfer | <a href="<?php echo $myBaseLink;?>"><?php echo $page_slogan;?></a></h1>  


  <div class="row form-signin" style="float:right;">
    <?php if($_USER === NULL) { ?>
    <!-- nicht eingeloggt -->
    <button id="login-button">SSO Login</button>
    <?php } ?>

    <?php if($_USER !== NULL) { ?>
    <!-- nicht eingeloggt -->
    <button id="logout-button">SSO Logout</button>
    <?php } ?>
  </div>
</div>  

<?php if($_allowUpload) { ?>
<div class="container">

<?php if((!empty($uploadPath))){   ?>

  <div class="row">
    <div class="col-md-12">
      <h3>
      
      <?php
      if(!empty($folderhash)) $myBaseLink2 = $myBaseLink.'?uploadlink='.$folderhash;
      else $myBaseLink2 = $myBaseLink.'?';
      $dirs = $uploadPath;
      //if(!empty($uploadlink)) $dirs = $uploadlink;
      if(!empty($uploadlink)) $dirs = str_replace(realpath($uploadlink.$ds.''), '', $dirs);
      $dirs = str_replace($targetPath, '', $dirs);
      //echo ':'.realpath($uploadlink.'/../');
      //die($dirs);
      $dirs = explode($ds, $dirs);
      $tmp = '/';
      echo '<a href="'.$myBaseLink2.'">[Root]</a> / ';
      

      for($i = 0; $i < count($dirs); $i++){
        $fldr = $dirs[$i];      
        if($i == 0 && realpath($uploadPath.$ds) == realpath($uploadlink.$ds)) continue;
        if(!empty($fldr)) {
          $tmp .= $fldr.'/';
          echo '<a href="'.$myBaseLink2.'&cd='.$tmp.'">'.$fldr.'</a> / ';
        }
      }

      ?>
      </h3>
      <form action="<?php echo $myBaseLink;?>" method="post" enctype="multipart/form-data" class="dropzone" id="image-upload">
      <input type="hidden" name="page" value="upload">
      <input type="hidden" name="csrf_token" value="<?php echo $_CSRFTOKEN;?>">
      <input type="hidden" name="uploadlink" value="<?php echo $folderhash;?>">
      <input type="hidden" name="cd" value="<?php echo $cd;?>">
      </form>
    </div>
  </div>
<?php } ?>

  <?php if($_USER !== NULL || $_allowUpload) {    
    $dir = new DirectoryIterator($uploadPath);
    ?>  <div class="row">
            <div class="col-md-12">  
           
            <form action="<?php echo $myBaseLink2;?>&cd=<?php echo $cd;?>" method="post">
      <input type="hidden" name="page" value="create_uploadFolder">
      <input type="hidden" name="csrf_token" value="<?php echo $_CSRFTOKEN;?>">
      Ordner <input type="text" name="folder" value="" placeholder="<?php echo $_USER['user_name'];?>">
      <input type="submit" value="erstellen">
      </form>     
           
            <table id="myTable">
              
            <thead>
            <tr>
                <th>Name</th>
                <th class="right">Grösse</th>
                <th class="right">Datum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php 
    
    $folders = [];
    $dateien = [];
    $repl = $targetPath;
    if(!empty($uploadlink)) $repl = $uploadlink;
    foreach ($dir as $fileinfo) {
        if ($fileinfo->isDir()) {          
              if(!$fileinfo->isDot()) $folders[] =  [$fileinfo->getFilename(), $fileinfo->getSize(), $fileinfo->getCTime(),             
              str_replace($repl, '', $fileinfo->getPathname())
            ];
        } else $dateien[] = [$fileinfo->getFilename(), $fileinfo->getSize(), $fileinfo->getCTime(), str_replace($repl, '', $fileinfo->getPathname())]; //echo '<tr><td>'.$fileinfo->getFilename().'</td><td>'.$fileinfo->getSize().' bytes</td></tr>';
    }

    usort($folders, function($a, $b) {
      return strcasecmp((string)trim($a[0]), (string)trim($b[0]));
      }
    );

    foreach($folders as $fileinfo){
      $key = (array_search($repl.$fileinfo[3], $uploadlinks));
      echo '<tr>
              <td><a href="?uploadlink='.$folderhash.'&cd='.$fileinfo[3].'">'.$fileinfo[0].'</a></td>
              <td class="right">Ordner</td>
              <td class="right">'.date("d.m.Y H:i:s", $fileinfo[2]).'</td>';
      echo ($_USER == NULL)? '<td><a href="'.$myBaseLink2 .'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a></td>'
                            :
                            '<td><a href="'.$myBaseLink2 .'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a> | 
                              <a data-address="'.$fileinfo[3].'" class="share-folder" href="#">[Uploadlink]</a> '.
                            (($key !== false)? '| <a href="'.$myBaseLink2 .'&cd='.dirname($fileinfo[3]).'&remove_uploadlink='.$key.'">[Link entfernen]</a> ':'')
                            .'</td>';
      echo '</tr>';
    }

  usort($dateien, function($a, $b) {
  return strcasecmp((string)trim($a[0]), (string)trim($b[0]));
  }
);

    foreach($dateien as $fileinfo){
      $key = (array_search($repl.$fileinfo[3], $downloadlinks));
      echo '<tr>
              <td>'.$fileinfo[0].'</td>
              <td class="right">'.$fileinfo[1].' bytes</td>
              <td class="right">'.date("d.m.Y H:i:s", $fileinfo[2]).'</td>';
      echo ($_USER == NULL)? '<td><a href="'.$myBaseLink2.'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a></td>'
                              :
                              '<td><a href="'.$myBaseLink2.'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a> | 
                                   <a data-address="'.$fileinfo[3].'" class="download-file" href="#">[Downloadlink]</a> '.
                              (($key !== false)? ' | <a href="'.$myBaseLink2.'&cd='.dirname($fileinfo[3]).'&remove_downloadlink='.$key.'">[Link entfernen]</a>':'')
                              .'</td>';
      echo '</tr>';
  }

    ?>
        </tbody>
    </table>
     
    <?PHP
    echo '</div></div>';
} ?>
</div>

<script type="text/javascript">


        var start = null;
        var size = 0;
        var anza = 0;
        var chunks = 0;
        var ende = null;        

        Dropzone.autoDiscover = false;

        var myDropzone = new Dropzone("#image-upload",   {
        //TuttiQuanti - Filtermöglichkeiten => acceptedFiles: "image/*,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.tsv,.ppt,.pptx,.pages,.odt,.rtf,.heif,.hevc",
        maxFilesize: 0, // kein Limit
        timeout: 0, // kein Timeout
        parallelUploads: 1, // nötzt nüd, so schadets höffetli au nüd
        chunking: true,      // jo, mer wänd euses File ufteile
        forceChunking: true, // jo, au au denne teile wenn chli gnueg wäri (so lauft's serverseitig de immer glich)
        parallelChunkUploads: true, // teili parallel ufelade
        chunkSize: 1000000*<?php echo str_replace("M", "", ini_get('upload_max_filesize')); ?>-100,  // size 1'000'000 bytes (~1MB)
        retryChunks: true,   // retry chunks on failure
        retryChunksLimit: 10, // retry maximum of 3 times (default is 3)
        addRemoveLinks: true, // 
        dictDefaultMessage: "<?php echo $page_dict;?>",
        chunksUploaded: function(file, done) {
          // All chunks have been uploaded. Perform any other actions
          let currentFile = file;
          var xhr = new XMLHttpRequest();
          xhr.open('POST', '/index.php', true);
          //Send the proper header information along with the request
          xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
          xhr.onload = function() {
              if (xhr.status === 200) {
                  //alert('User\'s name is ' + xhr.responseText);
                  done();
              }
              else {
                  alert('Request failed. Returned status of ' + xhr.status);
                  currentFile.accepted = false;                 
                  file.accepted = false;
                  file.status = Dropzone.ERROR;                  
                  Dropzone._errorProcessing([currentFile], xhr.responseText);
              }
          };
          xhr.send("page=concat&csrf_token=<?php echo $_CSRFTOKEN;?>&uploadlink=<?php echo $folderhash;?>&cd=<?php echo $cd;?>&dzuuid=" + currentFile.upload.uuid + "&dztotalchunkcount=" + currentFile.upload.totalChunkCount + "&fullpath=" + currentFile.fullPath + "&dzfilename=" + currentFile.name); //currentFile.name.substr( (currentFile.name.lastIndexOf('.') +1) )
        },

        error: function (file, response) { alert(response);},
        init: function() {

              this.on("addedfile", function(file, xhr, data) {
                    start = start || new Date().getTime();
                    size = size+file.size;                
                    anza++;  
                });

                this.on("sending", function(file, xhr, data) {
                    chunks++;  
                    if(file.fullPath){
                        data.append("fullPath", file.fullPath);                                                
                    }
                });

                this.on("queuecomplete", function() {
                   location.reload();                
                });

        },
        //success: function (file, response) {_this = this; setTimeout(function(){_this.removeFile(file);}, 3000);},
  }
);

// add paste event listener to the page
document.onpaste = function(event){
  var items = (event.clipboardData || event.originalEvent.clipboardData).items;
  for (index in items) {
    var item = items[index];
    if (item.kind === 'file') {
      // adds the file to your dropzone instance
      //alert("file");
      myDropzone.addFile(item.getAsFile())
    } //else alert(item);
  }
}

</script>
<?php } ?>

<!-- Synology-Login/LogOut --> 
<script type="text/javascript" src="<?php echo $sso_str;?>/webman/sso/synoSSO-1.0.0.js"></script>
<script>
$(document).ready( function () {
    $('#myTable').DataTable({
            "searching": false,
            "paging": false,
            "bInfo" : false,
            "ordering": false,
        });


        $('.share-folder').click(function () {          
          let str = $(this).attr('data-address');
          $.ajax ({ url : '/index.php' ,                          
                     cache: false,                                
                       type: 'POST',                              
                           data:{ 
                             page:'create_uploadlink',
                             folder:str,
                             csrf_token:"<?php echo $_CSRFTOKEN ; ?>"
                             },                           
                            error: function(xhr){                                         
                                      console.log(xhr.status);
                                      console.log(xhr.responseText);
                                      alert(xhr.responseText);
                             },                                 
                             success: function(response){   
                              var protocol = location.protocol;
                              var slashes = protocol.concat("//");
                              var host = slashes.concat(window.location.hostname);
                              let lnk = host+'?uploadlink='+response;
                              const el = document.createElement('textarea');
                              el.value = lnk;
                              el.setAttribute('readonly', '');
                              el.style.position = 'absolute';
                              el.style.left = '-9999px';
                              document.body.appendChild(el);
                              el.select();
                              document.execCommand('copy');
                              document.body.removeChild(el);
                              alert('Link ist nun in Zwischenablage:'+"\n"+lnk);
                              location.reload();        
                              }   });   
      
    });

    $('.download-file').click(function () {          
          let str = $(this).attr('data-address');
          $.ajax ({ url : '/index.php' ,                          
                     cache: false,                                
                       type: 'POST',                              
                           data:{ 
                             page:'create_downloadlink',
                             file:str,
                             csrf_token:"<?php echo $_CSRFTOKEN ; ?>"
                             },                           
                            error: function(xhr){                                         
                                      console.log(xhr.status);
                                      console.log(xhr.responseText);
                                      alert(xhr.responseText);
                             },                                 
                             success: function(response){   
                              var protocol = location.protocol;
                              var slashes = protocol.concat("//");
                              var host = slashes.concat(window.location.hostname);
                              let lnk = host+'?download='+response;
                              const el = document.createElement('textarea');
                              el.value = lnk;
                              el.setAttribute('readonly', '');
                              el.style.position = 'absolute';
                              el.style.left = '-9999px';
                              document.body.appendChild(el);
                              el.select();
                              document.execCommand('copy');
                              document.body.removeChild(el);
                              alert('Link ist nun in Zwischenablage:'+"\n"+lnk);
                              location.reload();        
                              }   });   
      
    });   
    });


  var doRed = false;

  SYNOSSO.init({          
     oauthserver_url:'<?php echo $sso_str;?>',          
     app_id:'<?php echo $sso_app;?>',         
     state:"<?php echo $_CSRFTOKEN ; ?>",    
     redirect_uri: '<?php echo $sso_red;?>',//redirect URI have to be the same as the one registered in SSO server, andshould be a plain text html file          
     callback: authCallback });
     
     function authCallback(response){          
       console.log("client side");
       if('not_login'=== response.status) { 
         //user notlogin                    
         console.log (response.status);
         }
         else if('login'=== response.status) 
         {                    
           console.log (response.status);                  
           console.log (response.access_token);
                     
           //alert("access token: "+response.access_token);                  
             $.ajax ({ url : '/index.php' ,                          
                     cache: false,                                
                       type: 'POST',                              
                           data:{ 
                             sso_accesstoken:response.access_token,
                             page:'sso',
                             csrf_token:"<?php echo $_CSRFTOKEN ; ?>"},                           
                              error: function(xhr){                                         
                                      console.log(xhr.status);
                                      console.log(xhr.responseText);
                                  },                                 
                                   success: function(response){     
                                      console.log(response);
                                      if(doRed) window.location.replace("/");
                                      
                                   }   });   
            } else {                    
             // alert("error");//deal with errors; 
            // console.log( response);
            }};

var myEventLogIn= function() {
    console.log("myEventLogIn");
    doRed = true;
    SYNOSSO.login();
};

var myEventLogOut= function() {
    console.log("myEventLogOut");
    SYNOSSO.logout();
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/', false);
    //Send the proper header information along with the request
    xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
    console.log(xhr.status);
     };
    xhr.send("page=sso-logout&csrf_token=<?php echo $_CSRFTOKEN;?>");
    // Simulate an HTTP redirect:
    window.location.replace("/");
};

var login_button = document.getElementById("login-button");        
if(login_button !== null) login_button.addEventListener('click' , myEventLogIn, false); 

var logout_button = document.getElementById("logout-button");        
if(logout_button !== null) logout_button.addEventListener('click' , myEventLogOut, false); 

<?php
if(!empty($_GET['delete']) || !empty($_GET['remove_uploadlink'])|| !empty($_GET['remove_downloadlink'])){
  echo '
  var URL = location.href.split("?");
  var params = URL[1].split("&");
  URL[0] = URL[0] + "?";
  for(var item in params) {
    if(!params[item].startsWith("delete=") && !params[item].startsWith("remove_")) URL[0] = URL[0] + "&" + params[item];
  }
  //alert(URL[0]);
  window.history.pushState(\'object\', document.title, URL[0]);
  ';
}
?>
</script>

</body>
</html>