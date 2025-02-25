<?php
namespace axenox\PackageManager;

use exface\Core\Factories\DataSheetFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\AppInterface;
use exface\Core\CommonLogic\Model\ConditionGroup;
use exface\Core\CommonLogic\Model\Aggregator;
use exface\Core\DataTypes\AggregatorFunctionsDataType;
use exface\Core\Interfaces\Selectors\AppSelectorInterface;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;
use exface\Core\Factories\ConditionFactory;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\CommonLogic\QueryBuilder\RowDataArrayFilter;
use exface\Core\Exceptions\Installers\InstallerRuntimeError;

class MetaModelInstaller extends AbstractAppInstaller
{

    const FOLDER_NAME_MODEL = 'Model';
    
    private $objectSheet = null;

    /**
     *
     * @param string $source_absolute_path
     * @return string
     */
    public function install($source_absolute_path) 
    {
        return $this->installModel($this->getSelectorInstalling(), $source_absolute_path);
    }

    /**
     *
     * @param string $source_absolute_path
     * @return string
     */
    public function update($source_absolute_path)
    {
        return $this->installModel($this->getSelectorInstalling(), $source_absolute_path);
    }

    /**
     *
     * @param string $destination_absolute_path
     *            Destination folder for meta model backup
     * @return string
     */
    public function backup($destination_absolute_path)
    {
        return $this->backupModel($destination_absolute_path);
    }

    /**
     *
     * @return string
     */
    public function uninstall()
    {
        $result = '';
        $sheets = $this->getModelDataSheets();
        if (! empty($sheets)){
            array_reverse($sheets);
            
            $transaction = $this->getWorkbench()->data()->startTransaction();
            
            foreach ($sheets as $ds){
                $counter = $ds->dataDelete($transaction);
                if ($counter > 0) {
                    $result .= ($result ? "; " : "") . $ds->getMetaObject()->getName() . " - " . $counter;
                }
            }
            
            $transaction->commit();
            
            if (! $result) {
                $result .= 'Nothing to uninstall';
            }
        } else {
            $result .= 'Nothing to uninstall';
        }
        return "\nModel changes: " . $result;
    }

    /**
     * Analyzes model data sheet and writes json files to the model folder
     *
     * @param string $destinationAbsolutePath
     * @return string
     */
    protected function backupModel($destinationAbsolutePath) : string
    {
        $result = '';
        $app = $this->getApp();
        $dir = $destinationAbsolutePath . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        // Fetch all model data in form of data sheets
        $sheets = $this->getModelDataSheets();
        
        // Make sure, the destination folder is there and empty (to remove 
        // files, that are not neccessary anymore)
        $app->getWorkbench()->filemanager()->pathConstruct($dir);
        // Remove any old files AFTER the data sheets were read successfully
        // in order to keep old data on errors.
        $app->getWorkbench()->filemanager()->emptyDir($dir);
        
        // Save each data sheet as a file and additionally compute the modification date of the last modified model instance and
        // the MD5-hash of the entire model definition (concatennated contents of all files). This data will be stored in the composer.json
        // and used in the installation process of the package
        $last_modification_time = '0000-00-00 00:00:00';
        $model_string = '';
        foreach ($sheets as $nr => $ds) {
            $model_string .= $this->exportModelFile($dir, $ds, str_pad($nr, 2, '0', STR_PAD_LEFT) . '_');
            $time = $ds->getColumns()->getByAttribute($ds->getMetaObject()->getAttribute('MODIFIED_ON'))->aggregate(new Aggregator($this->getWorkbench(), AggregatorFunctionsDataType::MAX));
            $last_modification_time = $time > $last_modification_time ? $time : $last_modification_time;
        }
        
        // Save some information about the package in the extras of composer.json
        $package_props = array(
            'app_uid' => $app->getUid(),
            'app_alias' => $app->getAliasWithNamespace(),
            'model_md5' => md5($model_string),
            'model_timestamp' => $last_modification_time
        );
        
        $packageManager = $this->getWorkbench()->getApp("axenox.PackageManager");
        $composer_json = $packageManager->getComposerJson($app);
        $composer_json['extra']['app'] = $package_props;
        $packageManager->setComposerJson($app, $composer_json);
        
        $result .= "\n" . 'Created meta model backup for "' . $app->getAliasWithNamespace() . '".';
        
        // Backup pages.
        $pageInstaller = new PageInstaller($this->getSelectorInstalling());
        $result .= '. ' . $pageInstaller->backup($destinationAbsolutePath);
        
        return $result;
    }

