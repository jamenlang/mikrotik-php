<?php
include('Net/SFTP.php');

$logging = 'file'; //add in mysql if you'd like, default 'file';

$log_file_handle = 'mtlog.txt';
$admin_password = 'tacobravo'; //admin password for config file.
$time_zone = 'America/Denver';
$ntp_server = '64.202.112.75';

$architecture_types = array (
	'RB2011' => 'mipsbe',
	'RB750' => 'mipsbe',
	'RB1200' => 'ppc'
);

$firmware_directory = 'c:/mikrotik/firmware/';
//place custom backup files in this directory with the model name in the file name. e.g. RB750GL-NONAT-CONFIG.backup
//place routeros-npk files in this directory too. e.g. routeros-mipsbe-6.18.npk

$ethernet_forenaming_scheme = 'ether'; //make sure this matches your backup files. e.g. ether1 
$sfp_forenaming_scheme = 'sfp'; //make sure this matches your backup files. e.g. sfp1

$failure_location = 'index.html';
$finished_location = 'index.html';

if(!file_exists($firmware_directory))
	die($firmware_directory . ' does not exist.');
else{
	$filelist = scandir($firmware_directory);
	$config_file_found = 0;
	$firmware_file_found = 0;
	foreach($filelist as $filename)
	{
		if(stristr($filename, '.backup'))
			$config_file_found = 1;
		if(stristr($filename, 'routeros-') && stristr($filename, '.npk'))
			$firmware_file_found = 1;
	}
	if ($config_file_found != 1)
		die($firmware_directory . ' has no .backup files in it.');
	if ($firmware_file_found != 1)
		die($firmware_directory . ' has no .npk files in it.');
}

$todo = ini_get('max_execution_time');
if ($todo != 0){
	file_put_contents($log_file_handle, 'Warning: Max execution time not 0; timeout: ' . $todo );
}

