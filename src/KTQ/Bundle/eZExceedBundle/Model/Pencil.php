<?php

/**
 * Sanitizing data for eZ Exceed's Pencil Twig function
 *
 * @copyright //autogen//
 * @license //autogen//
 * @version //autogen//
 *
 */

namespace KTQ\Bundle\eZExceedBundle\Model;

use eZ\Publish\Core\SignalSlot\Repository;
use eZ\Publish\Core\Repository\ContentService;
use eZ\Publish\Core\Repository\ContentTypeService;
use eZ\Publish\API\Repository\Values\Content\VersionInfo;
use eZ\Publish\Core\Repository\LocationService;
use eZ\Publish\Core\FieldType\Page\PageService;
use eZ\Publish\Core\Repository\LanguageService;

use eZ\Publish\Core\Repository\Values\Content\Content;
use eZ\Publish\Core\Repository\Values\Content\Location;
use eZ\Publish\Core\FieldType\Page\Parts\Page;
use eZ\Publish\Core\FieldType\Page\Parts\Block;

use ezexceed\models\content\Object as eZExceedObject;
use ezexceed\models\page\Page as eZExceedPage;

class Pencil
{
    // @var Content
    protected $currentContent;

    // @var int
    protected $currentContentId;

    // @var Location
    protected $currentLocation;

    // @var array
    protected $entities;

    // @var string
    protected $title;

    // @var Page
    protected $pageField;

    // @var int
    protected $zoneIndex;

    // @var Block
    protected $block;

    // @var Repository
    protected $repository;

    // @var ContentService
    protected $contentService;

    // @var ContentTypeService
    protected $contentTypeService;

    // @var LocationService
    protected $locationService;

    // @var PageService
    protected $pageService;

    // @var LanguageService
    protected $languageService;

    // @var boolean
    protected $canCurrentUserEditCurrentContent;

    // @var object
    protected $serviceContainer;


    public function __construct( $serviceContainer, Repository $repository, PageService $pageService )
    {
        $this->entities = array();
        $this->title = '';
        $this->block = null;

        $this->repository = $repository;

        // Services
        $this->contentService = $this->repository->getContentService();
        $this->contentTypeService = $this->repository->getContentTypeService();
        $this->locationService = $this->repository->getLocationService();
        $this->languageService = $this->repository->getContentLanguageService();
        $this->pageService = $pageService;

        $this->serviceContainer = $serviceContainer;
        // TODO: Remove
        $this->zoneIndex = 0;
    }

    protected function loadLocation()
    {
        if (!$this->currentContent) {
            $locationId = $this->serviceContainer->get('request')->attributes->get('locationId');
            if ($locationId) {
                $this->currentLocation = $this->locationService->loadLocation($locationId);
                $this->currentContent = $this->contentService->loadContentByContentInfo($this->currentLocation->contentInfo);
                $this->currentContentId = $this->currentContent->getVersionInfo()->contentInfo->id;
                $this->canCurrentUserEditCurrentContent = $this->repository->canUser( 'content', 'edit', $this->currentContent );
            }
        }
    }

    public function fill($input)
    {
        $this->loadLocation();
        $this->entities = array();
        if (is_array($input)) {
            foreach ($input as $key => $value) {
                // Arrays of blocks are not supported
                if ($value instanceof Block) { return false; }

                // Array contains Contents or Locations
                if (is_numeric($key)) {
                    if ($this->isPencilCompatible($value)) {
                        $this->addEntity($value);
                    }
                }
                else {
                    // Array is a set of Ids.
                    $this->addIdArray($value, $key );
                }
            }
        }
        else {
            if ($this->repository->canUser( 'content', 'edit', $this->currentContent )) {
                $this->addEntity( $input );
            }
        }
        return true;
    }

    protected function addEntity( $value )
    {
        switch (true) {
            case $value instanceof Content:
                return $this->addContent($value);
            case $value instanceof Location:
                return $this->addLocation($value);
            case $value instanceof Block:
                return $this->addBlock($value);
        }
    }