    /**
     * Writes JSON File of a $data_sheet to a specific location
     *
     * @param string $backupDir            
     * @param DataSheetInterface $data_sheet
     * @param string $filename_prefix            
     * @return string
     */
    protected function exportModelFile($backupDir, DataSheetInterface $data_sheet, $filename_prefix = null, $split_by_object = true) : string
    {
        if ($data_sheet->isEmpty()) {
            return '';
        }
        
        if (! file_exists($backupDir)) {
            Filemanager::pathConstruct($backupDir);
        }
        
        if ($split_by_object === true) {
            if ($data_sheet->getMetaObject()->isExactly('exface.Core.OBJECT')) {
                $col = $data_sheet->getUidColumn();
                $objectUids = $col->getValues(false);
            } else {
                foreach ($data_sheet->getColumns() as $col) {
                    if ($attr = $col->getAttribute()) {
                        if ($attr->isRelation() && $attr->getRelation()->getRightObject()->isExactly('exface.Core.OBJECT') && $attr->isRequired()) {
                            $objectUids = array_unique($col->getValues(false));
                            break;
                        }
                    }
                }
            }
        }
        
        $fileManager = $this->getWorkbench()->filemanager();
        $fileName = $filename_prefix . $data_sheet->getMetaObject()->getAlias() . '.json';
        if (! empty($objectUids)) {
            $rows = $data_sheet->getRows();
            $uxon = $data_sheet->exportUxonObject();
            $objectColumnName = $col->getName();
            foreach ($objectUids as $objectUid) {
                $uxon->setProperty('rows', array_values($this->filterRows($rows, $objectColumnName, $objectUid)));
                $subfolder = $backupDir . DIRECTORY_SEPARATOR . $this->getObjectSubfolder($objectUid);
                $fileManager->dumpFile($subfolder . DIRECTORY_SEPARATOR . $fileName, $uxon->toJson(true));
            }
        } else {
            $contents = $data_sheet->exportUxonObject()->toJson(true);
            $fileManager->dumpFile($backupDir . DIRECTORY_SEPARATOR . $fileName, $contents);
            return $contents;
        }
        
        return '';
    }
    
    protected function filterRows(array $rows, string $filterRowName, string $filterRowValue)
    {
        $filter = new RowDataArrayFilter();
        $filter->addAnd($filterRowName, $filterRowValue, EXF_COMPARATOR_EQUALS);
        return $filter->filter($rows);
    }