while(1) {
	//wait for mt to respond on port 80.
	do {
		is_connected(0);
	} while (is_connected(0) != '1');
	
	//make double sure it's up before continuing.
	do {
		is_connected(0);
	} while (is_connected(0) != '1');
	
	//wait for ssh access.
	sleep(10);
	
	//set time.
	try{
		set_time();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//update firmware.
	try{
		$configfile = update_firmware();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//wait for the mt to reboot if it needs to.
	sleep(10);
	
	//wait for mt to respond on port 80.
	do {		
		is_connected(0);
	} while (is_connected(0) != '1');
	
	//wait for ssh access.
	sleep(10);
	
	//apply config file.
	try{
		$firstrun = load_blank_config($configfile);
		if($firstrun == 'blanked')
			sleep(90);
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}

	//wait for the mikrotik to reboot if it needs to.
	do {
		is_connected(0);
	}
	while (is_connected(0) != '1');
	
	//wait for ssh access.
	sleep(10);
	
	//fix mac addresses.
	try{
		fix_mac_addresses();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//make sure it's still reachable on port 80.
	do {
		is_connected(0);
	}
	while (is_connected(0) != '1');
	
	//wait for ssh access.
	sleep(10);
	
	//set time.
	try{
		set_time();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//update bootloader if it's not up to date.
	try{
		$bootloader_state = update_bootloader();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//if the bootloader was updated the mt has to reboot again. we'll check to see if it was updated and wait if necessary.
	if($bootloader_state == 'justnowupdated'){
		do {
			is_connected(0);
		}
		while (is_connected(0) != '1');
		sleep(10);
	}
	
	//sing if successful.
	try{
		task_complete();
	} catch (Exception $e) {
		header("Location: $GLOBALS[failure_location]");
		echo 'Caught exception: ',  $e->getMessage(), "\n";
	}
	
	//wait while user changes out mikrotiks so this can run again.
	sleep(22);
}

function logthis($entry){
	if($GLOBALS[logging] != 'file'){
		//add in mysql support
	}
	else{
		file_put_contents(date("M/j H:i:s.u") . ', ' . $GLOBALS['log_file_handle'], $entry . PHP_EOL, FILE_APPEND | LOCK_EX);	
	}
}

function is_connected($noloop)
{

    $connected = @fsockopen("192.168.88.1", "80"); //website and port
    if ($connected){
        $is_conn = true; //action when connected
        fclose($connected);
    }else{
        $is_conn = false; //action in connection failure
		if ($noloop != '1')
			$is_conn = second_connected();
    }
    return $is_conn;
}

function second_connected()
{
	for ($i = 5; $i > 0; $i--)
	{
		if (is_connected(1) != '1'){			
			if ($i == 1){
				logthis('no more mikrotiks. killing myself. ');
				sleep(4);
			}
			else{
				logthis('dying in : ' . ($i - 1) . ' cycles' );
			}
		}
		else{
			
			return 1;
		}
	}
	
	header('Location: ' . $GLOBALS['finished_location'] . '');
	exit;
}

function set_time(){
	
	$ssh = new Net_SSH2('192.168.88.1');
	if (!$ssh->login('admin', '')) {
		if ($ssh->login('admin', $GLOBALS['admin_password'])) {
			$ssh->exec('system clock set time-zone-name=' . $GLOBALS['time_zone']);
			$ssh->exec('system clock set date=' . date("M/j/Y"));
			$ssh->exec('system clock set time=' . date("G:i:s"));
			$ssh->exec('system ntp client set primary-ntp=' . $GLOBALS['ntp_server']);
			$ssh->exec('system ntp client set enabled yes');
			logthis('set time');
		}
		else{
			logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
		}
	}
	
}

function update_firmware(){	

	$ssh = new Net_SSH2('192.168.88.1');
	$current_firwmare_version = '';
	if ($ssh->login('admin', '')) {
		
		$routerboard = $ssh->exec('system resource print');
	
		preg_match_all('/([^:]*?):([^\r\n]*)\r\n?/', $routerboard, $matches);
		$output = array_combine(preg_replace('/\s/','',$matches[1]), $matches[2]);
		$current_firmware_version = preg_replace("/[^0-9.]/", "", $output['version']);
		logthis('current firmware version: ' . $current_firmware_version );
		$routerboard = $ssh->exec('system routerboard print');

		preg_match_all('/([^:]*?):([^\r\n]*)\r\n?/', $routerboard, $matches);
		$output = array_combine(preg_replace('/\s/','',$matches[1]), $matches[2]);
		$model = 'RB' . preg_replace("/[^0-9]/", "", $output['model']);
		$ssh->disconnect();

		$files = scandir($GLOBALS['firmware_directory']);
		
		$sftp = new Net_SFTP('192.168.88.1');
		if (!$sftp->login('admin', '')) {
			exit('Login Failed');
		}
		$routeros_file_found = 0;
		foreach ($files as $file){
			if (strstr($file, 'routeros-' . $GLOBALS['architecture_types'][$model] . '-'))
			{
				$routeros_file_found = 1;
				$newfw = preg_replace("/.npk/", "", $file);
				$newfw = preg_replace("/[^0-9.]/", "", $newfw);
				if ($current_firmware_version != $newfw){
					if($current_firmware_version > $newfw){
						logthis('current firmware is newer than ' . $newfw );
					}
					else{
						logthis('new firmware version: ' . $newfw );
					}
				}
				else{
					logthis('firmware already up to date' );
				}
			}
		}
		if($routeros_file_found == 0)
			die('no routeros for ' . $GLOBALS['architecture_types'][$model] . ' found.');
		$sftp->pwd();
		foreach ($files as $file){
			if ($file != '.' && $file != '..'){
				if (strstr($file, $GLOBALS['architecture_types'][$model]) || strstr($file, $model)){
					if (!strstr($file, $current_firmware_version . '-') && !strstr($file, $current_firmware_version . '.npk')){ //append a '-' here also check for npk...
						logthis('sftping file (not using a password): ' . $file);
						$sftp->put("$file", file_get_contents($GLOBALS['firmware_directory'] . $file));
						$ssh->exec(':beep frequency=137 length=2ms;');
					}
				}
				if (strstr($file, $model)){
					$configfile = $file;
				}
			}
		}
		if(!$configfile)
			die('no backup for ' . $model . ' found.');
		$ssh = new Net_SSH2('192.168.88.1');
		if ($ssh->login('admin', '')) {
			$todo = $ssh->exec('system reboot');
			logthis($todo );
			$ssh->disconnect();
			logthis('updated firmware (not using a password)');
		}
		
		return $configfile; 
	}
	
	if ($ssh->login('admin', $GLOBALS['admin_password'])) {
		$routerboard = $ssh->exec('system resource print');
	
		preg_match_all('/([^:]*?):([^\r\n]*)\r\n?/', $routerboard, $matches);
		$output = array_combine(preg_replace('/\s/','',$matches[1]), $matches[2]);
		$current_firmware_version = preg_replace("/[^0-9.]/", "", $output['version']);
		logthis('current firmware version: ' . $current_firmware_version );
		$routerboard = $ssh->exec('system routerboard print');

		preg_match_all('/([^:]*?):([^\r\n]*)\r\n?/', $routerboard, $matches);
		$output = array_combine(preg_replace('/\s/','',$matches[1]), $matches[2]);
		$model = 'RB' . preg_replace("/[^0-9]/", "", $output['model']);
		$ssh->disconnect();

		$files = scandir($GLOBALS['firmware_directory']);
	
		$sftp = new Net_SFTP('192.168.88.1');
		if (!$sftp->login('admin', $GLOBALS['admin_password'])) {
			exit('Login Failed');
		}
		
 		foreach ($files as $file){

			if (strstr($file, 'routeros-' . $GLOBALS['architecture_types'][$model] . '-'))
			{
				$routeros_file_found = 1;
				$newfw = preg_replace("/.npk/", "", $file);
				$newfw = preg_replace("/[^0-9.]/", "", $newfw);
				
				if ($current_firmware_version != $newfw){
					if($current_firmware_version > $newfw){
						logthis('current firmware is newer than ' . $newfw );
					}
					else{
						logthis('new firmware version: ' . $newfw );
					}
				}
				else{
					logthis('firmware already up to date' );
				}
			}
		}
		if($routeros_file_found == 0)
			die('no routeros for ' . $GLOBALS['architecture_types'][$model] . ' found.');
		$sftp->pwd();
		foreach ($files as $file){
			if ($file != '.' && $file != '..'){
				if (strstr($file, $GLOBALS['architecture_types'][$model]) || strstr($file, $model)){
					if (!strstr($file, $current_firmware_version . '-') && !strstr($file, $current_firmware_version . '.npk')){
						logthis('sftping file (using password): ' . $file );
						$sftp->put("$file", file_get_contents($GLOBALS['firmware_directory'] . $file));
						$ssh->exec(':beep frequency=137 length=2ms;');
					}
				}
				if (strstr($file, $model)){
					$configfile = $file;
				}
			}
		}
		
		if(!$configfile)
			die('no backup for ' . $model . ' found.');
			
		$ssh = new Net_SSH2('192.168.88.1');
		if ($ssh->login('admin', $GLOBALS['admin_password'])) {
			$todo = $ssh->exec('system reboot');
			logthis($todo );
			$ssh->disconnect();
			logthis('updated firmware (using password)');
		}
		else{
			logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
		}
		
		return $configfile; 
	}
	else{
		logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
	}
}

function load_blank_config($configfile) {
	$ssh = new Net_SSH2('192.168.88.1');
	for ($login = 0; $login <= 3; $login++)
	{
		if ($ssh->login('admin', '')) {
			$todo = $ssh->exec('system backup load name=' . $configfile . ' password="'. $GLOBALS['admin_password'] . '"'); //load the config file.
			logthis($todo );
			$todo = $ssh->exec('system reboot');
			logthis($todo );
			logthis('blanking : (' . $configfile . ')');
		
			return 'blanked';
		}
		logthis('skipped blanking: (attempt ' . $login . ') cannot log in without password; assuming blanked already.' );
	}
}

function fix_mac_addresses(){
	
	$ssh = new Net_SSH2('192.168.88.1');
	if (!$ssh->login('admin', '')) {
		if ($ssh->login('admin', $GLOBALS['admin_password'])) {
			$detail = $ssh->exec('int eth print');
			logthis($detail );
			if (preg_match_all("/$GLOBALS[ethernet_forenaming_scheme]/", $detail, $matches)) {
				$i=1;
				foreach($matches[0] as $match){					
					$todo = $ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['ethernet_forenaming_scheme'] . $i);
					logthis($todo .PHP_EOL, FILE_APPEND | LOCK_EX);
					$ssh->exec(':beep frequency=120 length=2ms;');
					if($i == '10'){
						$todo = $ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['sfp_forenaming_scheme'] . '1');
						logthis($todo .PHP_EOL, FILE_APPEND | LOCK_EX);
					}
					$i++;
				}
				$todo = $ssh->exec('int eth print');
				logthis($todo .PHP_EOL, FILE_APPEND | LOCK_EX);
				logthis('fixed mac addresses');
			}
		}
		else{
			logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
		}
	}
	
}

function update_bootloader(){
	
	$ssh = new Net_SSH2('192.168.88.1');
	if (!$ssh->login('admin', '')) {
		if ($ssh->login('admin', $GLOBALS['admin_password'])) {
			$todo = $ssh->exec('system routerboard print');
			echo $todo;
			$string = str_replace(PHP_EOL, ': ', $todo);
			$list = explode(': ', $string);
			$result = array();
			for ($i=0 ; $i<count($list) ; $i+=2) {
				$result[ trim($list[$i]) ] = trim($list[$i+1]);
			}
			$upgrade_firmware = $result['upgrade-firmware'];
			$current_firmware = $result['current-firmware'];
			if ($current_firmware != $upgrade_firmware){
				$todo = $ssh->exec('/system routerboard upgrade');
				$todo = 'upgrading bootloader ' . $current_firmware . ' -> ' . $upgrade_firmware; 
				$todo = $ssh->exec('/system reboot');
				logthis($todo );
				return 'justnowupdated';
			}
			else{
				$todo = 'bootloader firmware is up to date';
				logthis($todo );
				return 'alreadyuptodate';
			}
		}
		else{
			logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
		}
	}
}

function task_complete(){
	$ssh = new Net_SSH2('192.168.88.1');
	if (!$ssh->login('admin', '')) {
		if ($ssh->login('admin', $GLOBALS['admin_password'])) {
			logthis('initial configuration completed');
			$ssh->exec(':beep frequency=784 length=200ms;');
			$ssh->exec(':delay 200ms;');
			$ssh->exec(':beep frequency=740 length=200ms;');
			$ssh->exec(':delay 200ms;');
			$ssh->exec(':beep frequency=659 length=200ms;');
			$ssh->exec(':delay 200ms;');
			$ssh->exec(':beep frequency=659 length=200ms;');
			$ssh->exec(':delay 200ms;');
			$ssh->exec(':beep frequency=740 length=200ms;');
			$ssh->exec(':delay 1000ms;');
		}
		else{
			logthis('password ' . $GLOBALS['admin_password'] . ' is incorrect. ' );
		}
	}
}

?>