<?php

namespace Salesfire\Salesfire\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;

require_once dirname(__FILE__)."/../lib/Salesfire/Salesfire/src/Formatter.php";
require_once dirname(__FILE__)."/../lib/Salesfire/Salesfire/src/Types/Product.php";
require_once dirname(__FILE__)."/../lib/Salesfire/Salesfire/src/Types/Transaction.php";

/**
 * Salesfire Feed
 *
 * @category   Salesfire
 * @package    Salesfire_Salesfire
 * @version.   1.2.1
 */
class Feed
{
    private $_helperData;
    private $_storeManager;
    private $_productCollectionFactory;
    private $_filesystem;
    private $_escaper;
    private $_taxHelper;
    private $_stockItem;

    public function __construct(
        \Salesfire\Salesfire\Helper\Data $helperData,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\ResourceModel\Product\CollectionFactory $productCollectionFactory,
        \Magento\Catalog\Model\ResourceModel\Category\CollectionFactory $categoryCollectionFactory,
        \Magento\Framework\Escaper $escaper,
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Catalog\Helper\Data $taxHelper,
        \Magento\CatalogInventory\Model\Stock\StockItemRepository $stockItem
    ) {
        $this->_helperData                = $helperData;
        $this->_storeManager              = $storeManager;
        $this->_productCollectionFactory  = $productCollectionFactory;
        $this->_categoryCollectionFactory = $categoryCollectionFactory;
        $this->_filesystem                = $filesystem;
        $this->_escaper                   = $escaper;
        $this->_taxHelper                 = $taxHelper;
        $this->_stockItem                 = $stockItem;

        $this->mediapath = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath().'catalog/';
    }

    public function printLine($siteId, $text, $tab=0)
    {
        file_put_contents($this->mediapath.$siteId.'.temp.xml', str_repeat("\t", $tab) . $text . "\n", FILE_APPEND);
    }

    public function escapeString($text)
    {
        return html_entity_decode(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', utf8_encode($text))));
    }

    public function execute()
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/salesfire.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);

        $storeCollection = $this->_storeManager->getStores();
        foreach ($storeCollection as $store)
        {
            $storeId = $store->getId();
            $this->_storeManager->setCurrentStore($storeId);

            if (! $this->_helperData->isAvailable($storeId)) {
                continue;
            }

            if (! $this->_helperData->isFeedEnabled($storeId)) {
                continue;
            }

            $siteId = $this->_helperData->getSiteId($storeId);
            $brand_code = $this->_helperData->getBrandCode($storeId);
            $gender_code = $this->_helperData->getGenderCode($storeId);
            $colour_code = $this->_helperData->getColourCode($storeId);
            $attribute_codes = $this->_helperData->getAttributeCodes($storeId);
            $default_brand = $this->_helperData->getDefaultBrand($storeId);
            $currency = $store->getCurrentCurrencyCode();

            $this->printLine($siteId, '<?xml version="1.0" encoding="utf-8" ?>', 0);
            $this->printLine($siteId, '<productfeed site="'.$this->_storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB).'" date-generated="'.gmdate('c').'">', 0);

