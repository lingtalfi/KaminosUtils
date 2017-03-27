<?php


namespace KaminosUtils\ModuleUtils;


use Bat\FileSystemTool;
use CopyDir\SimpleCopyDirUtil;
use DirScanner\DirScanner;
use Kamille\Architecture\ApplicationParameters\ApplicationParameters;
use Kamille\Module\ModuleInterface;
use Kamille\Services\XModuleInstaller;
use Kamille\Utils\StepTracker\StepTrackerAwareInterface;
use MethodInjector\MethodInjector;

class ModuleInstallTool
{


    /**
     * The idea is to help a module copy its files to the target application.
     * The module must have a directory named "files" at its root, which contains
     * an app directory (i.e. files/app at the root of the module directory).
     *
     *
     * Usage:
     * ---------
     * From your module install code:
     * ModuleInstallTool::installFiles($this);
     *
     *
     * Note: this code assumes that a files step is created.
     *
     */
    public static function installFiles(ModuleInterface $module, $replaceMode = true)
    {

        $moduleClassName = get_class($module);
        $p = explode('\\', $moduleClassName);
        array_shift($p); // drop Module prefix
        $moduleName = $p[0];


        if ($module instanceof StepTrackerAwareInterface) {
            $module->getStepTracker()->startStep('files');
        }


        $appDir = ApplicationParameters::get('app_dir');
        if (is_dir($appDir)) {
            $sourceAppDir = $appDir . "/class-modules/$moduleName/files/app";
            if (file_exists($sourceAppDir)) {
                $o = SimpleCopyDirUtil::create();
                $o->setReplaceMode($replaceMode);
                $ret = $o->copyDir($sourceAppDir, $appDir);
                $errors = $o->getErrors();
            }
        }

        if ($module instanceof StepTrackerAwareInterface) {
            $module->getStepTracker()->stopStep('files');
        }
    }


    public static function uninstallFiles(ModuleInterface $module, $replaceMode = true)
    {

        $moduleClassName = get_class($module);
        $p = explode('\\', $moduleClassName);
        array_shift($p); // drop Module prefix
        $moduleName = $p[0];


        if ($module instanceof StepTrackerAwareInterface) {
            $module->getStepTracker()->startStep('files');
        }


        $appDir = ApplicationParameters::get('app_dir');
        if (is_dir($appDir)) {
            $sourceAppDir = $appDir . "/class-modules/$moduleName/files/app";
            if (file_exists($sourceAppDir)) {
                DirScanner::create()->scanDir($sourceAppDir, function ($path, $rPath, $level) use ($appDir) {
                    $targetEntry = $appDir . "/" . $rPath;
                    /**
                     * Note: for now, we don't follow symlinks
                     */
                    if (file_exists($targetEntry) && !is_link($targetEntry)) {
                        FileSystemTool::remove($targetEntry);
                    }
                });
            }
        }

        if ($module instanceof StepTrackerAwareInterface) {
            $module->getStepTracker()->stopStep('files');
        }
    }


    public static function bindModuleServices($moduleServicesClassName)
    {
        $o = new MethodInjector();
        $filter = [
            [\ReflectionMethod::IS_STATIC],
            [\ReflectionMethod::IS_PUBLIC, \ReflectionMethod::IS_PROTECTED],
        ];


        $methods = $o->getMethodsList($moduleServicesClassName, $filter);
        foreach ($methods as $method) {
            $m = $o->getMethodByName($moduleServicesClassName, $method);
            if (false === $o->hasMethod($m, 'Core\Services\X', $filter)) {
                $c = trim($m->getContent());
                if (0 === stripos($c, 'protected')) {
                    $c = 'public' . substr($c, 9);
                    $m->setContent($c);
                }
                $o->appendMethod($m, 'Core\Services\X');
            }
        }
    }


    public static function unbindModuleServices($candidateModule)
    {
        $o = new MethodInjector();
        $filter = [
            [\ReflectionMethod::IS_STATIC],
            [\ReflectionMethod::IS_PUBLIC, \ReflectionMethod::IS_PROTECTED],
        ];

        $methods = $o->getMethodsList($candidateModule, $filter);
        foreach ($methods as $method) {
            $m = $o->getMethodByName($candidateModule, $method);
            if (false !== $o->hasMethod($m, 'Core\Services\X', $filter)) {
                $o->removeMethod($m, 'Core\Services\X');
            }
        }
    }


