<?php
$tsugi_disable_xerte_session = true;
require_once(dirname(__FILE__) . "/config.php");

global $tsugi_enabled;
global $xerte_toolkits_site;

$id = $_GET["template_id"];
if(is_numeric($id))
{
    if (!isset($_REQUEST['group']))
    {
        die('group parameter not supplied!');
    }
	$tsugi_enabled = true;

    $xerte_toolkits_site->group = $_REQUEST{'group'};

	require("play.php");

}
?>