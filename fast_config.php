<?php
include('Net/SFTP.php');

$admin_password = 'tacobravo'; //admin password for router.

$ethernet_forenaming_scheme = 'ether'; //make sure this matches your router config. e.g. ether1 
$sfp_forenaming_scheme = 'sfp'; //make sure this matches your router config. e.g. sfp1
$vlan_forenaming_scheme = 'vlan'; //make sure this is set to what you want vlans to start with, e.g. vlan3309 


if(!isset($_POST) || $_POST != ''){
?>
<html>
<body style="background-color: e0e7f7";>
<form action="fast_config.php" method="POST"/>
<table border="0">
<tr><td style="border-left: dashed 1px; border-top: dashed 1px;">VLAN:</td><td style="border-right: dashed 1px; border-top: dashed 1px;"><input type="text" name="vlan"/></td></tr>
<tr><td style="border-left: dashed 1px; border-bottom: dashed 1px;">Interface: </td><td style="border-right: dashed 1px; border-bottom: dashed 1px;"><input type="text" name="interface"/></td></tr>
<tr><td>Subnet:</td><td><input type="text" name="subnet"/></td><td><input value="Apply" type="submit"/></td>
</table>
<?php
}

if(isset($_POST['subnet']) && $_POST['subnet'] != ''){
	do {
		is_connected();
	}
	while (is_connected() != '1');

	if($_POST['vlan'] != '')
		make_vlan($_POST['vlan'],$_POST['interface'] );
	set_addr($_POST['subnet'], $_POST['vlan']);
}

function is_connected()
{
	$connected = @fsockopen("192.168.88.1", "80"); //website and port
	if ($connected){
		$is_conn = true; //action when connected
		fclose($connected);
	}else{
		$is_conn = false; //action in connection failure
	}
	return $is_conn;
}

function make_vlan($vlan, $interface) {
	$interface = (($interface != '') ? $interface : 'ether1');
	$ssh = new Net_SSH2('192.168.88.1');
	if ($ssh->login('admin', $GLOBALS['admin_password'])) {
		$ssh->exec('interface ethernet print');
		$ssh->exec('interface vlan add name=' . $GLOBALS['vlan_forenaming_scheme'] . $vlan . ' vlan-id=' . $vlan . ' interface=' . $interface);//make a new vlan with the vlan# as the vlan name	
		$verify = $ssh->exec('interface vlan print');
	}
}

function set_addr($subnet, $interface) {
	$interface = (($interface != '') ? $interface : $GLOBALS['ethernet_forenaming_scheme'] . '1');
	$ip = explode('/',$subnet);
	$cidr = $ip[1];
	$ip_parts = explode('.',$ip[0]);
	$ip_parts[3] = $ip_parts[3] + 1;
	$gw = implode('.', $ip_parts);
	$ip_parts[3] = $ip_parts[3] + 1;
	$ip = implode('.', $ip_parts);

	$ssh = new Net_SSH2('192.168.88.1');
	if ($ssh->login('admin', $GLOBALS['admin_password'])) {
		//while we're here we might as well reset the mac addresses again.
		$detail = $ssh->exec('int eth print');
		if (preg_match_all("/$GLOBALS[ethernet_forenaming_scheme]/", $detail, $matches)) {
			$i=1;
			foreach($matches[0] as $match){
				$ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['ethernet_forenaming_scheme'] . $i);
				$ssh->exec(':beep frequency=120 length=2ms;');
				$i++;
				if($i == '10')
					$ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['sfp_forenaming_scheme'] . '1');
			}
		}
		
		$ssh->exec('ip address add address=' . $ip . '/' . $cidr . ' interface=' . $interface);//set the ip on the specified interface.
		$ssh->exec('ip route add dst-address=0.0.0.0/0 gateway=' . $gw); //add the default route
	}	
}

$ssh = new Net_SSH2('192.168.88.1');
if ($ssh->login('admin', $GLOBALS['admin_password'])) {
	//while we're here we might as well reset the mac addresses again.
	$detail = $ssh->exec('int eth print');
	if (preg_match_all("/$GLOBALS[ethernet_forenaming_scheme]/", $detail, $matches)) {
		$i=1;
		foreach($matches[0] as $match){
			$ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['ethernet_forenaming_scheme'] . $i);
			$ssh->exec(':beep frequency=120 length=2ms;');
			$i++;
			if($i == '10')
				$ssh->exec('int ethernet reset-mac-address ' . $GLOBALS['sfp_forenaming_scheme'] . '1');
		}
	}

	$verify = $ssh->exec('interface vlan print');
	$others = preg_match_all('/\d\s+[a-zA-Z]?\d+\s+1500\senabled\s+(\d+)\s([a-zA-Z0-9]+)/', $verify, $out);
	foreach ($out[1] as $double => $single){
		if (isset($vlan) && !strstr($vlan, $single) || !isset($vlan)){
			echo 'VLAN ' . $single . ' is on ' . $out[2][$double] . '<br />';
		}
	}

	$more = $ssh->exec('ip address print');
	$others = preg_match_all('/\d?\s+(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})\/\d+\s+\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\s+([a-zA-Z0-9]+)/', $more, $out);

	foreach ($out[1] as $double => $single){
		echo $single . ' is on ' . $out[2][$double] . '<br />';
	}
	if (isset($interface)){
		$verify = $ssh->exec('ip address print where interface=' . $interface);
		if(strstr($verify, $ip . '/' . $cidr))
		{
			echo $ip . ' is on ' . $interface . '<br />';
			$ssh->exec('beep');
		}
	}
		
	$verify = $ssh->exec('ip route print where dst-address=0.0.0.0/0');
	$others = preg_match_all('/0.0.0.0\/0\s+?(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $verify, $out);
	foreach ($out[1] as $single){
		echo 'default gateway set as ' . $single . '<br />';
	}
	if (isset($gw)){
		if(strstr($verify, $gw))
		{
			echo 'for verification: last configured default route set as ' . $gw . '<br />';
			$ssh->exec('beep');
		}
	}
	echo '<br />';
	echo 'ip address print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('ip address print'));
	echo 'ip route print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('ip route print'));
	echo 'int eth print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('int eth print'));
	echo 'int vlan print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('int vlan print'));
	$ssh->exec('ip neighbor discovery set [find] discover=yes');
	$ssh->exec('tool mac-server set [find] disabled=no');
	echo 'tool mac-server print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('tool mac-server print'));
	echo 'system routerboard print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('system routerboard print'));
	echo 'system resource print:' . '<br />';
	echo preg_replace('/\r\n/', '<br />', $ssh->exec('system resource print'));
	$ssh->exec('beep');
	$ssh->exec('beep');
	$ssh->exec('beep');
	$ssh->exec('beep');
	$ssh->exec('beep');
}
else{
	die('password ' . $GLOBALS['admin_password'] . ' is incorrect.');
}
?>
