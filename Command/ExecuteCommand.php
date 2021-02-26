<?php

/** @noinspection PhpUnused */
/** @noinspection PhpMissingFieldTypeInspection */

namespace JMose\CommandSchedulerBundle\Command;

use Doctrine\Persistence\ObjectManager;
use JMose\CommandSchedulerBundle\Entity\ScheduledCommand;
use JMose\CommandSchedulerBundle\Service\CommandSchedulerExcecution;
use Symfony\Bridge\Doctrine\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class ExecuteCommand : This class is the entry point to execute all scheduled command.
 *
 * @author  Julien Guyon <julienguyon@hotmail.com>
 */
//#[ConsoleCommand(name: 'scheduler:execute', description: 'Execute scheduled commands')]
class ExecuteCommand extends Command
{
    use LockableTrait;

    /**
     * @var string
     */
    protected static $defaultName = 'scheduler:execute';
    //private ObjectManager | EntityManager $em;
    private ObjectManager $em;
    private EventDispatcherInterface $eventDispatcher;
    private string $dumpMode;
    private ?int $commandsVerbosity = null;
    private $output;
    private InputInterface $input;
    private CommandSchedulerExcecution $commandSchedulerExcecution;

    /**
     * ExecuteCommand constructor.
     *
     * @param CommandSchedulerExcecution $commandSchedulerExcecution
     * @param EventDispatcherInterface $eventDispatcher
     * @param ManagerRegistry $managerRegistry
     * @param string $managerName
     * @param string | bool $logPath
     */
    public function __construct(
        CommandSchedulerExcecution $commandSchedulerExcecution,
        EventDispatcherInterface $eventDispatcher,
        ManagerRegistry $managerRegistry,
        string $managerName,
    private string | bool $logPath
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->em = $managerRegistry->getManager($managerName);
        //$this->em = $managerRegistry->getManagerForClass(ScheduledCommand::class);
        //EntityManagerInterface
        //$this->em = $this->getDoctrine()->getManager($managerName);

        $this->commandSchedulerExcecution = $commandSchedulerExcecution;

        // If logpath is not set to false, append the directory separator to it
        if (false !== $this->logPath) {
            $this->logPath = rtrim($this->logPath, '/\\').DIRECTORY_SEPARATOR;
        }

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        $this->setDescription('Execute scheduled commands')
            ->addOption('dump', null, InputOption::VALUE_NONE, 'Display next execution')
            ->addOption('no-output', null, InputOption::VALUE_NONE, 'Disable output message from scheduler')
            ->setHelp(<<<'HELP'
The <info>%command.name%</info> is the entry point to execute all scheduled command:

You can list the commands with last and next exceution time with
<info>php bin/console scheduler:list</info>

HELP
            );
    }

    /**
     * Initialize parameters and services used in execute function.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->output = $output;
        $this->input = $input;

        $this->dumpMode = (string) $this->input->getOption('dump');

        // Store the original verbosity before apply the quiet parameter
        $this->commandsVerbosity = $this->output->getVerbosity();

        if (true === $this->input->getOption('no-output')) {
            $this->output->setVerbosity(OutputInterface::VERBOSITY_QUIET);
        }
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Exception
     * @throws ExceptionInterface
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /*
         * Be sure that there are no overlapping Execution of commands.
         * The command is released at the end of this function
         * @see https://symfony.com/doc/current/console/lockable_trait.html
         */
        if (!$this->lock()) {
            $this->output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }


        $sectionListing = $this->output->section();
        $io = new SymfonyStyle($this->input, $sectionListing);

        // Before continue, we check that the "log_path" is valid and writable (except for gaufrette)
        if (false !== $this->logPath &&
            !str_starts_with($this->logPath, 'gaufrette:') &&
            !is_writable($this->logPath)
        ) {
            $io->error(
                $this->logPath.' not found or not writable. Check `log_path` in your config.yml'
            );

            return Command::FAILURE;
        }

        $commandsToExceute = $this->em->getRepository(ScheduledCommand::class)
            ->findCommandsToExecute();
        $amountCommands = count($commandsToExceute);




        $io->title('Start : '.($this->dumpMode ? 'Dump' : 'Execute').' of '.$amountCommands.' scheduled command(s)');


        if (is_iterable($commandsToExceute) && $amountCommands >= 1)
        {
        # dry-run ?
        if ($this->input->getOption('dump'))
        {
            foreach ($commandsToExceute as $command)
            {
                $io->info($command->getName().': '.$command->getCommand().' '.$command->getArguments());
            }
        }
        else
        {
            # Exceute
            $sectionProgressbar = $this->output->section();
            $progress = new ProgressBar($sectionProgressbar);
            $progress->setMessage('Start');
            $progress->start($amountCommands);

                foreach ($commandsToExceute as $command) {

                    $progress->setMessage('Start Exceution of '.$command->getCommand().' '.$command->getArguments());

                    $result = $this->commandSchedulerExcecution->executeCommand($command, $this->input->getOption('env'), $this->commandsVerbosity);

                if($result==0)
                {$io->success($command->getName().': '.$command->getCommand().' '.$command->getArguments());}
                else
                {$io->error($command->getName().': ERROR '.$result.': '.$command->getCommand().' '.$command->getArguments());}

                    $progress->advance();
                }

            $progress->finish();
            $sectionProgressbar->clear();

            $io->section('Finished Excecutions');

        }}
        else {
            $io->success('Nothing to do.');
        }


        $this->release();

        return Command::SUCCESS;
    }
}
