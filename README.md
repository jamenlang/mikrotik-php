#mikrotik-php
=============
a suite of php scripts 

If you experience problems with the following scripts:
be sure to fix them and let me know :)

cookiecutter: make up to date copies of routers from backup files

fast_config: quickly apply a /30 ip to an interface and generates a complete output for copy and pasting.

=============


## cookiecutter


routine to mass configure routers by updating router OS and applying config files using php.

requirements:
=============

cookiecutter requires phpseclib to function. 

phpseclib is available from http://phpseclib.sourceforge.net/


setup:
=============

change the entries in the config file accordingly.

at this point in time file is supported, monitor log with windows preview pane or notepadd++ with silent update enabled.

$logging = 'file'; //add in mysql if you'd like, default 'file';

$log_file_handle = 'mtlog.txt';

mikrotik now requires backup files with passwords to be applied using a password, modify this to reflect the backup file admin password. 

$admin_password = 'tacobravo'; //admin password for config file.

because it's the best

$time_zone = 'America/Denver';

no reason, others are available http://support.ntp.org/bin/view/Servers/StratumOneTimeServers

$ntp_server = '64.202.112.75';

if you see a missing architecture type add it in

$architecture_types = array (
	'RB2011' => 'mipsbe',
	'RBxxXx' => 'xxxXx',
	'RB750' => 'mipsbe',
	'RB1200' => 'ppc'
);

place router OS in the following directory, it's not necessary to move the 18 smaller files, just routeros-xxxXx-6.18.npk for the architecture types listed above.
also place custom backup files in this directory with the model name in the file name. e.g. RB750GL-NONAT-CONFIG.backup

$firmware_directory = 'c:/mikrotik/firmware/';

change these to match the default files. mikrotik default forenames are whack, ether1-gateway? ether10-slave-local?! just be sure to name them in your backup like you want them named and change the following accordingly.

make sure this matches your backup files. e.g. ether1,ether2,ether3 would be 'ether' GigabitEthern-1,GigabitEthern-2 would be 'GigabitEthern-'

$ethernet_forenaming_scheme = 'ether'; 

$sfp_forenaming_scheme = 'sfp'; //make sure this matches your backup files. e.g. sfp1


## fast_config

routine to apply /30 subnet to interfaces or add them to new vlans using php.

requirements:
=============

fast_config requires phpseclib to function. 

phpseclib is available from http://phpseclib.sourceforge.net/


setup:
=============

change the entries in the config file accordingly.

$admin_password = 'tacobravo'; //admin password for router.

$ethernet_forenaming_scheme = 'ether'; //make sure this matches your router config. e.g. ether1 

$sfp_forenaming_scheme = 'sfp'; //make sure this matches your router config. e.g. sfp1

$vlan_forenaming_scheme = 'vlan'; //make sure this is set to what you want vlans to start with, e.g. vlan3309
