<?php
/**
 *
 * @category   Mage
 * @package    Mage_Shell
 * @copyright  Copyright (c) 2016
 * @author     Gregory Hoole <greghoole@gmail.com>
 *
 * @todo still need pricing (using random pricing for now)
 *
 */

require_once '../abstract.php';

class Perfion_Api extends Mage_Shell_Abstract
{

    private $wsdl = "http://2.228.24.234:8080/Perfion/GetData.asmx?wsdl";

    private $options = array(
        'trace'        => TRUE,
        'soap_version' => SOAP_1_2,
        'style'        => SOAP_DOCUMENT,
        'use'          => SOAP_LITERAL,
        'encoding'     => 'utf-8',
        'classmap'     => array('timezone' => 'TimeZone')
    );

    private $attributes = [
        'CommercialNumber',
        'Description',
        'ExtendedDescription',
        'Graphene',
        'ItemName',
        'ItemNumber',
        'LastUpdate',
        'MetaDescription',
        'MetaKeywords',
        'ModelName',
        'ProductDevCategory',
        'ProductImage',
        'ProductWeight',
        'USP'
    ];

    /**
     * Setup our class for executing the product load process
     */
    public function __construct()
    {
        parent::__construct();

        $this->logFile = 'perfion_import_' . date('m_d_Y_H_i') . '.log';

        // set start message in log
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);
        Mage::log('Beginning Execution at ' . date('m/d/Y h:i a') . PHP_EOL, null, $this->logFile);
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);

        // setup read/write connections for any direct queries we need
        $resource = Mage::getSingleton('core/resource');
        // read
        $this->readConnection = $resource->getConnection('core_read');
        // write
        $this->writeConnection = $resource->getConnection('core_write');

        // absolute path to local instance of magento
        $this->rootPath = dirname(dirname(dirname(__FILE__)));

        // import paths (files for magmi)
        $this->importPath = $this->rootPath . '/var/import/';
        $this->importImagesPath = $this->importPath . '/images';

        if (!file_exists($this->importPath)) {
            mkdir($this->importPath, 0755, TRUE);
        }

        if (!file_exists($this->importImagesPath)) {
            mkdir($this->importImagesPath, 0755, TRUE);
        }

        $this->exportHeaders = array(
            'sku'
            , '_store'
            , 'attribute_set'
            , 'type'
            , '_category'
            , '_root_category'
            , '_product_websites'
            , 'created_at'
            , 'custom_design'
            , 'custom_design_from'
            , 'custom_design_to'
            , 'custom_layout_update'
            , 'description'
            , 'gallery'
            , 'gift_message_available'
            , 'has_options'
            , 'image'
            , 'image_label'
            , 'is_imported'
            , 'manufacturer'
            , 'media_gallery'
            , 'meta_description'
            , 'meta_keyword'
            , 'meta_title'
            , 'minimal_price'
            , 'msrp'
            , 'msrp_display_actual_price_type'
            , 'msrp_enabled'
            , 'name'
            , 'news_from_date'
            , 'news_to_date'
            , 'options_container'
            , 'page_layout'
            , 'price'
            , 'required_options'
            , 'short_description'
            , 'small_image'
            , 'small_image_label'
            , 'special_from_date'
            , 'special_price'
            , 'special_to_date'
            , 'status'
            , 'tax_class_id'
            , 'thumbnail'
            , 'thumbnail_label'
            , 'updated_at'
            , 'url_key'
            , 'url_path'
            , 'videoid'
            , 'visibility'
            , 'weight'
            , 'qty'
            , 'min_qty'
            , 'use_config_min_qty'
            , 'is_qty_decimal'
            , 'backorders'
            , 'use_config_backorders'
            , 'min_sale_qty'
            , 'use_config_min_sale_qty'
            , 'max_sale_qty'
            , 'use_config_max_sale_qty'
            , 'is_in_stock'
            , 'notify_stock_qty'
            , 'use_config_notify_stock_qty'
            , 'manage_stock'
            , 'use_config_manage_stock'
            , 'stock_status_changed_auto'
            , 'use_config_qty_increments'
            , 'qty_increments'
            , 'use_config_enable_qty_inc'
            , 'enable_qty_increments'
            , 'is_decimal_divided'
            , 'category_ids'
        );

        // client specific attributes
        foreach ($this->attributes as $attribute) {
            $this->exportHeaders[] = 'testuser_' . strtolower($attribute);
        }
    }

    /**
     * Run script
     */
    public function run()
    {
        // put magento into maintenance mode
        touch($this->rootPath . '/maintenance.flag');

        // load categories first
        $result = array();

        $client = new SoapClient($this->wsdl, $this->options);
        $query = "<Query><Select languages='EN'><Feature id='*' view='WebDetail'/></Select><From id='WebPlatform'/><Where><Clause id='WebQuery' operator='EXECUTE' value='B2C_Category' /></Where></Query>";
        $params = array('query' => $query);

        $result = $client->__soapCall('ExecuteQuery', array('parameters' => $params));

        $xmlString = str_replace('utf-16', 'utf-8', $result->ExecuteQueryResult);

        $xml = simplexml_load_string($xmlString);
        if ($xml !== FALSE) {

            Mage::log('Parsed XML Successfully' . PHP_EOL, null, $this->logFile);

            $xmlAsArray = $this->__xml2array($xml);

            $categories = [];
            foreach($xmlAsArray['WebPlatform'] as $category) {
                // build a parent/child array of categories - 5124 is the root
                
                $category_id = (int) $category->attributes()->id;
                $parent_id = (int) $category->attributes()->parentId;

                $categories[$category_id] = array(
                    'id' => $category_id,
                    'parent_id' => $parent_id,
                    'name' => (string) $category->Value,
                    'children' => []
                );
            }
            
            // sort by category id
            ksort($categories);

            // establish parent/child relationships
            foreach($categories as $category) {
                if (isset($categories[$category['parent_id']])) {
                    $categories[$category['parent_id']]['children'][] = $category['id'];
                }                
            }

            // get category paths
            $category_paths = $this->prepare_categories_for_update($categories, 5124, '');

            $category_index = [];
            foreach(explode(PHP_EOL, $category_paths) as $path) {
                if (!empty($path)) { 
                    $path_parts = explode(';', $path);
                    $index = array_pop($path_parts);
                    $category_index[$index] = $path;
                }
            }
        }        

        // export file
        $this->outputFile = $this->importPath . time() . '_' . date('m_d_Y_H_i') . '_perfion_import.csv';

        $result = array();

        $client = new SoapClient($this->wsdl, $this->options);
        $query = "<Query><Select languages='EN'><Feature id='*' view='WebDetail'/><Feature id='WebPlatform'/></Select><From id='100'/><Where><Clause id='WebQuery' operator='EXECUTE' value='B2C' /></Where></Query>";
        $params = array('query' => $query);

        // deactivate the catalog
        $this->deactivateAllProducts();

        try {
            $result = $client->__soapCall('ExecuteQuery', array('parameters' => $params));

            $xmlString = str_replace('utf-16', 'utf-8', $result->ExecuteQueryResult);

            $xml = simplexml_load_string($xmlString);
            if ($xml !== FALSE) {

                Mage::log('Parsed XML Successfully' . PHP_EOL, null, $this->logFile);

                $xmlAsArray = $this->__xml2array($xml);

                // these array keys are the product attributes - keep for debugging
                // print_r(array_keys($xmlAsArray['Features']));

                // these are the items provided - keep for debugging
                // print_r($xmlAsArray['ItemName'][0]);

                if ($outputHandle = fopen($this->outputFile, 'wb')) {

                    // export header row
                    fputcsv($outputHandle, $this->exportHeaders, ',', '"');

                    $attributeSet = 'Default';

                    // loop starts here...
                    foreach ($xmlAsArray['ItemName'] as $product) {

                        $loadedImages = [];
                        
                        $productImages = $this->__xml2array($product->ProductImage);
                        unset($productImages['@attributes']);
                        foreach ($productImages as $key => $image) {
                            $imageFileName = $image . '.jpg';
                            if (!file_exists($this->importImagesPath . '/' . $imageFileName)) {
                                $imageFile = $this->__getFile($image);
                                if ($imageHandle = fopen($this->importImagesPath . '/' . $imageFileName, 'wb')) {
                                    fwrite($imageHandle, $imageFile);
                                    fclose($imageHandle);
                                }
                            }
                            $loadedImages[] = $imageFileName;
                        }

                        // exportRow is the Magento formated row
                        $exportRow = array();
                        $exportRow[] = $product->ItemNumber; // sku
                        $exportRow[] = ''; // _store
                        $exportRow[] = $attributeSet; // _attribute_set
                        $exportRow[] = 'simple'; // _type
                        $exportRow[] = ''; // _category
                        $exportRow[] = 'Default Category'; // _root_category
                        $exportRow[] = 'base'; // _product_websites
                        $exportRow[] = date('n/d/y H:i', strtotime($product->LastUpdate)); // created_at
                        $exportRow[] = ''; // custom_design
                        $exportRow[] = ''; // custom_design_from
                        $exportRow[] = ''; // custom_design_to
                        $exportRow[] = ''; // custom_layout_update
                        $exportRow[] = $product->ExtendedDescription; // description
                        $exportRow[] = ''; // gallery
                        $exportRow[] = ''; // gift_message_available
                        $exportRow[] = '0'; // has_options
                        $exportRow[] = (count($loadedImages)) ? '+' . $loadedImages[0] : ''; // image (+ for include)
                        $exportRow[] = ''; // image_label
                        $exportRow[] = 'No'; // is_imported
                        $exportRow[] = ''; // manufacturer
                        $exportRow[] = (count($loadedImages)) ? '+' . implode(';+', $loadedImages) : ''; // media_gallery
                        $exportRow[] = $product->MetaDescription; // meta_description
                        $exportRow[] = $product->MetaKeywords; // meta_keyword
                        $exportRow[] = $product->Value; // meta_title
                        $exportRow[] = ''; // minimal_price
                        $exportRow[] = ''; // msrp
                        $exportRow[] = 'Use config'; // msrp_display_actual_price_type
                        $exportRow[] = 'Use config'; // msrp_enabled
                        $exportRow[] = $product->Value; // name
                        $exportRow[] = ''; // news_from_date
                        $exportRow[] = ''; // news_to_date
                        $exportRow[] = 'Block after Info Column'; // options_container
                        $exportRow[] = ''; // page_layout
                        $exportRow[] = mt_rand(10, 1000); // price - NEED
                        $exportRow[] = '0'; // required_options
                        $exportRow[] = $product->Description; // short description
                        $exportRow[] = (count($loadedImages)) ? '+' . $loadedImages[0] : ''; // small_image (+ for include)
                        $exportRow[] = ''; // small_image_label
                        $exportRow[] = ''; // special_from_date
                        $exportRow[] = ''; // special_price
                        $exportRow[] = ''; // special_to_date
                        $exportRow[] = '1'; // status - all product data coming from API is active
                        $exportRow[] = '2'; // tax_class_id
                        $exportRow[] = (count($loadedImages)) ? '+' . $loadedImages[0] : ''; // thumbnail (+ for include)
                        $exportRow[] = ''; // thumbnail_label
                        $exportRow[] = date('n/d/y H:i', strtotime($product->LastUpdate)); // updated_at
                        $exportRow[] = $this->__getUrlKey($product->Value); // url_key
                        $exportRow[] = $this->__getUrlKey($product->Value); // url_path
                        $exportRow[] = ''; // video_id
                        $exportRow[] = '4'; // visibility
                        $exportRow[] = $product->ProductWeight; // weight
                        $exportRow[] = '10000'; // qty - set to a high number, we aren't managing qty
                        $exportRow[] = '0'; // min_qty
                        $exportRow[] = '1'; // use_config_min_qty
                        $exportRow[] = '0'; // is_qty_decimal
                        $exportRow[] = '0'; // backorders
                        $exportRow[] = '1'; // use_config_backorders
                        $exportRow[] = '1'; // min_sale_qty
                        $exportRow[] = '1'; // use_config_min_sale_qty
                        $exportRow[] = '0'; // max_sale_qty
                        $exportRow[] = '1'; // use_config_max_sale_qty
                        $exportRow[] = '1'; // is_in_stock
                        $exportRow[] = ''; // notify_stock_qty
                        $exportRow[] = '1'; // use_config_notify_stock_qty
                        $exportRow[] = '0'; // manage_stock
                        $exportRow[] = '1'; // use_config_manage_stock
                        $exportRow[] = '0'; // stock_status_changed_auto
                        $exportRow[] = '1'; // use_config_qty_increments
                        $exportRow[] = '0'; // qty_increments
                        $exportRow[] = '1'; // use_config_enable_qty_inc
                        $exportRow[] = '0'; // enable_qty_increments
                        $exportRow[] = '0'; // is_decimal_divided
                        $exportRow[] = $this->__getCategories($category_index[(string) $product->WebPlatform]); //'2,12,20'; // category_ids

                        // client specific attributes
                        $attributeOffset = 73;

                        foreach ($this->attributes as $attribute) {
                            $exportRow[] = ''; // fill with blanks initially (maintains column spacing)
                        }

                        foreach($product as $key => $value) {
                            $keyPosition = array_search($key, $this->attributes);
                            $exportRow[$keyPosition + $attributeOffset] = $value;
                        }

                        fputcsv($outputHandle, $exportRow, ',', '"');

                        // log success
                        Mage::log('Added ' . $product->ItemNumber . ' successfully to import file ' . PHP_EOL, null, $this->logFile);
                    }
                    // loop ends here...

                    // close file
                    fclose($outputHandle);

                } else {
                    throw new Exception('Unable to open ' . $this->outputFile . PHP_EOL);
                }
            } else {
                throw new Exception('Unable to parse result into XML' . PHP_EOL);
            }
        } catch (SoapFault $e) {
            Mage::log($e->getMessage() . PHP_EOL, null, $this->logFile);
            $this->__exceptionNotification($e->getMessage());
        } catch(Exception $e) {
            Mage::log($e->getMessage() . PHP_EOL, null, $this->logFile);
            $this->__exceptionNotification($e->getMessage());
        }

        // execute magmi import process here
        $this->__executeMagmiImport();

        // take magento out of maintenance mode
        unlink($this->rootPath . '/maintenance.flag');

        // set end message in log
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);
        Mage::log('Completed Execution at ' . date('m/d/Y h:i a') . PHP_EOL, null, $this->logFile);
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);
    }

    /**
     * Simple recursive function to print the categories in parent/child view
     *
     * @param array $categories all the categories 
     * @param int $current_root current category root id
     * @param int $level current category level
     *
     * @return string $data Image Data
     *
     */
    private function list_categories($categories, $current_root, $level = 0) {

        $root_category = $categories[$current_root];

        if ($level) {
            echo str_repeat('-', $level) . ' ' . $root_category['name'] . PHP_EOL;
        }

        if (!empty($root_category['children'])) {
            foreach ($root_category['children'] as $category_id) {
                $this->list_categories($categories, $category_id, $level+1);
            }
        }
    }

    /**
     * Prepares category tree for comparison to currently loaded categories
     *
     * @param array $categories all the categories 
     * @param int $current_root current category root id
     * @param string $output category string
     *
     * @return string $data Image Data
     *
     */
    private function prepare_categories_for_update($categories, $current_root, $output = '') {

        $root_category = $categories[$current_root];

        if (!empty($root_category['children'])) {
            foreach ($root_category['children'] as $category_id) {
                $result .= $this->prepare_categories_for_update($categories, $category_id, $output . $root_category['name'] . ';');
            }
            return $result;
        } else {
            return $output . $root_category['name'] . PHP_EOL;
        }
    }

    /**
     * Fetch a product image from the provided url
     *
     * @param string $imageId File Id
     *
     * @return string $data Image Data
     *
     */
    private function __getFile($imageId)
    {
        // need to pretend to be a browser
        $agent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.0.3705; .NET CLR 1.1.4322)';

        $url = 'http://2.228.24.234:8080/Perfion/Image.aspx?id=' . $imageId;
        Mage::log('Fetching ' . $url . PHP_EOL, null, $this->logFile);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        curl_setopt($ch, CURLOPT_URL, $url);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }

    /**
     * Determine product categories for given category path
     * Assumes categories already loaded into Magento
     *
     * @param string $path category path
     *
     * @return string $categoryIds comma separated list of category ids
     *
     */
    private function __getCategories($path)
    {
        // set store
        Mage::app()->setCurrentStore(1);

        $categoryIds = array(2);

        $categories = explode(';', $path);

        // discard the first one cause this is the root category
        array_shift($categories);

        $categoryCount = count($categories);

        $_category = Mage::getModel('catalog/category')->load(2);
        if ($_category->getId()) {
            $_subcategories = $_category->getChildrenCategories(); // root level sub categories
            if (count($_subcategories) > 0) {
                for ($i = 0; $i < $categoryCount; $i++) { // each loop is a new level (skip first as that is the root)
                    $categoryName = strtoupper($categories[$i]);

                    // if blank/empty, ignore
                    if (empty(trim($categoryName))) {
                        continue;
                    }

                    $levelMatch = null;

                    foreach($_subcategories as $_subcategory) {

                        if (is_null($levelMatch)) {
                            if (strtoupper($_subcategory->getName()) == $categoryName) { // match
                                $levelMatch = $_subcategory->getId();
                            }
                        }

                    }

                    if (is_null($levelMatch)) { // no match, add and assign
                        $newCategoryId = $this->__addCategory($categoryName, $_category->getId());
                    } else { // match, assign
                        $newCategoryId = $levelMatch;
                    }

                    $categoryIds[] = $newCategoryId;
                    $_category = Mage::getModel('catalog/category')->load($newCategoryId);
                    $_subcategories = $_category->getChildrenCategories(); // next level sub categories

                    $levelMatch = null; // reset

                }

            } else { // no root sub categories - add our tree (happens only once)

                for ($i = 0; $i < $categoryCount; $i++) {
                    $categoryName = strtoupper($categories[$i]);

                    // if blank/empty, ignore
                    if (empty(trim($categoryName))) {
                        continue;
                    }

                    $newCategoryId = $this->__addCategory($categoryName, $_category->getId());
                    $categoryIds[] = $newCategoryId;
                    $_category = Mage::getModel('catalog/category')->load($newCategoryId);
                }
            }

        } else {
            echo 'Error!! Default category not loaded' . PHP_EOL;
        }
        return implode(',', $categoryIds);
    }

    /**
     * Add new category to Magento
     *
     * @param string $categoryName category name
     * @param int $parentId category parent id
     *
     * @return int $category->getId() category id of new category
     *
     */
    private function __addCategory($categoryName, $parentId)
    {
        try {

            $category = Mage::getModel('catalog/category');
            $category->setName($categoryName);
            $category->setUrlKey($this->__getUrlKey($categoryName));
            $category->setIsActive(1);
            $category->setDisplayMode(Mage_Catalog_Model_Category::DM_PRODUCT);
            $category->setIsAnchor(1);
            $category->setStoreId(1);

            $parentCategory = Mage::getModel('catalog/category')->load($parentId);
            $category->setPath($parentCategory->getPath());
            $category->save();

            echo 'Category "' . $categoryName . '" Added!' . PHP_EOL;

            return $category->getId();

        } catch(Exception $e) {
            var_dump($e);
        }
    }

    /**
     * Using a product title, generate the url key
     * Spaces are replaced with an underscore
     * All other non-alphanumeric characters are removed
     * Key is always lowercase
     *
     * @param string $title product title
     *
     * @return string $url_key clean and formatted url key
     *
     */
    private function __getUrlKey($title)
    {
        $pattern = '/[^a-z0-9_]+/i';
        $replacement = '';
        $url_key = strtolower(preg_replace($pattern, $replacement, str_replace(' ', '_', $title)));
        return $url_key;
    }

    /**
     * Deactivate all products in catalog (value = 2)
     *
     * This is done before each product load as the API will only provide
     * active products. This was, if a product is no longer in the API feed
     * it will be inactive after the run.
     *
     * @return void
     *
     */
    private function deactivateAllProducts()
    {
        $sql = 'UPDATE catalog_product_entity_int cpei, catalog_product_entity cpe ';
        $sql .= 'SET cpei.value = "2" ';
        $sql .= 'WHERE cpe.entity_id = cpei.entity_id ';
        $sql .= 'AND cpei.attribute_id = 96';
        $this->writeConnection->query($sql);
    }

    /**
     * Now that we generated an import file, kick off the Magmi process to import the data
     *
     * @return void
     *
     */
    private function __executeMagmiImport()
    {

        $magmiCommand = 'cd ' . $this->rootPath . '/magmi/cli; php magmi.cli.php -profile=TestUser -mode=create -CSV:filename="' . $this->outputFile . '"';
        
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);
        Mage::log('Start MAGMI Execution at ' . date('m/d/Y h:i a') . PHP_EOL, null, $this->logFile);
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);

        $output = shell_exec($magmiCommand);
        
        Mage::log('Magmi Output: ' . PHP_EOL . $output . PHP_EOL, null, $this->logFile);
        $progressText = file_get_contents($this->rootPath . '/magmi/state/progress.txt');
        Mage::log('Magmi Progress Output: ' . PHP_EOL . $progressText . PHP_EOL, null, $this->logFile);

        $indexingCommand = 'cd ' . $this->rootPath . '/shell; php indexer.php --reindexall';

        $output = shell_exec($indexingCommand);

        Mage::log('Indexer Output: ' . PHP_EOL . $output . PHP_EOL, null, $this->logFile);

        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);
        Mage::log('Completed MAGMI Execution at ' . date('m/d/Y h:i a') . PHP_EOL, null, $this->logFile);
        Mage::log('---------------------------------------------' . PHP_EOL, null, $this->logFile);

        return;
    }

    /**
     * Send an email notification that an exception occurred
     *
     * This has to happen as the site will (most likely) still be in maintenance mode
     *
     * @return void
     *
     */
    private function __exceptionNotification($message)
    {
        $receipients = [
            'testuser@domain.com'
        ];

        $subject = 'URGENT: API Feed Exception';

        $message = 'The following exception was encountered while running the API Feed: ' . PHP_EOL . PHP_EOL;
        $message .= '----------------------------------------------------' . PHP_EOL;
        $message .= $message . PHP_EOL . PHP_EOL;
        $message .= '----------------------------------------------------' . PHP_EOL . PHP_EOL;
        $message .= 'Please respond quickly. The site is most likely still in maintenance mode.';

        $headers = 'From: Test User <testuser@domain.com>';

        foreach($receipients as $receipient) {
            mail($receipient, $subject, $message, $headers);
        }
    }

    /**
     * Convert an XML object (SimpleXMLElement object) to an array
     *
     * @param SimpleXMLElement $xmlObject
     * @param array $out
     *
     * @return array
     *
     **/
    private function __xml2array($xmlObject, $out = array())
    {
        foreach ( (array) $xmlObject as $index => $node ) {
            $out[$index] = ( is_object ( $node ) ) ? $this->__xml2array ( $node ) : $node;
        }
        return $out;
    }
}

$shell = new Perfion_Api();
$shell->run();
