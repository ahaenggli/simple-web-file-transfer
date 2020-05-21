<?php
/** Konfiguration  */
$sso_str='https://'.$_SERVER['HTTP_HOST'].''; // ohne "/" am Ende (url zum nas)
$sso_red='https://'.$_SERVER['HTTP_HOST'].'/'; // so wie erfasst bei SSO-Server (redirect)
$sso_app="013675635df2f5dcaab6e20fb1c87d89"; // key vom nas

$myBaseLink = 'https://'.$_SERVER['HTTP_HOST'].'/';

$ds = DIRECTORY_SEPARATOR;
$targetPath = dirname( __FILE__ ) . "{$ds}uploads{$ds}";
if(file_exists("./config.php")) require_once("./config.php");

/** ToDo's
 *  - Download von ./uploads/ ermöglichen
 *  - Files/Folder löschen 
 *  - Foldertoken erneuern (inhalt belassen)
 *  - File/Folderliste alphabetisch sortieren
 *  - $.ajax durch XMLHttpRequest ersetzen
 *  - OWASP Top 10 prüfen/absichern
 * 
 ** Doku
 * Eingeloggt ($_USER is not NULL):
 *  - Logout-Button
 *  - Mit Foldertoken: Upload in gewählten Ordner
 *  - Ohne Foldertoken: Upload direkt in ./uploads/ möglich
 *  - Ordner erstellen
 *  - Uploadlinks sehen
 *  - Downloadlinks sehen
 * 
 * Gast ($_USER is NULL):
 *  - Login-Button
 *  - Foldertoken: Upload in gewählten Ordner
 *  - ohne Token: kein Upload möglich
 * 
 * Download:
 *  - Nur via Downloadtoken möglich (egal ob Gast oder User)
 */

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
if(isset($_POST['csrf_token'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) {returnResponse("csrf_token invalid", "error", 403); exit;}

// Direkter Page-Aufruf ohne csrf_token? Nein.
if(isset($_POST['page'])  && !hash_equals($_CSRFTOKEN , $_POST['csrf_token'])) {returnResponse("csrf_token invalid", "error", 403); exit;}

//Page: sso, nichts, upload, concat, folder-zeugs
if(!in_array($_POST['page'], ['sso', 'sso-logout', 'upload', 'concat', 'create_uploadFolder']) && !empty($_POST['page'])) {returnResponse("page not found", "error", 404); exit;}
$_Page = $_POST['page'];

//SSO Access-Token
if($_Page == 'sso-logout') unset($_SESSION["sso_accesstoken"]);
if(isset($_SESSION["sso_accesstoken"])) $sso_accesstoken = $_SESSION["sso_accesstoken"]; 
if(!isset($_POST['page']) && isset($_POST['sso_accesstoken'])){returnResponse("sso not found", "error", 404); exit;}
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
    //var_dump($foldertoken );
  }else {
    unset($_SESSION["sso_accesstoken"]);
    unset($_POST["sso_accesstoken"]);   
  }     
  //var_dump($sso_accesstoken);
}

// Hätte vorher abgehandelt werden müssen...
if($_Page == 'sso') {returnResponse("sso invalid", "error", 403); exit;}

