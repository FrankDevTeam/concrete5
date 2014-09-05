<?php
namespace Concrete\Core\Page\Search;

use Loader;
use Config;
use Cache;
use PageList;
use Collection;
use Area;
use Concrete\Core\Area\SubArea;
use Block;
use stdClass;

class IndexedSearch
{

    public $searchBatchSize = PAGE_SEARCH_INDEX_BATCH_SIZE;
    public $searchReindexTimeout = PAGE_SEARCH_INDEX_LIFETIME;

    private $cPathSections = array();
    private $searchableAreaNames;

    public function getSearchableAreaAction()
    {
        $action = Config::get('SEARCH_INDEX_AREA_METHOD');
        if (!strlen($action)) {
            $action = 'blacklist';
        }
        return $action;
    }

    public function getSavedSearchableAreas()
    {
        $areas = Config::get('SEARCH_INDEX_AREA_LIST');
        $areas = unserialize($areas);
        if (!is_array($areas)) {
            $areas = array();
        }
        return $areas;
    }

    public function clearSearchIndex()
    {
        $db = Loader::db();
        $db->Execute('truncate table PageSearchIndex');
    }

    public function matchesArea($arHandle)
    {
        if (!isset($this->arHandles)) {
            $searchableAreaNamesInitial = $this->getSavedSearchableAreas();
            if ($this->getSearchableAreaAction() == 'blacklist') {
                $areas = Area::getHandleList();
                foreach ($areas as $arHandle) {
                    if (!in_array($arHandle, $searchableAreaNamesInitial)) {
                        $this->searchableAreaNames[] = $arHandle;
                    }
                }
            } else {
                $this->searchableAreaNames = $searchableAreaNamesInitial;
            }
        }

        foreach ($this->searchableAreaNames as $sarHandle) {
            if (preg_match('/^' . $sarHandle . SubArea::AREA_SUB_DELIMITER . '.+/i', $arHandle)) {
                return true;
            } else {
                if (in_array($arHandle, $this->searchableAreaNames)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function reindexPage($page)
    {
        $db = Loader::db();
        if (is_object($page) && ($page instanceof Collection) && ($page->getAttribute('exclude_search_index') != 1)) {
            $datetime = Loader::helper('date')->getOverridableNow();
            $db->Replace(
                'PageSearchIndex',
                array(
                    'cID' => $page->getCollectionID(),
                    'cName' => $page->getCollectionName(),
                    'cDescription' => $page->getCollectionDescription(),
                    'cPath' => $page->getCollectionPath(),
                    'cDatePublic' => $page->getCollectionDatePublic(),
                    'content' => $this->getBodyContentFromPage($page),
                    'cDateLastIndexed' => $datetime
                ),
                array('cID'),
                true
            );
        } else {
            $db->Execute('delete from PageSearchIndex where cID = ?', array($page->getCollectionID()));
        }
    }

    public function getBodyContentFromPage($c)
    {

        $text = '';

        $tagsToSpaces = array(
            '<br>',
            '<br/>',
            '<br />',
            '<p>',
            '</p>',
            '</ p>',
            '<div>',
            '</div>',
            '</ div>',
            '&nbsp;'
        );
        $blarray = array();
        $db = Loader::db();
        $r = $db->Execute(
            'select bID, arHandle from CollectionVersionBlocks where cID = ? and cvID = ?',
            array($c->getCollectionID(), $c->getVersionID())
        );
        $th = Loader::helper('text');
        while ($row = $r->FetchRow()) {
            if ($this->matchesArea($row['arHandle'])) {
                $b = Block::getByID($row['bID'], $c, $row['arHandle']);
                if (!is_object($b)) {
                    continue;
                }
                $bi = $b->getInstance();
                if (method_exists($bi, 'getSearchableContent')) {
                    $searchableContent = $bi->getSearchableContent();
                    if (strlen(trim($searchableContent))) {
                        $text .= $th->decodeEntities(
                                strip_tags(str_ireplace($tagsToSpaces, ' ', $searchableContent)),
                                ENT_QUOTES,
                                APP_CHARSET
                            ) . ' ';
                    }
                }
                unset($b);
                unset($bi);
            }
        }

        return $text;
    }

    /**
     * Reindexes the search engine.
     */
    public function reindexAll($fullReindex = false)
    {
        Cache::disableLocalCache();

        $db = Loader::db();


        if ($fullReindex) {
            $db->Execute("truncate table PageSearchIndex");
        }

        $pl = new PageList();
        $pl->ignoreAliases();
        $pl->ignorePermissions();
        $pl->sortByCollectionIDAscending();
        $pl->filter(
            false,
            '(c.cDateModified > psi.cDateLastIndexed or UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(psi.cDateLastIndexed) > ' . $this->searchReindexTimeout . ' or psi.cID is null or psi.cDateLastIndexed is null)'
        );
        $pl->filter(false, '(ak_exclude_search_index is null or ak_exclude_search_index = 0)');
        $pages = $pl->get($this->searchBatchSize);

        $num = 0;
        foreach ($pages as $c) {

            // make sure something is approved
            $cv = $c->getVersionObject();
            if (!$cv->cvIsApproved) {
                continue;
            }

            $c->reindex($this, true);
            $num++;
            unset($c);
        }

        $pnum = Collection::reindexPendingPages();
        $num = $num + $pnum;

        Cache::enableLocalCache();
        $result = new stdClass;
        $result->count = $num;
        return $result;
    }

}