    public static function bindModuleHooks($candidateModule)
    {
        $o = new MethodInjector();
        $filter = [
            [\ReflectionMethod::IS_STATIC],
            [\ReflectionMethod::IS_PUBLIC, \ReflectionMethod::IS_PROTECTED],
        ];
        /**
         * The strategy here is that hook method which name starts with the module name is a provider method,
         * and other methods are subscriber methods.
         * So for instance for the Core module, one could find the following methods in the CoreHooks class:
         *
         * - Core_hook1
         * - Core_hook2
         * - OtherModule_doSomething
         * - OtherModule2_doSomethingElse
         *
         * The first two methods are provider methods,
         * and the last two methods are subscriber methods to the OtherModule and OtherModule2 modules respectively.
         *
         *
         *
         */

        // list candidate module's methods
        $p = explode('\\', $candidateModule); // Module is the first component
        $module = $p[1];
        $methods = $o->getMethodsList($candidateModule, $filter);
        $providerMethods = [];
        $subscriberMethods = [];
        foreach ($methods as $method) {
            $p = explode('_', $method, 2);
            $moduleName = $p[0];
            if ($module === $moduleName) {
                $providerMethods[] = $method;
            } else {
                $subscriberMethods[$moduleName][] = $method;
            }
        }

        // list application hooks methods
        $appHooksClass = 'Core\Services\Hooks';
        $appHooksMethods = $o->getMethodsList($appHooksClass, $filter);


        // installed modules
        $installed = XModuleInstaller::getInstalled();


        //--------------------------------------------
        // FIRST, BIND PROVIDERS OF THE CANDIDATE MODULE
        //--------------------------------------------
        foreach ($providerMethods as $method) {
            $m = $o->getMethodByName($candidateModule, $method);
            if (false === $o->hasMethod($m, $appHooksClass, $filter)) {
                $content = trim($m->getContent());
                if (0 === stripos($content, 'protected')) {
                    $content = 'public' . substr($content, 9);
                }


                // compile the method content
                $innerContents = [];
                $innerContents[] = $m->getInnerContent();

                // do other modules want to subscribe to it?
                foreach ($installed as $mod) {
                    $candidateModuleHookClass = 'Module\\' . $mod . '\\' . $mod . 'Hooks';
                    if (false !== ($mSource = $o->getMethodByName($candidateModuleHookClass, $method))) {
                        $innerContent = $mSource->getInnerContent();
                        // prepare inner content
                        $startComment = self::getHookComment($mod, "start");
                        $endComment = self::getHookComment($mod, "end");
                        $innerContent = $startComment . $innerContent . PHP_EOL . trim($endComment);
                        $innerContents[] = $innerContent;
                    }
                }


                $p = explode('{', $content, 2);
                $start = trim($p[0]) . PHP_EOL . "\t{";
                $end = "\t}";
                $innerContents = array_filter($innerContents);
                $body = implode(PHP_EOL, $innerContents);
                $body = self::trimMethodContent($body);
                $lines = explode(PHP_EOL, $body);
                $body = "\t\t" . implode(PHP_EOL . "\t\t", $lines);
                $content = $start . PHP_EOL . $body . PHP_EOL . $end;

                $m->setContent($content);
                $o->appendMethod($m, $appHooksClass);
            }
        }


        //--------------------------------------------
        // BIND SUBSCRIBERS OF THE CLASS BEING INSTALLED
        //--------------------------------------------
        foreach ($installed as $mod) {
            if (array_key_exists($mod, $subscriberMethods)) {
                $methods = $subscriberMethods[$mod];
                $installedHooksClassName = 'Core\Services\Hooks';
                foreach ($methods as $method) {
                    if (false !== ($m = $o->getMethodByName($installedHooksClassName, $method))) {
                        // take the inner content, and add it to the target module's hook method

                        if (false !== ($mSource = $o->getMethodByName($candidateModule, $method))) {
                            $innerContent = $mSource->getInnerContent();

                            // does the target hook class already contain the hook?
                            $startComment = self::getHookComment($module, "start");
                            $targetInnerContent = $m->getInnerContent();

                            if (false === strpos($targetInnerContent, $startComment)) { // if not, we add the hook

                                $innerContent = self::trimMethodContent($innerContent);
                                $targetInnerContent = self::trimMethodContent($targetInnerContent);
                                $endComment = self::getHookComment($module, "end");
                                $innerContent = $startComment . $innerContent . PHP_EOL . $endComment;
                                $targetInnerContent .= PHP_EOL . $innerContent;
                                $targetInnerContent = trim($targetInnerContent);

                                $o->replaceMethodByInnerContent($installedHooksClassName, $method, $targetInnerContent);
                            }
                        }
                    }
                }
            }
        }
    }

    public static function unbindModuleHooks($candidateModule)
    {
        $targetClass = 'Core\Services\Hooks';
        $p = explode('\\', $candidateModule); // Module is the first component
        $module = $p[1];


        $o = new MethodInjector();
        $filter = [
            [\ReflectionMethod::IS_STATIC],
            [\ReflectionMethod::IS_PUBLIC],
        ];
        // unbind providers
        $hooksMethods = $o->getMethodsList($targetClass, $filter);

        // unbind subscribers
        foreach ($hooksMethods as $method) {
            if (0 !== strpos($method, $module . "_")) {
                if (false !== ($m = $o->getMethodByName($targetClass, $method))) {

                    $innerContent = $m->getInnerContent();
                    // does the target hook class already contain the hook?
                    $startComment = self::getHookComment($module, "start");
                    $startComment = trim($startComment);

                    if (false !== strpos($innerContent, $startComment)) {
                        $endComment = self::getHookComment($module, "end");
                        $endComment = trim($endComment);


                        $pattern = '!' . $startComment . '.*' . $endComment . '!Ums';
                        $innerContent = preg_replace($pattern, '', $innerContent);
                        $innerContent = self::trimMethodContent($innerContent);
                        $innerContent = trim($innerContent);
                        $o->replaceMethodByInnerContent($targetClass, $method, $innerContent);
                    }
                }
            }
        }


        /**
         * Then unbind providers.
         * I don't know why but unbinding providers has to be done AFTER unbinding subscribers (with the current code
         * at least)
         */
        foreach ($hooksMethods as $method) {
            if (0 === strpos($method, $module . "_")) {
                if (false !== ($m = $o->getMethodByName($targetClass, $method))) {
                    $o->removeMethod($m, $targetClass);
                }
            }
        }

    }
    //--------------------------------------------
    //
    //--------------------------------------------
    private static function trimMethodContent($content)
    {
        $p = explode(PHP_EOL, $content);
        $p = array_map(function ($v) {
            return trim($v);
        }, $p);
        return implode(PHP_EOL, $p);
    }

    private static function getHookComment($module, $type = "start")
    {
        return '// mit-' . $type . ':' . $module . PHP_EOL;
    }

}