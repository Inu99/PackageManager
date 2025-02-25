<?php
namespace axenox\PackageManager\Actions;

use kabachello\ComposerAPI\ComposerAPI;
use exface\Core\Interfaces\Actions\iModifyData;
use exface\Core\Exceptions\Actions\ActionInputMissingError;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * This action runs one or more selected test steps
 *
 * @author Andrej Kabachnik
 *        
 */
class ComposerRequire extends AbstractComposerAction implements iModifyData
{

    protected function init()
    {
        parent::init();
        $this->setIcon(Icons::INSTALL);
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \axenox\PackageManager\Actions\AbstractComposerAction::performComposerAction()
     */
    protected function performComposerAction(ComposerAPI $composer, TaskInterface $task)
    {
        $input = $this->getInputDataSheet($task);
        
        if (! $input->getMetaObject()->isExactly('axenox.PackageManager.PACKAGE_INSTALLED')) {
            throw new ActionInputInvalidObjectError($this, 'Wrong input object for action "' . $this->getAliasWithNamespace() . '" - "' . $input->getMetaObject()->getAliasWithNamespace() . '"! This action requires input data based based on "axenox.PackageManager.PACKAGE_INSTALLED"!', '6T5E8Q6');
        }
        
        $packages = array();
        foreach ($input->getRows() as $nr => $row) {
            if (! isset($row['name']) || ! $row['name']) {
                throw new ActionInputMissingError($this, 'Missing package name in row ' . $nr . ' of input data for action "' . $this->getAliasWithNamespace() . '"!', '6T5TRFE');
            }
            $packages[] = $row['name'] . ($row['version'] ? ':' . $row['version'] : '');
            
            if ($row['repository_type'] && $row['repository_url']) {
                $composer->config('repositories.' . $row['name'], array(
                    $row['repository_type'],
                    $row['repository_url']
                ));
            }
        }
        
        return $composer->require($packages);
    }
}
?>