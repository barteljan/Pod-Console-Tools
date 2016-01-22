<?php
/**
 * Created by PhpStorm.
 * User: bartel
 * Date: 22.01.16
 * Time: 05:48
 */

namespace barteljan\pods\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class UpgradePodRepoCommand  extends Command{

    protected function configure()
    {
        $this->setName("pod:upgrade")
            ->setDescription("commits, tags and uploads your current local pod-project into your private pod-repo")
            ->setDefinition(array(
                new InputOption('repo', 'r', InputOption::VALUE_REQUIRED, 'The name of your pod repo'),
                new InputOption('next-version', 'nv', InputOption::VALUE_OPTIONAL, 'The next version of your pod'),
                new InputOption('path', 'p', InputOption::VALUE_OPTIONAL, 'The path to your local pod repo'),
                new InputOption('force', 'f', InputOption::VALUE_NONE, "Don't ask to much just get the job done.")
            ))
            ->setHelp(<<<EOT
commits, tags and uploads your current local pod-project into your private pod-repo

Usage:

<info>pod-tools.phar pod:upgrade -r"<TheNameOfYourRepo>"</info>

EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $header_style = new OutputFormatterStyle('black', 'white', array('bold'));
        $output->getFormatter()->setStyle('header', $header_style);

        //check command options
        $repo = $input->getOption("repo");

        //check if private repo name is set
        if(empty($repo)){
            throw new \InvalidArgumentException('pod repo name not specified - use the option --repo="<YourPrivateRepoName>" for that');
        }

        //set path if not specified
        $oldPath = getcwd();
        $path = $input->getOption("path");
        if(empty($path)){
            $path = $oldPath;
        }
        chdir($path);

        //check if force is set
        $force = $input->getOption("force");

        // check if repo has some uncommited changes
        // commit them if needed (ask user if a commit should be done)
        if(!$this->checkForUncommitedChanges($input,$output,$force)){
            throw new \Exception("We cannot update your local pods version.\nUncommited changes in repo:".$path."");
            return;
        }

        //modifiy version in podfile
        if(!$this->modifyPodfileVersion($input,$output,$force,$path)){
            throw new \Exception("We cannot update your local pods version.\nFailure modifing pod version in repo:".$path."!");
            return;
        }


        chdir($oldPath);
    }

    protected function checkForUncommitedChanges(InputInterface $input, OutputInterface $output,$force){

        $questionHelper = $this->getHelper('question');

        $hasUncommitedFiles = $this->hasUncommitedFiles();

        $question = new ConfirmationQuestion('You have uncommited changes in your local commit repo.'."\n"
                                            .'Do you want to commit them ? (n)',false);

        if($hasUncommitedFiles && !$force ){
            $shouldICommit = $questionHelper->ask($input,$output,$question);
            if($shouldICommit){
                $this->commitAllFiles($input,$output,$force);
                return true;
            }else{
                return false;
            }
        }else if($hasUncommitedFiles && $force){
            $this->commitAllFiles($input,$output,$force);
            return true;
        }

        return true;
    }

    protected function commitAllFiles(InputInterface $input, OutputInterface $output,$force,$message = "Commit repo changes before creating a new pod version"){

        if(!$force){
            $questionHelper = $this->getHelper('question');
            $question = new Question("Please enter a commit message (".getcwd()."): ", $message);
            $message = $questionHelper->ask($input,$output,$question);
        }

        $command = 'git add . && git commit -m "'.$message.'"';
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });
    }

    protected function hasUncommitedFiles(){
        $process = new Process('git diff --quiet HEAD');

        $process->setTimeout(3600);
        //returns 0 when none changes are found and something grater 0 otherwise
        $result = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        return $result;
    }

    protected function modifyPodfileVersion(InputInterface $input, OutputInterface $output,$force, $path){

        $podSpecPath = $this->getPodSpecPathInDirectory($path);
        //TODO: Hier weiter
        $output->writeln($podSpecPath);
        return true;
    }

    protected function getPodSpecPathInDirectory($directoryPath){

        $filePattern = $directoryPath."*.podspec";
        $filePath = null;
        foreach (glob($filePattern) as $filename) {
            $filePath = $filename;
        }

        if(!$filename){
            throw new \Exception("We cannot update your local pods version.\nNo Podspec found in local repo: ".$directoryPath);
        }

        return $filename;

    }

}