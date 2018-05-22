<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{
    protected static $defaultName = 'environment:push';

    private $api;
    private $activityMonitor;
    private $config;
    private $git;
    private $questionHelper;
    private $selector;
    private $ssh;

    public function __construct(
        Api $api,
        ActivityMonitor $activityMonitor,
        Config $config,
        Git $git,
        QuestionHelper $questionHelper,
        Selector $selector,
        Ssh $ssh
    ) {
        $this->api = $api;
        $this->activityMonitor = $activityMonitor;
        $this->config = $config;
        $this->git = $git;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->ssh = $ssh;
        parent::__construct();
    }


    protected function configure()
    {
        $this->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('source', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The target branch name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('set-upstream', 'u', InputOption::VALUE_NONE, 'Set the target environment as the upstream for the source branch')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment after pushing')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent (only used with --activate)');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityMonitor->addWaitOptions($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Push code to the current environment');
        $this->addExample('Push code, without waiting for deployment', '--no-wait');
        $this->addExample(
            'Push code and activate the environment as a child of \'develop\'',
            '--activate --parent develop'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $this->git->setDefaultRepositoryDir($projectRoot);

        // Validate the source argument.
        $source = $input->getArgument('source');
        if ($source === '') {
            $this->stdErr->writeln('The <error><source></error> argument cannot be specified as an empty string.');
            return 1;
        } elseif (strpos($source, ':') !== false
            || !($sourceRevision = $this->git->execute(['rev-parse', '--verify', $source]))) {
            $this->stdErr->writeln(sprintf('Invalid source ref: <error>%s</error>', $source));
            return 1;
        }

        $this->stdErr->writeln(
            sprintf('Source revision: %s', $sourceRevision),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        // Find the target branch name (--target, the name of the current
        // environment, or the Git branch name).
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($selection->hasEnvironment()) {
            $target = $selection->getEnvironment()->id;
        } elseif ($currentBranch = $this->git->getCurrentBranch()) {
            $target = $currentBranch;
        } else {
            $this->stdErr->writeln('Could not determine target environment name.');
            return 1;
        }

        // Guard against accidental pushing to production.
        if ($target === 'master'
            && !$this->questionHelper->confirm(
                'Are you sure you want to push to the <comment>master</comment> (production) branch?'
            )) {
            return 1;
        }

        // Determine whether the target environment is new.
        $project = $selection->getProject();
        $targetEnvironment = $this->api->getEnvironment($target, $project);
        $this->stdErr->writeln(sprintf(
            'Pushing <info>%s</info> to the %s environment <info>%s</info>',
            $source,
            $targetEnvironment ? 'existing' : 'new',
            $target
        ));

        $activate = false;
        $parentId = null;
        if ($target !== 'master') {
            // Determine whether to activate the environment after pushing.
            if (!$targetEnvironment || $targetEnvironment->status === 'inactive') {
                $activate = $input->getOption('activate')
                    || ($input->isInteractive() && $this->questionHelper->confirm(sprintf(
                        'Activate <info>%s</info> after pushing?',
                        $target
                    )));
            }

            // If activating, determine what the environment's parent should be.
            if ($activate) {
                $parentId = $input->getOption('parent');
                if (!$parentId) {
                    $autoCompleterValues = array_keys($this->api->getEnvironments($project));
                    $parentId = $autoCompleterValues === ['master']
                        ? 'master'
                        : $this->questionHelper->askInput('Parent environment', 'master', $autoCompleterValues);
                }
            }
        }

        // Ensure the correct Git remote exists.
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Build the Git command.
        $gitArgs = [
            'push',
            $this->config->get('detection.git_remote_name'),
            $source . ':refs/heads/' . $target,
        ];
        foreach (['force', 'force-with-lease', 'set-upstream'] as $option) {
            if ($input->getOption($option)) {
                $gitArgs[] = '--' . $option;
            }
        }

        // Build the SSH command to use with Git.
        $extraSshOptions = [];
        $env = [];
        if (!$this->activityMonitor->shouldWait($input)) {
            $extraSshOptions['SendEnv'] = 'PLATFORMSH_PUSH_NO_WAIT';
            $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
        }
        $this->git->setSshCommand($this->ssh->getSshCommand($extraSshOptions));

        // Push.
        $success = $this->git->execute($gitArgs, null, false, false, $env);
        if (!$success) {
            return 1;
        }

        // Clear some caches after pushing.
        $this->api->clearEnvironmentsCache($project->id);
        if ($selection->hasEnvironment()) {
            try {
                $sshUrl = $selection->getEnvironment()->getSshUrl();
                /** @var \Platformsh\Cli\Service\Relationships $relationships */
                $relationships = $this->getService('relationships');
                $relationships->clearCaches($sshUrl);
            } catch (EnvironmentStateException $e) {
                // Ignore environments with a missing SSH URL.
            }
        }

        if ($activate) {
            $args = [
                '--project' => $project->getUri(),
                '--environment' => $target,
                '--parent' => $parentId,
                '--yes' => true,
                '--no-wait' => $input->getOption('no-wait'),
            ];

            return $this->runOtherCommand('environment:activate', $args);
        }

        return 0;
    }
}
