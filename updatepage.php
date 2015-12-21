<?php

//
// Mediawiki bot. Edits a wiki page with a directory list from a windows box
// goal - a wiki page that always has an update to listing of a windows directory
//
// Usage: php update.php --config=configFle
// Options:
//			--config 	    Config file
// Example:
//        php updatepage.php --config=c:\update.ini
//
//

include 'config.php';				// Add composer packages

									// Global vars
define('LEVEL_CHAR','#');			// Delimeter for wiki output
define('NEW_SECTION',"\n\n");		// New section delimeter
define('SECTION_HEADER_MARKER',"=="); // Section marker
define('NEW_REC',"\n");				// New line delimeter
define('EMPTY_SIGN','(empty)');		// String to show for empty dir
define('SHOW_EMPTY',false);			// Show empty sign EMPTY_SIGN  for empty folders
define('SHOW_LEVELS',1);			// Max number of levels to show
define('MARK_SECTION_SIZE',1*1024*1024*1024);		// Mark section if size is more that MARK_SECTION_SIZE Gb
define('MARK_SECTION_START','<span style="color: red;">');
define('MARK_SECTION_END','</span>');
define('DATABASES_DIR','Databases');
define('BACKUP_SIZE_TEXT',', incl. Backup: ');

$SKIP_FOLDERS = array('$RECYCLE.BIN');	// Folders to skip in Backup folder

////////////////////////////////////// Start here
$LOG->message("parselog started");
$ckStart = new CheckStart($CONFIG['VAR_DIR'].'updatepage.lock');
if(!$ckStart->canStart()) {			// Check if script already running. Doesn't allow customer to send multiple restart requests
  printLogAndDie("Script is already running.");
}

								// Load dir content
$dirContent = loadDir($CONFIG['FOLDER_TO_SCAN']);
$backupSummary = array();
if (array_key_exists('BACKUP_FOLDER_TO_SCAN',$CONFIG) && $CONFIG['BACKUP_FOLDER_TO_SCAN'] ) {	// Add backup info
  $backupDirContent = loadDir($CONFIG['BACKUP_FOLDER_TO_SCAN']);
  $backupSummary = summarizeBackup($backupDirContent);
}
									// Prepare output for wiki page
$output = '='.$CONFIG['FOLDER_TO_SCAN'].' ('.human_filesize($dirContent['SIZE']).
          (array_key_exists('SIZE',$backupSummary) ? BACKUP_SIZE_TEXT.human_filesize($backupSummary['SIZE']+$dirContent['SIZE']) : '').
          ")=\n\n".prepareOutput($dirContent);

									// Write data on wiki
if ($error = writeOnWiki($output,$CONFIG)) {
  printLogAndDie($error);
}

$LOG->message("parselog finished");

// loadDir - Recursive function to return a multidimensional array of folders and files
//            that are contained within the directory given
// Call: $data = loadDir($dir);
// Where:	$dir - path to directory 
// $data - returned data in format:
// ['SIZE => totalSize		-- total directory size
//	'FILES' => ['file1'=>size1,'file2'=>size2],
//  'DIRS' => ['dir1'=>['SIZE => dir1Size,'FILES' => ['file11'=>size12..],'DIRS'=>[...]],
//             'dir2'=>['SIZE => dir2Size,'FILES' => ['file21'=>size22..],'DIRS'=>[...]],
//		      ]
//
function loadDir($dir) {
  global $SKIP_FOLDERS;
  $dirContent = array();			// Dir content 
  $dirContent['SIZE'] = 0;			// Initialize structure
  $dirContent['DIRS'] = array();
  $dirContent['FILES'] = array();

  $fsobj = new COM('Scripting.FileSystemObject', null, CP_UTF8);	// Requires extension=php_com_dotnet.dll
  try {								// Try to write on the page
	$folderObj = $fsobj->GetFolder($dir);
  }
  catch (Exception $e) {				// Some wiki error
	printLogAndDie("Error loading folder ".$dir.": ".$e->getMessage());
  }
  foreach ($folderObj->Files as $fileObj) {			// Check files in dir
	$dirContent['FILES'][$fileObj->Name] = $fileObj->Size;// Store file and it's size
	$dirContent['SIZE'] += $fileObj->Size;			// Add file's size to total size
  }
  foreach ($folderObj->SubFolders as $subFolderObj) {// Check subdirectories
    if (in_array($subFolderObj->Name,$SKIP_FOLDERS)) {	// Skip this folder
      continue;
    }
	$dirContent['DIRS'][$subFolderObj->Name] = array();	// Init new dir structure
	$subDirContent = loadDir($dir.'/'.$subFolderObj->Name);	// Load subdir data
    $dirContent['DIRS'][$subFolderObj->Name] = $subDirContent;	// Store subdir data
    $dirContent['SIZE'] += $subDirContent['SIZE'];		// Add subdir size to total size
  }
  return $dirContent;				// Return Dir content
}									// -- loadDir --

