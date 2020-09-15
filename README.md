# CRPQCheck
CRPQ Checkpointing System for AWS is a php/python environment for checkpointing instances on AWS. It is based around the follow scripts:

- `cron_checkpoint.php` This script is triggered periodically by a cron job and check the SQL database to see if an instance needs to be checkpointed. If it detects an instance which needs to be checkpointed it attachs a volume to the instance, triggers the local checkpointing script (checkpointProcess.py) and creates an AMI of the instance
- `cron_restore.php` This script is triggered periodically by a cron job and checks if an instance which should be active (via the database) have failed and restores the instance by starting a new instance attaching the volume which contains the process memory and running the restore scripts (restoreProcess.py and restoreDocker.py)
- `CRPQ.php` This script triggers checkpoints periodically based upon a queueing system which uses probability of failure and cost of the instance as inputs. Checkpoints are triggered via the SQL database
- `checkpointProcess.py` This script checkpoints local processes using CRIU
- `restoreProcess.py` This script restores local processes using CRIU
- `restoreDocker.py` This script restores local Docker Containers using CRIU *Experimental*
- `configuration_checkpoint.php` This file contains configuration information for the database, mailing system and AWS
- `criu_ssh_restore_expect.sh` Bash script for polling an instance to determine if it has been restored so the restoreProcess.py script can be triggered.

[`CRIU`](https://github.com/checkpoint-restore/criu) is used for the checkpointing and restoration of processes.