            $mediaUrl = $this->_storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA);

            $categories = $this->getCategories($storeId);

            if (! empty($categories)) {
                $this->printLine($siteId, '<categories>', 1);
                foreach ($categories as $category) {
                    $parent = $category->getParentCategory()->setStoreId($storeId);
                    if ($category->getLevel() <= 1) {
                        continue;
                    }

                    $this->printLine($siteId, '<category id="category_' . $category->getId() . '"' . ($parent && $parent->getLevel() > 1 ? ' parent="category_'.$parent->getId(). '"' : '') . '>', 2);

                    $this->printLine($siteId, '<id>' . $this->escapeString($category->getId()) . '</id>', 3);

                    $this->printLine($siteId, '<name><![CDATA['.$this->escapeString($category->getName()).']]></name>', 3);

                    $this->printLine($siteId, '<breadcrumb><![CDATA['.$this->escapeString($this->getCategoryBreadcrumb($storeId, $category)).']]></breadcrumb>', 3);

                    $description = $category->getDescription();
                    if (! empty($description)) {
                        $this->printLine($siteId, '<description><![CDATA['.$this->escapeString($description).']]></description>', 3);
                    }

                    $this->printLine($siteId, '<link>' . $category->getUrl(true) . '</link>', 3);

                    $keywords = $category->getMetaKeywords();
                    if (! empty($keywords)) {
                        $this->printLine($siteId, '<keywords>', 3);
                        foreach (explode(',', $keywords) as $keyword) {
                            $this->printLine($siteId, '<keyword><![CDATA['.$this->escapeString($keyword).']]></keyword>', 4);
                        }
                        $this->printLine($siteId, '</keywords>', 3);
                    }

                    $this->printLine($siteId, '</category>', 2);
                }
                $this->printLine($siteId, '</categories>', 1);
            }

            $page = 1;
            do {
                $products = $this->getVisibleProducts($storeId, $page);
                $count = count($products);

                if ($page == 1 && $count) {
                    $this->printLine($siteId, '<products>', 1);
                }

                foreach ($products as $product) {
                    $this->printLine($siteId, '<product id="product_'.$product->getId().'">', 2);

                    $this->printLine($siteId, '<id>' . $product->getId() . '</id>', 3);

                    $this->printLine($siteId, '<title><![CDATA[' . $this->escapeString($product->getName()) . ']]></title>', 3);

                    $this->printLine($siteId, '<description><![CDATA[' . $this->escapeString(substr($this->_escaper->escapeHtml(strip_tags($product->getDescription())), 0, 5000)) . ']]></description>', 3);

                    $this->printLine($siteId, '<price currency="' . $currency . '">' . $product->getFinalPrice() . '</price>', 3);

                    $this->printLine($siteId, '<sale_price currency="' . $currency . '">' . ($product->getSpecialPrice() ? $product->getSpecialPrice() : $product->getFinalPrice()) . '</sale_price>', 3);

                    $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($product->getSku()).']]></mpn>', 3);

                    $this->printLine($siteId, '<link>' . $product->getProductUrl(true) . '</link>', 3);

                    if (! empty($gender_code)) {
                        $gender = $product->getResource()->getAttribute($gender_code)->setStoreId($storeId)->getFrontend()->getValue($product);
                        if (! empty($gender)) {
                            $this->printLine($siteId, '<gender><![CDATA['.$this->escapeString($gender).']]></gender>', 3);
                        }
                    }

                    if (! empty($colour_code)) {
                        $colour = $product->getResource()->getAttribute($colour_code)->setStoreId($storeId)->getFrontend()->getValue($product);
                        if (! empty($colour)) {
                            $this->printLine($siteId, '<colour><![CDATA['.$this->escapeString($colour).']]></colour>', 3);
                        }
                    }

                    if (! empty($brand_code)) {
                        $brand = $product->getResource()->getAttribute($brand_code)->setStoreId($storeId)->getFrontend()->getValue($product);
                        if (! empty($brand)) {
                            $this->printLine($siteId, '<brand>' . $this->escapeString($brand) . '</brand>', 3);
                        }
                    } else if (! empty($default_brand)) {
                        $this->printLine($siteId, '<brand>' . $this->escapeString($default_brand) . '</brand>', 3);
                    }

                    $categories = $product->getCategoryIds();
                    if (! empty($categories)) {
                        $this->printLine($siteId, '<categories>', 3);
                        foreach ($categories as $categoryId) {
                            $this->printLine($siteId, '<category id="category_'.$categoryId.'" />', 4);
                        }
                        $this->printLine($siteId, '</categories>', 3);
                    }

                    $keywords = $product->getMetaKeywords();
                    if (! empty($keywords)) {
                        $this->printLine($siteId, '<keywords>', 3);
                        foreach (explode(',', $keywords) as $keyword) {
                            $this->printLine($siteId, '<keyword><![CDATA['.$this->escapeString($keyword).']]></keyword>', 4);
                        }
                        $this->printLine($siteId, '</keywords>', 3);
                    }

                    $this->printLine($siteId, '<variants>', 3);

                    if ($product->getTypeId() === 'configurable' && !empty($product->getOptions())) {
                        $childProducts = $product->getTypeInstance()->getUsedProducts($product);

                        if (count($childProducts) > 0) {
                            foreach ($childProducts as $childProduct) {
                                $this->printLine($siteId, '<variant>', 4);

                                $this->printLine($siteId, '<id>' . $childProduct->getId() . '</id>', 5);

                                if (! empty($attribute_codes)) {
                                    $attributes = [];

                                    foreach($attribute_codes as $attribute) {
                                        if (empty($attribute) || in_array($attribute, array('id', 'mpn', 'stock', 'link', 'image'))) {
                                            continue;
                                        }

                                        $text = $childProduct->getResource()->getAttribute($attribute)->setStoreId($storeId)->getFrontend()->getValue($childProduct);

                                        if (! empty($text)) {
                                            $attributes[$attribute] = $text;
                                        }
                                    }

                                    if (! empty($attributes)) {
                                        $this->printLine($siteId, '<attributes>', 5);

                                        foreach($attributes as $attribute => $text) {
                                            $this->printLine($siteId, '<'.$attribute.'><![CDATA['.$this->escapeString($text).']]></'.$attribute.'>', 6);
                                        }

                                        $this->printLine($siteId, '</attributes>', 5);
                                    }
                                }

                                $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($childProduct->getSku()).']]></mpn>', 5);

                                $stock_item = $this->_stockItem->get($childProduct->getId());
                                $this->printLine($siteId, '<stock>'.($stock_item && $stock_item->getIsInStock() ? ($stock_item->getQty() > 0 ? (int) $stock_item->getQty() : 1) : 0).'</stock>', 5);

                                $this->printLine($siteId, '<link>' . $product->getProductUrl(true) . '</link>', 5);

                                $image = $childProduct->getImage();
                                if (! empty($image)) {
                                    $this->printLine($siteId, '<image>' . $mediaUrl . 'catalog/product' . $image . '</image>', 5);
                                }

                                $this->printLine($siteId, '</variant>', 4);
                            }
                        }
                    } else {
                        $this->printLine($siteId, '<variant>', 4);

                        $this->printLine($siteId, '<id>' . $product->getId() . '</id>', 5);

                        if (! empty($attribute_codes)) {
                            $attributes = [];

                            foreach($attribute_codes as $attribute) {
                                if (empty($attribute) || in_array($attribute, array('id', 'mpn', 'stock', 'link', 'image'))) {
                                    continue;
                                }

                                $text = $product->getResource()->getAttribute($attribute)->setStoreId($storeId)->getFrontend()->getValue($product);

                                if (! empty($text)) {
                                    $attributes[$attribute] = $text;
                                }
                            }

                            if (! empty($attributes)) {
                                $this->printLine($siteId, '<attributes>', 5);

                                foreach($attributes as $attribute => $text) {
                                    $this->printLine($siteId, '<'.$attribute.'><![CDATA['.$this->escapeString($text).']]></'.$attribute.'>', 6);
                                }

                                $this->printLine($siteId, '</attributes>', 5);
                            }
                        }

                        $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($product->getSku()).']]></mpn>', 5);

                        $stock_item = $this->_stockItem->get($product->getId());
                        $this->printLine($siteId, '<stock>'.($stock_item && $stock_item->getIsInStock() ? ($stock_item->getMinQty() > 0 ? (int) $stock_item->getQty() : 1) : 0).'</stock>', 5);

                        $this->printLine($siteId, '<link>' . $product->getProductUrl(true) . '</link>', 5);

                        $image = $product->getImage();
                        if (! empty($image)) {
                            $this->printLine($siteId, '<image>' . $mediaUrl . 'catalog/product' . $image . '</image>', 5);
                        }

                        $this->printLine($siteId, '</variant>', 4);
                    }

                    $this->printLine($siteId, '</variants>', 3);

                    $this->printLine($siteId, '</product>', 2);
                }

                $page++;
            } while ($count >= 100);

            if ($count || $page > 1) {
                $this->printLine($siteId, '</products>', 1);
            }

            $this->printLine($siteId, '</productfeed>', 0);

            @rename($this->mediapath.$siteId.'.temp.xml', $this->mediapath.$siteId.'.xml');
            @unlink($this->mediapath.$siteId.'.temp.xml');
        }

        return;

        $attributeSetModel = Mage::getModel("eav/entity_attribute_set");
        $bundlePriceModel = Mage::getModel('bundle/product_price');

        $entityTypeId = Mage::getModel('eav/entity')
                ->setType('catalog_product')
                ->getTypeId();
        $attributeSetName = 'Default';
        $defaultAttributeSetId = Mage::getModel('eav/entity_attribute_set')
                ->getCollection()
                ->setEntityTypeFilter($entityTypeId)
                ->addFieldToFilter('attribute_set_name', $attributeSetName)
                ->getFirstItem()
                ->getAttributeSetId();

        $defaultAttributes = Mage::getModel('catalog/product_attribute_api')->items($defaultAttributeSetId);
        $defaultAttributeCodes = array();
        foreach ($defaultAttributes as $attributes) {
            $defaultAttributeCodes[] = $attributes['code'];
        }

        $storeCollection = Mage::getModel('core/store')->getCollection();
        foreach ($storeCollection as $store)
        {
            $storeId = $store->getId();
            Mage::app()->setCurrentStore($storeId);

            if (! Mage::helper('salesfire')->isAvailable($storeId)) {
                continue;
            }

            if (! Mage::helper('salesfire')->isFeedEnabled($storeId)) {
                continue;
            }
            $siteId = Mage::helper('salesfire')->getSiteId($storeId);
            $brand_code = Mage::helper('salesfire')->getBrandCode($storeId);
            $gender_code = Mage::helper('salesfire')->getGenderCode($storeId);
            $default_brand = Mage::helper('salesfire')->getDefaultBrand($storeId);

            @unlink(Mage::getBaseDir('media').'/catalog/'.$siteId.'.temp.xml');

            $mediaUrl = Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_MEDIA);

            $currency = $store->getCurrentCurrencyCode();

            $this->printLine($siteId, '<?xml version="1.0" encoding="utf-8" ?>', 0);
            $this->printLine($siteId, '<productfeed site="'.Mage::getBaseUrl().'" date-generated="'.gmdate('c').'">', 0);

            $categories = $this->getCategories($storeId);

            if (! empty($categories)) {
                $this->printLine($siteId, '<categories>', 1);
                foreach ($categories as $category) {
                    $parent = $category->getParentCategory()->setStoreId($storeId);
                    if ($category->getLevel() <= 1) {
                        continue;
                    }

                    $this->printLine($siteId, '<category id="category_' . $category->getId() . '"' . ($parent && $parent->getLevel() > 1 ? ' parent="category_'.$parent->getId(). '"' : '') . '>', 2);

                    $this->printLine($siteId, '<id>' . $this->escapeString($category->getId()) . '</id>', 3);

                    $this->printLine($siteId, '<name><![CDATA['.$this->escapeString($category->getName()).']]></name>', 3);

                    $this->printLine($siteId, '<breadcrumb><![CDATA['.$this->escapeString($this->getCategoryBreadcrumb($storeId, $category)).']]></breadcrumb>', 3);

                    $description = $category->getDescription();
                    if (! empty($description)) {
                        $this->printLine($siteId, '<description><![CDATA['.$this->escapeString($description).']]></description>', 3);
                    }

                    $this->printLine($siteId, '<link>' . $category->getUrl() . '</link>', 3);

                    $keywords = $category->getMetaKeywords();
                    if (! empty($keywords)) {
                        $this->printLine($siteId, '<keywords>', 3);
                        foreach (explode(',', $keywords) as $keyword) {
                            $this->printLine($siteId, '<keyword><![CDATA['.$this->escapeString($keyword).']]></keyword>', 4);
                        }
                        $this->printLine($siteId, '</keywords>', 3);
                    }

                    $this->printLine($siteId, '</category>', 2);
                }
                $this->printLine($siteId, '</categories>', 1);
            }

            $page = 1;
            do {
                $products = $this->getVisibleProducts($storeId, $page);
                $count = count($products);

                if ($page == 1 && $count) {
                    $this->printLine($siteId, '<products>', 1);
                }

                foreach ($products as $product) {
                    $this->printLine($siteId, '<product id="product_'.$product->getId().'">', 2);

                    $this->printLine($siteId, '<id><' . $product->getId() . '</id>', 3);

                    $this->printLine($siteId, '<title><![CDATA[' . $this->escapeString($product->getName()) . ']]></title>', 3);

                    $this->printLine($siteId, '<description><![CDATA[' . $this->escapeString(substr($this->_escaper->escapeHtml(strip_tags($product->getDescription())), 0, 5000)) . ']]></description>', 3);

                    $this->printLine($siteId, '<price currency="' . $currency . '">' . $this->getProductPrice($product, $currency, $bundlePriceModel) . '</price>', 3);

                    $this->printLine($siteId, '<sale_price currency="' . $currency . '">' . $this->getProductSalePrice($product, $currency, $bundlePriceModel) . '</sale_price>', 3);

                    $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($product->getSku()).']]></mpn>', 3);

                    $this->printLine($siteId, '<link>' . $product->getProductUrl() . '</link>', 3);

                    if (! empty($gender_code)) {
                        $gender = $product->getResource()->getAttribute($gender_code)->setStoreId($storeId)->getFrontend()->getValue($product);
                        if ($gender != 'No') {
                            $this->printLine($siteId, '<gender><![CDATA['.$this->escapeString($gender).']]></gender>', 3);
                        }
                    }

                    if (! empty($brand_code)) {
                        $brand = $product->getResource()->getAttribute($brand_code)->setStoreId($storeId)->getFrontend()->getValue($product);
                        if ($brand != 'No') {
                            $this->printLine($siteId, '<brand>' . $this->escapeString($brand) . '</brand>', 3);
                        }
                    } else if (! empty($default_brand)) {
                        $this->printLine($siteId, '<brand>' . $this->escapeString($default_brand) . '</brand>', 3);
                    }

                    $categories = $product->getCategoryIds();
                    if (! empty($categories)) {
                        $this->printLine($siteId, '<categories>', 3);
                        foreach ($categories as $categoryId) {
                            $this->printLine($siteId, '<category id="category_'.$categoryId.'" />', 4);
                        }
                        $this->printLine($siteId, '</categories>', 3);
                    }

                    $keywords = $product->getMetaKeywords();
                    if (! empty($keywords)) {
                        $this->printLine($siteId, '<keywords>', 3);
                        foreach (explode(',', $keywords) as $keyword) {
                            $this->printLine($siteId, '<keyword><![CDATA['.$this->escapeString($keyword).']]></keyword>', 4);
                        }
                        $this->printLine($siteId, '</keywords>', 3);
                    }

                    $attributeSetId = $product->getAttributeSetId();
                    $specificAttributes = Mage::getModel('catalog/product_attribute_api')->items($attributeSetId);
                    $attributeCodes = array();
                    foreach ($specificAttributes as $attributes) {
                        $attributeCodes[] = $attributes['code'];
                    }

                    $currentAttributes = array_diff($attributeCodes, $defaultAttributeCodes);

                    $this->printLine($siteId, '<variants>', 3);

                    if ($product->isConfigurable()) {
                        $childProducts = Mage::getModel('catalog/product_type_configurable')
                            ->getUsedProductCollection($product)
                            ->addAttributeToSelect('*');

                        if (count($childProducts) > 0) {
                            foreach ($childProducts as $childProduct) {
                                $this->printLine($siteId, '<variant>', 4);

                                $this->printLine($siteId, '<id>' . $childProduct->getId() . '</id>', 5);

                                foreach($currentAttributes as $attribute) {
                                    $attribute = trim($attribute);

                                    if (in_array($attribute, ['id', 'mpn', 'link', 'image', 'stock'])) {
                                        continue;
                                    }

                                    $text = $childProduct->getResource()->getAttribute($attribute)->setStoreId($storeId)->getFrontend()->getValue($childProduct);

                                    if ($text != 'No') {
                                        $this->printLine($siteId, '<'.$attribute.'><![CDATA['.$this->escapeString($text).']]></'.$attribute.'>', 5);
                                    }
                                }

                                $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($childProduct->getSku()).']]></mpn>', 5);

                                $this->printLine($siteId, '<stock>'.($childProduct->getStockItem() && $childProduct->getStockItem()->getIsInStock() ? ($childProduct->getStockItem()->getQty() > 0 ? (int) $childProduct->getData('stock_item')->getData('qty') : 1) : 0).'</stock>', 5);

                                $this->printLine($siteId, '<link>' . $product->getProductUrl() . '</link>', 5);

                                $image = $childProduct->getImage();
                                if (! empty($image)) {
                                    $this->printLine($siteId, '<image>' . $mediaUrl.'catalog/product'.$image . '</image>', 5);
                                }

                                $this->printLine($siteId, '</variant>', 4);
                            }
                        }
                    } else {
                        $this->printLine($siteId, '<variant>', 4);

                        $this->printLine($siteId, '<id>' . $product->getId() . '</id>', 5);

                        foreach($currentAttributes as $attribute) {
                            $attribute = trim($attribute);

                            $text = $product->getResource()->getAttribute($attribute)->setStoreId($storeId)->getFrontend()->getValue($product);

                            if ($text != 'No') {
                                $this->printLine($siteId, '<'.$attribute.'><![CDATA['.$this->escapeString($text).']]></'.$attribute.'>', 5);
                            }
                        }

                        $this->printLine($siteId, '<mpn><![CDATA['.$this->escapeString($product->getSku()).']]></mpn>', 5);

                        $this->printLine($siteId, '<stock>'.($product->getStockItem() && $product->getStockItem()->getIsInStock() ? ($product->getStockItem()->getQty() > 0 ? (int) $product->getData('stock_item')->getData('qty') : 1) : 0).'</stock>', 5);

                        $this->printLine($siteId, '<link>' . $product->getProductUrl() . '</link>', 5);

                        $image = $product->getImage();
                        if (! empty($image)) {
                            $this->printLine($siteId, '<image>' . $mediaUrl.'catalog/product'.$image . '</image>', 5);
                        }

                        $this->printLine($siteId, '</variant>', 4);
                    }

                    $this->printLine($siteId, '</variants>', 3);

                    $this->printLine($siteId, '</product>', 2);
                }

                $page++;
            } while ($count >= 100);

            if ($count || $page > 1) {
                $this->printLine($siteId, '</products>', 1);
            }

            $this->printLine($siteId, '</productfeed>', 0);

            @rename(Mage::getBaseDir('media').'/catalog/'.$siteId.'.temp.xml', Mage::getBaseDir('media').'/catalog/'.$siteId.'.xml');
            @unlink(Mage::getBaseDir('media').'/catalog/'.$siteId.'.temp.xml');
        }
    }

    public function getCategories($storeId)
    {
        $rootCategoryId = $this->_storeManager->getStore($storeId)->getRootCategoryId();
        $categories = $this->_categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addFieldToFilter('is_active', 1)
            ->addAttributeToFilter('path', array('like' => "1/{$rootCategoryId}/%"))
            ->addAttributeToSelect('*');

        return $categories;
    }

    public function getCategoryBreadcrumb($storeId, $category, $breadcrumb='')
    {
        if (! empty($breadcrumb)) {
            $breadcrumb = ' > ' . $breadcrumb;
        }

        $breadcrumb = $category->getName() . $breadcrumb;

        $parent = $category->getParentCategory()->setStoreId($storeId);
        if ($parent && $parent->getLevel() > 1) {
            return $this->getCategoryBreadcrumb($storeId, $parent, $breadcrumb);
        }

        return $breadcrumb;
    }

    protected function getVisibleProducts($storeId, $curPage=1, $pageSize=100)
    {
        $collection = $this->_productCollectionFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToFilter('status', 1)
            ->addAttributeToFilter('visibility', 4)
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addMinimalPrice()
            ->setPageSize($pageSize);

        if (!empty($curPage))
        {
            $collection->setCurPage($curPage);
        }

        $collection->clear();

        return $collection;
    }

    protected function getProductPrice($product, $currency, $bundlePriceModel)
    {
        switch($product->getTypeId())
        {
            case 'grouped':
                return $this->_attributeOptionsPrice($product, $product->getMinimalPriceattributeOptions);
            break;

            case 'bundle':
                return $bundlePriceModel->getTotalPrices($product, 'min', 1);
            break;

            default:
                return $this->_taxHelper->getTaxPrice($product, $product->getPrice(), true);
        }
    }

    protected function getProductSalePrice($product, $currency, $bundlePriceModel)
    {
        switch($product->getTypeId())
        {
            case 'grouped':
                return $this->_taxHelper->getTaxPrice($product, $product->getMinimalPrice(), true);
            break;

            case 'bundle':
                return $bundlePriceModel->getTotalPrices($product, 'min', 1);
            break;

            default:
                if ($product->getSpecialPrice()) {
                    return $this->_taxHelper->getTaxPrice($product, $product->getSpecialPrice(), true);
                }
                return $this->_taxHelper->getTaxPrice($product, $product->getFinalPrice(), true);
        }
    }
}