// Old version of loadDir. Works much faster, but returns invalid file size for files which are larger than 2Gb
//  http://php.net/manual/en/function.stat.php
//  Note: Because PHP's integer type is signed and many platforms use 32bit integers, some filesystem functions may return unexpected results for files which are larger than 2GB.
//
// Old version requires https://github.com/kenjiuno/php-wfio for Unicode filename support
//
function loadDirOLD($dir) {
  $dirContent = array();			// Dir content
  $dirContent['SIZE'] = 0;			// Initialize structure
  $dirContent['DIRS'] = array();
  $dirContent['FILES'] = array();
  if (!file_exists('wfio://'.$dir)) {			// Check if dir exists
	return $dirContent;
  }
  $files = scandir('wfio://'.$dir); // Scan directory
  if(is_array($files)) {			// Files/Directoris found
	foreach($files as $fileName) {
	  if($fileName == '.' || $fileName == '..') // Skip home and previous listings
		continue;
	  if(is_dir('wfio://'.$dir.'/'.$fileName)) { // Process directory
		$dirContent['DIRS'][$fileName] = array(); 		// Init new dir structure
		$subDirContent = loadDir($dir.'/'.$fileName);	// Load subdir data
		$dirContent['DIRS'][$fileName] = $subDirContent;// Store subdir data
		$dirContent['SIZE'] += $subDirContent['SIZE'];	// Add subdir size to total size
	  }
	  else {
		$stat = stat('wfio://'.$dir.'/'.$fileName);		// Load stat for file
		$dirContent['FILES'][$fileName] = $stat['size'];// Store file and it's size
		$dirContent['SIZE'] += $stat['size'];			// Add file's size to total size
	  }
	}
  }
  return $dirContent;				// Return Dir content
}									// -- loadDir --


//
// summarizeBackup - summarize stat for all backup folders
// Call:  $backupSummary = summarizeBackup($backupDirContent);
// Where:	$backupSummary - array with summarized data for all the databases' backups
// 			$backupDirContent - result of loadDir for backup folder. Summarize for top level only
//          $backupDirContent = array (
//				'Database 1' => 4567891361	- summary for Database 1
//				'Database 2' => 56545647	- summary for Database 2
//			)
function summarizeBackup($backupDirContent) {
  $backupSummary = array('SIZE' => $backupDirContent['SIZE'], 'BACKUP' => array());	// Summary size for all backups
  foreach ($backupDirContent['DIRS'] as $backupName => $backupStat) {				// Loop first level, FM first level is Day/Monthly backups.
	if (array_key_exists(DATABASES_DIR,$backupStat['DIRS'])) { // We search for DATABASES_DIR dir only here
	  foreach ($backupStat['DIRS'][DATABASES_DIR]['DIRS'] as $dirName => $dirStat) { // Accumulate backup summary here
        if (!array_key_exists($dirName,$backupSummary['BACKUP'])) {
		  $backupSummary['BACKUP'][$dirName] = 0;
        }
	    $backupSummary['BACKUP'][$dirName] +=  $dirStat['SIZE'];
	  }
	  foreach ($backupStat['DIRS'][DATABASES_DIR]['FILES'] as $fName => $fSize) { // Accumulate backup summary here
		if (!array_key_exists($fName,$backupSummary['BACKUP'])) {
		  $backupSummary['BACKUP'][$fName] = 0;
		}
		$backupSummary['BACKUP'][$fName] +=  $fSize;
	  }
	}
  }
  return $backupSummary;
}									// -- summarizeBackup --

