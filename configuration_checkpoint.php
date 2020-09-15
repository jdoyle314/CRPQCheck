<?php
/**
 *
 * Configuration options
 *
 * You may need to logout/login for some changes to take effect
 *
 * Make sure Apache service has read/write access to the repository and config folder(s)
 *
 * NOTICE: Current .htaccess file inside repository directory will prevent script and html execution inside that directory
 *
 */

class gatorconf {


	public static function get($param) {

		$config = array(

				// language selection, available languages: english, french, polish, portuguese and serbian
				'language' => 'english',

				// use database to store users? (true/false)
				'use_database' => true,
				'db_host' => '<DB DNS>',
				'db_username' => '<DB Username',
				'db_password' => '<DB Password>',
				'db_database' => '<DB Name>',
				//'db_host' => 'localhost',
				//'db_username' => 'root',
				//'db_password' => '',
				//'db_database' => 'source2screent',

				// main file repository
				// this is also a repository for users without specific homedir
				// you'll need to make sure your webserver can write here
				//'repository' => getcwd().'/repository',
				//'repository' => 's3://media-revqual/'.$_SESSION['simple_auth']['username'],
				's3_root' => '<S3 Bucket URL',
				'local_temp_folder' => '/tmp',

                // email configuration
                'support_mail_from' => 'support@dithen.com',
                'mail_from' => 'admin@dithen.com',
                'mail_from_name' => 'Dithen',
                'mail_signature' => "\n\nBest Regards,\nThe Dithen Team",

                //ec2_config format instance_type => array(cost $, processor, memory, GPU, spot_price)
                'ec2_instance_config' => array(
                    "m3.medium" => array(0.067, 1, 3, 0, 0.0099),
                    "r3.large" => array(0.166, 2, 15, 0, 0.0174),
                    "c3.large" => array(0.105, 2, 3, 0, 0.0178),
                    //"c4.large" => array(0.105, 2, 3, 0, 0.0206),
                    "m3.large" => array(0.133, 2, 7, 0, 0.02),
                    "m4.large" => array(0.12, 2, 8, 0, 0.0241),
                    "c4.xlarge" => array(0.209, 4, 7, 0, 0.0397),
                    //"c3.xlarge" => array(0.21, 4, 7, 0, 0.0523),
                    "r3.xlarge" => array(0.333, 4, 30, 0, 0.0475),
                    "m3.xlarge" => array(0.266, 4, 15, 0, 0.0525),
                    "m4.xlarge" => array(0.239, 4, 16, 0, 0.0576),
                    "m4.2xlarge" => array(0.479, 8, 32, 0, 0.0744),
                    "d2.xlarge" => array(0.69, 4, 30, 0, 0.0725),
                    //"c4.4xlarge" => array(0.838, 16, 30, 0, 0.1893),
                    "i2.xlarge" => array(0.853, 4, 30, 0, 0.0886),
                    "c4.2xlarge" => array(0.419, 8, 15, 0, 0.0894),
                    //"c3.2xlarge" => array(0.42, 8, 15, 0, 0.0967),
                    "m3.2xlarge" => array(0.532, 8, 30, 0, 0.0986),
                    "r3.2xlarge" => array(0.665, 8, 61, 0, 0.1099),
                    "c3.4xlarge" => array(0.84, 16, 30, 0, 0.1593),
                    "i2.2xlarge" => array(1.705, 6, 61, 0, 0.1804),
                    "r3.4xlarge" => array(1.33, 16, 122, 0, 0.214),
                    "c3.8xlarge" => array(1.68, 32, 60, 0, 0.2893),
                    "d2.4xlarge" => array(2.76, 16, 122, 0, 0.3151),
                    "m4.10xlarge" => array(2.394, 40, 160, 0, 0.4018),
                    "r3.8xlarge" => array(2.66, 32, 244, 0, 0.4117),
                    "i2.4xlarge" => array(3.41, 16, 122, 0, 0.43),
                    "d2.2xlarge" => array(1.38, 8, 61, 0, 0.5),
                    //"c4.8xlarge" => array(1.675, 36, 60, 0, 0.3245),
                    //"g2.2xlarge" => array(0.65, 8, 15, 1, 0.56),
                    "g2.2xlarge" => array(0.3035, 8, 15, 1, 0.3035),    //specail case we set the price of GPU g2 same as spot even though it is not.
                    "d2.8xlarge" => array(5.52, 36, 244, 0, 0.5762),
                    "i2.8xlarge" => array(6.82, 32, 244, 0, 0.7431),
                    "m4.4xlarge" => array(0.958, 16, 64, 0, 0.7859),
                    //"t2.nano" => 0.0065,
                    //"t2.micro" => 0.013,
                    //"t2.small" => 0.026,
                    //"t2.medium" => 0.052,
                    //"t2.large" => 0.104,
                ),

                'ec2_storage_magnetic' => 0.00416666666666666666666666666667/60*8,  //dollar/60 GB/hour
                'ec2_default_disk_used' => 2,  //the space used vs available in each image in GB
                'ec2_default_disk_size' => 8,  //default image disk size

				'ec2_price_mutiplier' => 4,
				'dollar_to_GBP' => 0.70,

			// AWS image config
			'IMAGEID' => "<Image ID>",
			'SECURITYGROUP' => "<Security Group>",

            'update_location' => '<Cert Location>',


		);

		/**
		 *
		 * End of configuration options
		 *
		*/

		//$config['base_path'] = str_replace('\\', '/', $config['base_path']);
		if (class_exists('gator')) $config = gator::validateConf($config, $param);

		return $config[$param];
	}

}