    /**
     *
     * @param AppInterface $app            
     * @return DataSheetInterface[]
     */
    public function getModelDataSheets() : array
    {
        $sheets = array();
        $app = $this->getApp();        
        $sheets = array();
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.APP'), 'UID');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATATYPE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_BEHAVIORS'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.ATTRIBUTE'), 'OBJECT__APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.DATASRC'), 'APP', array(
            'CONNECTION',
            'CUSTOM_CONNECTION',
            'QUERYBUILDER',
            'CUSTOM_QUERY_BUILDER'
        ));
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.CONNECTION'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.MESSAGE'), 'APP');
        $sheets[] = $this->getObjectDataSheet($app, $this->getWorkbench()->model()->getObject('ExFace.Core.OBJECT_ACTION'), 'APP');
        
        return $sheets;
    }

    /**
     *
     * @param AppInterface $app            
     * @param MetaObjectInterface $object            
     * @param string $app_filter_attribute_alias   
     * @param array $exclude_attribute_aliases         
     * @return DataSheetInterface
     */
    protected function getObjectDataSheet($app, MetaObjectInterface $object, $app_filter_attribute_alias, array $exclude_attribute_aliases = array()) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObject($object);
        foreach ($object->getAttributeGroup('~WRITABLE')->getAttributes() as $attr) {
            if (in_array($attr->getAlias(), $exclude_attribute_aliases)){
               continue;
            }
            $ds->getColumns()->addFromExpression($attr->getAlias());
        }
        $ds->addFilterFromString($app_filter_attribute_alias, $app->getUid());
        $ds->getSorters()->addFromString('CREATED_ON', 'ASC');
        $ds->getSorters()->addFromString($object->getUidAttributeAlias(), 'ASC');
        $ds->dataRead();
        return $ds;
    }

    /**
     *
     * @param AppSelectorInterface $app_selector            
     * @param string $source_absolute_path            
     * @return string
     */
    protected function installModel(AppSelectorInterface $app_selector, $source_absolute_path) : string
    {
        $result = '';
        $model_source = $source_absolute_path . DIRECTORY_SEPARATOR . self::FOLDER_NAME_MODEL;
        
        if (is_dir($model_source)) {
            $transaction = $this->getWorkbench()->data()->startTransaction();
            $dataSheets = $this->readModelSheets($model_source);
            foreach ($dataSheets as $data_sheet) {
                try {
                
                    // Remove columns, that are not attributes. This is important to be able to import changes on the meta model itself.
                    // The trouble is, that after new properties of objects or attributes are added, the export will already contain them
                    // as columns, which would lead to an error because the model entities for these columns are not there yet.
                    foreach ($data_sheet->getColumns() as $column) {
                        if (! $column->getMetaObject()->hasAttribute($column->getAttributeAlias()) || ! $column->getAttribute()) {
                            $data_sheet->getColumns()->remove($column);
                        }
                    }
                    
                    if ($mod_col = $data_sheet->getColumns()->getByExpression('MODIFIED_ON')) {
                        $mod_col->setIgnoreFixedValues(true);
                    }
                    if ($user_col = $data_sheet->getColumns()->getByExpression('MODIFIED_BY_USER')) {
                        $user_col->setIgnoreFixedValues(true);
                    }
                    
                    // Disable timestamping behavior because it will prevent multiple installations of the same
                    // model since the first install will set the update timestamp to something later than the
                    // timestamp saved in the model files
                    foreach ($data_sheet->getMetaObject()->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class) as $behavior) {
                        $behavior->disable();
                    }
                    
                    // There were cases, when the attribute, that is being filtered over was new, so the filters
                    // did not work (because the attribute was not there). The solution is to run an update
                    // with create fallback in this case. This will cause filter problems, but will not delete
                    // obsolete instances. This is not critical, as the probability of this case is extremely
                    // low in any case and the next update will turn everything back to normal.
                    if (! $this->checkFiltersMatchModel($data_sheet->getFilters())) {
                        $data_sheet->getFilters()->removeAll();
                        $counter = $data_sheet->dataUpdate(true, $transaction);
                    } else {
                        $counter = $data_sheet->dataReplaceByFilters($transaction);
                    }
                    
                    if ($counter > 0) {
                        $result .= ($result ? "; " : "") . $data_sheet->getMetaObject()->getName() . " - " . $counter;
                    }
                } catch (\Throwable $e) {
                    throw new InstallerRuntimeError($this, 'Failed to install model sheet for "' . $data_sheet->getMetaObject()->getAliasWithNamespace() . '": ' . $e->getMessage(), null, $e);
                }
            }
            
            if (! $result) {
                $result .= 'No changes found';
            }
            
            // Install pages.
            $pageInstaller = new PageInstaller($this->getSelectorInstalling());
            $result_pages = $pageInstaller->install($source_absolute_path);
            $result .= ($result && $result_pages ? '; ' : '') . $result_pages;
            
            // Commit the transaction
            $transaction->commit();
        } else {
            $result .= 'No model files to install';
        }
        return "\nModel changes: " . $result;
    }
    
    /**
     * 
     * @param string $absolutePath
     * @return DataSheetInterface[]
     */
    protected function readModelSheets($absolutePath) : array
    {
        $dataSheets = [];
        $folderSheets = $this->readDataSheetsFromFolder($absolutePath);
        
        ksort($folderSheets);
        
        foreach ($folderSheets as $key => $sheet) {
            $type = StringDataType::substringBefore($key, '@');
            if ($dataSheets[$type] === null) {
                $dataSheets[$type] = $sheet;
            } else {
                $baseSheet = $dataSheets[$type];
                if (! $baseSheet->getMetaObject()->isExactly($sheet->getMetaObject())) {
                    throw new RuntimeException('Model sheet type mismatch: model sheets with same name must have the same structure in all subfolders of the model!');
                }
                $dataSheets[$type]->addRows($sheet->getRows());
            }
        }
        
        return $dataSheets;
    }
    
    protected function readDataSheetsFromFolder(string $absolutePath) : array
    {
        $folderSheets = [];
        
        $exface = $this->getWorkbench();
        foreach (scandir($absolutePath) as $file) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            $path = $absolutePath . DIRECTORY_SEPARATOR . $file;
            $key = $file . '@' . $absolutePath;
            if (is_dir($path)) {
                $folderSheets = array_merge($folderSheets, $this->readDataSheetsFromFolder($path));
            } else {
                $folderSheets[$key] = $this->readDataSheetFromFile($path);
            }
        }
        
        return $folderSheets;
    }
    
    protected function readDataSheetFromFile(string $path) : DataSheetInterface
    {
        $contents = file_get_contents($path);
        
        // Translate old error model to message model
        if (mb_strtolower(basename($path)) === '07_error.json') {
            $replaceFrom = [
                'exface.Core.ERROR',
                'ERROR_CODE',
                'ERROR_TEXT'
            ];
            $replaceTo = [
                'exface.Core.MESSAGE',
                'CODE',
                'TITLE'
            ];
            $contents = str_replace($replaceFrom, $replaceTo, $contents);
        }
        
        return DataSheetFactory::createFromUxon($this->getWorkbench(), UxonObject::fromJson($contents));
    }
    
    protected function checkFiltersMatchModel(ConditionGroup $condition_group) : bool
    {
        foreach ($condition_group->getConditions() as $condition){
            if(! $condition->getExpression()->isMetaAttribute()){
                return false;
            }
        }
        
        foreach ($condition_group->getNestedGroups() as $subgroup){
            if (! $this->checkFiltersMatchModel($subgroup)){
                return false;
            }
        }
        return true;
    }
    
    protected function getObjectSubfolder(string $uid) : string
    {
        if ($this->objectSheet !== null) {
            $row = $this->objectSheet->getRow($this->objectSheet->getUidColumn()->findRowByValue($uid));
            $alias = $this->getApp()->getAliasWithNamespace() . AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER . $row['ALIAS'];
        }
        
        if (! $alias) {
            $alias = $this->getWorkbench()->model()->getObject($uid)->getAliasWithNamespace();
        }
        
        return trim($alias);
    }
}