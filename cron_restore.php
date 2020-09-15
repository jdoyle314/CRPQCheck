<?php
set_time_limit(1200);
error_reporting(E_ALL);
set_include_path(dirname(__FILE__));
$current_spot_FM_version=1.02;

define('DS', '/');
require_once "configuration_checkpoint.php";
require_once "include/common/phpass.php";

if(gatorconf::get('use_database')){
    require_once "include/common/mysqli.php";
}

require 'include/aws/aws-autoloader.php';
$bucketName = "<bucket name>";

//use Aws\Sdk;
require 'include/aws_cred.php';

$email_text_instance_failed="Dear User,

We were unable to fulfill your Dithen terminal instance request. Please try again later. You have not been charged for this.

For any questions, contact us at support@dithen.com using this email address!

Sincerely,

Dithen";

$email_text_instance_restored="Dear User,

Your Dithen terminal instance <INSTANCE> has been successfully restored from the checkpoint. 
 
For any questions, contact us at support@dithen.com using this email address!

Sincerely,

Dithen";



$now_time=date("Y-m-d H:i:s");
echo "Restore script started at $now_time \n\r";

$db = new DBDriver();

$sql = "SELECT * FROM custom_instances WHERE status=6 and termination_time='0000-00-00 00:00:00' and (locked='0' OR TIMESTAMPDIFF(MINUTE,lock_time,NOW()) > 10) ORDER BY creation_time ASC  ";
$rs=$db->query($sql);
$instancestatus=$db->fetchAll($rs);
for($i=0;$i<count($instancestatus); $i++)
{

    $user_id=$instancestatus[$i]['user_id'];
    $instance_id=$instancestatus[$i]['instance_id'];
    $aws_instance_id=$instancestatus[$i]['aws_instance_id'];
    $instance_ami=$instancestatus[$i]['instance_ami'];
    $spot_req_id=$instancestatus[$i]['spot_req_id'];
    $status=$instancestatus[$i]['status'];
    $ipaddress=$instancestatus[$i]['ip_address'];
    $volume_id=$instancestatus[$i]['restore_volume_id'];
    $user_name=($instancestatus[$i]['username']);
    $generated_password=($instancestatus[$i]['password']);
    $create_users=($instancestatus[$i]['create_users']);
    $appname=($instancestatus[$i]['appname']);
    $instance_cost=($instancestatus[$i]['instance_cost']);
    $is_spot=($instancestatus[$i]['is_spot']);
    $creation_time=($instancestatus[$i]['creation_time']);
    $now_time=date("Y-m-d H:i:s");
    $env_str=file_get_contents(gatorconf::get('update_location').'envvars');
    $env_str=str_replace("www-data", $user_name, $env_str);
    $env_str=str_replace("'", "'\''", $env_str);
    $data["aws_instance_id"]=$aws_instance_id;

    $now_time=date("Y-m-d H:i:s");
    echo "Restore script loop $i started at $now_time for instance $aws_instance_id AMI $instance_ami spot_req $spot_req_id restore_volume $volume_id user $user_id username $user_name pass $generated_password \n\r";

    $sql_check = "SELECT * FROM custom_instances WHERE status!=0 and termination_time='0000-00-00 00:00:00' and (locked='0' OR TIMESTAMPDIFF(MINUTE,lock_time,NOW()) > 10) AND instance_id='{$instance_id}'";
    echo $sql."\n\r";
    $rs_check=$db->query($sql_check);
    $instancecheck=$db->fetchAll($rs_check);
    if(!count($instancecheck))
    {
        continue;
    }

    //lock status
    $sqlup = "UPDATE custom_instances SET locked=1, lock_time='{$now_time}' WHERE instance_id='{$instance_id}'  ";
    echo $sql."\n\r";
    $db->execute($sqlup);

    $sqluser = "SELECT * FROM dithen_users WHERE user_id='{$user_id}' ";
    echo $sql."\n\r";
    $rsuser=$db->query($sqluser);
    $userdate=$db->fetchAll($rsuser);
    $user_email=$userdate[0]['user_email'];

    //process based on status code
    switch($status){
        //only deal with case 6 which is checkpointing, the rest is covered by cron_instance_v3
        case 6:
            //case 6, special case of resumed instance

            //process the new instance request
            echo "Processing case 6/restore for spot_req $spot_req_id <BR>\r\n";
            if($is_spot==1 && $aws_instance_id=="")
            {
                //so this is a spot
                try {
                    $spotresult = $ec2Client->describeSpotInstanceRequests(array(
                        "SpotInstanceRequestIds" => array($spot_req_id),

                    ));
                }
                catch (Aws\Ec2\Exception\Ec2Exception $e) {
                    // The AWS error code (e.g., )
                    echo "Instance $aws_instance_id died \n\r";
                    echo $e->getAwsErrorCode() . "\n";
                    // The bucket couldn't be created
                    $sql = "UPDATE custom_instances SET locked=0, status=0, termination_time=last_bill_time WHERE instance_id='{$instance_id}'	";
                    $db->execute($sql);
                    echo $sql."\n\r";
                    continue;
                }
                if($spotresult["SpotInstanceRequests"][0]["State"]=="closed" || $spotresult["SpotInstanceRequests"][0]["State"]=="cancelled")
                {
                    //request was not fulfilled, kill it
                    echo "Instance $aws_instance_id was not fulfilled by amazon \n\r";
                    $sql = "UPDATE custom_instances SET locked=0, status=0, termination_time=last_bill_time WHERE instance_id='{$instance_id}'	";
                    $db->execute($sql);
                    echo $sql."\n\r";
                    break;
                }

                $aws_instance_id=$spotresult["SpotInstanceRequests"][0]["InstanceId"];
                if($aws_instance_id=="")
                {
                    echo "instance id of spot $spot_req_id not found: <BR>\r\n";
                    break;
                }
                echo "Found instance id of spot ($spot_req_id): ".$aws_instance_id."\r\n";

            }

            //tag the instance
            echo "Adding instance tags DT_$user_name to $aws_instance_id \r\n";
            $result = $ec2Client->createTags(array(
                'DryRun' => False,
                // Resources is required
                'Resources' => array($aws_instance_id),
                // Tags is required
                'Tags' => array(
                    array(
                        'Key' => 'Name',
                        'Value' => 'DT_'.$user_name,
                    ),
                    // ... repeated
                ),
            ));

            $result2 = $ec2Client->describeInstances(array(
                'InstanceIds' => array($aws_instance_id),
            ));
            $ipaddress=$result2["Reservations"][0]["Instances"][0]["PublicIpAddress"];
            if($ipaddress=="")
            {
                echo "IP address of instance $aws_instance_id not found: \r\n";
                break;
            }

            //run the CRIU commands
            echo "Attempting to connect via SSH to $aws_instance_id ip $ipaddress \n\r";
            $connection = ssh2_connect($ipaddress, 22, array('hostkey', 'ssh-rsa'));
            if($connection)
            {
                //create the users
                if (ssh2_auth_pubkey_file($connection, 'ubuntu',
                    '<public key location>',
                    '<private key location>')) {
                    //echo "Public Key Authentication Successful\n";

                    echo "Attaching Volume $volume_id to $aws_instance_id \n\r";
                    $result = $ec2Client->attachVolume(array(
                        'Device' => '/dev/sdg', // REQUIRED
                        'DryRun' => false,
                        'InstanceId' => $aws_instance_id, // REQUIRED
                        'VolumeId' => $volume_id, // REQUIRED
                    ));

                    $start_time = time();
                    do
                    {
                        sleep(10);
                        $result = $ec2Client->describeVolumes(array(
                            'VolumeIds' => array($volume_id),
                        ));
                        $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];

                        echo "Volume state is: ".$volume_status."\n\r";
                        if ((time() - $start_time) > 240) {
                            //detach volume before saving image
                            echo "Attachment timed out. Detaching Volume $volume_id \n\r";
                            $result = $ec2Client->detachVolume(array(
                                'VolumeId' => $volume_id, // REQUIRED
                            ));
                            break 2;
                        }
                    }while($volume_status!="attached");

                    //Format the File system
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
                    $stream = ssh2_exec($connection, "sudo mount /dev/xvdg /home/ubuntu/criumount ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    sleep(2);

                    echo "Restoring Process \n\r";
                    $stream = ssh2_exec($connection, 'ls /home/ubuntu/criumount ');
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    $criu_folders=$ssh_output;
                    $exp_folders=explode("\n", trim($criu_folders));
                    if(($key = array_search("lost+found", $exp_folders)) !== false) {
                        unset($exp_folders[$key]);
                    }
                    print_r($exp_folders);
                    $job_criu=implode(" ", $exp_folders);

                    echo "Spawning restore expect script /dithen/criu_ssh_restore_expect.sh $ipaddress $job_criu \n\r";
                    $stat=shell_exec("sudo /dithen/criu_ssh_restore_expect.sh $ipaddress $job_criu ");


                    sleep(10);


                    echo "Unmounting FS \n\r";
                    $stream = ssh2_exec($connection, "sudo umount -l /dev/xvdg ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    sleep(2);

                    //detach volume before saving image
                    echo "Detaching volume FS $volume_id from $aws_instance_id \n\r";
                    $result = $ec2Client->detachVolume(array(
                        'VolumeId' => $volume_id, // REQUIRED
                    ));
                    $start_time = time();
                    do
                    {
                        sleep(5);
                        $result = $ec2Client->describeVolumes(array(
                            'VolumeIds' => array($volume_id),
                        ));
                        $volume_status=$result["Volumes"][0]["Attachments"][0]["State"];

                        echo "Volume state is: ".$volume_status."\n\r";
                        if ((time() - $start_time) > 180) {
                            echo "Detaching timed out. Force detaching $volume_id \n\r";
                            $result = $ec2Client->detachVolume(array(
                                'VolumeId' => $volume_id, // REQUIRED
                                'Force' => true, // REQUIRED
                            ));
                            $start_time = time();

                        } 
                    }while($volume_status!="");


                    $stream = ssh2_exec($connection, "sudo python /bin/resumeProcess.py ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    $stream = ssh2_exec($connection, "sudo python /bin/restoreDocker.py ");
                    $errorStream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    // Enable blocking for both streams
                    stream_set_blocking($errorStream, true);
                    stream_set_blocking($stream, true);
                    // Whichever of the two below commands is listed first will receive its appropriate output.  The second command receives nothing
                    $ssh_output=stream_get_contents($stream);
                    $ssh_error=stream_get_contents($errorStream);
                    echo "Output: " . $ssh_output. "Error: " . $ssh_error. "\n\r";

                    $stream = ssh2_exec($connection, "echo \"System Restore is complete.\" | wall ");
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

                    echo "Restore Done! \r\n";

                    echo "Creating DNS entries! \r\n";
                    //create the DNS entries
                    $sqlsd = "SELECT * FROM subdomain_rota WHERE available='1' order by available_date ASC LIMIT 1 ";
                    $rssd=$db->query($sqlsd);
                    $sddata=$db->fetchAll($rssd);
                    $subdomain=$sddata[0]['subdomain'];
                    $sd_id=$sddata[0]['sd_id'];
                    //remove this rota from the available subdomain table
                    $sqlsd = "UPDATE subdomain_rota SET available='0' WHERE sd_id='{$sd_id}' ";
                    $db->execute($sqlsd);
                    //update the custom instance table
                    $sql = "UPDATE custom_instances SET subdomain='{$subdomain}' WHERE instance_id='{$instance_id}'	";
                    $db->execute($sql);
                    $result = $r53client->changeResourceRecordSets(array(
                        // HostedZoneId is required
                        'HostedZoneId' => 'Z3FD2RUWI089O4',
                        // ChangeBatch is required
                        'ChangeBatch' => array(
                            'Comment' => 'string',
                            // Changes is required
                            'Changes' => array(
                                array(
                                    // Action is required
                                    'Action' => 'CREATE',   //CREATE, DELETE
                                    // ResourceRecordSet is required
                                    'ResourceRecordSet' => array(
                                        // Name is required
                                        'Name' => $subdomain.'.dithen.com.',
                                        // Type is required
                                        'Type' => 'A',
                                        'TTL' => 300,
                                        'ResourceRecords' => array(
                                            array(
                                                // Value is required
                                                'Value' => $ipaddress,
                                            ),
                                        ),
                                    ),
                                ),
                            ),
                        ),
                    ));

                    //clean up code, deleting the checkpoint ami, snapshot and volume
                    //delete any checkpoint data and volume
                    $sql = "SELECT * FROM checkpoints WHERE volume_id='{$volume_id}'  ";
                    $rscheckpoint=$db->query($sql);
                    $checkpointstatus=$db->fetchAll($rscheckpoint);
                    $previous_volume=$checkpointstatus[0]['volume_id'];
                    $previous_image=$checkpointstatus[0]['instance_ami'];
                    $sql = " DELETE FROM checkpoints WHERE volume_id='{$volume_id}' ";
                    $db->execute($sql);
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
                            $result = $ec2Client->deregisterImage(array(
                                'ImageId' => $previous_image
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {

                        }

                        try
                        {
                            //echo "Deleting previous snapshot $snapshot_id \n\r";
                            $result = $ec2Client->deleteSnapshot(array(
                                'SnapshotId' => $snapshot_id, // REQUIRED
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {

                        }

                    }

                    if($previous_volume)
                    {
                        try
                        {
                            $result = $ec2Client->deleteVolume(array(
                                'VolumeId' => $previous_volume
                            ));

                        }
                        catch (Aws\Ec2\Exception\Ec2Exception $e) {

                        }

                    }
                    //clean up code ends

                    //send restore complete email
                    $email_subject="Dithen Instance ".$aws_instance_id." restored";
                    send_email($user_email, $data, $email_subject, $email_text_instance_restored);

                    echo "Writing DB of user \n\r";
                    $sql = "UPDATE custom_instances SET locked=0, status=0, aws_instance_id='{$aws_instance_id}', ip_address='{$ipaddress}' WHERE instance_id='{$instance_id}'	";
                    $db->execute($sql);
                    echo $sql."\n\r";
                }
            }
            else
            {
                //could not connect. die and wait
                //in all other cases, no nothing, unlock
                //detach volume before saving image
                echo "SSH connection failed to $aws_instance_id ip $ipaddress \n\r ";
                break;
            }

            break;
        default:

    }

    $sqlup = "UPDATE custom_instances SET locked=0 WHERE instance_id='{$instance_id}'  ";
    $db->execute($sqlup);
    echo $sqlup."\n\r";
}


echo "Done \n\r";

function Visit($url){
    $agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)";$ch=curl_init();
    curl_setopt ($ch, CURLOPT_URL,$url );
    curl_setopt($ch, CURLOPT_USERAGENT, $agent);
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt ($ch,CURLOPT_VERBOSE,false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch,CURLOPT_SSLVERSION,3);
    curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, FALSE);
    $page=curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    //we have added 302 because now we do URL redirection in the instance page
    if(($httpcode>=200 && $httpcode<300) || $httpcode==302) return true;
    else return false;
}


//Same as Visit but returns the content rather than http code
function Visit_Content($url){
    $agent = "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)";$ch=curl_init();
    curl_setopt ($ch, CURLOPT_URL,$url );
    curl_setopt($ch, CURLOPT_USERAGENT, $agent);
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt ($ch,CURLOPT_VERBOSE,false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, FALSE);
    curl_setopt($ch,CURLOPT_SSLVERSION,3);
    curl_setopt($ch,CURLOPT_SSL_VERIFYHOST, FALSE);
    $page=curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if($httpcode>=200 && $httpcode<300) return $page;
    else return false;
}


/**
 *
 * encrypt string
 */
function encrypt($string)
{
    // test if encryption is off or blank string
    if (gatorconf::get('encrypt_url_actions') != true || !isset($_SESSION['simple_auth']['cryptsalt']) || $string == '') return $string;

    $key = $_SERVER['SERVER_NAME'];

    $encrypted = base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($key), $string, MCRYPT_MODE_CBC, md5(md5($key))));

    // url safe
    $ret = strtr($encrypted, '+/=', '-_~');

    return $ret;
}

/**
 *
 * decrypt
 */
function decrypt($string)
{
    // test if encryption is off or blank string

    $key = $_SERVER['SERVER_NAME'];

    // clean url safe
    $encrypted = strtr($string, '-_~', '+/=');

    $decrypted = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($key), base64_decode($encrypted), MCRYPT_MODE_CBC, md5(md5($key))), "\0");

    return $decrypted;
}


