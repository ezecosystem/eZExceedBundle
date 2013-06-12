<?php

/**
 * Sanitizing data for eZ Exceed's Pencil Twig function
 *
 * @copyright //autogen//
 * @license //autogen//
 * @version //autogen//
 */

namespace KTQ\Bundle\eZExceedBundle\Model;

use eZ\Publish\Core\SignalSlot\Repository;
use eZ\Publish\Core\Repository\ContentService;
use eZ\Publish\Core\Repository\ContentTypeService;
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


    public function __construct( Repository $repository, PageService $pageService )
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


        // TODO: Remove
        $this->zoneIndex = 0;
    }

    public function fill( $input, Content $currentContent )
    {
        $this->currentContent = $currentContent;
        $this->currentContentId = $currentContent->getVersionInfo()->contentInfo->id;
        $this->canCurrentUserEditCurrentContent = $this->repository->canUser( 'content', 'edit', $this->currentContent );

        if( is_array( $input ) )
        {
            foreach( $input as $key => $value )
            {
                // Arrays of blocks are not supported
                if( $value instanceof Block )
                    return;

                // Array contains Contents or Locations
                if( is_numeric( $key ) )
                {
                    if( $this->isPencilCompatible( $value ) )
                    {
                        $this->addEntity( $value );
                    }
                }
                else
                {
                    // Array is a set of Ids.
                    $this->addIdArray( $value, $key );
                }
            }
        }
        else
            $this->addEntity( $input );
    }

    protected function addEntity( $value )
    {
        if( $value )
        {
            if( $value instanceof Content )
                $this->addContent( $value );

            else if( $value instanceof Location )
                $this->addLocation( $value );

            else if( $value instanceof Block )
                $this->addBlock( $value );
        }
    }

    protected function addContent( Content $content )
    {
        if( $this->repository->canUser( 'content', 'edit', $content ) )
        {
            $contentVersionInfo = $content->getVersionInfo();
            $contentTypeIdentifier = $this->contentTypeService->loadContentType( $contentVersionInfo->contentInfo->contentTypeId )->identifier;

            $name = $contentVersionInfo->contentInfo->name;

            if( array_key_exists( $this->languageService->getDefaultLanguageCode(), $contentVersionInfo->names ) )
                $name = $contentVersionInfo->names[ $this->languageService->getDefaultLanguageCode() ];

            $entity = array(
                'id' => $content->__get( 'id' ),
                'name' => $name,
                'classIdentifier' => $contentTypeIdentifier
            );
            $this->entities[] = $entity;
        }
    }

    protected function addLocation( Location $location )
    {
        if( $this->repository->canUser( 'content', 'read', $location->getContentInfo(), $location ) )
        {
            $this->addContent( $this->contentService->loadContentByContentInfo( $location->getContentInfo() ) );
        }
    }

    protected function addBlock( Block $block )
    {
        $this->pageField = $this->getPageField();

        // TODO: Make sure the block is from the user's latest draft. How..?
        //$this->block = $this->fetchBlockFromLatestUserDraft( $block );

        $this->block = $block;
        $this->setZoneIndex();

        $blockItems = $this->pageService->getValidBlockItems( $block );
        if( $blockItems )
        {
            $locationIdMapper = function( $blockItem )
            {
                return $blockItem->locationId;
            };

            $locationIdList = array_map( $locationIdMapper, $blockItems );
            // Use 'nodes' and not 'locations' to remain compatible
            $this->addIdArray( $locationIdList, 'nodes' );
        }

        $waitingBlockItems = $this->pageService->getWaitingBlockItems( $block );
        if( $waitingBlockItems )
        {
            $this->addSeparator( 'Content in queue' );

            $contentIdMapper = function( $blockItem )
            {
                return $blockItem->contentId;
            };

            $contentIdList = array_map( $contentIdMapper, $waitingBlockItems );
            // Use 'objects' and not 'contents' to remain compatible
            $this->addIdArray( $contentIdList, 'objects' );
        }
    }

    protected function addIdArray( $values, $type )
    {
        // TODO: Translate somehow
        // $this->title = \ezpI18n::tr( 'ezexceed', 'Edit ' . $type );
        $this->title = 'Edit ' . $type;

        if( $type === 'objects' )
        {
            foreach( $values as $contentId )
                $this->addContent( $this->contentService->loadContent( $contentId ) );
        }
        elseif( $type === 'nodes' )
        {
            foreach( $values as $locationId )
                $this->addLocation( $this->locationService->loadLocation( $locationId ) );
        }
    }

    protected function isPencilCompatible( $input )
    {
        $compatible = false;

        if( is_object( $input ) )
        {
            if( $input instanceof Block || $input instanceof Content || $input instanceof Location )
            {
                $compatible = true;
            }
        }

        return $compatible;
    }

    protected function setZoneIndex()
    {
        if( $this->pageField )
        {
            foreach( $this->pageField->zones as $zoneIndex => $zone )
            {
                foreach( $zone->blocks as $block )
                {
                    echo 'From Content: ' . $block->id . '<br />From input: ' . $this->block->id;
                    if( $block->id === $this->block->id )
                    {
                        $this->zoneIndex = $zoneIndex;
                        break;
                    }
                }
            }
        }
    }

    protected function getPageField()
    {
        $fields = $this->currentContent->getFields();

        foreach( $fields as $field )
        {
            $field = $field->value;

            if( property_exists( $field, 'page' ) && $field->page instanceof Page )
                return $field->page;
        }

        return null;
    }

    protected function fetchBlockFromLatestUserDraft( $currentBlock )
    {
        if( $this->pageField === null )
            return $currentBlock;

        echo 'pagefield is not null';

        if( !$draft = eZExceedObject::fetchLatestUserDraft( $this->currentContentId, $this->repository->getCurrentUser() ) )
            return $currentBlock;

        if( !$field = eZExceedPage::fetchEzpageAttribute( $draft ) )
            return $currentBlock;

        $this->debug( $field );
        //$fieldValue = $field->content();

        /*
        foreach($fieldValue->zones as $zone) {
            for($i = 0; $i < $zone->getBlockCount(); $i++) {
                $block = $zone->getBlock($i);

                if ($currentBlock->attribute('id') === $block->attribute('id'))
                    return $block;
            }
        }
        */

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








    private function debug( $stuff )
    {
        echo '<pre>';
        print_r( $stuff );
        echo '</pre>';
    }
}
