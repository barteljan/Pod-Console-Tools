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

class UploadPodRepoCommand  extends Command{

    protected function configure()
    {
        $this->setName("pod:upload")
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

        $this->writeHeader($output,"Pod: ".$path);

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

        $this->writeHeader($output,"Upload podspec to repo ".$repo);

        $this->uploadPodSpecToRepo($output,$repo);

        $podspecVersion = $this->getPodspecVersionFromPath($this->getPodSpecPathInDirectory($path));
        $this->writeHeader($output,"Sucessfully uploaded podspec: '".$path."' to version: '".$podspecVersion."'\nto podspec-repo ".$repo."'");

        chdir($oldPath);
    }

    protected function uploadPodSpecToRepo($output,$podSpecRepo){
        $command = 'pod repo push '.$podSpecRepo." --allow-warnings --verbose";
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $return = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        if($return>0){
            throw new \Exception("We cannot update your local pods version.\n Failure updating podspec version!");
        }
    }

    protected function writeHeader(OutputInterface $output,$text){
        $output->writeln("");
        $output->writeln("---------------------------------------------");
        $output->writeln("<header>".$text."</header>");
        $output->writeln("---------------------------------------------");
        $output->writeln("");
    }

    protected function checkForUncommitedChanges(InputInterface $input, OutputInterface $output,$force){

        $questionHelper = $this->getHelper('question');

        $hasUncommitedFiles = $this->hasUncommitedFiles();

        $this->writeHeader($output,"Commit uncommited changes");

        $question = new ConfirmationQuestion('You have uncommited changes in your local commit repo.'."\n"
                                            .'Do you want to commit them ? (n)',false);

        if($hasUncommitedFiles && !$force ){
            $this->printGitStatus($input,$output);
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

        $command = 'git add .';
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $return = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        if($return>0){
            throw new \Exception("We cannot update your local pods version.\nFailure adding all files to git!");
        }

        $command = 'git commit -m "'.$message.'"';
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $return = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        if($return>0){
            throw new \Exception("We cannot update your local pods version.\nFailure commiting files to git!");
        }
    }

    protected function commitTagAndPushCurrentVersion(InputInterface $input,OutputInterface $output,$force,$version){

         $this->writeHeader($output,"Commit new version: ".$version);
         $this->commitAllFiles($input,$output,$force,"Changed pod version to ".$version);
         $this->writeHeader($output,"Tag new version: ".$version);
         $this->tagGitVersion($output,$version);
         $this->writeHeader($output,"Push new version: ".$version);
         $this->gitPush($input,$output,$force);
    }


    protected function tagGitVersion(OutputInterface $output,$version){
        $command = 'git tag '.$version;
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $return = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        if($return>0){
            throw new \Exception("We cannot update your local pods version.\nFailure tagging git with:".$version."!");
        }

    }

    protected function gitPush(InputInterface $input,OutputInterface $output,$force){

        $questionHelper = $this->getHelper('question');

        $question = new ConfirmationQuestion('Push changes to remote ? (n): ',false);

        if($force || $questionHelper->ask($input,$output,$question)){

            $command = 'git push';
            $output->writeln($command);
            $process = new Process($command);

            $process->setTimeout(3600);
            $return = $process->run(function ($type, $buffer) {
                global $output;
                echo '       '.$buffer;
            });

            if($return>0){
                throw new \Exception("We cannot update your local pods version.\nFailure tagging pushing repo to remote!");
            }

            $command = 'git push --tags';
            $output->writeln($command);
            $process = new Process($command);

            $process->setTimeout(3600);
            $return = $process->run(function ($type, $buffer) {
                global $output;
                echo '       '.$buffer;
            });

            if($return>0){
                throw new \Exception("We cannot update your local pods version.\nFailure tagging pushing tags to remote!");
            }

        }
    }


    protected function printGitStatus(InputInterface $input, OutputInterface $output){
        $command = 'git status';
        $output->writeln($command);
        $process = new Process($command);

        $process->setTimeout(3600);
        $return = $process->run(function ($type, $buffer) {
            global $output;
            echo '       '.$buffer;
        });

        if($return>0){
            throw new \Exception("We cannot update your local pods version.\nCannot output status of git repo!");
        }

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

        $currentVersion = $this->getPodspecVersionFromPath($podSpecPath);

        $this->writeHeader($output,"Current podspec version is: ".$currentVersion);

        $nextVersion = $this->incrementedVersion($currentVersion);


        if(!$force){
            $questionHelper = $this->getHelper('question');
            $question = new ConfirmationQuestion("Next podspec version should be: ".$nextVersion." is this correct ? (n)", false);
            $isCorrect = $questionHelper->ask($input,$output,$question);

            if(!$isCorrect){
                $question = new Question("Please enter the correct version number or exit to quit: ");
                $nextVersion = $questionHelper->ask($input,$output,$question);
                if($nextVersion=="exit"){
                    throw new \Exception("We cannot update your local pods version.\nUser aborted update process!");
                }
            }
        }

        if(!$this->checkVersionNumber($nextVersion)){
            throw new \Exception("We cannot update your local pods version.\n Version number ".$nextVersion." is not valid");
        }


        $this->writeHeader($output,"Next podspec version will be: ".$nextVersion);

        $this->writeHeader($output,"Write version to podspec");
        $this->replaceVersionInPath($currentVersion,$nextVersion,$podSpecPath);

        $this->commitTagAndPushCurrentVersion($input,$output,$force,$nextVersion);

        return true;
    }

    protected function checkVersionNumber($versionNumber){

        if(empty($versionNumber)){
            return false;
        }

        //check if version number contains other anything except numbers and some dots
        if (preg_match('/[^0-9.]/', $versionNumber)) {
            return false;
        }

        return true;
    }


    protected function getPodspecVersionFromPath($path){

        //find part where our current version is specified
        $pattern = "/version(\s)*=(\s)*/i";
        $versionLine = implode(preg_grep($pattern, file($path)));
        //remove all newlines
        $versionLine = str_replace("\n","",$versionLine);

        //now extract the version
        $pattern ='/["\']{1}([0-9.]+)["\']{1}/i';
        preg_match($pattern,$versionLine,$versionMatches);
        $version = $versionMatches[1];

        return $version;
    }


    public function replaceVersionInPath($oldVersion,$newVersion,$path){

        if(!file_exists($path)){
            throw new \Exception("We cannot update your local pods version.\n Path :".$path." does not exist");
        }

        $reading = fopen($path, 'r');
        $writing = fopen($path.'.tmp', 'a');

        $pattern = '/version(\s)*=(\s)*"'.$oldVersion.'"/i';
        $versionLine = implode(preg_grep($pattern, file($path)));

        $replaced = false;

        while (!feof($reading)) {
            $line = fgets($reading);


            if ($line == $versionLine) {
                $line = str_replace($oldVersion,$newVersion,$versionLine);
                $replaced = true;
            }
            fputs($writing, $line);

        }
        fclose($reading); fclose($writing);
        // might as well not overwrite the file if we didn't replace anything
        if ($replaced)
        {
            rename($path.'.tmp', $path);
        } else {
            unlink($path.'.tmp');
            throw new \Exception("We cannot update your local pods version.\n Cannot replace version: ".$oldVersion." with ".$newVersion." in ".$path);
        }
    }

    protected function incrementedVersion($versionString){

        //find last version number
        $pattern ='/([0-9]+)$/i';
        preg_match($pattern,$versionString,$matches);
        $lastVersionNumber = $matches[1];
        $incrementedLastVersionNumber = $lastVersionNumber+1;

        //find everything but the last number
        //position of last dot
        $positionOfLastDot = strrpos($versionString,".");
        $everthingButTheLastVersionNumber = substr($versionString,0,$positionOfLastDot);

        //add incremented version number seperated by a dot
        $newVersionString = $everthingButTheLastVersionNumber.".".$incrementedLastVersionNumber;

        return $newVersionString;
    }

    protected function getPodSpecPathInDirectory($directoryPath){

        $filePattern = $directoryPath."*.podspec";
        $filePath = null;
        foreach (glob($filePattern) as $filename) {
            $filePath = $filename;
            break;
        }

        if(!$filename){
            throw new \Exception("We cannot update your local pods version.\nNo Podspec found in local repo: ".$directoryPath);
        }

        return $filename;

    }

}