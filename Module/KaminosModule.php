<?php


namespace KaminosUtils\Module;


use Kamille\Architecture\ApplicationParameters\ApplicationParameters;

use Kamille\Module\ModuleInterface;
use KaminosUtils\Module\Exception\KaminosModuleException;
use KaminosUtils\ModuleUtils\ModuleInstallTool;
use Output\ProgramOutputAwareInterface;
use Output\ProgramOutputInterface;


/**
 * This class helps you implementing basic module install tasks, like:
 * - mapping the module files to the application
 *      (just create a files/app directory inside your module directory)
 *
 *
 * But there is a philosophy that comes with it (that's the price to pay).
 * So the philosophy is that a module install/uninstall is composed of steps.
 *
 * Each module uses a certain number of steps (depending on the module);
 * the idea is to be able to display the following to the user:
 *
 * - step 1/5: installing files
 * - step 2/5: installing database
 * - ...
 *
 * So the benefit of having steps is that we have some kind of map/synopsis,
 * and we know in advance HOW MANY steps are required, which is the useful information
 * this philosophy try to promote.
 *
 * Some steps are registered automatically by this class (for files/app for instance, and other
 * auto mechanisms); and you need to register your own steps with the registerSteps method.
 *
 *
 *
 */
abstract class KaminosModule implements ProgramOutputAwareInterface, ModuleInterface
{
    /**
     * @var ProgramOutputInterface $output
     */
    private $output;
    /**
     * @var array of id => label
     */
    private $steps;


    public function __construct()
    {
        $this->steps = [];
    }


    public function install()
    {

        $steps = [];
        $this->collectAutoSteps($steps, 'install');

        $this->registerSteps($steps, 'install');
        $this->steps = $steps;


        $this->installAuto();
        $this->installModule();
    }

    public function uninstall()
    {
        // TODO: Implement uninstall() method.
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    public function setProgramOutput(ProgramOutputInterface $output)
    {
        $this->output = $output;
        return $this;
    }


    //--------------------------------------------
    //
    //--------------------------------------------
    protected function installModule()
    {

    }

    protected function uninstallModule()
    {

    }


    /**
     * @param $type , string (install|uninstall)
     */
    protected function registerSteps(array &$steps, $type)
    {

    }


    protected function startStep($stepId)
    {
        if (array_key_exists($stepId, $this->steps)) {
            $label = $this->getStepLabel($stepId);
            $this->getOutput()->notice($label, false);
        } else {
            throw new KaminosModuleException("step $stepId doesn't exist");
        }
    }


    protected function stopStep($stepId, $text = "done")
    {
        if (array_key_exists($stepId, $this->steps)) {
            if ("done" === $text) {
                $this->getOutput()->success($text);
            }
        } else {
            throw new KaminosModuleException("step $stepId doesn't exist");
        }
    }


    protected function collectAutoSteps(array &$steps, $type)
    {
        if (true === $this->useAutoFiles()) {
            if ('install' === $type) {
                $steps['files'] = "Installing files";
            } else {
                $steps['files'] = "Uninstalling files";
            }
        }
        if (true === $this->useXServices()) {
            if ('install' === $type) {
                $steps['xservices'] = "Installing services";
            } else {
                $steps['xservices'] = "Uninstalling services";
            }
        }
    }

    protected function installAuto()
    {
        if (true === $this->useAutoFiles()) {
            $this->startStep('files');
            ModuleInstallTool::installFiles($this);
            $this->stopStep('files', "done");
        }

        if (true === $this->useXServices()) {
            $this->startStep('xservices');
            $n = $this->getModuleName();
            $moduleName = 'Module\\' . $n . '\\' . $n . "Services";
            ModuleInstallTool::bindModuleServices($moduleName);
            $this->stopStep('xservices', "done");
        }
    }

    //--------------------------------------------
    //
    //--------------------------------------------
    private function useAutoFiles()
    {
        $d = $this->getModuleDir();
        $f = $d . "/files/app";
        return (file_exists($f));
    }


    private function useXServices()
    {
        $d = $this->getModuleDir();
        $n = $this->getModuleName();
        $f = $d . "/$n" . "Services.php";
        return (file_exists($f));
    }


    private function getModuleName()
    {
        $className = get_called_class();
        $p = explode('\\', $className);
        array_shift($p); // drop the Module prefix
        return $p[0];
    }

    private function getModuleDir()
    {
        $moduleName = $this->getModuleName();
        $appDir = ApplicationParameters::get("app_dir");
        return $appDir . "/class-modules/$moduleName";
    }

    /**
     * @return ProgramOutputInterface
     */
    private function getOutput()
    {
        return $this->output;
    }

    private function getStepLabel($stepId)
    {
        $n = 0;
        $label = null;
        foreach ($this->steps as $id => $label) {
            $n++;
            if ($id === $stepId) {
                break;
            }
        }
        $count = count($this->steps);
        $msg = "----> Step $n/$count: $label ... ";
        return $msg;
    }

}