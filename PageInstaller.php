<?php
namespace axenox\PackageManager;

use exface\Core\CommonLogic\AppInstallers\AbstractAppInstaller;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\CommonLogic\Model\UiPage;
use exface\Core\Factories\UiPageFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Exceptions\UiPage\UiPageNotFoundError;
use exface\Core\Exceptions\UiPage\UiPageIdMissingError;
use exface\Core\Exceptions\Installers\InstallerRuntimeError;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Interfaces\Selectors\UiPageSelectorInterface;
use exface\Core\Factories\SelectorFactory;

class PageInstaller extends AbstractAppInstaller
{

    const FOLDER_NAME_PAGES = 'Install\\Pages';

    protected function getPagesPathWithLanguage($source_path, $languageCode)
    {
        return $this->getPagePath($source_path) . DIRECTORY_SEPARATOR . $languageCode;
    }

    protected function getPagePath($source_path)
    {
        return $source_path . DIRECTORY_SEPARATOR . $this::FOLDER_NAME_PAGES;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::install()
     */
    public function install($source_absolute_path)
    {
        $pagesFile = [];
        $workbench = $this->getWorkbench();
        $cms = $workbench->getCMS();
        
        // FIXME make the installer get all languages instead of only the default one.
        $dir = $this->getPagesPathWithLanguage($source_absolute_path, $this->getDefaultLanguageCode());
        if (! $dir) {
            // Ist entsprechend der momentanen Sprache kein passender Ordner vorhanden, wird
            // nichts gemacht.
            return;
        }
        
        // Find pages files. 
        $files = glob($dir . DIRECTORY_SEPARATOR . '*.json');
        if ($files === false) {
            $workbench->getLogger()->logException((new InstallerRuntimeError($this, 'Error reading folder "' . $dir . DIRECTORY_SEPARATOR . '*.json"! - no pages were installed!')));
        }
        // Make sure, the array only contains existing files.
        $files = array_filter($files, 'is_file');
        
        // Load pages. If anything goes wrong, the installer should not continue to avoid broken menu
        // structures etc., so don't silence any exceptions here.
        foreach ($files as $file) {
            try {
                $page = UiPageFactory::createFromUxon($workbench, UxonObject::fromJson(file_get_contents($file)));
                $page->setApp($this->getApp()->getSelector());
                // Wird eine Seite neu hinzugefuegt ist die menuDefaultPosition gleich der
                // gesetzen Position.
                $page->setMenuDefaultPosition($page->getMenuPosition());
                $pagesFile[] = $page;
            } catch (\Throwable $e) {
                throw new InstallerRuntimeError($this, 'Cannot load page model from file "' . $file . '": corrupted UXON?', null, $e);
            }
        }
        $pagesFile = $this->sortPages($pagesFile);
        
        // Pages aus der Datenbank laden.
        $pagesDb = $cms->getPagesForApp($this->getApp());
        
        // Pages vergleichen und bestimmen welche erstellt, aktualisiert oder geloescht werden muessen.
        $pagesCreate = [];
        $pagesUpdate = [];
        $pagesDelete = [];
        
        foreach ($pagesFile as $pageFile) {
            try {
                $pageDb = $cms->getPage(SelectorFactory::createPageSelector($workbench, $pageFile->getId()), true);
                // Die Seite existiert bereits und wird aktualisiert.
                if (! $pageDb->equals($pageFile) && $pageDb->isUpdateable()) {
                    // Irgendetwas hat sich an der Seite geaendert.
                    if (! $pageDb->equals($pageFile, ['menuParentPageAlias', 'menuIndex'])) {
                        // Der Inhalt der Seite (vlt. auch die Position) haben sich geaendert.
                        if ($pageDb->isMoved()) {
                            // Die Seite wurde manuell umgehaengt. Die menuDefaultPosition wird
                            // aktualisiert, die Position im Baum wird nicht aktualisiert.
                            $pageFile->setMenuIndex($pageDb->getMenuIndex());
                            $pageFile->setMenuParentPageAlias($pageDb->getMenuParentPageAlias());
                        }
                        $pagesUpdate[] = $pageFile;
                    } elseif (! $pageDb->isMoved()) {
                        // Die Position der Seite hat sich geaendert. Nur Aktualisieren wenn die
                        // Seite nicht manuell umgehaengt wurde.
                        $pagesUpdate[] = $pageFile;
                    }
                }
            } catch (UiPageNotFoundError $upnfe) {
                // Die Seite existiert noch nicht und muss erstellt werden.
                $pagesCreate[] = $pageFile;
            }
        }
        
        foreach ($pagesDb as $pageDb) {
            if (! $this->hasPage($pageDb, $pagesFile) && $pageDb->isUpdateable()) {
                // Die Seite existiert nicht mehr und wird geloescht.
                $pagesDelete[] = $pageDb;
            }
        }
        
        $result = '';
        
        // Pages erstellen.
        $pagesCreatedCounter = 0;
        $pagesCreatedErrorCounter = 0;
        foreach ($pagesCreate as $page) {
            try {
                $cms->createPage($page);
                $pagesCreatedCounter ++;
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesCreatedErrorCounter ++;
            }
        }
        if ($pagesCreatedCounter) {
            $result .= ($result ? ', ' : '') . $pagesCreatedCounter . ' created';
        }
        if ($pagesCreatedErrorCounter) {
            $result .= ($result ? ', ' : '') . $pagesCreatedErrorCounter . ' create errors';
        }
        
        // Pages aktualisieren.
        $pagesUpdatedCounter = 0;
        $pagesUpdatedErrorCounter = 0;
        foreach ($pagesUpdate as $page) {
            try {
                $cms->updatePage($page);
                $pagesUpdatedCounter ++;
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesUpdatedErrorCounter ++;
            }
        }
        if ($pagesUpdatedCounter) {
            $result .= ($result ? ', ' : '') . $pagesUpdatedCounter . ' updated';
        }
        if ($pagesUpdatedErrorCounter) {
            $result .= ($result ? ', ' : '') . $pagesUpdatedErrorCounter . ' update errors';
        }
        
        // Pages loeschen.
        $pagesDeletedCounter = 0;
        $pagesDeletedErrorCounter = 0;
        foreach ($pagesDelete as $page) {
            try {
                $cms->deletePage($page);
                $pagesDeletedCounter ++;
            } catch (\Throwable $e) {
                $workbench->getLogger()->logException($e);
                $pagesDeletedErrorCounter ++;
            }
        }
        if ($pagesDeletedCounter) {
            $result .= ($result ? ', ' : '') . $pagesDeletedCounter . ' deleted';
        }
        if ($pagesDeletedErrorCounter) {
            $result .= ($result ? ', ' : '') . $pagesDeletedErrorCounter . ' delete errors';
        }
        
        return $result ? 'Pages: ' . $result : '';
    }

    /**
     * Searches an array of UiPages for a certain UiPage and returns if it is contained.
     * 
     * @param UiPageInterface $page
     * @param UiPageInterface[] $pageArray
     * @return boolean
     */
    protected function hasPage(UiPageInterface $page, $pageArray)
    {
        foreach ($pageArray as $arrayPage) {
            if ($page->isExactly($arrayPage)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ein Array von UiPages wird sortiert und zurueckgegeben. Die Sortierung erfolgt so, dass
     * Seiten ohne Parent im uebergebenen Array, ganz nach oben sortiert werden. Hat die Seite
     * einen Parent im Array, so wird sie nach diesem Parent einsortiert. Werden die Seiten
     * in der zurueckgegebenen Reihenfolge im CMS aktualisiert, ist sichergestellt, dass der
     * Seitenbaum des Arrays intakt bleibt, egal wo er dann in den existierenden Baum
     * eingehaengt wird.
     * 
     * @param UiPageInterface[] $pages
     */
    protected function sortPages($pages)
    {
        if (empty($pages)) {
            return $pages;
        }
        
        $inputPages = $pages;
        $sortedPages = [];
        $i = 0;
        do {
            $pagePos = 0;
            do {
                $page = $inputPages[$pagePos];
                $parentAlias = $page->getMenuParentPageAlias();
                $parentFound = false;
                // Hat die Seite einen Parent im inputArray?
                foreach ($inputPages as $parentPagePos => $parentPage) {
                    if ($parentPage->isExactly($parentAlias)) {
                        $parentFound = true;
                        break;
                    }
                }
                if (! $parentFound) {
                    // Wenn die Seite keinen Parent im inputArray hat, hat sie einen im
                    // outputArray?
                    foreach ($sortedPages as $parentPagePos => $parentPage) {
                        if ($parentPage->isExactly($parentAlias)) {
                            $parentFound = true;
                            break;
                        }
                    }
                    // Hat sie einen Parent im outputArray, dann wird sie nach diesem
                    // einsortiert, sonst wird sie am Anfang einsortiert.
                    $out = array_splice($inputPages, $pagePos, 1);
                    array_splice($sortedPages, $parentFound ? $parentPagePos + 1 : 0, 0, $out);
                } else {
                    // Hat die Seite einen Parent im inputArray dann wird sie erstmal ueber-
                    // sprungen. Sie wird erst im outputArray einsortiert, nachdem ihr Parent
                    // dort einsortiert wurde.
                    $pagePos ++;
                }
                // Alle Seiten im inputArray durchgehen.
            } while ($pagePos < count($inputPages));
            $i ++;
            // So oft wiederholen wie es Seiten im inputArray gibt oder die Abbruchbedingung
            // erfuellt ist (kreisfoermige Referenzen).
        } while (count($inputPages) > 0 && $i < count($pages));
        
        if (count($inputPages) > 0) {
            // Sortierung nicht erfolgreich, kreisfoermige Referenzen? Die unsortierten Seiten
            // werden zurueckgegeben.
            return $pages;
        } else {
            return $sortedPages;
        }
    }

    protected function getDefaultLanguageCode()
    {
        $languageCode = $this->getApp()->getLanguageDefault();
        if (! $languageCode) {
            $defaultLocale = $this->getWorkbench()->getConfig()->getOption("LOCALE.DEFAULT");
            $languageCode = substr($defaultLocale, 0, strpos($defaultLocale, '_'));
        }
        
        return $languageCode;
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::update()
     */
    public function update($source_absolute_path)
    {
        $this->install($source_absolute_path);
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::backup()
     */
    public function backup($destination_absolute_path)
    {
        /** @var Filemanager $fileManager */
        $fileManager = $this->getWorkbench()->filemanager();
        $cms = $this->getWorkbench()->getCMS();
        
        // Empty pages folder in case it is an update
        try {
            $fileManager->emptyDir($this->getPagePath($destination_absolute_path));
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
        }
        
        // Dann alle Dialoge der App als Dateien in den Ordner schreiben.
        $pages = $cms->getPagesForApp($this->getApp());
        
        if (! empty($pages)) {
            $dir = $this->getPagesPathWithLanguage($destination_absolute_path, $this->getDefaultLanguageCode());
            $fileManager->pathConstruct($dir);
        }
        
        /** @var UiPage $page */
        foreach ($pages as $page) {
            try {
                // Ist die parent-Seite der Root, dann wird ein leerer MenuParentPageAlias gespeichert.
                // Dadurch wird die Seite beim Hinzufuegen auf einem anderen System automatisch im Root
                // eingehaengt, auch wenn der an einer anderen Stelle ist als auf diesem System.
                if ($page->getMenuParentPageAlias() === null || ($cms->getPageIdInCms($page->getMenuParentPage()) == $cms->getPageIdRoot())) {
                    $page->setMenuParentPageAlias("");
                }
                
                // Hat die Seite keine UID wird ein Fehler geworfen. Ohne UID kann die Seite nicht
                // manipuliert werden, da beim Aktualisieren oder Loeschen die UID benoetigt wird.
                if (! $page->getId()) {
                    throw new UiPageIdMissingError('The UiPage "' . $page->getAliasWithNamespace() . '" has no UID.');
                }
                // Hat die Seite keinen Alias wird ein Alias gesetzt und die Seite wird aktualisiert.
                if (! $page->getAliasWithNamespace()) {
                    $page = $page->copy(UiPage::generateAlias($page->getApp()->getAliasWithNamespace() . '.'));
                    $cms->updatePage($page);
                }
                
                // Exportieren der Seite
                $contents = $page->exportUxonObject()->toJson(true);
                $fileManager->dumpFile($dir . DIRECTORY_SEPARATOR . $page->getAliasWithNamespace() . '.json', $contents);
            } catch (\Throwable $e) {
                throw new InstallerRuntimeError($this, 'Unknown error while backing up page "' . $page->getAliasWithNamespace() . '"!', null, $e);
            }
        }
        
        return count($pages) . ' pages for "' . $this->getApp()->getAliasWithNamespace() . '" exported.';
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\InstallerInterface::uninstall()
     */
    public function uninstall()
    {}
}
