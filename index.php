<?php
/** Konfiguration  */
$sso_str='https://'.$_SERVER['HTTP_HOST'].''; // ohne "/" am Ende (url zum nas)
$sso_red='https://'.$_SERVER['HTTP_HOST'].'/'; // so wie erfasst bei SSO-Server (redirect)
$sso_app="013675635df2f5dcaab6e20fb1c87d89"; // key vom nas

$myBaseLink = 'https://'.$_SERVER['HTTP_HOST'].'/';

$ds = DIRECTORY_SEPARATOR;
$targetPath = dirname( __FILE__ ) . "{$ds}uploads{$ds}";
$uploadPath = null;

$foldertokens = [];
$downloadtokens = [];

if(file_exists("./config.php")) require_once("./config.php");
if(file_exists($targetPath.$ds.'foldertokens.php')) require_once($targetPath.$ds.'foldertokens.php');
if(file_exists($targetPath.$ds.'downloadtokens.php')) require_once($targetPath.$ds.'downloadtokens.php');

// kein Zeit-Limit
set_time_limit(0); 
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
if(isset($_POST['csrf_token'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) {returnResponse("csrf_token invalid", "error", 403); }

// Direkter Page-Aufruf ohne csrf_token? Nein.
if(isset($_POST['page'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) {returnResponse("csrf_token invalid", "error", 403); }

//Page: sso, nichts, upload, concat, folder-zeugs
if(!in_array($_POST['page'], ['sso', 'sso-logout', 'upload', 'concat', 'create_uploadFolder', 'create_uploadlink', 'create_downloadlink']) && !empty($_POST['page'])) {returnResponse("page not found", "error", 404); }
$_Page = $_POST['page'];

//SSO Access-Token
if($_Page == 'sso-logout') unset($_SESSION["sso_accesstoken"]);
if(isset($_SESSION["sso_accesstoken"])) $sso_accesstoken = $_SESSION["sso_accesstoken"]; 
if(!isset($_POST['page']) && isset($_POST['sso_accesstoken'])){returnResponse("sso not found", "error", 404); }
elseif(isset($_POST['sso_accesstoken'])) $sso_accesstoken=$_POST['sso_accesstoken'];

$foldertoken = '';
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

if(isset($_POST['foldertoken']) && !empty($_POST['foldertoken'])) $_GET['foldertoken'] = $_POST['foldertoken'];
if(isset($_GET['foldertoken']) && !empty($_GET['foldertoken'])){
  
  if(isset($foldertokens[$_GET['foldertoken']])) {
    $foldertoken = $foldertokens[$_GET['foldertoken']];
    $folderhash = $_GET['foldertoken'];
    $uploadPath = realpath($foldertoken).$ds;
  }
  else  returnResponse("token invalid", "error", 403); 
  
  if(empty($foldertoken)) returnResponse("token invalid", "error", 403);
}

// Upload i.O. falls eingelogg ODER FolderUpload-Token ist i.O.
if(!$_allowUpload && $_USER !== NULL) $_allowUpload = true;
elseif(!empty($foldertoken))  $_allowUpload = true;

$cd = '';

if(isset($_POST['cd']) && !empty($_POST['cd']) && $_allowUpload) $_GET['cd'] = $_POST['cd'];
if(isset($_GET['cd']) && !empty($_GET['cd']) && $_allowUpload){ 
 $tmp = realpath($targetPath.$_GET['cd'].$ds).$ds;
 if(!empty($foldertoken)) $tmp = realpath($foldertoken.$_GET['cd'].$ds).$ds;
 //die($foldertoken);

 if(!empty($foldertoken) && strpos($tmp, $foldertoken) === false) returnResponse("token ft invalid", "error", 403);
 if(!empty($uploadPath)  && strpos($tmp, $uploadPath) === false)  returnResponse("token up invalid", "error", 403);
 if(file_exists($tmp)) {
   $cd = $_GET['cd'];
   $uploadPath = $tmp;   
 }else returnResponse("cd invalid", "error", 403);
}

if(empty($uploadPath) && $_USER !== NULL) $uploadPath = $targetPath;


if(isset($_GET['downloadtoken'])){
  if(!array_key_exists($_GET['downloadtoken'], $downloadtokens)) returnResponse("downloadtoken invalid", 404);
  $file = $downloadtokens[$_GET['downloadtoken']];
  if(!file_exists($file)) returnResponse("downloadtoken invalid", 404);
  
  header('Content-Description: File Transfer');
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.basename($file).'"');
  header('Expires: 0');
  header('Cache-Control: must-revalidate');
  header('Pragma: public');
  header('Content-Length: ' . filesize($file));
  readfile($file);
  exit;
}


if($_Page == 'create_uploadlink'){
 if(!empty($_POST['folder']) && $_USER != NULL){
   $tmp = realpath($targetPath.$_POST['folder'].$ds);
   if(is_dir($tmp)){
    
    $key = array_search($tmp, $foldertokens);
    if($key  === false){      
      while($key === false || array_key_exists($key, $foldertokens)) $key = bin2hex(random_bytes(16));
      $foldertokens[$key] = $tmp;
      $txt = '<?php $foldertokens = [';
      foreach($foldertokens as $k=>$v) if(is_dir($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
      $txt .=']; ?>';
      file_put_contents($targetPath.$ds.'foldertokens.php', $txt);           
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
     
     $key = array_search($tmp, $downloadtokens);
     if($key  === false){      
       while($key === false || array_key_exists($key, $downloadtokens)) $key = bin2hex(random_bytes(16));
       $downloadtokens[$key] = $tmp;
       $txt = '<?php $downloadtokens = [';
       foreach($downloadtokens as $k=>$v) if(is_file($v) && file_exists($v)) $txt .= "'$k'=>'$v',";
       $txt .=']; ?>';
       file_put_contents($targetPath.$ds.'downloadtokens.php', $txt);           
     }
 
     die($key);
    }
   
  }
  returnResponse("Keine Berechtigung", "error", 403);
 }

if($_Page == 'create_uploadFolder' && $_allowUpload){
  $foldername = $_POST['folder'];
  $tmp = $uploadPath.$foldername.$ds;
  if(!empty($foldertoken) && strpos($tmp, $foldertoken) === false) returnResponse("token ft invalid", "error", 403);
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
      // copy chunk
      $chunk = file_get_contents($temp_file_path);
      if ( empty($chunk) ) returnResponse("Error: chunk empty", 415);
      // add chunk to main file
      file_put_contents("{$uploadPath}{$fileType}", $chunk, FILE_APPEND | LOCK_EX);
      // delete chunk
      unlink($temp_file_path);
      if ( file_exists($temp_file_path) ) returnResponse("error: temp not deleted", 415);
    }

  exit;
}

if(isset($_GET['delete']) && !empty($_GET['delete']) && $_allowUpload){
  $tmp = realpath($uploadPath.$ds.$_GET['delete']);  
  if(!empty($foldertoken) && strpos($tmp, $foldertoken) === false) returnResponse("token ft invalid", "error", 403);
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

  <title>file transfer | nacht-adriano</title>

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
  <h1>simple web file transfer | <a href="<?php echo $myBaseLink;?>">`'sig kreativ. jetzt.'`</a></h1>  
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
      if(!empty($folderhash)) $myBaseLink2 = $myBaseLink.'?foldertoken='.$folderhash;
      else $myBaseLink2 = $myBaseLink.'?';
      $dirs = $uploadPath;
      //if(!empty($foldertoken)) $dirs = $foldertoken;
      if(!empty($foldertoken)) $dirs = str_replace(realpath($foldertoken.$ds.''), '', $dirs);
      $dirs = str_replace($targetPath, '', $dirs);
      //echo ':'.realpath($foldertoken.'/../');
      //die($dirs);
      $dirs = explode($ds, $dirs);
      $tmp = '/';
      echo '<a href="'.$myBaseLink2.'">[Root]</a> / ';
      

      for($i = 0; $i < count($dirs); $i++){
        $fldr = $dirs[$i];      
        if($i == 0 && realpath($uploadPath.$ds) == realpath($foldertoken.$ds)) continue;
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
      <input type="hidden" name="foldertoken" value="<?php echo $folderhash;?>">
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
      Ordner <input type="text" name="folder" value="<?php echo $_USER['user_name'];?>">
      <input type="submit" value="erstellä">
      </form>     
           
            <table id="myTable">
              
            <thead>
            <tr>
                <th>Namä</th>
                <th class="right">Grössi</th>
                <th class="right">Datum</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php 
    
    $folders = [];
    $dateien = [];
    $repl = $targetPath;
    if(!empty($foldertoken)) $repl = $foldertoken;
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
      echo '<tr>
              <td><a href="?foldertoken='.$folderhash.'&cd='.$fileinfo[3].'">'.$fileinfo[0].'</a></td>
              <td class="right">Ordner</td>
              <td class="right">'.date("d.m.Y H:i:s", $fileinfo[2]).'</td>';
      echo ($_USER == NULL)? '<td><a href="'.$myBaseLink2 .'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a></td>':'<td><a href="'.$myBaseLink2 .'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a> | <a data-address="'.$fileinfo[3].'" class="share-folder" href="#">[Uploadlink]</a> </td>';
      echo '</tr>';
    }


usort($dateien, function($a, $b) {
  return strcasecmp((string)trim($a[0]), (string)trim($b[0]));
  }
);

    foreach($dateien as $fileinfo){
      echo '<tr>
              <td>'.$fileinfo[0].'</td>
              <td class="right">'.$fileinfo[1].' bytes</td>
              <td class="right">'.date("d.m.Y H:i:s", $fileinfo[2]).'</td>';
      echo ($_USER == NULL)? '<td><a href="'.$myBaseLink2.'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a></td>':'<td><a href="'.$myBaseLink2.'&cd='.dirname($fileinfo[3]).'&delete='.basename($fileinfo[3]).'">[löschen]</a> | <a data-address="'.$fileinfo[3].'" class="download-file" href="#">[Downloadlink]</a> </td>';
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

        Dropzone.autoDiscover = false;

        var myDropzone = new Dropzone("#image-upload",   {
        //TuttiQuanti - Filtermöglichkeiten => acceptedFiles: "image/*,application/pdf,.doc,.docx,.xls,.xlsx,.csv,.tsv,.ppt,.pptx,.pages,.odt,.rtf,.heif,.hevc",
        maxFilesize: 10240, // megabytes
        timeout: 0, // kein Timeout
        parallelUploads: 10, // nötzt nüd, so schadets höffetli au nüd
        chunking: true,      // jo, mer wänd euses File ufteile
        forceChunking: true, // jo, au au denne teile wenn chli gnueg wäri (so lauft's serverseitig de immer glich)
        parallelChunkUploads: true, // teili parallel ufelade
        chunkSize: 1000000*<?php echo str_replace("M", "", ini_get('upload_max_filesize')); ?>-100,  // size 1'000'000 bytes (~1MB)
        retryChunks: true,   // retry chunks on failure
        retryChunksLimit: 3, // retry maximum of 3 times (default is 3)
        addRemoveLinks: true, // 
        dictDefaultMessage: "<h2>Dröck do druf - oder zieh s'zügs eifach do ine</h2>",
        chunksUploaded: function(file, done) {
          // All chunks have been uploaded. Perform any other actions
          let currentFile = file;
          var xhr = new XMLHttpRequest();
          xhr.open('POST', '/', true);
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
          xhr.send("page=concat&csrf_token=<?php echo $_CSRFTOKEN;?>&foldertoken=<?php echo $folderhash;?>&cd=<?php echo $cd;?>&dzuuid=" + currentFile.upload.uuid + "&dztotalchunkcount=" + currentFile.upload.totalChunkCount + "&fullpath=" + currentFile.fullPath + "&dzfilename=" + currentFile.name); //currentFile.name.substr( (currentFile.name.lastIndexOf('.') +1) )
        },

        error: function (file, response) { alert(response);},
        init: function() {
                this.on("sending", function(file, xhr, data) {
                    if(file.fullPath){
                        data.append("fullPath", file.fullPath);
                    }
                });
                this.on("queuecomplete", function(files, response) {
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
                              let lnk = host+'?foldertoken='+response;
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
                              let lnk = host+'?downloadtoken='+response;
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
if(!empty($_GET['delete'])){
  echo '
  var URL = location.href.split("?");
  var params = URL[1].split("&");
  URL[0] = URL[0] + "?";
  for(var item in params) {
    if(!params[item].startsWith("delete=")) URL[0] = URL[0] + "&" + params[item];
  }
  //alert(URL[0]);
  window.history.pushState(\'object\', document.title, URL[0]);
  ';
}
?>
</script>

</body>
</html>