//
// prepareOutput - Prepare data for storing on wiki
// Call: 	$output = prepareOutput($dirContent,$level);
// Where: 	$output - text data for storing on wiki
//			$dirContent - dir content in the format returned by loadDir
//			$level - level of file/directory for recursive calls
//
function prepareOutput($dirContent,$level=0) {
  $result = '';
  if ($level > SHOW_LEVELS) {	// Don't show more than SHOW_LEVELS levels
    return '';
  }
								// Process files
  if (isset($dirContent['FILES']) && $dirContent['FILES']) {
    ksort($dirContent['FILES']); // Sort files
    foreach ($dirContent['FILES']  as $key => $val) {
	  $result .= formatOutput($key,$val,$level);
	}
  } 
  else if (SHOW_EMPTY) {		// Add 'empty' string if dir is empty
	$result .= str_repeat(LEVEL_CHAR, $level).' '.EMPTY_SIGN.NEW_REC;
  }
								// Process dirs
  if (isset($dirContent['DIRS']) && $dirContent['DIRS']) {
    ksort($dirContent['DIRS']); // Sort dirs
    foreach ($dirContent['DIRS'] as $key => $val) {
	  $result .= formatOutput($key,$val['SIZE'],$level);
	  $result .= prepareOutput($val,$level+1);
	}
  }
  return $result;			
}								// -- prepareOutput --

// formatOutput - show formated name and size of dir/file including additional backup info
//			  function additionally checks global $backupSummary array for backup values
// Call:	$str - formatOutput($name,$size,$level);
// Where:	$str - formated string
// 			$name - name of dir/file
//			$size - actual size of dir/file
//			$level - level of file/directory. We add backup info only for zero level
//
function formatOutput($name,$size,$level) {
  global $backupSummary;
  if (!$level) {			// Top level = new section
	$backupInfo = '';
	if (array_key_exists('BACKUP',$backupSummary) && array_key_exists($name,$backupSummary['BACKUP'])) {
	  $backupInfo = BACKUP_SIZE_TEXT.human_filesize($backupSummary['BACKUP'][$name] + $size);
	}
	if ($size > MARK_SECTION_SIZE) {
	  return NEW_SECTION.SECTION_HEADER_MARKER.MARK_SECTION_START."$name (".
	         human_filesize($size).$backupInfo.
	         ")".MARK_SECTION_END.SECTION_HEADER_MARKER.NEW_SECTION;
	}
	else {
	  return NEW_SECTION.SECTION_HEADER_MARKER."$name (".
	         human_filesize($size).$backupInfo.
	         ")".SECTION_HEADER_MARKER.NEW_SECTION;
	}
  }
  else {
    return str_repeat(LEVEL_CHAR, $level)."$name (".human_filesize($size).")".NEW_REC;
  }
}								// -- showSize --

//
// writeOnWiki - write data on wiki
// Call:	$err = writeOnWiki($output);
// Where:	$output - data to write on wiki
//			$CONFIG - config data
//			$CONFIG['api_url'] - api URL
//			$CONFIG['WIKI_USERNAME'] - username to connect to wiki
//			$CONFIG['WIKI_PASSWORD'] - password for username
//			$CONFIG['WIKI_PAGE'] - page to update
//			$err - error if any
//
function writeOnWiki ($output,$CONFIG) {
  try	{								// Write outpput on wiki page
    $wiki = new Wikimate($CONFIG['WIKI_API_URL']);
    if (!$wiki->login($CONFIG['WIKI_USERNAME'],$CONFIG['WIKI_PASSWORD'])) {
      $error = $wiki->getError();
      return "Wikimate error: ".$error['login'];
    }
  }
  catch (Exception $e) {				// Some wiki error
    return "Wikimate error: ".$e->getMessage();
  }
									// Connect to required page
  $page = $wiki->getPage($CONFIG['WIKI_PAGE']);
  if (!$page->exists()) {				// No page found error
    return "No such page: ".$CONFIG['WIKI_PAGE'];
  }
  try {								// Try to write on the page
    if (!$page->setText($output)) {
      return "Page was not updated. ".print_r($page->getError());
    }
  }
  catch (Exception $e) {				// Some wiki error
    return "Error updating page: ".$e->getMessage();
  }
}									// -- writeOnWiki --


// human_filesize - nice and simple function to get a human readable file
// http://jeffreysambells.com/2012/10/25/human-readable-filesize-php
//
function human_filesize($bytes, $decimals = 2) {
    $size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
    $factor = floor((strlen($bytes) - 1) / 3);
    return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
}								// -- human_filesize --

?>