function copy_scp_recurse($source, $dest){
    global $connection, $sftp;
    if(is_dir($source)) {
        $dir_handle=opendir($source);
        while($file=readdir($dir_handle)){
            if($file!="." && $file!=".."){
                if(is_dir($source."/".$file)){
                    if(!is_dir($dest."/".$file)){
                        ssh2_sftp_mkdir($sftp, $dest."/".$file);
                    }
                    copy_scp_recurse($source."/".$file, $dest."/".$file);
                } else {
                    ssh2_scp_send($connection, $source."/".$file, $dest."/".$file, 0755);
                }
            }
        }
        closedir($dir_handle);
    } else {
        copy($source, $dest);
    }
}


function hashPassword($plainPassword)
{


    // use openwall.com phpass class
    $hasher = new PasswordHash(8, true);
    return $hasher->HashPassword($plainPassword);

}


function send_email($user_email, $data, $email_subject, $email_text)
{
    global $sesclient;

    $subject = $email_subject;
    $email_text = str_replace('<INSTANCE>',$data["aws_instance_id"], $email_text);
    $email_text=str_replace('<HOURS>',$data["hours"],$email_text);
    $email_text=str_replace('<COST>',$data["instance_total_cost"],$email_text);
    $email_text=str_replace('<BALANCE>',$data["user_credit"],$email_text);
    $email_text=str_replace('<CHECKPOINT_TIME>',$data["checkpoint_time"],$email_text);
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