if(isset($_POST['foldertoken'])) $_GET['foldertoken'] = $_POST['foldertoken'];
if(isset($_GET['foldertoken']) && !empty($_GET['foldertoken'])){
  foreach ($dir as $fileinfo) {
    if ($fileinfo->isDir() && !$fileinfo->isDot()) {          
        if(file_exists($fileinfo->getPathname().'/.hash')) {
        $tmp = file_get_contents($fileinfo->getPathname().'/.hash');
        if($tmp == $_GET['foldertoken']){
          $foldertoken  = $fileinfo->getFilename();
          $folderhash   = $tmp;

          if(isset($_GET['downloadtoken'])){
            if (is_dir($fileinfo->getPathname())){
              if ($dh = opendir($fileinfo->getPathname())){
                while (($file = readdir($dh)) !== false){                    
                  if(!is_dir($file) && !in_array($file, array('.','..', '.hash'))){
                    $file = $fileinfo->getFilename().$ds.$file;
                    if(md5($file) == $_GET['downloadtoken']){
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
                  }
                }
                closedir($dh);
              }
            }

            returnResponse("page not found", "error", 404);
          }

          //die($foldertoken);
        }
      }
    }
 }
 if(empty($foldertoken)) {returnResponse("token invalid", "error", 403); exit;}
}

// Upload i.O. falls eingelogg ODER FolderUpload-Token ist i.O.
if(!$_allowUpload && $_USER !== NULL) $_allowUpload = true;
elseif(!empty($foldertoken))  $_allowUpload = true;

if($_Page == 'create_uploadFolder' && $_USER !== NULL){
  $foldername = $_POST['folder'];
  if(!file_exists($targetPath.$foldername)) mkdir($targetPath.$foldername, 0644);
  if(!file_exists($targetPath.$foldername.'/.hash')) file_put_contents($targetPath.$foldername.'/.hash', bin2hex(random_bytes(16)));
}

if(empty($foldertoken) && $_USER != NULL && in_array($_Page, ['upload', 'concat', ''])) 
{
  $foldertoken = $ds;
  $folderhash   = NULL;
  //mkdir($targetPath.$foldertoken, 0644);
  //if(!file_exists($targetPath.$foldertoken.'/.hash')) file_put_contents($targetPath.$foldertoken.'/.hash', bin2hex(random_bytes(32)));
}

// Upload-Page
if($_Page == 'upload'){  
  if(!$_allowUpload) {returnResponse("allowUpload invalid", "error", 403); exit;}
  // chunk variables
  $fileId = $_POST['dzuuid'];
  $chunkIndex = $_POST['dzchunkindex'] + 1;
  $chunkTotal = $_POST['dztotalchunkcount'];
  if($chunkIndex > $chunkTotal) returnResponse(null, "An error occurred", 415);
  // file path variables
  $fileType = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
  $fileSize = $_FILES["file"]["size"];
  $filename = "{$fileId}-{$chunkIndex}.tmp";
  $targetPath .= $foldertoken.$ds;
  $targetFile = $targetPath . $filename;
  
  //$returnResponse("nok");
  move_uploaded_file($_FILES['file']['tmp_name'], $targetFile);

  // Be sure that the file has been uploaded
  if (!file_exists($targetFile) ) returnResponse(null, "An error occurred", 415);

  // Read and write for owner, read for everybody else
  chmod($targetFile, 0644) or returnResponse(null, "Error: could not set permissions", 415);

  returnResponse(null, "success", 200);

  exit;
}

// Nach Upload der Chunks -> Konkateniere diese in richtiger Reihenfolge
if($_Page == 'concat'){
    if(!$_allowUpload) {returnResponse("allowUpload invalid", "error", 403); exit;}
      // get variables
    $fileId = $_POST['dzuuid'];
    $chunkTotal = $_POST['dztotalchunkcount'];

    // file path variables
    $fileType = $_POST['dzfilename'];
    $targetPath .= $foldertoken.$ds;
    // Teile durchgehen und zusammensetzen
    for ($i = 1; $i <= $chunkTotal; $i++) {
      // target temp file
      $temp_file_path = realpath("{$targetPath}{$fileId}-{$i}.tmp") or returnResponse("error: chunk lost.",415);
      // copy chunk
      $chunk = file_get_contents($temp_file_path);
      if ( empty($chunk) ) returnResponse("Error: chunk empty", 415);
      // add chunk to main file
      file_put_contents("{$targetPath}{$fileId}.{$fileType}", $chunk, FILE_APPEND | LOCK_EX);
      // delete chunk
      unlink($temp_file_path);
      if ( file_exists($temp_file_path) ) returnResponse("error: temp not deleted",415);
    }

  exit;
}
?><!DOCTYPE html>
<html>
<head>  
  <meta charset="utf-8">  
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=10.0, user-scalable=yes">

  <title>file transfer | nacht-adriano</title>

  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css" integrity="sha384-Vkoo8x4CGsO3+Hhxv8T/Q5PaXtkKtu6ug5TOeNV6gBiFeWPGFN9MuhOf23Q9Ifjh" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.7.0/min/dropzone.min.css">  
  
  <script src="https://cdnjs.cloudflare.com/ajax/libs/babel-polyfill/7.8.7/polyfill.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.7.0/min/dropzone.min.js"></script>
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

<?php if((!empty($foldertoken))){   ?>

  <div class="row">
    <div class="col-md-12">
      <h3><?php echo $foldertoken; ?></h3>
      <form action="/" method="post" enctype="multipart/form-data" class="dropzone" id="image-upload">
      <input type="hidden" name="page" value="upload">
      <input type="hidden" name="csrf_token" value="<?php echo $_CSRFTOKEN;?>">
      <input type="hidden" name="foldertoken" value="<?php echo $folderhash;?>">
      </form>
    </div>
  </div>
<?php } ?>

  <?php if($_USER !== NULL) {    
    echo '  <div class="row">
            <div class="col-md-12">  
              <h3>Linkliste:</h3>';
    
    foreach ($dir as $fileinfo) {
        if ($fileinfo->isDir() && !$fileinfo->isDot()) {
            if(!file_exists($fileinfo->getPathname().'/.hash')) file_put_contents($fileinfo->getPathname().'/.hash', bin2hex(random_bytes(16)));            
            if(file_exists($fileinfo->getPathname().'/.hash')){
              $tmp = file_get_contents($fileinfo->getPathname().'/.hash');
              echo '== <a href="'.$myBaseLink.'?foldertoken='.$tmp.'">./'.$fileinfo->getFilename().'/</a><br>';

              if (is_dir($fileinfo->getPathname())){
                if ($dh = opendir($fileinfo->getPathname())){
                  while (($file = readdir($dh)) !== false){                    
                    if(!is_dir($file) && !in_array($file, array('.','..', '.hash'))){
                      $l = $file;
                      $file = $fileinfo->getFilename().$ds.$file;
                      echo '> <a href="'.$myBaseLink.'?foldertoken='.$tmp.'&downloadtoken='.md5($file).'">' . $l . '</a> <br>';
                    }
                  }
                  closedir($dh);
                }
              }


            }
        }
    }
    ?>
      <form action="/" method="post">
      <input type="hidden" name="page" value="create_uploadFolder">
      <input type="hidden" name="csrf_token" value="<?php echo $_CSRFTOKEN;?>">
      ./<input type="text" name="folder" value="<?php echo $_USER['user_name'];?>">/
      <input type="submit" value="erstellä">
      </form>
    <?PHP
    echo '</div></div>';
} ?>


</div>

<script type="text/javascript">

  Dropzone.options.imageUpload = {
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
          xhr.send("page=concat&csrf_token=<?php echo $_CSRFTOKEN;?>&foldertoken=<?php echo $folderhash;?>&dzuuid=" + currentFile.upload.uuid + "&dztotalchunkcount=" + currentFile.upload.totalChunkCount + "&dzfilename=" + currentFile.name); //currentFile.name.substr( (currentFile.name.lastIndexOf('.') +1) )
        },

        error: function (file, response) { alert(response);},
        //success: function (file, response) {_this = this; setTimeout(function(){_this.removeFile(file);}, 3000);},
  };

</script>
<?php } ?>

<!-- Synology-Login/LogOut --> 
<script src="https://code.jquery.com/jquery-3.5.1.min.js" integrity="sha256-9/aliU8dGd2tb6OSsuzixeV4y/faTqgFtohetphbbj0=" crossorigin="anonymous"></script>
<script type="text/javascript" src="<?php echo $sso_str;?>/webman/sso/synoSSO-1.0.0.js"></script>
<script>
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

</script>

</body>
</html>