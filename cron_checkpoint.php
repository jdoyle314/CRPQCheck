<?php
set_time_limit(7200);
error_reporting(E_ERROR | E_WARNING | E_PARSE);
set_include_path(dirname(__FILE__));

define('DS', '/');
require_once "configuration_checkpoint.php";
require_once "include/common/phpass.php";

if(gatorconf::get('use_database')){
    require_once "include/common/mysqli.php";
}

require 'include/aws/aws-autoloader.php';
$bucketName = "<bucket-name>";

//use Aws\Sdk;
require 'include/aws_cred.php';





$db = new DBDriver();


$now_time=date("Y-m-d H:i:s");
echo "Checkpoint script started at $now_time \n\r";

//find all running instances
$sql = "SELECT * FROM custom_instances WHERE status=0 and checkpoint!=0 and termination_time='0000-00-00 00:00:00' ORDER BY checkpoint ASC ";
$rs=$db->query($sql);
$instancestatus=$db->fetchAll($rs);
for($idx=0; $idx<count($instancestatus); $idx++)
{
    //
    $previous_volume="";
    $previous_image="";
    $user_id=$instancestatus[$idx]['user_id'];
    $aws_instance_id=$instancestatus[$idx]['aws_instance_id'];
    $now_time=date("Y-m-d H:i:s");
    echo "Checkpoint script loop $idx started at $now_time for $aws_instance_id \n\r";

    //we read the current status again in case things have changed
    $sql = "SELECT * FROM custom_instances WHERE checkpoint!=0 and termination_time='0000-00-00 00:00:00' AND aws_instance_id='{$aws_instance_id}' ";
    $rs=$db->query($sql);
    $instancestatus2=$db->fetchAll($rs);
    if(!count($instancestatus2))
    {
        echo "$aws_instance_id already processed by some other cron\n\r";
        continue;
    }
    $locked=$instancestatus2[0]['locked'];

    echo "Lock level is $locked\n\r";

    if($locked=='1')
    {
        //currently being checkpointed, skip
        echo "$aws_instance_id is locked level 1\n\r";
        continue;
    }


    $result2 = $ec2Client->describeInstances(array(
        'InstanceIds' => array($aws_instance_id),
    ));
    try {
        $result2 = $ec2Client->describeInstances(array(
            'InstanceIds' => array($aws_instance_id),
        ));
    }
    catch (Aws\Ec2\Exception\Ec2Exception $e) {
        // The AWS error code (e.g., )
        echo "Instance $aws_instance_id died<BR>";
        echo $e->getAwsErrorCode() . "\n";
        // The bucket couldn't be created
        //echo $e->getMessage() . "\n";
        continue;
    }


    $ipaddress=$result2["Reservations"][0]["Instances"][0]["PublicIpAddress"];
    $instance_type=$result2["Reservations"][0]["Instances"][0]["InstanceType"];
    $ec2_prices=gatorconf::get('ec2_instance_config');
    $ec2_ram=$ec2_prices[$instance_type][2];

    //see if the instance is already being processed or part of the an existing image save process
    if($locked=='2')
    {
        echo "$aws_instance_id processing level 2\n\r";
        //check if the image save is complete
        $sql = "SELECT * FROM checkpoints WHERE aws_instance_id='{$aws_instance_id}'  ";
        $rscheckpoint=$db->query($sql);
        $checkpointstatus=$db->fetchAll($rscheckpoint);
        $volume_id=$checkpointstatus[0]['temp_volume_id'];
        $instance_ami=$checkpointstatus[0]['temp_instance_ami'];
        $previous_image=$checkpointstatus[0]['instance_ami'];
        $previous_volume=$checkpointstatus[0]['volume_id'];
        $checkpoint_id=$checkpointstatus[0]['checkpoint_id'];
        $checkpoint_time=$checkpointstatus[0]['checkpoint_time'];
        $PIDs=$checkpointstatus[0]['PIDs'];

        $awsresult = $ec2Client->describeImages(array(
            'Owners' => array('self'),
            "Filters" => array(
                array("Name" => "state", "Values" => array('available')) ),
            'ImageIds' => array($instance_ami),
        ));
        $ready=count($awsresult["Images"]);
        if($ready)
        {
            //image save is complete
            //run the CRIU commands
            echo "Image save $instance_ami complete. \n\r";
            echo "Attempting SSH to $instance_ami $ipaddress. \n\r";
            $connection = ssh2_connect($ipaddress, 22, array('hostkey', 'ssh-rsa'));
            if($connection)
            {
                //create the users
                if (ssh2_auth_pubkey_file($connection, 'ubuntu',
                    '<public key location>',
                    '<private key location>')) {
                    //echo "Public Key Authentication Successful\n";
                    //All done resume the process
                    echo "Resuming Process<BR>\n\r";
                    if(trim($PIDs)!="")
                    {
                        echo "sudo kill -CONT $PIDs \n\r";
                        //add user to sudo group
                        $stream = ssh2_exec($connection, "sudo kill -CONT $PIDs ");
                        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                        // Enable blocking for both streams
                        stream_set_blocking($errorStream, true);
                        stream_set_blocking($stream, true);
                        $ssh_output=stream_get_contents($stream);
                        $ssh_error=stream_get_contents($errorStream);
                        echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";
                    }

                    echo "Resuming docker process command sudo python /bin/restoreDocker.py  \n\r";
                    $stream = ssh2_exec($connection, "sudo python /bin/restoreDocker.py ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    $stream = ssh2_exec($connection, "echo \"System checkpointing is complete. Please relogin or type 'dithen' to continue. \" | wall ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    // Close the streams
                    fclose($errorStream);
                    fclose($stream);

                    if(trim($previous_image))
                    {
                        //find the snapshot_id of the AMI
                        $awsdelresult = $ec2Client->describeImages(array(
                            'Owners' => array('self'),
                            "Filters" => array(
                                array("Name" => "state", "Values" => array('available')) ),
                            'ImageIds' => array($previous_image),
                        ));
                        $snapshot_id=$awsdelresult["Images"][0]["BlockDeviceMappings"][0]["Ebs"]["SnapshotId"];


                        try
                        {
                            echo "Deleting previous image $previous_image \n\r";
                            $result = $ec2Client->deregisterImage(array(
                                'ImageId' => $previous_image
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {
                            // The AWS error code (e.g., )
                            echo $e->getAwsErrorCode() . "\n";
                            // The bucket couldn't be created
                            echo $e->getMessage() . "\n";
                        }

                        try
                        {
                            echo "Deleting previous snapshot $snapshot_id \n\r";
                            $result = $ec2Client->deleteSnapshot(array(
                                'SnapshotId' => $snapshot_id, // REQUIRED
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {
                            // The AWS error code (e.g., )
                            echo $e->getAwsErrorCode() . "\n";
                            // The bucket couldn't be created
                            echo $e->getMessage() . "\n";
                        }

                    }

                    if(trim($previous_volume))
                    {
                        try
                        {
                            echo "Deleting previous volume $previous_volume \n\r";
                            $result = $ec2Client->deleteVolume(array(
                                'VolumeId' => $previous_volume
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {
                            // The AWS error code (e.g., )
                            echo $e->getAwsErrorCode() . "\n";
                            // The bucket couldn't be created
                            echo $e->getMessage() . "\n";
                        }

                    }

                    $now_time=date("Y-m-d H:i:s");
                    $sql = "UPDATE checkpoints SET volume_id=temp_volume_id, instance_ami=temp_instance_ami, ip_address='{$ipaddress}', checkpoint_time='{$now_time}', PIDs='{$PIDs}', temp_instance_ami='', temp_volume_id=''  WHERE checkpoint_id='{$checkpoint_id}'	";
                    echo $sql."\n\r";
                    $db->execute($sql);
                    $sql = "UPDATE custom_instances SET checkpoint='0', locked='0' WHERE  aws_instance_id='{$aws_instance_id}' ";
                    echo $sql."\n\r";
                    $db->execute($sql);

                    //send email
                    $sqluser = "SELECT * FROM dithen_users WHERE user_id='{$user_id}' ";
                    $rsuser=$db->query($sqluser);
                    $userdate=$db->fetchAll($rsuser);
                    $user_email=$userdate[0]['user_email'];
                    $email_subject="Dithen Instance Checkpointed";
                    $email_text_image_checkpointed="Dear User,

The instance $aws_instance_id has been checkpointed per your request and can now be accessed as usual.

For any questions, contact us at support@dithen.com using this email address!

Sincerely,

Dithen";

                    send_email($user_email, $data, $email_subject, $email_text_image_checkpointed);
                }

            }
            else
            {
                //could not connect. die and wait
                //in all other cases, no nothing, unlock
                echo "SSH connection failed after image save. \n\r";
                //break;
            }
        }
        else
        {
            //AMI not ready yet
            echo "AMI $instance_ami not ready yet \n\r";
        }
        continue;
    }


    $sql = "SELECT * FROM checkpoints WHERE aws_instance_id='{$aws_instance_id}'  ";
    $rscheckpoint=$db->query($sql);
    $checkpointstatus=$db->fetchAll($rscheckpoint);
    if(count($checkpointstatus))
    {
        //already checkpointed, doing second time
        $volume_id=$checkpointstatus[0]['volume_id'];
        $previous_image=$checkpointstatus[0]['instance_ami'];
        $previous_volume=$checkpointstatus[0]['volume_id'];
        $checkpoint_id=$checkpointstatus[0]['checkpoint_id'];
        $checkpoint_time=$checkpointstatus[$idx]['checkpoint_time'];
        echo "Detected exiting checkpoint data with checkpoint_id $checkpoint_id volume $volume_id AMI $previous_image  \n\r";
        $seconds = (strtotime("now")- strtotime($checkpoint_time));
        $now_time=date("Y-m-d H:i:s");
        $sql = "UPDATE checkpoints SET checkpoint_time='{$now_time}' WHERE checkpoint_id='{$checkpoint_id}'	";
        echo $sql;
        $db->execute($sql);

    }
    else
    {
        //first time checkpointing
        echo "First time checkpointing. \n\r";
        $seconds = (strtotime("now")- strtotime($instancestatus[$idx]['creation_time']));
        $previous_image="";
        $previous_volume="";

        try {
            $result2 = $ec2Client->describeInstances(array(
                'InstanceIds' => array($aws_instance_id),
            ));
        }
        catch (Aws\Ec2\Exception\Ec2Exception $e) {
            // The AWS error code (e.g., )
            echo "Instance $aws_instance_id died. Removing this from DB. \n\r";
            $sql = "UPDATE custom_instances SET checkpoint='0', locked='0' WHERE  aws_instance_id='{$aws_instance_id}' ";
            echo $sql."\n\r";
            $db->execute($sql);
            // The bucket couldn't be created
            continue;
        }

        $now_time=date("Y-m-d H:i:s");
        $sql = " INSERT INTO checkpoints (user_id, aws_instance_id, instance_type, checkpoint_time)
					VALUES ('{$user_id}', '{$aws_instance_id}', '{$instance_type}', '{$now_time}' ) ";
        $db->execute($sql);
        echo $sql."\n\r";
        $checkpoint_id=$db->getLastInsertId();

    }

    //lock the instance so only one can checkpoint at a time
    $sql = "UPDATE custom_instances SET locked='1' WHERE  aws_instance_id='{$aws_instance_id}' ";
    echo $sql."\n\r";
    $db->execute($sql);


    $result = $ec2Client->createVolume(array(
        'AvailabilityZone' => "us-east-1c", // REQUIRED
        'Encrypted' => false,
        'Size' => $ec2_ram+1, // REQUIRED
        'VolumeType' => "standard", // REQUIRED
    ));
    $volume_id=$result["VolumeId"];
    echo "Volume created. ID is: ".$volume_id."\n\r";

    do
    {
        $result = $ec2Client->describeVolumes(array(
            'VolumeIds' => array($volume_id),
        ));
        $volume_status=$result["Volumes"][0]["State"];

    }while($volume_status!="available");
    echo "Volume state is: ".$volume_status."\n\r";

    //tag the volume
    echo "Creating tags of volume DVC_".$aws_instance_id."\n\r";
    $result = $ec2Client->createTags(array(
        'DryRun' => False,
        // Resources is required
        'Resources' => array($volume_id),
        // Tags is required
        'Tags' => array(
            array(
                'Key' => 'Name',
                'Value' => 'DVC_'.$aws_instance_id,   //dithen volume criu
            ),
            // ... repeated
        ),
    ));

    echo "Attaching volume: ".$volume_id." to $aws_instance_id \n\r";
    $result = $ec2Client->attachVolume(array(
        'Device' => '/dev/sdg', // REQUIRED
        'DryRun' => false,
        'InstanceId' => $aws_instance_id, // REQUIRED
        'VolumeId' => $volume_id, // REQUIRED
    ));

    $start_time = time();
    $mount_str = 'hijklmn';
    $randommountchar='g';

    do
    {
        sleep(5);
        $result = $ec2Client->describeVolumes(array(
            'VolumeIds' => array($volume_id),
        ));
        $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];
        echo "Volume state is: ".$volume_status."\n\r";

        if ((time() - $start_time) > 240)
        {
            //timeout 200 seconds
            //detach volume before saving image
            echo "Attach timeout. Detaching Volume $volume_id <BR>\n\r";
            $result = $ec2Client->detachVolume(array(
                'VolumeId' => $volume_id, // REQUIRED
            ));

            //mount to a different random mount points
            $randommountchar = $mount_str[rand(0, strlen($mount_str)-1)];
            $start_time = time();
            do
            {
                sleep(5);
                $result = $ec2Client->describeVolumes(array(
                    'VolumeIds' => array($volume_id),
                ));
                $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];

                echo "Volume state is: ".$volume_status."\n\r";
                if ((time() - $start_time) > 100)
                {
                    echo "Volume ditachment timed out ".$volume_status."\n\r";
                    echo "Force detaching ".$volume_id."\n\r";
                    $result = $ec2Client->detachVolume(array(
                        'VolumeId' => $volume_id, // REQUIRED
                        'Force' => true, // REQUIRED
                    ));
                    sleep(10);
                    try
                    {
                        echo "Deleting volume $volume_id \n\r";
                        $result = $ec2Client->deleteVolume(array(
                            'VolumeId' => $volume_id
                        ));

                    }
                    catch (Aws\Ec2\Exception\Ec2Exception $e) {
                        // The AWS error code (e.g., )
                        echo "Could not delete volume ".$volume_id."\n\r";
                        echo $e->getAwsErrorCode() . "\n";
                        // The bucket couldn't be created
                        echo $e->getMessage() . "\n";
                    }
                    $sql = " DELETE FROM checkpoints WHERE checkpoint_id='{$checkpoint_id}' ";
                    $db->execute($sql);
                    $sql = "UPDATE custom_instances SET locked='0' WHERE  aws_instance_id='{$aws_instance_id}' ";
                    echo $sql."\n\r";
                    $db->execute($sql);
                    continue 2;
                }
            }while($volume_status!="");

            $result = $ec2Client->attachVolume(array(
                'Device' => '/dev/sd'.$randommountchar, // REQUIRED
                'DryRun' => false,
                'InstanceId' => $aws_instance_id, // REQUIRED
                'VolumeId' => $volume_id, // REQUIRED
            ));
            echo "Volume attachment attempt 2 /dev/sd$randommountchar \n\r";

            $start_time = time();
            do
            {
                sleep(5);
                $result = $ec2Client->describeVolumes(array(
                    'VolumeIds' => array($volume_id),
                ));
                $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];
                echo "Volume state is: ".$volume_status."\n\r";

                if ((time() - $start_time) > 240)
                {
                    //timeout 200 seconds
                    //detach volume before saving image
                    echo "Attach timeout. Detaching Volume $volume_id <BR>\n\r";
                    $randommountchar = $str[rand(0, strlen($str)-1)];
                    $result = $ec2Client->detachVolume(array(
                        'VolumeId' => $volume_id, // REQUIRED
                    ));
                    echo "Force detaching ".$volume_id."\n\r";
                    $result = $ec2Client->detachVolume(array(
                        'VolumeId' => $volume_id, // REQUIRED
                        'Force' => true, // REQUIRED
                    ));
                    sleep(10);
                    try
                    {
                        echo "Deleting volume $volume_id \n\r";
                        $result = $ec2Client->deleteVolume(array(
                            'VolumeId' => $volume_id
                        ));

                    }
                    catch (Aws\Ec2\Exception\Ec2Exception $e) {
                        // The AWS error code (e.g., )
                        echo "Could not delete volume ".$volume_id."\n\r";
                        echo $e->getAwsErrorCode() . "\n";
                        // The bucket couldn't be created
                        echo $e->getMessage() . "\n";
                    }

                    $sql = " DELETE FROM checkpoints WHERE checkpoint_id='{$checkpoint_id}' ";
                    $db->execute($sql);
                    $sql = "UPDATE custom_instances SET locked='0' WHERE  aws_instance_id='{$aws_instance_id}' ";
                    echo $sql."\n\r";
                    $db->execute($sql);
                    continue 2;
                }
            }while($volume_status!="attached");
        }
    }while($volume_status!="attached");

    //run the CRIU commands
    echo "Attempting SSH connection to ".$ipaddress."\n\r";
    $connection = ssh2_connect($ipaddress, 22, array('hostkey', 'ssh-rsa'));
    if($connection)
    {
        //create the users
        if (ssh2_auth_pubkey_file($connection, 'ubuntu',
            '<public key location',
            '<private key location>')) {
            //echo "Public Key Authentication Successful\n";

            //Format the File system
            $stream = ssh2_exec($connection, "sudo mkfs -t ext4 /dev/xvd$randommountchar ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            //mount the attached checkpoint volume
            $stream = ssh2_exec($connection, "mkdir /home/ubuntu/criumount ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            //mount the attached checkpoint volume
            $stream = ssh2_exec($connection, "sudo mount /dev/xvd$randommountchar /home/ubuntu/criumount ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            // Run a command that will probably create users
            $stream = ssh2_exec($connection, "echo \"System is entering checkpointing state. Current process will automatically resume shortly.\" | wall ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            sleep(1);

            //add user to sudo group
            echo "Starting CRIU command sudo python /bin/checkpointProcess.py /home/ubuntu/criumount/ \n\r";
            $stream = ssh2_exec($connection, "sudo python /bin/checkpointProcess.py /home/ubuntu/criumount/ ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            //save the PIDs from the text output
            $suspended_PIDs=$ssh_output;
            $pos = strpos($suspended_PIDs, "PIDs");
            $PIDs = substr($suspended_PIDs, $pos+4);    // returns "f"
            $line_breaks = array("\r", "\n");
            $PIDs = trim(str_replace($line_breaks, " ", $PIDs));

            //unmount before creating image
            echo "Unmounting FS<BR>\n\r";
            $stream = ssh2_exec($connection, "sudo umount -l /dev/xvd$randommountchar ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            //detach volume before saving image
            echo "Detaching Volume<BR>\n\r";
            $result = $ec2Client->detachVolume(array(
                'VolumeId' => $volume_id, // REQUIRED
            ));
            //echo '<pre>'.print_r($result).'</pre>';
            $start_time = time();
            do
            {
                sleep(5);
                $result = $ec2Client->describeVolumes(array(
                    'VolumeIds' => array($volume_id),
                ));
                $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];

                echo "Volume state is: ".$volume_status."\n\r";
                if ((time() - $start_time) > 200)
                {
                    echo "Volume ditachment timed out ".$volume_status."\n\r";
                    //timeout 30 seconds
                    echo "Force detaching ".$volume_id."\n\r";
                    $result = $ec2Client->detachVolume(array(
                        'VolumeId' => $volume_id, // REQUIRED
                        'Force' => true, // REQUIRED
                    ));
                    sleep(10);
                    try
                    {
                        echo "Deleting volume $volume_id \n\r";
                        $result = $ec2Client->deleteVolume(array(
                            'VolumeId' => $volume_id
                        ));

                    }
                    catch (Aws\Ec2\Exception\Ec2Exception $e) {
                        // The AWS error code (e.g., )
                        echo "Could not delete volume ".$volume_id."\n\r";
                        echo $e->getAwsErrorCode() . "\n";
                        // The bucket couldn't be created
                        echo $e->getMessage() . "\n";
                    }

                    $sql = " DELETE FROM checkpoints WHERE checkpoint_id='{$checkpoint_id}' ";
                    echo $sql."\n\r";
                    $db->execute($sql);
                    //lock the instance so only one can checkpoint at a time
                    $sql = "UPDATE custom_instances SET locked='0' WHERE  aws_instance_id='{$aws_instance_id}' ";
                    echo $sql."\n\r";
                    $db->execute($sql);
                    $stream = ssh2_exec($connection, "echo \"System checkpointing failed because of timeout (detach).\" | wall ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";
                    //resume paused process
                    if(trim($PIDs)!="")
                    {
                        echo "sudo kill -CONT $PIDs 1\n\r";
                        //add user to sudo group
                        $stream = ssh2_exec($connection, "sudo kill -CONT $PIDs ");
                        $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                        // Enable blocking for both streams
                        stream_set_blocking($errorStream, true);
                        stream_set_blocking($stream, true);
                        $ssh_output=stream_get_contents($stream);
                        $ssh_error=stream_get_contents($errorStream);
                        echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";
                    }
                    continue 2;
                }
            }while($volume_status!="");

            $now_time=date("Y-m-d H:i:s");
            $sql = "UPDATE checkpoints SET checkpoint_time='{$now_time}' WHERE checkpoint_id='{$checkpoint_id}'	";
            echo $sql."\n\r";
            $db->execute($sql);

            echo "Sync<BR>\n\r";
            $stream = ssh2_exec($connection, "sync ");
            $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
            // Enable blocking for both streams
            stream_set_blocking($errorStream, true);
            stream_set_blocking($stream, true);
            // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
            $ssh_output=stream_get_contents($stream);
            $ssh_error=stream_get_contents($errorStream);
            echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

            //wait 10 second to flush cache
            sleep(20);

            echo "Creating Image<BR>\n\r";
            $result = $ec2Client->createImage(array(
                'Description' => 'User Custom Image',
                'InstanceId' => $aws_instance_id, // REQUIRED
                'Name' => 'CRIU_'.$aws_instance_id."_".date("Ymd_His"), // REQUIRED
                'NoReboot' => true
            ));
            $instance_ami=$result['ImageId'];
            echo "AMI $instance_ami creation initiated for instance $aws_instance_id <BR>\n\r";

            //lock the instance so only one can checkpoint at a time
            $sql = "UPDATE custom_instances SET locked='2' WHERE  aws_instance_id='{$aws_instance_id}' ";
            echo $sql."\n\r";
            $db->execute($sql);
            $now_time=date("Y-m-d H:i:s");
            $sql = "UPDATE checkpoints SET temp_volume_id='{$volume_id}', temp_instance_ami='{$instance_ami}', ip_address='{$ipaddress}', checkpoint_time='{$now_time}', PIDs='{$PIDs}' WHERE checkpoint_id='{$checkpoint_id}'	";
            echo $sql."\n\r";
            $db->execute($sql);

            // Close the streams
            fclose($errorStream);
            fclose($stream);

        }
    }
    else
    {
        //could not connect. die and wait
        //in all other cases, no nothing, unlock
        echo "SSH connection failed \n\r";
        //break;
    }

    echo "Done \n\r";

}

//Delete all checkpoints older than 2 days
$sql = "SELECT * FROM checkpoints WHERE checkpoint_time < NOW() - INTERVAL 2 DAY   ";
$rscheckpoint=$db->query($sql);
$checkpointstatus=$db->fetchAll($rscheckpoint);
for($idx=0; $idx<count($checkpointstatus); $idx++)
{
    $previous_image=$checkpointstatus[$idx]['instance_ami'];
    $previous_volume=$checkpointstatus[$idx]['volume_id'];
    $checkpoint_id=$checkpointstatus[$idx]['checkpoint_id'];

    if($previous_image)
    {
        $awsdelresult = $ec2Client->describeImages(array(
            'Owners' => array('self'),
            "Filters" => array(
                array("Name" => "state", "Values" => array('available')) ),
            'ImageIds' => array($previous_image),
        ));
        $snapshot_id=$awsdelresult["Images"][0]["BlockDeviceMappings"][0]["Ebs"]["SnapshotId"];

        try
        {
            echo "Deleting 2 day timeout previous image $previous_image \n\r";
            $result = $ec2Client->deregisterImage(array(
                'ImageId' => $previous_image
            ));

        }
        catch (Aws\Ec2\Exception\Ec2Exception $e) {
            // The AWS error code (e.g., )
            echo $e->getAwsErrorCode() . "\n";
            // The bucket couldn't be created
            echo $e->getMessage() . "\n";
        }

        try
        {
            echo "Deleting 2 day timeout previous snapshot $snapshot_id \n\r";
            $result = $ec2Client->deleteSnapshot(array(
                'SnapshotId' => $snapshot_id, // REQUIRED
            ));

        }
        catch (Aws\Ec2\Exception\Ec2Exception $e) {
            // The AWS error code (e.g., )
            echo $e->getAwsErrorCode() . "\n";
            // The bucket couldn't be created
            echo $e->getMessage() . "\n";
            //continue;
        }
    }

    if($previous_volume)
    {
        try
        {
            echo "Deleting 2 day timeout previous volume $previous_volume \n\r";
            $result = $ec2Client->deleteVolume(array(
                'VolumeId' => $previous_volume
            ));

        }
        catch (Aws\Ec2\Exception\Ec2Exception $e) {
            // The AWS error code (e.g., )
            echo $e->getAwsErrorCode() . "\n";
            // The bucket couldn't be created
            echo $e->getMessage() . "\n";
        }

    }
    $sql = " DELETE FROM checkpoints WHERE checkpoint_id='{$checkpoint_id}' ";
    $db->execute($sql);
    echo $sql."\n\r";
}




function send_email($user_email, $data, $email_subject, $email_text)
{
    global $sesclient;

    $subject = $email_subject;
    $email_text = str_replace('<INSTANCE>',$data["aws_instance_id"], $email_text);
    $email_text=str_replace('<HOURS>',$data["hours"],$email_text);
    $email_text=str_replace('<COST>',$data["instance_total_cost"],$email_text);
    $email_text=str_replace('<BALANCE>',$data["user_credit"],$email_text);
    $email_text_html=str_replace(gatorconf::get('filemanager_path').'dithen_jobs.php','<a href="'.gatorconf::get('filemanager_path').'dithen_jobs.php">'.gatorconf::get('filemanager_path').'dithen_jobs.php</a>',$email_text);

    
    $email_text_html=nl2br($email_text_html);
    $body = $email_text;
    $bodyhtml = $email_text_html;

    $emailSentId = $sesclient->sendEmail(array(
        // Source is required
        'Source' => 'Dithen<admin@dithen.com>',
        // Destination is required
        'Destination' => array(
            'ToAddresses' => array($user_email),
            'BccAddresses' => array(gatorconf::get('support_mail_from'))
        ),
        // Message is required
        'Message' => array(
            // Subject is required
            'Subject' => array(
                // Data is required
                'Data' => $subject,
                'Charset' => 'UTF-8',
            ),
            // Body is required
            'Body' => array(
                'Text' => array(
                    // Data is required
                    'Data' => $body,
                    'Charset' => 'UTF-8',
                ),
                'Html' => array(
                    // Data is required
                    'Data' => '<b>'.$bodyhtml.'</b>',
                    'Charset' => 'UTF-8',
                ),
            ),
        ),
        'ReplyToAddresses' => array( 'admin@dithen.com' ),
        'ReturnPath' => 'admin@dithen.com'
    ));


}

echo "Script ended normally.\n\r";
