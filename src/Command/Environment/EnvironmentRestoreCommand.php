<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRestoreCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('environment:restore')
          ->setDescription('Restore an environment backup')
          ->addArgument('backup', InputArgument::OPTIONAL, 'The name of the backup. Defaults to the most recent one')
          ->addOption('no-wait', null, InputOption::VALUE_NONE, 'Do not wait for the operation to complete');
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->addExample('Restore the most recent backup');
        $this->addExample('Restore a specific backup', '92c9a4b2aa75422efb3d');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $backupName = $input->getArgument('backup');
        if (!empty($backupName)) {
            // Find the specified backup.
            $backupActivities = $environment->getActivities(0, 'environment.backup');
            foreach ($backupActivities as $activity) {
                if ($activity['payload']['backup_name'] == $backupName) {
                    $selectedActivity = $activity;
                    break;
                }
            }
            if (empty($selectedActivity)) {
                $this->stdErr->writeln("Backup not found: <error>$backupName</error>");

                return 1;
            }
        } else {
            // Find the most recent backup.
            $environmentId = $environment['id'];
            $this->stdErr->writeln("Finding the most recent backup for the environment <info>$environmentId</info>");
            $backupActivities = $environment->getActivities(1, 'environment.backup');
            if (!$backupActivities) {
                $this->stdErr->writeln("No backups found");

                return 1;
            }
            /** @var \Platformsh\Client\Model\Activity $selectedActivity */
            $selectedActivity = reset($backupActivities);
        }

        if (!$selectedActivity->operationAvailable('restore')) {
            if (!$selectedActivity->isComplete()) {
                $this->stdErr->writeln("The backup is not complete, so it cannot be restored");
            } else {
                $this->stdErr->writeln("The backup cannot be restored");
            }

            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $name = $selectedActivity['payload']['backup_name'];
        $date = date('Y-m-d H:i', strtotime($selectedActivity['created_at']));
        if (!$questionHelper->confirm(
          "Are you sure you want to restore the backup <comment>$name</comment> from <comment>$date</comment>?",
          $input,
          $this->stdErr
        )
        ) {
            return 1;
        }

        $this->stdErr->writeln("Restoring backup <info>$name</info>");

        $activity = $selectedActivity->restore();
        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
              "The backup was successfully restored",
              "Restoring failed"
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }
}
