<?php

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once "configuration_checkpoint.php";
require 'include/aws/aws-autoloader.php';

use Aws\Common\Aws;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Aws\Ec2\Ec2Client;

require 'include/aws_cred.php';

$ec2_prices=gatorconf::get('ec2_instance_config');


if(gatorconf::get('use_database')){
    require_once "include/common/mysqli.php";
}
$db = new DBDriver();
if ($db->connect_error)
{
          error_log("Connect failed: $dbS2S->connect_error\n", 3, "mysql-errors.log");
}
$result = $dbS2S->query("SELECT * FROM custom_instances WHERE status=0 and termination_time='0000-00-00 00:00:00'");
if ($result)
{
		$instances=array();
        while ($row = $result->fetch_assoc())
        {
			print "Examining {$row['aws_instance_id']}\n";
			$instanceDetails = $ec2Client->describeInstances(array('InstanceIds' => array($row['aws_instance_id'])));
			$privateIPAddress=$instanceDetails['Reservations'][0]['Instances'][0]['NetworkInterfaces'][0]['PrivateIpAddresses'][0]['PrivateIpAddress'];
			$instance_type=$result2["Reservations"][0]["Instances"][0]["InstanceType"];
			$ec2_prices=gatorconf::get('ec2_instance_config');
			$ec2_price=$ec2_prices[$instance_type][0];
			$stmtS2S = $dbS2S->stmt_init();
			$checkpointTime;
			$select_query = "SELECT MAX(checkpoint_time) FROM `checkpoints` WHERE  aws_instance_id = ?";
			if ($stmtS2S->prepare($select_query))
			{
					$stmtS2S->bind_param("s", $row['aws_instance_id']);
					$stmtS2S->execute();
					$stmtS2S->bind_result($checkpointTime);
					$stmtS2S->fetch();
			}
			$stmtS2S->close();
			if($checkpointTime =='')
			{
					$checkpointTime=$row['creation_time'];
			}
			print "Checkpoint Time is {$checkpointTime}\n";
			$timeSinceCheckpoint=time()-strtotime($checkpointTime);
			$failureTime;
			$select_query = "SELECT failure_time FROM `checkpoints_info` WHERE  aws_instance_type = ?";
			if ($stmtS2S->prepare($select_query))
			{
					$stmtS2S->bind_param("s", $instance_type);
					$stmtS2S->execute();
					$stmtS2S->bind_result($failureTime);
					$stmtS2S->fetch();
			}
			$stmtS2S->close();
			if($failureTime =='')
			{
					$failureTime=$row['creation_time'];
			}
			$timeSinceFailure=time()-strtotime($failureTime);
			$k;
			switch ($instace_type) {
					case "m3.2xlarge":
					$k=0.66;
					break;
					case "g2.2xlarge":
					$k=0.706;
					break;
					case "m4.4xlage":
					$k=0.574;
					break;
					case "m3.2xlage":
					$k=0.588;
					break;
					case "c3.8xlage":
					$k=0.696;
					break;
			}
			$score=((1-exp(-pow($timeSinceFailure,$k)))*($timeSinceCheckpoint)-(5/60)-(1/60))*$ec2_price;
			$instances[$row['aws_instance_id']]=$score;
		}
		rsort($instances);
		for ($i=0;$i<count(instances)&&$i<5;$i++)
		{
			$sql = "UPDATE custom_instances SET checkpoint='1' WHERE  aws_instance_id='{$key($instances[$i])}' ";
            $db->execute($sql);
		}
}