    protected function addContent( Content $content )
    {
        if ($this->repository->canUser('content', 'edit', $content)) {
            $contentVersionInfo = $content->getVersionInfo();
            $contentTypeIdentifier = $this->contentTypeService->loadContentType($contentVersionInfo->contentInfo->contentTypeId)->identifier;

            $name = $contentVersionInfo->contentInfo->name;
            $defaultLanguageCode = $this->languageService->getDefaultLanguageCode();

            if (isset($contentVersionInfo->names[$defaultLanguageCode])) {
                $name = $contentVersionInfo->names[$defaultLanguageCode];
            }

            $entity = array(
                'id' => $content->__get( 'id' ),
                'separator' => false,
                'name' => $name,
                'classIdentifier' => $contentTypeIdentifier
            );
            $this->entities[] = $entity;
        }
    }

    protected function addLocation( Location $location )
    {
        if ($this->repository->canUser('content', 'read', $location->getContentInfo(), $location)) {
            $this->addContent( $this->contentService->loadContentByContentInfo( $location->getContentInfo() ) );
        }
    }

    protected function addBlock(Block $block)
    {
        $this->pageField = $this->getPageField();
        $this->block = $block;
        $this->setZoneIndex();

        $blockItems = $this->pageService->getValidBlockItems( $block );
        if ($blockItems) {
            $locationIdMapper = function($blockItem) { return $blockItem->locationId; };
            $locationIdList = array_map($locationIdMapper, $blockItems);

            // Use 'nodes' and not 'locations' to remain compatible
            $this->addIdArray($locationIdList, 'nodes');
        }

        $waitingBlockItems = $this->pageService->getWaitingBlockItems( $block );
        if ($waitingBlockItems) {
            $this->addSeparator('Content in queue');

            $contentIdMapper = function($blockItem) { return $blockItem->contentId; };
            $contentIdList = array_map($contentIdMapper, $waitingBlockItems);

            // Use 'objects' and not 'contents' to remain compatible
            $this->addIdArray($contentIdList, 'objects');
        }
    }

    protected function addIdArray($values, $type)
    {
        // TODO: Translate somehow
        // $this->title = \ezpI18n::tr( 'ezexceed', 'Edit ' . $type );
        $this->title = 'Edit ' . $type;

        if ($type === 'objects') {
            foreach($values as $contentId) {
                $this->addContent($this->contentService->loadContent($contentId));
            }
        }
        elseif ($type === 'nodes') {
            foreach($values as $locationId) {
                $this->addLocation($this->locationService->loadLocation($locationId));
            }
        }
    }

    protected function isPencilCompatible($input)
    {
        return ($input instanceof Block || $input instanceof Content || $input instanceof Location);
    }

    protected function setZoneIndex()
    {
        if (!$this->pageField) {
            return false;
        }
        foreach ($this->pageField->zones as $zoneIndex => $zone) {
            foreach ($zone->blocks as $block) {
                if ($block->id === $this->block->id) {
                    $this->zoneIndex = $zoneIndex;
                    return true;
                }
            }
        }
    }

    protected function getPageField( Content $content = null )
    {
        $content = $content ?: $this->currentContent;
        $fields = $content->getFields();

        foreach ($fields as $field) {
            $field = $field->value;

            if (property_exists($field, 'page') && $field->page instanceof Page)
                return $field->page;
        }

        return null;
    }

    protected function fetchBlockFromLatestUserDraft( $currentBlock )
    {
        if ($this->pageField === null) {
            return $currentBlock;
        }

        $allCurrentUserDrafts = $this->contentService->loadContentDrafts();
        if (!$allCurrentUserDrafts) {
            return $currentBlock;
        }

        $contentId = $this->currentContentId;
        $versionInfoFilter = function(VersionInfo $version) use ($contentId)
        {
            return $version->contentInfo->id === $contentId;
        };

        $versionInfos = array_filter($allCurrentUserDrafts, $versionInfoFilter);

        if( !$versionInfo = reset( $versionInfos ) )
            return $currentBlock;

            return $currentBlock;
        $content = $this->contentService->loadContentByVersionInfo($versionInfo);
        $pageField = $this->getPageField($content);

        if (!$pageField->zones)
            return $currentBlock;

        foreach ($pageField->zones as $zone) {
            foreach ($zone->blocks as $block) {
                if ($block->id === $this->block->id)
                    return $block;
            }
        }

        return $currentBlock;
    }

    protected function addSeparator( $title = '' )
    {
        $this->entities[] = array(
            'separator' => true,
            'title' => $title
        );
    }

    public function attribute( $attribute )
    {
        return $this->$attribute;
    }
}
